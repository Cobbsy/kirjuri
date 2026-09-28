<?php
/* Commands for bin/kirjuri. Each command function takes the argument list and returns an exit code. */

function cli_out($line = '') {
    fwrite(STDOUT, $line . "\n");
}


function cli_err($line) {
    fwrite(STDERR, $line . "\n");
}


function cli_commands() {
    return array(
        'install' => array('cli_install', 'Install without a browser; settings from KIRJURI_DB_HOST, KIRJURI_DB_NAME, KIRJURI_DB_USER, KIRJURI_DB_PASSWORD and KIRJURI_ADMIN_PASSWORD'),
        'doctor' => array('cli_doctor', 'Check the PHP environment, folders, configuration and database'),
        'migrate' => array('cli_migrate', 'Apply pending database migrations (--status to only list them)'),
        'user:list' => array('cli_user_list', 'List user accounts'),
        'user:create' => array('cli_user_create', 'user:create <username> <name> <access 0-3> [--api]; reads the password from stdin'),
        'user:password' => array('cli_user_password', 'user:password <username>; reads the new password from stdin and unlocks the account'),
        'user:unlock' => array('cli_user_unlock', 'user:unlock <username> | --ip <address>; clear the failed login counter of an account or an IP address'),
        'cache:clear' => array('cli_cache_clear', 'Delete compiled templates and other caches (sessions are kept)'),
        'errors' => array('cli_errors', 'errors [--id <request id>] [--last <n>]; show logs/error.log'),
        'log' => array('cli_log', 'log [--last <n>] [--case <uid>]; show the event log'),
        'audit:show' => array('cli_audit_show', 'audit:show <audit file name>; decrypt and print an audit log entry'),
    );
}


function cli_main($argv) {
    $command = isset($argv[1]) ? $argv[1] : 'help';
    $args = array_slice($argv, 2);
    $commands = cli_commands();
    if (!isset($commands[$command])) {
        cli_out('Usage: php bin/kirjuri <command> [arguments]');
        cli_out();
        foreach ($commands as $name => $definition) {
            cli_out(sprintf('  %-14s %s', $name, $definition[1]));
        }
        return ($command === 'help' || $command === '--help') ? 0 : 1;
    }
    try {
        return call_user_func($commands[$command][0], $args);
    } catch (RuntimeException $e) {
        // Expected problems such as a missing user or a short password. Anything else, including
        // database errors (PDOException extends RuntimeException), goes to the exception handler,
        // which logs it to logs/error.log.
        if ($e instanceof PDOException) {
            throw $e;
        }
        cli_err($e->getMessage());
        return 1;
    }
}


function cli_option($args, $name, $default = null) {
    $position = array_search($name, $args, true);
    return ($position !== false && isset($args[$position + 1])) ? $args[$position + 1] : $default;
}


function cli_positional($args) {
    // Arguments that are neither --options nor the values following them.
    $positional = array();
    for ($i = 0; $i < count($args); $i++) {
        if (substr($args[$i], 0, 2) === '--') {
            if (in_array($args[$i], array('--id', '--last', '--case', '--ip'), true)) {
                $i++;
            }
            continue;
        }
        $positional[] = $args[$i];
    }
    return $positional;
}


function cli_database() {
    global $mysql_config, $kirjuri_database;
    $mysql_config = kirjuri_mysql_config();
    if ($mysql_config === null) {
        throw new RuntimeException('Kirjuri is not installed: conf/mysql_credentials.php is missing. Open install.php in a browser first.');
    }
    $kirjuri_database = connect_database('kirjuri-database');
    return $kirjuri_database;
}


