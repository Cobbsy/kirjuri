<?php
// Replace conf/imei.txt, the IMEI TAC list used to fill in new devices' make and model.
require_once './include_functions.php';
ksess_verify(0);
ksess_validate(posted_token());
if (isset($_FILES['fileToUpload']['tmp_name']) && is_string($_FILES['fileToUpload']['tmp_name'])
    && move_uploaded_file($_FILES['fileToUpload']['tmp_name'], 'conf/imei.txt')) {
    event_log_write('0', 'Admin', 'IMEI database replaced.');
    header('Location: settings.php');
    die;
}
message('error', $_SESSION['lang']['missing_form_field']);
header('Location: settings.php');
die;
