<?php
/* Central error handling.
**
** Every request gets an ID, sent back in the X-Request-Id header and written with every
** error. Uncaught exceptions and fatal errors are logged to logs/error.log as one JSON
** object per line (with a stack trace), and the visitor gets a short error page quoting
** the request ID. Look an ID up with `bin/kirjuri errors --id <id>`.
*/

function kirjuri_request_id() {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(6));
    }
    return $id;
}


function kirjuri_error_log_path() {
    // KIRJURI_ERROR_LOG lets tests and tools point the log elsewhere.
    return getenv('KIRJURI_ERROR_LOG') ?: dirname(__DIR__) . '/logs/error.log';
}


function kirjuri_log_error($type, $message, $file = '', $line = 0, $trace = array()) {
    // Append one error to logs/error.log. Never throws: logging must not hide the original error.
    $entry = array(
        'time' => date('c'),
        'request_id' => kirjuri_request_id(),
        'type' => $type,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'user' => isset($_SESSION['user']['username']) ? $_SESSION['user']['username'] : null,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI',
        'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : implode(' ', isset($_SERVER['argv']) ? $_SERVER['argv'] : array()),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        'trace' => $trace,
    );
    $log_file = kirjuri_error_log_path();
    if (is_writable(dirname($log_file)) && (!file_exists($log_file) || is_writable($log_file))) {
        @file_put_contents($log_file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
    } else {
        error_log('Kirjuri [' . kirjuri_request_id() . '] ' . $type . ': ' . $message . ' in ' . $file . ':' . $line);
    }
}


function kirjuri_trace_lines($throwable) {
    $lines = array();
    foreach ($throwable->getTrace() as $i => $frame) {
        $lines[] = '#' . $i . ' ' . (isset($frame['file']) ? $frame['file'] . ':' . $frame['line'] : '[internal]') . ' '
            . (isset($frame['class']) ? $frame['class'] . $frame['type'] : '') . $frame['function'] . '()';
    }
    return $lines;
}


function kirjuri_exception_handler($throwable) {
    $chain = array();
    for ($e = $throwable; $e !== null; $e = $e->getPrevious()) {
        $chain[] = get_class($e) . ': ' . $e->getMessage();
    }
    kirjuri_log_error(get_class($throwable), implode(' <- ', $chain), $throwable->getFile(), $throwable->getLine(), kirjuri_trace_lines($throwable));
    if (function_exists('event_log_write') && isset($_SERVER['REQUEST_URI'])) {
        @event_log_write('0', 'Error', 'Uncaught ' . get_class($throwable) . ' [request ' . kirjuri_request_id() . ']');
    }
    kirjuri_error_page($throwable);
}


function kirjuri_shutdown_handler() {
    // Fatal errors (out of memory, timeouts, parse errors in included files) skip the exception handler.
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        kirjuri_log_error('Fatal error', $error['message'], $error['file'], $error['line']);
        kirjuri_error_page(null);
    }
}


function kirjuri_error_hint($throwable) {
    // A hint for administrators about common causes.
    if ($throwable instanceof PDOException) {
        $code = (string) $throwable->getCode();
        if (in_array($code, array('42S02', '42S22'), true)) {
            return 'The database schema looks out of date. Run "php bin/kirjuri migrate" on the server.';
        }
        if (in_array($code, array('2002', '1045', '1044', '1049', 'HY000'), true)) {
            return 'Kirjuri could not connect to the database. Check conf/mysql_credentials.php and that the database server is running, or run "php bin/kirjuri doctor".';
        }
    }
    return '';
}


function kirjuri_error_page($throwable) {
    // Render a self-contained error page. It uses no templates or session data, as those may be what failed.
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error [' . kirjuri_request_id() . ']: ' . ($throwable ? get_class($throwable) . ': ' . $throwable->getMessage() : 'fatal error, see logs/error.log') . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Request-Id: ' . kirjuri_request_id());
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    global $prefs;
    $show_details = $throwable !== null
        && isset($prefs['settings']['show_errors']) && $prefs['settings']['show_errors'] === '1'
        && isset($_SESSION['user']['access']) && $_SESSION['user']['access'] === '0';
    $hint = $throwable !== null ? kirjuri_error_hint($throwable) : '';
    $h = function ($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    };
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error - Kirjuri</title>'
        . '<link href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body><div class="container" style="margin-top:40px;max-width:760px;">'
        . '<h2>Something went wrong</h2>'
        . '<p>Kirjuri could not complete the request. The error has been logged.</p>'
        . '<p>If you report this, include the reference <code id="request-id">' . $h(kirjuri_request_id()) . '</code>.</p>';
    if ($hint !== '') {
        echo '<div class="alert alert-info">' . $h($hint) . '</div>';
    }
    if ($show_details) {
        echo '<div class="alert alert-warning"><strong>' . $h(get_class($throwable)) . '</strong>: ' . $h($throwable->getMessage())
            . '<br><small>' . $h($throwable->getFile()) . ':' . $h($throwable->getLine()) . '</small></div>';
    }
    echo '<p><a href="index.php">Back to the front page</a></p></div></body></html>';
    exit(1);
}


function kirjuri_register_error_handlers() {
    set_exception_handler('kirjuri_exception_handler');
    register_shutdown_function('kirjuri_shutdown_handler');
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Request-Id: ' . kirjuri_request_id());
    }
}
