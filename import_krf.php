<?php
require_once './include_functions.php';
ksess_verify(3); // Same access as adding an examination request.
ksess_validate(isset($_POST['token']) ? $_POST['token'] : '');

function verify_keys($key, $table) {
    // The columns of Kirjuri 0.9.0 exam_requests and attachments rows. Each row is checked against its
    // own table: a column of the other one would fail the insert after the case had been created.
    $allowed_keys = array(
        'exam_requests' => array('id', 'parent_id', 'case_id', 'case_name', 'case_suspect', 'case_file_number', 'case_added_date',
            'case_confiscation_date', 'case_start_date', 'case_ready_date', 'case_remove_date', 'case_devicecount', 'case_investigator',
            'forensic_investigator', 'phone_investigator', 'case_investigation_lead', 'case_investigator_tel', 'case_investigator_unit',
            'case_crime', 'copy_location', 'is_removed', 'case_status', 'case_requested_action', 'device_action', 'case_contains_mob_dev',
            'case_urgency', 'case_urg_justification', 'case_request_description', 'examiners_notes', 'device_type', 'device_manuf',
            'device_model', 'device_os', 'device_identifier', 'device_location', 'device_item_number', 'device_document', 'device_owner',
            'device_is_host', 'device_host_id', 'device_include_in_report', 'device_time_deviation', 'device_size_in_gb',
            'device_contains_evidence', 'last_updated', 'classification', 'report_notes', 'criminal_act_date_start',
            'criminal_act_date_end', 'case_password', 'case_owner', 'is_protected'),
        'attachments' => array('id', 'request_id', 'name', 'description', 'type', 'size', 'content', 'uploader', 'date_uploaded', 'hash',
            'attr_1', 'attr_2', 'attr_3'),
    );
    if (in_array($key, $allowed_keys[$table], true)) {
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
    verify_keys($key, 'exam_requests');
}
foreach (array('children' => 'exam_requests', 'files' => 'attachments') as $section => $table) {
    if (!empty($case_array[$section])) {
        foreach ($case_array[$section] as $row) {
            foreach (array_keys((array) $row) as $key) {
                verify_keys($key, $table);
            }
        }
    }
}

// Attachments are stored as exported, so their content must match the SHA-256 hash and size the file states.
if (!empty($case_array['files'])) {
    foreach ($case_array['files'] as $row) {
        $content = @gzdecode(base64_decode(isset($row['content']) ? (string) $row['content'] : ''));
        if ($content === false || !isset($row['hash']) || !hash_equals((string) $row['hash'], hash('sha256', $content))
            || !isset($row['size']) || (string) $row['size'] !== (string) strlen($content)) {
            trigger_error('Invalid KRF file: an attachment does not match its hash or size.');
            header('Location: index.php');
            die;
        }
    }
}

$input = $case_array['parent'];
foreach (array('id', 'parent_id', 'case_id', 'is_removed', 'case_added_date', 'case_start_date', 'case_devicecount', 'last_updated') as $key) {
    unset($input[$key]); // Set for the new case below.
}
$columns = array('case_start_date' => null);
foreach ($input as $key => $value) {
    $columns[verify_keys($key, 'exam_requests')] = $value;
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