function cli_read_password() {
    // Read a password from stdin without echoing it on a terminal.
    $interactive = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($interactive) {
        fwrite(STDOUT, 'Password: ');
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    if (strlen($password) < KIRJURI_MIN_PASSWORD_LENGTH) {
        throw new RuntimeException('The password must be at least ' . KIRJURI_MIN_PASSWORD_LENGTH . ' characters long.');
    }
    return $password;
}


function cli_install($args) {
    // Everything comes from the environment, so passwords stay out of the shell history and process list.
    if (kirjuri_mysql_config() !== null) {
        cli_err('Kirjuri is already installed (conf/mysql_credentials.php exists). Use "migrate" to update the database.');
        return 1;
    }
    $env = function ($name, $default = null) {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if ($default === null) {
                throw new RuntimeException('Set ' . $name . ' in the environment.');
            }
            return $default;
        }
        return $value;
    };
    $config = array(
        'mysql_server' => $env('KIRJURI_DB_HOST', 'localhost'),
        'mysql_username' => $env('KIRJURI_DB_USER'),
        'mysql_password' => $env('KIRJURI_DB_PASSWORD'),
        'mysql_database' => kirjuri_validate_database_name($env('KIRJURI_DB_NAME', 'kirjuri')),
    );
    $admin_password = $env('KIRJURI_ADMIN_PASSWORD');
    if (strlen($admin_password) < KIRJURI_MIN_PASSWORD_LENGTH) {
        throw new RuntimeException('KIRJURI_ADMIN_PASSWORD must be at least ' . KIRJURI_MIN_PASSWORD_LENGTH . ' characters long.');
    }

    if (kirjuri_create_database(kirjuri_connect_server($config), $config['mysql_database'])) {
        cli_out('Created database ' . $config['mysql_database'] . '.');
    }

    global $mysql_config;
    $mysql_config = $config;
    $db = connect_database('kirjuri-database');
    kirjuri_migrate($db, null, 'cli_out');
    kirjuri_write_mysql_credentials($config);
    $ids = array_keys(kirjuri_migrations());
    @file_put_contents('cache/schema_version', end($ids));
    cli_out('Created ' . kirjuri_create_default_users($db, $admin_password) . ' built-in account(s). Log in as "admin".');
    event_log_write('0', 'Admin', 'Installed from the command line.');
    return 0;
}


function cli_doctor($args) {
    $failures = 0;
    $report = function ($status, $message) use (&$failures) {
        $labels = array('ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]');
        if ($status === 'fail') {
            $failures++;
        }
        cli_out($labels[$status] . ' ' . $message);
    };

    $report(version_compare(PHP_VERSION, '8.1.0') >= 0 ? 'ok' : 'fail', 'PHP ' . PHP_VERSION . ' (8.1 or newer required)');
    foreach (array('pdo_mysql', 'mbstring', 'openssl', 'zlib', 'json', 'session') as $extension) {
        $report(extension_loaded($extension) ? 'ok' : 'fail', 'PHP extension ' . $extension);
    }
    $report(file_exists('vendor/autoload.php') ? 'ok' : 'fail', 'Dependencies in vendor/');

    foreach (array('conf', 'logs', 'cache') as $folder) {
        $owner = function_exists('posix_getpwuid') && file_exists($folder) ? posix_getpwuid(fileowner($folder))['name'] : '?';
        $report(is_writable($folder) ? 'ok' : 'fail', $folder . '/ is writable by ' . (function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'this user') . ' (owner: ' . $owner . '). The web server user must be able to write here too.');
        $report(file_exists($folder . '/.htaccess') ? 'ok' : 'warn', $folder . '/.htaccess denies direct web access (Apache only; configure other servers yourself)');
    }

    $settings_file = kirjuri_settings_file();
    $prefs = null;
    if ($settings_file === null) {
        $report('fail', 'No settings file (conf/settings.conf is missing)');
    } else {
        try {
            $prefs = kirjuri_load_settings($settings_file);
            $report('ok', 'Settings read from ' . $settings_file);
        } catch (RuntimeException $e) {
            $report('fail', $e->getMessage());
        }
    }
    if ($prefs !== null) {
        try {
            kirjuri_load_language($prefs);
            $report('ok', 'Language file ' . kirjuri_language_file($prefs));
        } catch (RuntimeException $e) {
            $report('fail', $e->getMessage());
        }
        $timezone = isset($prefs['settings']['timezone']) ? $prefs['settings']['timezone'] : '';
        $report(in_array($timezone, timezone_identifiers_list(), true) ? 'ok' : 'fail', 'Time zone "' . $timezone . '"');
        if (isset($prefs['settings']['show_errors']) && $prefs['settings']['show_errors'] === '1') {
            $report('warn', 'show_errors is on: PHP warnings are shown to users. Turn it off in production.');
        }
        if (isset($prefs['settings']['enable_ldap_authentication']) && $prefs['settings']['enable_ldap_authentication'] === '1') {
            $report(extension_loaded('ldap') ? 'ok' : 'fail', 'LDAP authentication is enabled; PHP extension ldap');
        }
        $mysqldump = isset($prefs['settings']['mysqldump_location']) ? $prefs['settings']['mysqldump_location'] : '';
        $report(is_executable($mysqldump) ? 'ok' : 'warn', 'mysqldump at "' . $mysqldump . '" for database backups from the settings page');
    }
    $report(file_exists('conf/audit_credentials.php') ? 'ok' : 'warn', 'Audit log key conf/audit_credentials.php (created on the first audited change)');

    if (kirjuri_mysql_config() === null) {
        $report('fail', 'conf/mysql_credentials.php is missing. Run install.php.');
    } else {
        try {
            $db = cli_database();
            $version = $db->query('SELECT VERSION()')->fetchColumn();
            $report('ok', 'Connected to the database (server ' . $version . ')');
            $pending = kirjuri_pending_migrations($db);
            $report(empty($pending) ? 'ok' : 'warn', empty($pending) ? 'Database schema is up to date' : 'Pending migrations: ' . implode(', ', array_keys($pending)) . '. Run "php bin/kirjuri migrate".');
            $admins = (int) $db->query("SELECT COUNT(*) FROM users WHERE access = 0 AND (flags IS NULL OR flags NOT LIKE '%I%')")->fetchColumn();
            $report($admins > 0 ? 'ok' : 'fail', $admins . ' active admin account(s)');
        } catch (PDOException $e) {
            $report('fail', 'Can not connect to the database: ' . $e->getMessage());
        }
    }

    cli_out();
    cli_out($failures === 0 ? 'No problems found.' : $failures . ' problem(s) found.');
    return $failures === 0 ? 0 : 1;
}


