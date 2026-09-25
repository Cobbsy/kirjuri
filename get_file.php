<?php
require_once './include_functions.php';
// A download changes nothing, so it needs no CSRF token; the access checks below protect it. Tokens in
// the URL would end up in server logs and browser history.
ksess_verify(1);

if (!isset($_GET['file'])) {
    die;
}
$file_id = filter_numbers($_GET['file']);

$file = kirjuri_find_attachment($kirjuri_database, $file_id, true);
if ($file === null) {
    echo "File not found.";
    die;
}
verify_case_ownership($file['request_id']);
event_log_write($file['request_id'], 'File', 'Attachment downloaded: '. $file['name'] . ", sha256: ". $file['hash']);

if (!empty($file['content'])) {
    $content = gzdecode($file['content']);
    $download_name = str_replace(array('"', "\r", "\n"), '', basename($file['name']));
    header('Content-Description: File Transfer');
    header('Content-Type: '.$file['type']);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="'.$download_name.'"; filename*=UTF-8\'\''.rawurlencode($download_name));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($content));
    echo $content;
    die;
} else {
    echo "File not found.";
    die;
}
?>
