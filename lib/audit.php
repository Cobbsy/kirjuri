<?php
/* The audit log: one entry per change, each in a file of its own under logs/audit/, sealed with AES-256-GCM and
** chained to the entry before it by that entry's SHA-256, with an index in the database. An edited, removed,
** reordered or inserted entry shows when the chain is checked. See docs/audit-log-design.md.
*/

const KIRJURI_AUDIT_DIR = 'logs/audit';
const KIRJURI_AUDIT_KEY_FILE = 'conf/audit_credentials.php';
const KIRJURI_AUDIT_MAGIC = 'KIRJURI-AUDIT 2';

/** An audit log entry could not be written. The change it was for must not be saved. */
class KirjuriAuditException extends RuntimeException {}


/**
 * The key string in the audit key file, or null when the file is missing or holds no key. The file is PHP, so that
 * it shows nothing if a web server without Kirjuri's access rules serves it, but it is read as text, never run.
 */
function kirjuri_audit_key_string(string $key_file = KIRJURI_AUDIT_KEY_FILE): ?string {
    $handle = is_file($key_file) ? @fopen($key_file, 'r') : false;
    if ($handle === false) {
        return null;
    }
    flock($handle, LOCK_SH); // Wait for a key that is being written.
    $contents = (string) stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
        $contents = substr($contents, 3);
    }
    return preg_match('/return\s*(["\'])([^"\']+)\1/', $contents, $match) ? $match[2] : null;
}


/**
 * Write a new audit key file, unless there is one: 64 hex characters from 32 random bytes. Returns whether this call
 * wrote it. 'x' fails when another request has just made the file, so only one key is ever written.
 */
function kirjuri_audit_create_key(string $key_file = KIRJURI_AUDIT_KEY_FILE): bool {
    $handle = @fopen($key_file, 'x');
    if ($handle === false) {
        return false;
    }
    flock($handle, LOCK_EX);
    fwrite($handle, '<?php return "' . bin2hex(random_bytes(32)) . '";' . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}


/** The 32 byte AES-256-GCM key for entries, from all of the key string. */
function kirjuri_audit_entry_key(string $key_string): string {
    return hash_hkdf('sha256', $key_string, 32, 'kirjuri audit log entries');
}


/** What an entry's seal is bound to: its number, the previous entry's fingerprint and its file name. */
function kirjuri_audit_context(int $number, string $previous, string $file): string {
    return 'kirjuri audit entry ' . $number . ' ' . $previous . ' ' . $file;
}


/** The contents of entry $number's file: one line with the number, the previous fingerprint and the sealed payload. */
function kirjuri_audit_seal_entry(int $number, string $previous, string $file, array $payload, string $key_string): string {
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new KirjuriAuditException('The entry could not be encoded: ' . json_last_error_msg());
    }
    $sealed = kirjuri_seal(gzencode($json), kirjuri_audit_entry_key($key_string), kirjuri_audit_context($number, $previous, $file));
    return KIRJURI_AUDIT_MAGIC . ' ' . $number . ' ' . $previous . ' ' . $sealed . "\n";
}


/**
 * Read an entry file. "format" is "chain" for a sealed entry, "plain" for an old unencrypted one (written when
 * OpenSSL was missing) and "legacy" for an old AES-256-CBC one. "payload" is null when the entry does not open:
 * changed, moved, or sealed with another key. "number" and "previous" are what a sealed entry's line says.
 */
function kirjuri_audit_open_entry(string $contents, string $file, ?string $key_string): array {
    $entry = array('format' => 'legacy', 'number' => null, 'previous' => null, 'payload' => null);
    if (strncmp($contents, KIRJURI_AUDIT_MAGIC . ' ', strlen(KIRJURI_AUDIT_MAGIC) + 1) === 0) {
        $entry['format'] = 'chain';
        $parts = explode(' ', rtrim($contents, "\n"), 5);
        if (count($parts) !== 5 || !preg_match('/^[1-9][0-9]*$/', $parts[2]) || !preg_match('/^[0-9a-f]{64}$/', $parts[3])) {
            return $entry;
        }
        $entry['number'] = (int) $parts[2];
        $entry['previous'] = $parts[3];
        if ($key_string === null) {
            return $entry;
        }
        $sealed = kirjuri_unseal($parts[4], kirjuri_audit_entry_key($key_string), kirjuri_audit_context($entry['number'], $entry['previous'], $file));
        $json = ($sealed === null) ? false : @gzdecode($sealed);
        $payload = ($json === false) ? null : json_decode($json, true);
        $entry['payload'] = is_array($payload) ? $payload : null;
        return $entry;
    }
    $data = trim($contents);
    if (substr($data, 0, 1) === '{') {
        $entry['format'] = 'plain';
    } elseif ($key_string !== null) {
        $data = @decrypt($data, $key_string);
    } else {
        return $entry;
    }
    $payload = is_string($data) ? json_decode($data, true) : null;
    $entry['payload'] = is_array($payload) ? $payload : null;
    return $entry;
}


