<?php
// Database connection and queries shared across pages.

function connect_database($database) {
    // The Kirjuri database with the credentials from conf/mysql_credentials.php.
    // Connection errors are thrown and handled by lib/errors.php.
    global $mysql_config;
    if ($database === 'kirjuri-database') {
        return kirjuri_open_database($mysql_config);
    }
}


/** A connection to the database named in $config, set up the way the rest of Kirjuri expects. */
function kirjuri_open_database(array $config) {
    $server = empty($config['mysql_server']) ? 'localhost' : $config['mysql_server'];
    // One statement per query: multi-statement mode would let an SQL injection add statements of its own.
    $db = new PDO('mysql:host=' . $server . ';dbname=' . $config['mysql_database'] . ';charset=utf8mb4', $config['mysql_username'], $config['mysql_password'],
        array(PDO::MYSQL_ATTR_MULTI_STATEMENTS => false));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    // PHP 8.1 started returning integer columns as ints. Kirjuri compares them as strings
    // (e.g. access === "0"), so keep fetching everything as strings.
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    // utf8mb4: MySQL's "utf8" has no room for characters outside the Basic Multilingual Plane, such as emoji.
    $db->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    return $db;
}


/** The database server's time zone setting, shown on the settings page. */
function kirjuri_database_timezone(PDO $db) {
    return $db->query('SELECT @@global.time_zone')->fetchColumn();
}


function get_users_with_credentials() {
    // Read all users including password hashes. $_SESSION['all_users'] leaves the hashes out.
    global $kirjuri_database;
    return kirjuri_list_users_with_credentials($kirjuri_database);
}