function cli_migrate($args) {
    $db = cli_database();
    if (in_array('--status', $args, true)) {
        $applied = kirjuri_applied_migrations($db);
        foreach (kirjuri_migrations() as $id => $migration) {
            cli_out((in_array($id, $applied, true) ? '[applied] ' : '[pending] ') . $id . ': ' . $migration['description']);
        }
        return 0;
    }
    $applied = kirjuri_migrate($db, null, 'cli_out');
    foreach ($applied as $id) {
        event_log_write('0', 'Admin', 'Applied database migration ' . $id . '.');
    }
    $ids = array_keys(kirjuri_migrations());
    @file_put_contents('cache/schema_version', end($ids));
    cli_out(empty($applied) ? 'Nothing to migrate, the database is up to date.' : count($applied) . ' migration(s) applied.');
    return 0;
}


function cli_find_user($db, $username) {
    $query = $db->prepare('SELECT * FROM users WHERE username = :username');
    $query->execute(array(':username' => filter_username($username)));
    $user = $query->fetch(PDO::FETCH_ASSOC);
    if ($user === false) {
        throw new RuntimeException('No user "' . $username . '".');
    }
    return $user;
}


function cli_user_list($args) {
    $db = cli_database();
    $levels = array('0' => 'admin', '1' => 'user', '2' => 'view only', '3' => 'add only');
    cli_out(sprintf('%-4s %-20s %-28s %-10s %s', 'ID', 'Username', 'Name', 'Access', 'Flags'));
    foreach ($db->query('SELECT id, username, name, access, flags FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $level = isset($levels[$user['access']]) ? $levels[$user['access']] : $user['access'];
        cli_out(sprintf('%-4s %-20s %-28s %-10s %s', $user['id'], $user['username'], $user['name'], $level, $user['flags']));
    }
    return 0;
}


function cli_user_create($args) {
    $positional = cli_positional($args);
    if (count($positional) !== 3 || !in_array($positional[2], array('0', '1', '2', '3'), true)) {
        cli_err('Usage: php bin/kirjuri user:create <username> <name> <access 0-3> [--api]');
        cli_err('Access levels: 0 admin, 1 user, 2 view only, 3 add only.');
        return 1;
    }
    list($username, $name, $access) = $positional;
    $username = filter_username($username);
    $db = cli_database();
    if (kirjuri_username_exists($db, $username)) {
        cli_err('User "' . $username . '" already exists. Use user:password to change the password.');
        return 1;
    }
    $password = cli_read_password();
    kirjuri_create_user($db, array(
            'username' => $username,
            'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'access' => $access,
            'flags' => in_array('--api', $args, true) ? 'A' : '',
            'note' => 'User created from the command line at ' . date('Y-m-d H:i'),
        ));
    event_log_write('0', 'Add', 'User created from the command line: ' . $username . ', access level ' . $access);
    cli_out('Created user "' . $username . '".');
    return 0;
}


function cli_user_password($args) {
    $positional = cli_positional($args);
    if (count($positional) !== 1) {
        cli_err('Usage: php bin/kirjuri user:password <username>');
        return 1;
    }
    $db = cli_database();
    $user = cli_find_user($db, $positional[0]);
    if ($user['attr_3'] === 'LDAP_AUTH_ONLY') {
        cli_err('"' . $user['username'] . '" signs in with LDAP; change the password in the directory instead.');
        return 1;
    }
    $password = cli_read_password();
    $query = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
    $query->execute(array(':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']));
    login_throttle_clear($user['username']);
    kirjuri_end_user_sessions($user['username']); // As on the web: whoever knew the old password is logged out.
    event_log_write('0', 'Update', 'Password changed from the command line for user ' . $user['username'] . '.');
    cli_out('Password changed for "' . $user['username'] . '". Their sessions have ended and their API key has changed.');
    return 0;
}