/** An entry's fingerprint: the SHA-256 of its whole file, in lowercase hex. */
function kirjuri_audit_fingerprint(string $contents): string {
    return hash('sha256', $contents);
}


/** The first 20 hex characters of a fingerprint in groups of four, as checkpoints show it. */
function kirjuri_audit_short_fingerprint(string $fingerprint): string {
    return implode(' ', str_split(substr($fingerprint, 0, 20), 4));
}


/**
 * A checkpoint as someone types or pastes it: "48213:9f3c1a2b77de0c415e9a", or a checkpoint line of audit:verify or a
 * report. The entry is the first number; the fingerprint is what follows the last comma (or else the first colon),
 * without spaces, at least 16 hex characters. Null when it can not be read.
 */
function kirjuri_audit_parse_checkpoint(string $text): ?array {
    $text = strtolower(trim($text));
    if (!preg_match('/\d+/', $text, $number)) {
        return null;
    }
    $comma = strrpos($text, ',');
    $colon = strpos($text, ':', (int) strpos($text, $number[0]));
    if ($comma !== false) {
        $fingerprint = substr($text, $comma + 1);
    } elseif ($colon !== false) {
        $fingerprint = substr($text, $colon + 1);
    } else {
        return null;
    }
    $fingerprint = (string) preg_replace('/\s+/', '', $fingerprint);
    if (!preg_match('/^[0-9a-f]{16,64}$/', $fingerprint)) {
        return null;
    }
    return array('entry' => (int) $number[0], 'prefix' => $fingerprint);
}


/** $request without fields whose name contains "password", at any depth: no password ever reaches the audit log. */
function kirjuri_audit_clean_request(array $request): array {
    foreach ($request as $key => $value) {
        if (stripos((string) $key, 'password') !== false) {
            unset($request[$key]);
        } elseif (is_array($value)) {
            $request[$key] = kirjuri_audit_clean_request($value);
        }
    }
    return $request;
}


/** Where entry file $file is, or null when $file is not an entry's name (such as a path). */
function kirjuri_audit_entry_path(string $file, string $dir = KIRJURI_AUDIT_DIR): ?string {
    if (!preg_match('/^\d{10}_[A-Za-z0-9]+\.log$/', $file)) {
        return null;
    }
    return $dir . '/' . substr($file, 0, 6) . '/' . $file;
}


