<?php
require_once './include_functions.php';
// A download changes nothing, so it needs no CSRF token; the access checks below protect it. Tokens in
// the URL would end up in server logs and browser history.
ksess_verify(1);

function send_attachment($name, $type, $content) {
    $download_name = str_replace(array('"', "\r", "\n"), '', basename($name));
    header('Content-Description: File Transfer');
    header('Content-Type: '.$type);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="'.$download_name.'"; filename*=UTF-8\'\''.rawurlencode($download_name));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($content));
    echo $content;
    die;
}

if (isset($_GET['case'], $_GET['name'])) {
    // A file that an old version stored in attachments/<case UID>/, which the web server must not serve itself.
    $case_id = kirjuri_uid_param($_GET['case']);
    if (kirjuri_find_case($kirjuri_database, $case_id) === null) {
        echo "File not found.";
        die;
    }
    verify_case_ownership($case_id);
    $path = kirjuri_legacy_attachment_path($case_id, $_GET['name']);
    if ($path === null) {
        echo "File not found.";
        die;
    }
    event_log_write($case_id, 'File', 'Attachment downloaded: ' . $path);
    send_attachment(basename($path), 'application/octet-stream', file_get_contents($path));
}

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
    send_attachment($file['name'], $file['type'], gzdecode($file['content']));
} else {
    echo "File not found.";
    die;
}
?>
