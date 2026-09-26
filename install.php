<?php
// Clear any lingering sessions.
session_name('KirjuriSessionID');
session_start();
session_destroy();
if (version_compare(PHP_VERSION, '8.1.0') < 0) {
    echo "Kirjuri requires PHP 8.1 or newer to run. You are using " . phpversion() . ". Please upgrade your PHP environment.";
    die;
}
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/migrations.php';
require_once __DIR__ . '/lib/install.php';
?>
<html>
<head>
  <link href="vendor/twbs/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
  <body>
    <div class="container-fluid">
      <div class="main">
    <h3>Kirjuri installer</h3>
<?php
if (file_exists("conf/mysql_credentials.php")) { echo "Installer has already been run on this instance. Please remove the file conf/mysql_credentials.php to run the installer again.";
    die;
}

function error_handler($n, $s, $f) // Custom error handler for the installation script.
{
    global $i;
    echo '<p style="color:red;">Installation error: ('.$n.') '.$s.'</p>';
    $i = 0;
}


set_error_handler('error_handler');

if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $process_user = posix_getpwuid(posix_geteuid());
    echo '<p>Web server running as "'.htmlspecialchars($process_user['name']).'"</p>';
}
echo '<p>Testing write permissions...</p>';

$i = 0; // Count folders
$test_folders = array(
    'conf',
    'cache',
    'logs',
);
foreach ($test_folders as $folder) {
    $result = '';
    file_put_contents($folder.'/test.txt', 'test');
    $result = file_get_contents($folder.'/test.txt');
    if ($result === 'test') {
        ++$i;
        echo '<span style="color:green;"> + '.$folder.'/ is writable. </span><br>';
        unlink($folder.'/test.txt');
    }
}

if ($i === count($test_folders)) {
    // See if all folders passed the write test
    echo '<b style="color:green;">   Write test passed!</b><hr>';
} else {
    echo '<b style="color:red;">   Write test failed, please check that the www server process owns the following folders: </b><br>';
    foreach ($test_folders as $folder) {
        echo '    '.$folder.'/<br>';
    }
    die;
}

// Continue the installer if data is present.
if ( (empty($_POST['u'])) ||  (empty($_POST['p'])) || (empty($_POST['d'])) || (empty($_POST['ap'])) ) {
    echo '<pre><form role="form" method="post">
This script will install the necessary databases for Kirjuri to operate,
save your credentials to <i>conf/mysql_credentials.php</i> and prepopulate
the users with "admin" and "anonymous". If you wish to do this manually,
you can get the necessary SQL queries from the source code of this script.

You can rerun this install script at any time to create a new database. This is
useful is you wish to create a test database first and then later create a
production database.

Please choose a name for your database. The default is "kirjuri".

<input type="checkbox" name="drop_database" value="drop"> Drop existing database. <b style="color:red;">THIS WILL DELETE YOUR DATA AND USERS.</b>
<input type="checkbox" name="migrate_old_database" value="migrate"> Migrate tutkinta.jutut database. <b style="color:red;">THIS WILL OVERWRITE YOUR EXISTING DATABASE.</b>

<input name="s" type="text" value="localhost"> MySQL server
<input name="u" type="text"> MySQL username
<input name="p" type="password"> MySQL password
<input name="d" type="text" value="kirjuri"> MySQL database
<input name="ap" type="password"> Create admin password (at least ' . KIRJURI_MIN_PASSWORD_LENGTH . ' characters)

<button type="submit">Install / rebuild databases</button></form></pre>
</div>
</div>
</body>
</html>';
    die;
} else {
    // If form is submitted
    $_POST['drop_database'] = isset($_POST['drop_database']) ? $_POST['drop_database'] : '';
    $_POST['migrate_old_database'] = isset($_POST['migrate_old_database']) ? $_POST['migrate_old_database'] : '';

    $mysql_config['mysql_server'] = $_POST['s'];
    $mysql_config['mysql_username'] = trim(preg_replace('/[^A-Za-z0-9\-]/', '', $_POST['u']));
    $mysql_config['mysql_password'] = $_POST['p'];
    if (strlen($_POST['ap']) < KIRJURI_MIN_PASSWORD_LENGTH) {
        echo '<p style="color:red;">The admin password must be at least ' . KIRJURI_MIN_PASSWORD_LENGTH . ' characters long.</p>';
        die;
    }
    try {
        $mysql_config['mysql_database'] = kirjuri_validate_database_name($_POST['d']);
    } catch (InvalidArgumentException $e) {
        echo '<p style="color:red;">' . htmlspecialchars($e->getMessage()) . '</p>';
        die;
    }

    // Connect to the server for database creation.
    try {
        $server = kirjuri_connect_server($mysql_config);
    } catch (PDOException $e) {
        die('<p style="color:red;">Connection failed: '.htmlspecialchars($e->getMessage()).'</p>');
    }

    // Drop database if wanted
    if ($_POST['drop_database'] === 'drop') {
        try {
            kirjuri_drop_database($server, $mysql_config['mysql_database']);
            echo '<p style="color:green;">Database dropped successfully.</p>';
        } catch (PDOException $e) {
            echo '<p style="color:red;">Error dropping database: '.htmlspecialchars($e->getMessage()).'</p>';
        }
    }

    // Create new database
    try {
        kirjuri_create_database($server, $mysql_config['mysql_database']);
        echo '<p style="color:green;">Database '.$mysql_config['mysql_database'].' is ready.</p>';
    } catch (PDOException $e) {
        die('<p style="color:red;">Error creating database: '.htmlspecialchars($e->getMessage()).'</p>');
    }
    $server = null;

    try {
        $kirjuri_database = kirjuri_open_database($mysql_config);
    } catch (PDOException $e) {
        die('<p style="color:red;">Connection failed: '.htmlspecialchars($e->getMessage()).'</p>');
    }

    // Save credentials to file only once the database is reachable, so a failed install can be retried.
    kirjuri_write_mysql_credentials($mysql_config);

    $report = function ($line) {
        echo '<p style="color:green;">' . htmlspecialchars($line) . '</p>';
    };
    try {
        // The baseline first: the optional legacy import below copies whole rows, so it needs the
        // table as it was before later migrations added columns.
        kirjuri_migrate($kirjuri_database, '001_baseline', $report);

        if ($_POST['migrate_old_database'] === 'migrate') {
            try {
                kirjuri_import_legacy_cases($kirjuri_database);
                echo '<p style="color:green;">Table tutkinta.jutut migrated.</p>';
            } catch (Exception $e) {
                echo '<p style="color:red;">Can not migrate old tables from tutkinta.jutut: ', htmlspecialchars($e->getMessage()), '.</p>';
            }
        }

        kirjuri_migrate($kirjuri_database, null, $report);
        echo '<p style="color:green;">Database schema is up to date.</p>';
    } catch (Exception $e) {
        die('<p style="color:red;">Error creating the database tables: ' . htmlspecialchars($e->getMessage()) . '</p>');
    }

    // The built-in accounts. On a rerun against an existing database they already exist.
    echo '<p style="color:green;">Default users added (' . kirjuri_create_default_users($kirjuri_database, $_POST['ap']) . ' new).</p>';

    echo '<p>Install script done, reload <a href="index.php">index.php</a>. The admininistrator account is "admin", log in with the password you designated.</p></div>
    </div>
    </body>
    </html>';
    die;
}
?>
</pre>
