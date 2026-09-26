<?php
require_once './include_functions.php';
ksess_verify(2); // View only or higher
$device_action = kirjuri_find_device($kirjuri_database, isset($_GET['uid']) ? filter_numbers($_GET['uid']) : '', true);
if ($device_action === null) {
    die;
}
verify_case_ownership($device_action['parent_id']);
echo kirjuri_render('progress_bar.twig', array('device_action' => $device_action['device_action']));
?>
