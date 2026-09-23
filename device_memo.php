<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher

$device = kirjuri_find_device($kirjuri_database, isset($_GET['uid']) ? filter_numbers($_GET['uid']) : '');
if ($device === null) {
    header('Location: index.php');
    die;
}
$mediarow = array($device);
$casefetch = $device;
verify_case_ownership($casefetch['parent_id']);

$connectedmediarow = kirjuri_attached_media($kirjuri_database, $device['id']);
$host = kirjuri_find_device($kirjuri_database, $device['device_host_id']);
$hostdevice = ($host === null) ? array() : array($host);

if (empty($_SESSION['case_token'][ $casefetch['parent_id'] ])) {
    $_SESSION['case_token'][$casefetch['parent_id']] = generate_token(16); // Initialize case token
}

$case = kirjuri_find_case($kirjuri_database, $casefetch['parent_id'], false);
$caserow = ($case === null) ? array() : array($case);
$case_device_id_list = kirjuri_case_devices($kirjuri_database, $casefetch['parent_id']);
// Cases the device can be moved to. Restricted cases the user may not open are left out.
$allcases = kirjuri_open_cases_for_user($kirjuri_database, $_SESSION['user']);

$imei_data = "";
if ( strpos( strtoupper($mediarow[0]['device_identifier']), "IMEI") !== false) {
    $imei_TAC =  substr(filter_numbers($mediarow[0]['device_identifier']), 0, 8);
    if (strlen($imei_TAC) === 8) {
        if (file_exists('conf/imei.txt')) {
            $imei_list = file('conf/imei.txt');
            foreach ($imei_list as $line) {
                if (substr($line, 0 , 8) === $imei_TAC) {
                    $imei_data = explode("|", $line);
                }
            }
        }
    }
}

if (file_exists('conf/report_notes.local')) {
    $templates['report_notes'] = file_get_contents('conf/report_notes.local');
    $templates['report_notes'] = filter_html($templates['report_notes']);
}
elseif (file_exists('conf/report_notes.template')) {
    $templates['report_notes'] = file_get_contents('conf/report_notes.template');
    $templates['report_notes'] = filter_html($templates['report_notes']);
}
else {
    $templates['report_notes'] = "";
}

$_SESSION['message_set'] = false;
echo kirjuri_render('device_memo.twig', array(
        'ct' => $_SESSION['case_token'][$casefetch['parent_id']],
        'templates' => $templates,
        'imei_data' => $imei_data,
        'device_actions' => $_SESSION['lang']['device_actions'],
        'device_locations' => $_SESSION['lang']['device_locations'],
        'connectedmediarow' => $connectedmediarow,
        'case_device_id_list' => $case_device_id_list,
        'hostdevice' => $hostdevice,
        'mediarow' => $mediarow,
        'allcases' => $allcases,
        'caserow' => $caserow,
        'devices' => $_SESSION['lang']['devices'],
        'media_objs' => $_SESSION['lang']['media_objs']
    ));
