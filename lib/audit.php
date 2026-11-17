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
    $contents = is_file($key_file) ? @file_get_contents($key_file) : false;
    if ($contents === false) {
        return null;
    }
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
