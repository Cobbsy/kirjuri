<?php
require_once "./include_functions.php";
ksess_verify(0);

if (!file_exists($prefs['settings']['mysqldump_location'])) {
    trigger_error("Can not run backup, mysqldump binary " . $prefs['settings']['mysqldump_location'] . " not found. Please set the location of the binary in the settings.");
    header('Location: settings.php');
    die;
}

// Pass the password through the environment so it does not show up in the process list, and
// escape every argument as the settings and credentials are user supplied.
$mysqldump_command = escapeshellarg($prefs['settings']['mysqldump_location'])
    . ' -h ' . escapeshellarg(empty($mysql_config['mysql_server']) ? 'localhost' : $mysql_config['mysql_server'])
    . ' -u ' . escapeshellarg($mysql_config['mysql_username'])
    . ' ' . escapeshellarg($mysql_config['mysql_database']);
// The dump goes to a temporary file first: only a dump that completed is sent. Writing both outputs
// to files also means mysqldump can not block on a full pipe.
$dump_file = tempnam(sys_get_temp_dir(), 'kirjuri_backup');
$error_file = tempnam(sys_get_temp_dir(), 'kirjuri_backup');
$mysqldump = proc_open($mysqldump_command, array(1 => array('file', $dump_file, 'w'), 2 => array('file', $error_file, 'w')),
    $pipes, null, array('MYSQL_PWD' => $mysql_config['mysql_password']));
$status = is_resource($mysqldump) ? proc_close($mysqldump) : -1;
$errors = trim((string) file_get_contents($error_file));
unlink($error_file);
if ($status !== 0) {
    unlink($dump_file);
    trigger_error('Backup failed: mysqldump failed with exit status ' . $status . ($errors === '' ? '.' : ': ' . $errors));
    header('Location: settings.php');
    die;
}

header('Content-Description: File Transfer');
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="kirjuri database backup '.date("j-m-y").'.sql"');
header('Content-Length: ' . filesize($dump_file));
event_log_write('0', 'Admin', 'Backed up database.');
readfile($dump_file);
unlink($dump_file);
