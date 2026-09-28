<?php
require_once __DIR__ . '/lib/errors.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/messages.php';
kirjuri_register_error_handlers();
session_name('KirjuriSessionID');
session_start(); // Keep valid session alive.
if (!isset($_SESSION['user']['username'], $_SESSION['user']['token']) || !file_exists('cache/user_' . $_SESSION['user']['username'] . "/session_" . $_SESSION['user']['token'] . ".txt" )) { // Drop session if sessionfile has been removed.
    $_SESSION = array();
    session_destroy();
    echo '<i style="color:red;" class="fa fa-ban"></i><script>window.location.href = "login.php";</script>';
    die;
}
// Update session file timestamp
touch('cache/user_' . $_SESSION['user']['username'] . "/session_" . $_SESSION['user']['token'] . ".txt");

$mysql_config = kirjuri_mysql_config();
if ($mysql_config === null) {
    header('Location: install.php'); // If file not found, assume install.php needs to be run.
    die;
}

try { // Check inbox
    $kirjuri_database = connect_database('kirjuri-database');
} catch (PDOException $e) {
    // This fragment is polled in the background; log the problem rather than showing it in the menu.
    kirjuri_log_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), kirjuri_trace_lines($e));
    die;
}
$_SESSION['unread'] = array('new' => (string) kirjuri_unread_count($kirjuri_database, $_SESSION['user']['username']));
if ($_SESSION['unread']['new'] > 0) {
    echo '<span style="color:white;" class="label label-danger">' . $_SESSION['unread']['new'] . '</span>';
}
