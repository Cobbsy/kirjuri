<?php
// Database connection and queries shared across pages.

function connect_database($database) {

    // PDO Database connector
    global $mysql_config;
    global $prefs;
    if (!isset($mysql_config['mysql_server'])) {
        $server = 'localhost';
    } else {
        $server = $mysql_config['mysql_server'];
    }
    if ($database === 'kirjuri-database') {
        try {

            $pdo_connect_string = 'mysql:host='.$server.';dbname='.$mysql_config['mysql_database'];
            $kirjuri_database = new PDO($pdo_connect_string, $mysql_config['mysql_username'], $mysql_config['mysql_password']);
            $kirjuri_database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $kirjuri_database->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            // PHP 8.1 started returning integer columns as ints. Kirjuri compares them as strings
            // (e.g. access === "0"), so keep fetching everything as strings.
            $kirjuri_database->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
            $kirjuri_database->exec('SET NAMES utf8');
            return $kirjuri_database;
        } catch (PDOException $e) {
            session_destroy();
            echo 'Database error: '.$e->getMessage().'. Run <a href="install.php">install</a> to create or upgrade tables and check your credentials.';
            die;
        }
    }
}


function get_users_with_credentials() {
    // Read all users including password hashes. $_SESSION['all_users'] leaves the hashes out.
    global $kirjuri_database;
    $query = $kirjuri_database->prepare('SELECT * FROM users ORDER BY access, username');
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