function cli_user_unlock($args) {
    $positional = cli_positional($args);
    $ip = cli_option($args, '--ip');
    if ($ip !== null && count($positional) === 0) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            cli_err('Not an IP address: ' . $ip);
            return 1;
        }
        login_throttle_clear(login_throttle_ip_key($ip));
        event_log_write('0', 'Auth', 'Login throttle cleared from the command line for ' . $ip);
        cli_out('Cleared failed logins from ' . $ip . '.');
        return 0;
    }
    if (count($positional) !== 1 || $ip !== null) {
        cli_err('Usage: php bin/kirjuri user:unlock <username> | --ip <address>');
        return 1;
    }
    login_throttle_clear(filter_username($positional[0]));
    event_log_write('0', 'Auth', 'Login throttle cleared from the command line for ' . filter_username($positional[0]));
    cli_out('Cleared failed logins for "' . filter_username($positional[0]) . '".');
    return 0;
}


function cli_cache_clear($args) {
    $removed = kirjuri_clear_cache();
    cli_out('Removed ' . $removed . ' cache entr' . ($removed === 1 ? 'y' : 'ies') . '.');
    return 0;
}


function cli_errors($args) {
    $file = kirjuri_error_log_path();
    if (!file_exists($file)) {
        cli_out('No errors logged (' . $file . ' does not exist).');
        return 0;
    }
    $id = cli_option($args, '--id');
    $entries = array();
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && ($id === null || $entry['request_id'] === $id)) {
            $entries[] = $entry;
        }
    }
    if ($id === null) {
        $entries = array_slice($entries, -(int) cli_option($args, '--last', '10'));
    }
    if (empty($entries)) {
        cli_out($id === null ? 'No errors logged.' : 'No errors with request ID ' . $id . '.');
        return $id === null ? 0 : 1;
    }
    foreach ($entries as $entry) {
        cli_out(str_repeat('-', 72));
        cli_out($entry['time'] . '  request ' . $entry['request_id'] . '  user ' . ($entry['user'] ?: '-') . '  ' . $entry['method'] . ' ' . $entry['uri']);
        cli_out($entry['type'] . ': ' . $entry['message']);
        cli_out('  at ' . $entry['file'] . ':' . $entry['line']);
        foreach ((array) $entry['trace'] as $frame) {
            cli_out('  ' . $frame);
        }
    }
    return 0;
}


function cli_log($args) {
    $case = cli_option($args, '--case');
    $file = $case === null ? 'logs/kirjuri.log' : 'logs/cases/uid' . filter_numbers($case) . '/events.log';
    if (!file_exists($file)) {
        cli_out('No log at ' . $file . '.');
        return $case === null ? 0 : 1;
    }
    foreach (array_slice(file($file, FILE_IGNORE_NEW_LINES), -(int) cli_option($args, '--last', '20')) as $line) {
        cli_out($line);
    }
    return 0;
}


function cli_audit_show($args) {
    $positional = cli_positional($args);
    if (count($positional) !== 1 || !preg_match('/^(\d{10})_[A-Za-z0-9]+\.log$/', basename($positional[0]), $match)) {
        cli_err('Usage: php bin/kirjuri audit:show <audit file name, e.g. 1790201586_AE66.log>');
        return 1;
    }
    $file = 'logs/audit/' . substr($match[1], 0, 6) . '/' . basename($positional[0]);
    if (!file_exists($file)) {
        cli_err('No audit file ' . $file . '.');
        return 1;
    }
    $data = trim(file_get_contents($file));
    if (substr($data, 0, 1) !== '{') {
        if (!file_exists('conf/audit_credentials.php')) {
            cli_err('The audit file is encrypted and conf/audit_credentials.php is missing.');
            return 1;
        }
        $data = decrypt($data, include 'conf/audit_credentials.php');
    }
    $decoded = json_decode($data, true);
    if (!is_array($decoded)) {
        cli_err('Could not decrypt or parse ' . $file . '.');
        return 1;
    }
    cli_out(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    cli_out('sha256: ' . hash('sha256', $data));
    return 0;
}
