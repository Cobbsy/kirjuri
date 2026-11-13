<?php
// The event log, newest first, for administrators.
require_once './include_functions.php';
ksess_verify(0);

// The newest lines only: the file grows for as long as the installation lives. bin/kirjuri log reads all of it.
const LOG_PAGE_LINES = 2000;
$lines = file_exists('logs/kirjuri.log') ? file('logs/kirjuri.log', FILE_IGNORE_NEW_LINES) : array();
$lines = array_reverse(array_slice($lines, -LOG_PAGE_LINES));
foreach ($lines as $key => $line) {
    $lines[$key] = str_replace(';', ' ', $line);
}

$_SESSION['message_set'] = false;
echo kirjuri_render('log.twig', array('lines' => $lines));
