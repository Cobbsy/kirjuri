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


/**
 * Release the named lock $name (GET_LOCK): now, or, inside a transaction, once the transaction has ended
 * (kirjuri_release_transaction_locks()). Released before the commit, another request could act on what this one has
 * not committed yet, such as the highest case number.
 */
function kirjuri_release_lock(PDO $db, string $name): void {
    if ($db->inTransaction()) {
        kirjuri_transaction_locks($name);
        return;
    }
    $db->prepare('SELECT RELEASE_LOCK(:name)')->execute(array(':name' => $name));
}


/** The named locks waiting for the end of the transaction: adds $name, or, without it, hands them over and forgets them. */
function kirjuri_transaction_locks(?string $name = null): array {
    static $names = array();
    if ($name !== null) {
        $names[] = $name;
        return $names;
    }
    $taken = $names;
    $names = array();
    return $taken;
}


/** Release the named locks taken inside the transaction that has just been committed or rolled back. */
function kirjuri_release_transaction_locks(PDO $db): void {
    foreach (kirjuri_transaction_locks() as $name) {
        $db->prepare('SELECT RELEASE_LOCK(:name)')->execute(array(':name' => $name));
    }
}
