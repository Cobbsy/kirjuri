<?php
/* This is the 'header' file in all php files containing shared functions etc.
** It also performs some other tasks.
*/
$mysql_timer_start = microtime(true);

// Log uncaught exceptions and fatal errors with a request ID before anything else can fail.
require_once __DIR__.'/lib/errors.php';
kirjuri_register_error_handlers();

if (version_compare(PHP_VERSION, '8.1.0') < 0) {
    echo "Kirjuri requires PHP 8.1 or newer to run. You are using " . phpversion() . ". Please upgrade your PHP environment.";
    die;
}

// Go to installer if no credentials found.
if (!file_exists('conf/mysql_credentials.php')) {
    header('Location: install.php');
    die;
}

// Load dependencies
require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/logging.php';
require_once __DIR__.'/lib/database.php';
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/session.php';
require_once __DIR__.'/lib/output.php';
$loader = new \Twig\Loader\FilesystemLoader('views/');
$twig = new \Twig\Environment($loader, array(
        'cache' => 'cache',
        'auto_reload' => true,
    ));

$pur_config = HTMLPurifier_Config::createDefault();
$pur_config->set('Cache.SerializerPath', './cache');
$purifier = new HTMLPurifier($pur_config);

session_name('KirjuriSessionID');
session_set_cookie_params(array(
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ));
session_start(); // Start a PHP session

foreach ($_GET as $key => $value) { // Lightly sanitize GET variables
    $strip_chars = array("<", ">", "'", ";");
    $value = is_array($value) ? '' : str_replace($strip_chars, "", $value);
    $_GET[$key] = $value;
}

// Set variables for message display.
$_SESSION['message_set'] = isset($_SESSION['message_set']) ? $_SESSION['message_set'] : '';
// An empty array rather than a string, as PHP 8 throws on string offsets like $_SESSION['user']['token'].
$_SESSION['user'] = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : array();

// If message has been set, do not clear it. Invidial files set message as shown before rendering page.
if ($_SESSION['message_set'] === false) {
    $_SESSION['message']['type'] = '';
    $_SESSION['message']['content'] = '';
}

set_error_handler('kirjuri_error_handler'); // Give errors to the custom error handler.

/* Some things to run on every page load. */

if (file_exists('conf/mysql_credentials.php')) {
    // Read credentials array from a file
    $mysql_config = include 'conf/mysql_credentials.php';
} else {
    session_destroy();
    header('Location: install.php'); // If file not found, assume install.php needs to be run.
    die;
}

if (file_exists('conf/settings.local')) {
    $settings_file = 'conf/settings.local';
} elseif (file_exists('conf/settings.conf')) {
    $settings_file = 'conf/settings.conf'; // Fall back to default settings.
} else {
    echo "Missing settings file at conf/settings.conf. Can not continue.";
    die;
}
$prefs = parse_ini_file($settings_file, true); // Parse settings file
$prefs['settings']['self'] = $_SERVER['PHP_SELF'];
$prefs['settings']['release'] = file_get_contents('conf/RELEASE');

if (file_exists('conf/' . basename($prefs['settings']['lang'], '.conf') . '.JSON')) {
    $_SESSION['lang'] = json_decode(file_get_contents('conf/' . basename($prefs['settings']['lang'], '.conf') . '.JSON'), true); // Parse language file
} else {
    $_SESSION['lang'] = parse_ini_file('conf/' . basename($prefs['settings']['lang'], '.conf') . '.conf', true); // Parse language file
}

if (isset($prefs['settings']['timezone'])) {
    date_default_timezone_set($prefs['settings']['timezone']);
}

// Create the attachments table for installations upgraded from versions without attachments.
$kirjuri_database = connect_database('kirjuri-database');
$query = $kirjuri_database->prepare('CREATE TABLE IF NOT EXISTS attachments (id INT(10) AUTO_INCREMENT PRIMARY KEY,
  request_id INT(10), name VARCHAR(256), description TEXT, type VARCHAR(256), size INT NOT NULL, content MEDIUMBLOB NOT NULL,
  uploader VARCHAR(256), date_uploaded DATETIME, hash VARCHAR(256), attr_1 TEXT, attr_2 TEXT, attr_3 TEXT) ');
$query->execute();

// Read users from database to settings. Password hashes are left out, as the session
// is stored on disk and passed to every template. Use get_users_with_credentials() when they are needed.
$query = $kirjuri_database->prepare('SELECT id, username, name, access, flags, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8 from users ORDER BY access, username;');
$query->execute();
$users = $query->fetchAll(PDO::FETCH_ASSOC);
$_SESSION['all_users'] = $users;

// Read tools from database to settings.
$query = $kirjuri_database->prepare('SELECT * FROM tools ORDER BY product_name;');
$query->execute();
$tools = $query->fetchAll(PDO::FETCH_ASSOC);
$_SESSION['all_tools'] = $tools;

if (!empty($_SESSION['user']['username'])) { // Get unread message count
    $query = $kirjuri_database->prepare('SELECT (SELECT COUNT(id) FROM messages WHERE msgto = :username AND received = "0") AS new');
    $query->execute(array(':username' => $_SESSION['user']['username']));
    $_SESSION['unread'] = $query->fetch(PDO::FETCH_ASSOC);
}

if ( (microtime(true) - $mysql_timer_start) > "2.0") {
    trigger_error("Your MySQL connection is slow. This might be a timeout issue when resolving the localhost hostname to an IP address. Try setting the MySQL server to your server IP from conf/mysql_credentials.php.");
}

/* Really extensive access logging.

if (isset($_SERVER['HTTP_REFERER'])) {
	$url = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
	if ($_SERVER['HTTP_REFERER'] !== $url) {
		event_log_write('0', "Access", $_SERVER['HTTP_REFERER'] . ' -> ' . $url);
	}
}

*/
