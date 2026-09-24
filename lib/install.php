<?php
// Installation steps shared by install.php and `php bin/kirjuri install`.

/** A cleaned database name, or an exception for names Kirjuri must not use. */
function kirjuri_validate_database_name($name) {
    $name = strtolower(trim(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $name)));
    if ($name === '') {
        throw new InvalidArgumentException('Please give a database name.');
    }
    if (in_array($name, array('mysql', 'information_schema', 'performance_schema', 'sys', 'users', 'files'), true)) {
        throw new InvalidArgumentException('Reserved database name, please choose something else.');
    }
    return $name;
}


/** Save the database settings to conf/mysql_credentials.php. Only call this once the database is reachable. */
function kirjuri_write_mysql_credentials(array $config) {
    $contents = '<?php return ' . var_export($config, true) . '; ?>' . "\n";
    if (file_put_contents('conf/mysql_credentials.php', $contents, LOCK_EX) === false) {
        throw new RuntimeException('Can not write conf/mysql_credentials.php. Is conf/ writable by this user?');
    }
}


/**
 * Create the built-in accounts: anonymous (1) for the anonymous add-only login and admin (2).
 * Existing accounts are left alone, so rerunning the installer does not reset the admin password.
 * Returns the number of accounts created.
 */
function kirjuri_create_default_users(PDO $db, $admin_password) {
    $query = $db->prepare('INSERT IGNORE INTO users (id, username, password, name, access, flags, attr_1) VALUES
        (1, "anonymous", "Not set.", "Anonymous user", "3", "S", "System account, do not remove."),
        (2, "admin", :admin_password, "Administrator", "0", "S", "Extra attribute columns for future compatibility")');
    $query->execute(array(':admin_password' => password_hash($admin_password, PASSWORD_DEFAULT)));
    return $query->rowCount();
}


/** A connection to the database server itself, for creating and dropping the Kirjuri database. */
function kirjuri_connect_server(array $config) {
    $server = empty($config['mysql_server']) ? 'localhost' : $config['mysql_server'];
    return new PDO('mysql:host=' . $server, $config['mysql_username'], $config['mysql_password'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}


/** Create the database unless it exists. $name must come from kirjuri_validate_database_name(). Returns whether it was created. */
function kirjuri_create_database(PDO $server, $name) {
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
    $exists->execute(array(':name' => $name));
    if ((int) $exists->fetchColumn() > 0) {
        return false;
    }
    $server->exec('CREATE DATABASE `' . $name . '`');
    return true;
}


/** $name must come from kirjuri_validate_database_name(). */
function kirjuri_drop_database(PDO $server, $name) {
    $server->exec('DROP DATABASE `' . $name . '`');
}


/** Copy the cases of the Kirjuri predecessor from tutkinta.jutut. Needs the 001_baseline table layout. */
function kirjuri_import_legacy_cases(PDO $db) {
    $db->exec('INSERT INTO exam_requests SELECT * FROM tutkinta.jutut');
}