/** Every file with an entry's name in a six-digit folder of $dir, as file name => path, sorted by name. */
function kirjuri_audit_entry_files(string $dir = KIRJURI_AUDIT_DIR): array {
    $files = array();
    foreach (glob($dir . '/[0-9][0-9][0-9][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: array() as $folder) {
        foreach (scandir($folder) ?: array() as $file) {
            if (preg_match('/^\d{10}_[A-Za-z0-9]+\.log$/', $file) && is_file($folder . '/' . $file)) {
                $files[$file] = $folder . '/' . $file;
            }
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}


/** A value that changes with the key, from which the key can not be worked out: it shows when the key was replaced. */
function kirjuri_audit_key_check(string $key_string): string {
    return bin2hex(hash_hkdf('sha256', $key_string, 32, 'kirjuri audit log key check'));
}


/**
 * The newest entry of the chain: its number, fingerprint and time, and the number of the entry that recorded the last
 * key replacement. Null while there is no chain.
 */
function kirjuri_audit_head(PDO $db): ?array {
    $row = $db->query('SELECT h.entry, h.fingerprint, h.key_replaced_at, c.created FROM audit_head h
        LEFT JOIN audit_chain c ON c.entry = h.entry WHERE h.id = 1')->fetch(PDO::FETCH_ASSOC);
    if ($row === false || (int) $row['entry'] === 0) {
        return null;
    }
    return array('entry' => (int) $row['entry'], 'fingerprint' => (string) $row['fingerprint'], 'created' => (string) $row['created'],
        'key_replaced_at' => ($row['key_replaced_at'] === null) ? null : (int) $row['key_replaced_at']);
}


/**
 * Write the audit log entry of a change, inside the open transaction that saves the change: the entry and its change
 * are saved together or not at all. The first entry starts the chain; a key that is missing or was replaced is
 * recorded in an entry of its own first. Returns the entry's file name ("file"), number and fingerprint, the number of
 * old entries fingerprinted when this call started the chain ("started") and the number of the key replacement's
 * entry when it wrote one ("key_replaced"). On any failure it deletes what it wrote and throws KirjuriAuditException;
 * the caller rolls back.
 */
function kirjuri_audit_write(PDO $db, ?int $case_id, string $level, string $description, array $request, array $user,
        string $dir = KIRJURI_AUDIT_DIR, string $key_file = KIRJURI_AUDIT_KEY_FILE): array {
    if (!$db->inTransaction()) {
        throw new LogicException('kirjuri_audit_write() needs an open transaction, so that the entry is saved with its change.');
    }
    $written = array();
    try {
        $head = kirjuri_audit_lock_head($db); // Held until the commit: one entry at a time.
        [$key_string, $made_key] = kirjuri_audit_key_for_writing($key_file);
        $check = kirjuri_audit_key_check($key_string);
        $result = array('started' => null, 'key_replaced' => null);
        if ($head['entry'] === 0) {
            $result['started'] = kirjuri_audit_start_locked($db, $head, $user, $dir, $key_string, $written);
        } elseif ($head['key_check'] !== $check) {
            $number = $head['entry'] + 1;
            $text = ($made_key ? 'The audit log key was missing, and a new one was made.' : 'The audit log key was replaced.')
                . ' Entries 1 to ' . $head['entry'] . ' can no longer be read with it.';
            $entry = kirjuri_audit_put($db, $number, $head['fingerprint'], null, 'Audit', $text, array(), $user, $dir, $key_string, array(), $written);
            $head = array('entry' => $number, 'fingerprint' => $entry['fingerprint'], 'key_replaced_at' => $number, 'key_check' => $check);
            $result['key_replaced'] = $number;
        }
        $number = $head['entry'] + 1;
        $entry = kirjuri_audit_put($db, $number, $head['fingerprint'], $case_id, $level, $description, $request, $user, $dir, $key_string, array(), $written);
        kirjuri_audit_save_head($db, $number, $entry['fingerprint'], $head['key_replaced_at'], $check);
        return array('file' => $entry['file'], 'entry' => $number, 'fingerprint' => $entry['fingerprint']) + $result;
    } catch (Throwable $e) {
        foreach ($written as $path) {
            @unlink($path);
        }
        throw ($e instanceof KirjuriAuditException) ? $e : new KirjuriAuditException($e->getMessage(), 0, $e);
    }
}


/**
 * Start the chain now if there is none, in a transaction of its own: for bin/kirjuri migrate, so that an upgrade does
 * not leave the work to someone's first change. Returns the number of old entries fingerprinted, or null when there
 * already is a chain.
 */
function kirjuri_audit_start(PDO $db, array $user, string $dir = KIRJURI_AUDIT_DIR, string $key_file = KIRJURI_AUDIT_KEY_FILE): ?int {
    $db->beginTransaction();
    $written = array();
    try {
        $head = kirjuri_audit_lock_head($db);
        if ($head['entry'] !== 0) {
            $db->commit();
            return null;
        }
        $count = kirjuri_audit_start_locked($db, $head, $user, $dir, kirjuri_audit_key_for_writing($key_file)[0], $written);
        kirjuri_audit_save_head($db, $head['entry'], $head['fingerprint'], null, $head['key_check']);
        $db->commit();
        return $count;
    } catch (Throwable $e) {
        $db->rollBack();
        foreach ($written as $path) {
            @unlink($path);
        }
        throw ($e instanceof KirjuriAuditException) ? $e : new KirjuriAuditException($e->getMessage(), 0, $e);
    }
}


/** The audit_head row, locked until the transaction ends. */
function kirjuri_audit_lock_head(PDO $db): array {
    $row = $db->query('SELECT entry, fingerprint, key_replaced_at, key_check FROM audit_head WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new KirjuriAuditException('The audit log has no head row. Run php bin/kirjuri migrate.');
    }
    return array('entry' => (int) $row['entry'], 'fingerprint' => (string) $row['fingerprint'],
        'key_replaced_at' => ($row['key_replaced_at'] === null) ? null : (int) $row['key_replaced_at'], 'key_check' => $row['key_check']);
}


function kirjuri_audit_save_head(PDO $db, int $entry, string $fingerprint, ?int $key_replaced_at, ?string $key_check): void {
    $db->prepare('UPDATE audit_head SET entry = :entry, fingerprint = :fingerprint, key_replaced_at = :replaced, key_check = :check WHERE id = 1')
        ->execute(array(':entry' => $entry, ':fingerprint' => $fingerprint, ':replaced' => $key_replaced_at, ':check' => $key_check));
}


/**
 * The key string to write with, and whether this call made the key file. A missing key file is made; one that exists
 * but holds no key stops the write, as a new key would make every entry before it unreadable.
 */
function kirjuri_audit_key_for_writing(string $key_file): array {
    $key_string = kirjuri_audit_key_string($key_file);
    if ($key_string !== null) {
        return array($key_string, false);
    }
    if (file_exists($key_file)) {
        throw new KirjuriAuditException('The audit log key ' . $key_file . ' can not be read.');
    }
    $made = kirjuri_audit_create_key($key_file);
    $key_string = kirjuri_audit_key_string($key_file);
    if ($key_string === null) {
        throw new KirjuriAuditException('The audit log key ' . $key_file . ' could not be written. Can the web server write to its folder?');
    }
    return array($key_string, $made);
}


/**
 * Entry 1: fingerprint every entry file there is (they are all from before the chain) into audit_legacy, and seal
 * the SHA-256 of their list. Moves $head to entry 1. Returns how many old entries there were.
 */
function kirjuri_audit_start_locked(PDO $db, array &$head, array $user, string $dir, string $key_string, array &$written): int {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0); // An old installation can have tens of thousands of entries to read.
    }
    $db->exec('DELETE FROM audit_legacy');
    $list = '';
    $rows = array();
    $files = kirjuri_audit_entry_files($dir);
    foreach ($files as $file => $path) {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new KirjuriAuditException('Can not read the old audit log entry ' . $path . '.');
        }
        $fingerprint = kirjuri_audit_fingerprint($contents);
        $list .= $file . ' ' . $fingerprint . "\n";
        $rows[] = array($file, $fingerprint, strlen($contents));
        if (count($rows) === 500) {
            kirjuri_audit_insert_legacy($db, $rows);
            $rows = array();
        }
    }
    kirjuri_audit_insert_legacy($db, $rows);
    $count = count($files);
    $list_sha256 = hash('sha256', $list);
    $text = 'Audit log chain started. ' . $count . ' earlier ' . ($count === 1 ? 'entry' : 'entries') . ' fingerprinted.';
    $entry = kirjuri_audit_put($db, 1, $list_sha256, null, 'Audit', $text, array(), $user, $dir, $key_string,
        array('old_entries' => array('count' => $count, 'list_sha256' => $list_sha256)), $written);
    $head = array('entry' => 1, 'fingerprint' => $entry['fingerprint'], 'key_replaced_at' => null, 'key_check' => kirjuri_audit_key_check($key_string));
    return $count;
}


function kirjuri_audit_insert_legacy(PDO $db, array $rows): void {
    if (empty($rows)) {
        return;
    }
    $values = array();
    foreach ($rows as $row) {
        array_push($values, $row[0], $row[1], $row[2]);
    }
    $db->prepare('INSERT INTO audit_legacy (file, fingerprint, size) VALUES ' . implode(', ', array_fill(0, count($rows), '(?, ?, ?)')))
        ->execute($values);
}


/** Seal and write entry $number's file and its audit_chain row. Returns the file's name and fingerprint. */
function kirjuri_audit_put(PDO $db, int $number, string $previous, ?int $case_id, string $level, string $description, array $request,
        array $user, string $dir, string $key_string, array $extra, array &$written): array {
    $time = time();
    $folder = $dir . '/' . substr((string) $time, 0, 6);
    if (!is_dir($folder) && !@mkdir($folder) && !is_dir($folder)) {
        throw new KirjuriAuditException('Can not create the audit log folder ' . $folder . '.');
    }
    $payload = array('entry' => $number, 'time' => date('c', $time), 'case' => $case_id,
        'event' => array('level' => $level, 'description' => $description), 'user' => $user,
        'request_contents' => kirjuri_audit_clean_request($request)) + $extra;
    $handle = false;
    for ($attempt = 0; $attempt < 10 && $handle === false; $attempt++) {
        $file = $time . '_' . strtoupper(generate_token(4)) . '.log';
        $handle = @fopen($folder . '/' . $file, 'x'); // Never an existing file.
    }
    if ($handle === false) {
        throw new KirjuriAuditException('Can not create an audit log entry in ' . $folder . '.');
    }
    $path = $folder . '/' . $file;
    $written[] = $path;
    $contents = kirjuri_audit_seal_entry($number, $previous, $file, $payload, $key_string);
    $saved = fwrite($handle, $contents) === strlen($contents) && fflush($handle) && fsync($handle);
    fclose($handle);
    if (!$saved) {
        throw new KirjuriAuditException('Could not write the audit log entry ' . $path . '.');
    }
    $fingerprint = kirjuri_audit_fingerprint($contents);
    $db->prepare('INSERT INTO audit_chain (entry, file, fingerprint, previous, case_id, username, created)
        VALUES (:entry, :file, :fingerprint, :previous, :case_id, :username, :created)')
        ->execute(array(':entry' => $number, ':file' => $file, ':fingerprint' => $fingerprint, ':previous' => $previous,
            ':case_id' => $case_id, ':username' => (string) ($user['username'] ?? '-'), ':created' => date('Y-m-d H:i:s', $time)));
    return array('file' => $file, 'fingerprint' => $fingerprint);
}
