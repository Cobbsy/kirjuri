<?php
// Event log, PHP error handler, and the pages' side of the audit log (lib/audit.php writes the entries).

function event_log_write($case_id, $event_level, $description, $audit_log_file = "-") {
    // Logging function.
    if (!isset($_SESSION['user']['token'])) {
        $sessiontoken = "-";
    } else {
        $sessiontoken = $_SESSION['user']['token'];
    }

    if (isset($_SESSION['user']['username'])) {
        $session_username = $_SESSION['user']['username'];
    } elseif (isset($_SESSION['two_factor_pending']['username'])) {
        $session_username = $_SESSION['two_factor_pending']['username']; // Password right, two-factor step not done yet.
    } else {
        $session_username = (PHP_SAPI === 'cli') ? 'cli' : "-";
    }

    $case_id = filter_numbers($case_id);
    $description = str_replace(array("\r", "\n"), ' ', $description); // Keep one event per line.
    $log = date('d/M/Y:H:i:s O').';'.$session_username.';'.$event_level.';"'.$description.'";'.(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'bin/kirjuri').';'.(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli').';'.$audit_log_file.';Session ID: '.$sessiontoken.';';

    if ($case_id === "0") {
        file_put_contents('logs/kirjuri.log', $log."-;\r\n", FILE_APPEND);
        return true;
    }
    else {
        if (!file_exists('logs/cases/')) {
            mkdir('logs/cases');
        }
        if (!file_exists('logs/cases/uid'. $case_id)) {
            mkdir('logs/cases/uid'. $case_id);
        }
        $case_logfile = 'logs/cases/uid' . $case_id . '/events.log';
    }
    file_put_contents($case_logfile, $log."\r\n", FILE_APPEND);
    file_put_contents('logs/kirjuri.log', $log."uid".$case_id.";"."\r\n", FILE_APPEND);
    return true;
}


function kirjuri_error_handler($errno, $errstr, $errfile, $errline) // Trigger an error
{
    global $prefs;
    if (!(error_reporting() & $errno)) {
        return false; // Error suppressed with @ or excluded by error_reporting.
    }
    // trigger_error() notices are how Kirjuri shows messages to users, so only log real PHP problems.
    $is_php_problem = !in_array($errno, array(E_USER_NOTICE), true);
    if ($is_php_problem && function_exists('kirjuri_log_error')) {
        $trace = array();
        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1) as $i => $frame) {
            $trace[] = '#' . $i . ' ' . (isset($frame['file']) ? $frame['file'] . ':' . $frame['line'] : '[internal]') . ' ' . $frame['function'] . '()';
        }
        kirjuri_log_error('PHP error ' . $errno, $errstr, $errfile, $errline, $trace);
    }
    if (isset($prefs['settings']['show_errors']) && $prefs['settings']['show_errors'] === '1') {
        // Show a message if errors are permitted on screen.
        $errnums = array(
            '1' => 'Error',
            '2' => 'Warning',
            '4' => 'Parse error',
            '8' => 'Notice',
            '16' => 'Core error',
            '32' => 'Compile warning',
            '64' => 'Compile error',
            '128' => 'Compile warning',
            '256' => 'User error',
            '512' => 'User warning',
            '1024' => 'User notice',
            '2048' => 'Strict',
            '4096' => 'Recoverable error',
            '8192' => 'Deprecated',
            '16384' => 'User deprecated',
            '32767' => 'All errors'
        );
        $_SESSION['message']['type'] = 'error';
        $_SESSION['message']['content'] = (isset($errnums[$errno]) ? $errnums[$errno] : 'Error').': '.$errstr;
        $_SESSION['message_set'] = true;
    }
    event_log_write('0', 'Error', $errno.' '.$errstr.', File: '.$errfile.', line '.$errline.($is_php_problem && function_exists('kirjuri_request_id') ? ' [request '.kirjuri_request_id().']' : ''));
}


/** Who made the request being audited, and how: the "user" block of an audit log entry. */
function kirjuri_audit_user() {
    // Two-factor sign-in writes entries before the session exists, and bin/kirjuri without a web request at all.
    if (isset($_SESSION['user']['username'])) {
        $username = $_SESSION['user']['username'];
    } elseif (isset($_SESSION['two_factor_pending']['username'])) {
        $username = $_SESSION['two_factor_pending']['username'];
    } else {
        $username = (PHP_SAPI === 'cli') ? 'cli' : '-';
    }
    return array(
        'username' => $username,
        'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'cli',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'bin/kirjuri',
        'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI',
    );
}


