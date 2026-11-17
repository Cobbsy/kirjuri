<?php
/*
 * Writes audit log entries, one per transaction, for AuditChainTest's test of writers working at the same time.
 * Usage: php audit_writer.php <dsn> <user> <password> <audit folder> <key file> <count> <go file>
 * It waits until <go file> exists, so that all the writers start together.
 */

[, $dsn, $user, $password, $dir, $key_file, $count, $go] = $argv;
$root = dirname(__DIR__, 3);
require $root . '/lib/helpers.php';
require $root . '/lib/audit.php';

$db = new PDO($dsn, $user, $password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
while (!file_exists($go)) {
    usleep(5000);
}
for ($i = 1; $i <= (int) $count; $i++) {
    $db->beginTransaction();
    try {
        kirjuri_audit_write($db, null, 'Update', 'Writer ' . getmypid() . ', entry ' . $i . '.', array('i' => $i), array('username' => 'writer'), $dir, $key_file);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
