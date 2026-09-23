<?php
require_once './include_functions.php';
ksess_verify(3); // Same access as adding an examination request.
ksess_validate(isset($_POST['token']) ? $_POST['token'] : '');

function verify_keys($key) {
    // Version 0.9.0
    $allowed_keys = array(
        0 => 'id',
        1 => 'parent_id',
        2 => 'case_id',
        3 => 'case_name',
        4 => 'case_suspect',
        5 => 'case_file_number',
        6 => 'case_added_date',
        7 => 'case_confiscation_date',
        8 => 'case_start_date',
        9 => 'case_ready_date',
        10 => 'case_remove_date',
        11 => 'case_devicecount',
        12 => 'case_investigator',
        13 => 'forensic_investigator',
        14 => 'phone_investigator',
        15 => 'case_investigation_lead',
        16 => 'case_investigator_tel',
        17 => 'case_investigator_unit',
        18 => 'case_crime',
        19 => 'copy_location',
        20 => 'is_removed',
        21 => 'case_status',
        22 => 'case_requested_action',
        23 => 'device_action',
        24 => 'case_contains_mob_dev',
        25 => 'case_urgency',
        26 => 'case_urg_justification',
        27 => 'case_request_description',
        28 => 'examiners_notes',
        29 => 'device_type',
        30 => 'device_manuf',
        31 => 'device_model',
        32 => 'device_os',
        33 => 'device_identifier',
        34 => 'device_location',
        35 => 'device_item_number',
        36 => 'device_document',
        37 => 'device_owner',
        38 => 'device_is_host',
        39 => 'device_host_id',
        40 => 'device_include_in_report',
        41 => 'device_time_deviation',
        42 => 'device_size_in_gb',
        43 => 'device_contains_evidence',
        44 => 'last_updated',
        45 => 'classification',
        46 => 'report_notes',
        47 => 'criminal_act_date_start',
        48 => 'criminal_act_date_end',
        49 => 'case_password',
        50 => 'case_owner',
        51 => 'is_protected',
        53 => 'request_id',
        54 => 'name',
        55 => 'description',
        56 => 'type',
        57 => 'size',
        58 => 'content',
        59 => 'uploader',
        60 => 'date_uploaded',
        61 => 'hash',
        62 => 'attr_1',
        63 => 'attr_2',
        64 => 'attr_3'
    );
    if (in_array($key, $allowed_keys)) {
        return $key;
    } else {
        echo "KEY INTEGRITY CHECK FAILURE: " . htmlspecialchars($key);
        die;
    }
}


if (empty($_FILES['fileToUpload']['tmp_name'][0]) || ($_FILES['fileToUpload']['error'][0] !== UPLOAD_ERR_OK)) {
    trigger_error('No KRF file uploaded.');
    header('Location: add_case.php');
    die;
}
$file['content'] = file_get_contents($_FILES['fileToUpload']['tmp_name'][0]);
$file['content'] = @gzdecode($file['content']);
$case_array = ($file['content'] === false) ? null : json_decode($file['content'], TRUE);

if (!is_array($case_array) || !isset($case_array['parent']) || !is_array($case_array['parent'])) {
    trigger_error('Invalid KRF file.');
    header('Location: index.php');
    die;
}

// Check every key before inserting anything, so a bad file does not leave a partially imported case.
foreach (array_keys($case_array['parent']) as $key) {
    verify_keys($key);
}
foreach (array('children', 'files') as $section) {
    if (!empty($case_array[$section])) {
        foreach ($case_array[$section] as $row) {
            foreach (array_keys((array) $row) as $key) {
                verify_keys($key);
            }
        }
    }
}

$input = $case_array['parent'];
foreach (array('id', 'parent_id', 'case_id', 'is_removed', 'case_added_date', 'case_start_date', 'case_devicecount', 'last_updated') as $key) {
    unset($input[$key]); // Set for the new case below.
}
$columns = array('case_start_date' => null);
foreach ($input as $key => $value) {
    $columns[verify_keys($key)] = $value;
}
$new_case = kirjuri_create_case($kirjuri_database, $columns);
$new_parent = kirjuri_find_case($kirjuri_database, $new_case['id']);

// Devices, then the attached media pointed at their hosts' new UIDs. All keys were validated above.
$new_ids = array();
if (!empty($case_array['children'])) {
    foreach ($case_array['children'] as $input) {
        $new_ids[$input['id']] = kirjuri_import_device($kirjuri_database, $new_parent['id'], $input);
    }
    kirjuri_remap_device_hosts($kirjuri_database, $new_parent['id'], $new_ids);
}

if (!empty($case_array['files'])) {
    foreach ($case_array['files'] as $file) {
        $file['content'] = base64_decode(isset($file['content']) ? $file['content'] : '');
        kirjuri_import_attachment($kirjuri_database, $new_parent['id'], $file);
    }
    unset($case_array['files']);
}

kirjuri_update_device_count($kirjuri_database, $new_parent['id']);

if (!file_exists('logs/cases/')) {
    mkdir('logs/cases');
}
if (!file_exists('logs/cases/uid' . $new_parent['id'])) {
    mkdir('logs/cases/uid' . $new_parent['id']);
}

file_put_contents('logs/cases/uid' . $new_parent['id'] . '/events.log', base64_decode(isset($case_array['caselog']) ? $case_array['caselog'] : ''));
file_put_contents('logs/cases/uid' . $new_parent['id'] . '/import.log', json_encode(isset($case_array['metadata']) ? $case_array['metadata'] : array(), JSON_PRETTY_PRINT));

event_log_write($new_parent['id'], "Add", "Imported case from file: " . basename($_FILES['fileToUpload']['name'][0]));
show_saved_succesfully();
header('Location: edit_request.php?case=' . $new_parent['id']);
die;
