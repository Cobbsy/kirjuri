<?php
require_once './include_functions.php';
ksess_verify(1);
ksess_validate(isset($_POST['token']) ? $_POST['token'] : '');
$id = kirjuri_uid_param(isset($_POST['case']) ? $_POST['case'] : '');
csrf_case_validate(isset($_POST['ct']) ? $_POST['ct'] : '', $id);
verify_case_ownership($id);
unset($_SESSION['failed_uploads']);

if ($prefs['settings']['allow_attachments'] !== '1' || empty($_FILES['fileToUpload']['name'])) {
    header('Location: edit_request.php?case='.$id);
    die;
}
$total = count($_FILES['fileToUpload']['name']);
for ($i = 0; $i < $total; ++$i) {
    if ($_FILES['fileToUpload']['error'][$i] !== UPLOAD_ERR_OK) {
        // Covers files over upload_max_filesize / post_max_size and empty file inputs.
        if ($_FILES['fileToUpload']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $_SESSION['failed_uploads'][] = basename($_FILES['fileToUpload']['name'][$i]) . " (upload error " . $_FILES['fileToUpload']['error'][$i] . ")";
            event_log_write($id, 'Error', 'Upload failed (PHP upload error ' . $_FILES['fileToUpload']['error'][$i] . '): '. basename($_FILES['fileToUpload']['name'][$i]));
        }
        continue;
    }
    if ($_FILES['fileToUpload']['size'][$i] > $prefs['settings']['max_attachment_size']) {
        $_SESSION['failed_uploads'][] = $_FILES['fileToUpload']['name'][$i] . "(filesize too big)";
        event_log_write($id, 'Error', 'Upload failed (filesize): '. basename($_FILES['fileToUpload']['name'][$i]));
        continue;
    };
    $file['name'] = basename($_FILES['fileToUpload']['name'][$i]);
    $file['type'] = mime_content_type($_FILES['fileToUpload']['tmp_name'][$i]);
    $file['content'] = file_get_contents($_FILES['fileToUpload']['tmp_name'][$i]); // Size checked above.
    $file['hash'] = hash('sha256', $file['content']);
    if (!kirjuri_attachment_exists($kirjuri_database, $id, $file['hash'])) {
        $file['size'] = strlen($file['content']);
        $stored = kirjuri_add_attachment($kirjuri_database, $id, $file['name'], $file['type'], $file['content'], $_SESSION['user']['username']);
        unset($file['content']);
        $compression_ratio = ($file['size'] > 0) ? (100 - (($stored['stored_size'] / $file['size']) * 100)) : 0;
        $_POST['content'] = "File data, " . $file['size'] . " bytes, compressed to " . $stored['stored_size'] . " bytes. (Reduction of " . round($compression_ratio, 2) . "%). sha256: " . $file['hash'];
        $audit_stamp = audit_log_write($_POST);
        kirjuri_set_attachment_audit_stamp($kirjuri_database, $stored['id'], $audit_stamp);
        event_log_write($id, 'Add', 'Attachment uploaded: '. $file['name'] . ", file sha256: " . $file['hash'], $audit_stamp);
    } else {
        $_SESSION['failed_uploads'][] = $file['name'] . " (file already exists)";
        event_log_write($id, 'Error', 'Upload failed (file exists): '. $file['name']);
    }
}
header('Location: edit_request.php?case='.$id);
die;
