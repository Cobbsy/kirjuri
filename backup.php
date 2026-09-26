<?php
require_once "./include_functions.php";
ksess_verify(0);

if (!file_exists($prefs['settings']['mysqldump_location'])) {
    trigger_error("Can not run backup, mysqldump binary " . $prefs['settings']['mysqldump_location'] . " not found. Please set the location of the binary in the settings.");
    header('Location: settings.php');
    die;
}

header('Content-Description: File Transfer');
header('Content-Encoding: UTF-8');
header('Content-Type: text; charset=utf-8');
header('Content-Disposition: attachment; filename="kirjuri database backup '.date("j-m-y").'.sql"');
// Pass the password through the environment so it does not show up in the process list, and
// escape every argument as the settings and credentials are user supplied.
$mysqldump_command = escapeshellarg($prefs['settings']['mysqldump_location'])
    . ' -h ' . escapeshellarg(empty($mysql_config['mysql_server']) ? 'localhost' : $mysql_config['mysql_server'])
    . ' -u ' . escapeshellarg($mysql_config['mysql_username'])
    . ' ' . escapeshellarg($mysql_config['mysql_database']);
$mysqldump = proc_open($mysqldump_command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array('MYSQL_PWD' => $mysql_config['mysql_password']));
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($mysqldump);
event_log_write('0', 'Admin', 'Backed up database.');
echo $out;
?>
