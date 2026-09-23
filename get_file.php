<?php
require_once './include_functions.php';
ksess_verify(1);
ksess_validate($_GET['token']);

if (!isset($_GET['file'])) {
    die;
}
$file_id = filter_numbers($_GET['file']);

$query = $kirjuri_database->prepare('SELECT name, content, size, type, request_id, hash FROM attachments WHERE id = :id');
$query->execute(array(':id' => $file_id));
$file = $query->fetch(PDO::FETCH_ASSOC);
if ($file === false) {
    echo "File not found.";
    die;
}
csrf_case_validate(isset($_GET['ct']) ? $_GET['ct'] : '', $file['request_id']);
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