/** A case UID for the audit log, or null for "not about a case" (0 or nothing, as the event log has it). */
function kirjuri_audit_case_id($case_id) {
    $case_id = filter_numbers($case_id);
    return ($case_id === '' || (int) $case_id === 0) ? null : (int) $case_id;
}


/**
 * Run $change and write its audit log entry in one database transaction, so that the change is saved with its entry
 * or not at all. $change makes the change and returns array(case UID or null, event level, event log text), or null
 * when it changed nothing; then nothing is written. A fourth element, when the request to record depends on the
 * change (an upload's stored size), replaces $request. $then, if given, gets the entry's file name before the commit.
 * The event log lines are written after the commit. Returns the entry's file name, or null when nothing changed.
 * Throws KirjuriAuditException when the entry can not be written, and passes on whatever $change throws; either way
 * the transaction is rolled back.
 */
function kirjuri_audited(PDO $db, callable $change, array $request, ?callable $then = null) {
    $db->beginTransaction();
    try {
        $event = $change();
        if ($event === null) {
            $db->rollBack();
            return null;
        }
        [$case_id, $level, $description] = $event;
        $entry = kirjuri_audit_write($db, kirjuri_audit_case_id($case_id), (string) $level, (string) $description, $event[3] ?? $request, kirjuri_audit_user());
        if ($then !== null) {
            $then($entry['file']);
        }
        $db->commit();
    } catch (Throwable $e) {
        kirjuri_audit_roll_back($db);
        throw $e;
    }
    kirjuri_audit_log_events($case_id, (string) $level, (string) $description, $entry);
    return $entry['file'];
}


/**
 * Write the audit log entry of a change that is not in the database (the settings file), before making it, or of an
 * event that has already happened (a two-factor code accepted). Throws KirjuriAuditException when it can not be
 * written: the caller must then not go on. Returns the entry's file name.
 */
function kirjuri_audit(PDO $db, $case_id, $level, $description, array $request) {
    if ($db->inTransaction()) {
        throw new LogicException('kirjuri_audit() runs a transaction of its own; inside a transaction, use kirjuri_audited().');
    }
    $db->beginTransaction();
    try {
        $entry = kirjuri_audit_write($db, kirjuri_audit_case_id($case_id), (string) $level, (string) $description, $request, kirjuri_audit_user());
        $db->commit();
    } catch (Throwable $e) {
        kirjuri_audit_roll_back($db);
        throw $e;
    }
    kirjuri_audit_log_events($case_id, (string) $level, (string) $description, $entry);
    return $entry['file'];
}


/** Roll back the transaction, unless it has already ended (a commit that failed can end it). */
function kirjuri_audit_roll_back(PDO $db) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}


/** The event log lines of a saved audit log entry: the entry itself, a chain start or key change, and the event. */
function kirjuri_audit_log_events($case_id, $level, $description, array $entry) {
    if ($entry['started'] !== null) {
        event_log_write('0', 'Audit', 'Audit log chain started; ' . $entry['started'] . ' earlier ' . ($entry['started'] === 1 ? 'entry' : 'entries') . ' fingerprinted.');
    }
    if ($entry['key_replaced'] !== null) {
        event_log_write('0', 'Error', 'The audit log key ' . KIRJURI_AUDIT_KEY_FILE . ' was missing or replaced, so entry ' . $entry['key_replaced']
            . ' records a new key. Entries before it can no longer be read with it.');
    }
    event_log_write('0', 'Audit', 'Audit entry ' . $entry['entry'] . ': ' . $entry['file'] . ', fingerprint ' . $entry['fingerprint'] . '.');
    event_log_write(kirjuri_audit_case_id($case_id) ?? '0', $level, $description, $entry['file']);
}


/**
 * A change was refused because its audit log entry could not be written: log why, tell the user it was not saved,
 * and stop. $json answers the status and location menus, which save in the background.
 */
function kirjuri_audit_refused(KirjuriAuditException $e, $json): never {
    event_log_write('0', 'Error', 'Not saved: the audit log could not be written. ' . $e->getMessage());
    if ($json) {
        kirjuri_send_json(array('saved' => false));
    }
    message('error', $_SESSION['lang']['audit_failed']);
    kirjuri_redirect_back('index.php');
    die;
}
