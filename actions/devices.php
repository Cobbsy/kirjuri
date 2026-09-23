<?php
// Devices and media within a case.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'set_removed':
    // Remove device from case
    ksess_verify(1);
    ksess_validate(posted_token());
    csrf_case_validate(posted_case_token(), $_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    $audit_stamp = audit_log_write($_GET);
    kirjuri_remove_device($kirjuri_database, $_GET['returnid'], $_GET['uid']); // Attached media go with it.
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    event_log_write($_GET['returnid'], 'Remove', 'Removed device UID' . $_GET['uid'] . ". " , $audit_stamp);
    message('info', $_SESSION['lang']['device_removed']);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'device_attach':
    // Associate a media/device with host device
    ksess_verify(1);
    ksess_validate($_POST['token']);
    $_GET['returnid'] = filter_numbers($_GET['returnid']);
    csrf_case_validate($_POST['ct'], $_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    if (isset($_POST['isanta']) && !kirjuri_attach_device($kirjuri_database, $_GET['returnid'], filter_numbers($_GET['uid']), filter_numbers($_POST['isanta']))) {
        // Both devices must belong to the case the user has access to.
        message('error', $_SESSION['lang']['missing_form_field']);
        header('Location: edit_request.php?case=' . $_GET['returnid'] . '&tab=devices');
        die;
    }
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['device_attached']);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'device_detach':
    // Remove device association
    ksess_verify(1);
    ksess_validate(posted_token());
    $_GET['returnid'] = filter_numbers($_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    kirjuri_detach_device($kirjuri_database, $_GET['returnid'], filter_numbers($_GET['uid'])); // Only devices of this case.
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['device_detached']);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'move_all':
    // Change all device locations and/or actions
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_update_all_devices($kirjuri_database, $_GET['returnid'],
        ($_POST['device_action'] != 'NO_CHANGE') ? $_POST['device_action'] : null,
        ($_POST['device_location'] != 'NO_CHANGE') ? $_POST['device_location'] : null);
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    event_log_write($_GET['returnid'], 'Update', 'Set all devices in case UID' . $_GET['returnid'] . ". " , $audit_stamp);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'change_device_status':
    // Dynamically set device action
    ksess_verify(1);
    $case_id = array('parent_id' => kirjuri_case_of($kirjuri_database, $_GET['uid']));
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $case_id['parent_id']);
    verify_case_ownership($case_id['parent_id']);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_update_device_field($kirjuri_database, $case_id['parent_id'], $_GET['uid'], 'device_action', $_POST['device_action']);
    $_SESSION['post_cache'] = '';
    event_log_write($case_id['parent_id'], "Update", "Changed device UID".$_GET['uid']." status to " .$_POST['device_action']. ". " , $audit_stamp);
    echo kirjuri_render('progress_bar.twig', array(
            'device_action' => $_POST['device_action']
        ));
    die;

case 'change_device_location':
    // Dynamically set device location.
    ksess_verify(1);
    $case_id = array('parent_id' => kirjuri_case_of($kirjuri_database, $_GET['uid']));
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $case_id['parent_id']);
    verify_case_ownership($case_id['parent_id']);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_update_device_field($kirjuri_database, $case_id['parent_id'], $_GET['uid'], 'device_location', $_POST['device_location']);
    $_SESSION['post_cache'] = '';
    event_log_write($case_id['parent_id'], "Update", "Changed device UID".$_GET['uid']." location to " .$_POST['device_location']. ". " , $audit_stamp);
    die;

case 'devicememo':
    // Update individual device details.
    $id = filter_numbers($_POST['parent_id']);
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $id);
    verify_case_ownership($id);
    $audit_stamp = audit_log_write($_POST);
    if (trim(strtolower(strip_tags($_POST['report_notes']))) === trim(strtolower(strip_tags($_POST['template_report_notes'])))) {
        $_POST['report_notes'] = "";
    }
    $original_case = $id;
    if (kirjuri_case_device($kirjuri_database, $original_case, $_POST['id'], true) === null) {
        // The device must belong to the case the access checks above were made for.
        event_log_write($original_case, 'Error', 'Device memo for UID' . filter_numbers($_POST['id']) . ', which is not in this case.');
        message('error', $_SESSION['lang']['not_in_access_group']);
        header('Location: index.php');
        die;
    }
    if (isset($_POST['new_parent_id']) && ($id !== $_POST['new_parent_id'])) {
        // Moving the device to another case: the target must be a case the user has access to.
        $id = filter_numbers($_POST['new_parent_id']);
        if (kirjuri_case_of($kirjuri_database, $id) !== $id) {
            message('error', $_SESSION['lang']['missing_form_field']);
            header('Location: device_memo.php?uid=' . filter_numbers($_POST['id']));
            die;
        }
        verify_case_ownership($id);
    }

    if (!empty($_POST['used_tool'])) {
        $_POST['examiners_notes'] = filter_html($_POST['examiners_notes']) . '<p>' . $_POST['used_tool'] . '</p>';
    }
    kirjuri_update_device_memo($kirjuri_database, $original_case, $_POST['id'], array(
            'report_notes' => filter_html($_POST['report_notes']),
            'examiners_notes' => filter_html($_POST['examiners_notes']),
            'device_type' => $_POST['device_type'],
            'device_manuf' => $_POST['device_manuf'],
            'device_model' => $_POST['device_model'],
            'device_size_in_gb' => $_POST['device_size_in_gb'],
            'device_owner' => $_POST['device_owner'],
            'device_os' => $_POST['device_os'],
            'device_time_deviation' => $_POST['device_time_deviation'],
            'case_request_description' => $_POST['case_request_description'],
            'device_item_number' => $_POST['device_item_number'],
            'device_document' => $_POST['device_document'],
            'device_identifier' => $_POST['device_identifier'],
            'device_include_in_report' => $_POST['device_include_in_report'],
            'device_contains_evidence' => $_POST['device_contains_evidence'],
        ));
    if ($original_case !== $id) {
        kirjuri_move_device($kirjuri_database, $original_case, $_POST['id'], $id); // With its attached media.
    }
    $_POST['returnid'] = $_GET['returnid'];
    event_log_write($id, 'Update', 'Updated device memo UID' . $_POST['id'] . '. ' , $audit_stamp);
    $_SESSION['post_cache'] = '';
    show_saved_succesfully();
    header('Location: device_memo.php?uid=' . $_POST['returnid']);
    die;

case 'device':
    // Create new device entry
    $id = filter_numbers($_POST['parent_id']);
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $id);
    verify_case_ownership($id);
    if ($_POST['device_host_id'] === '0') {
        // If new device is an associated media, it is not a host by itself
        $device_is_host = '1';
    }
    else {
        $device_is_host = '0';
    }
    if (empty($_POST['device_type'])) {
        $_SESSION['message']['type'] = 'error';
        $_SESSION['message']['content'] = sprintf($_SESSION['lang']['missing_form_field'], $_SESSION['lang']['device_type']);
        $_SESSION['message_set'] = true;
        header('Location: edit_request.php?case=' . $_POST['parent_id'] . '&tab=devices');
        die;
    }
    if (empty($_POST['device_action'])) {
        $_SESSION['message']['type'] = 'error';
        $_SESSION['message']['content'] = sprintf($_SESSION['lang']['missing_form_field'], $_SESSION['lang']['action_status']);
        $_SESSION['message_set'] = true;
        header('Location: edit_request.php?case=' . $_POST['parent_id'] . '&tab=devices');
        die;
    }
    if (empty($_POST['device_location'])) {
        $_SESSION['message']['type'] = 'error';
        $_SESSION['message']['content'] = sprintf($_SESSION['lang']['missing_form_field'], $_SESSION['lang']['device_location']);
        $_SESSION['message_set'] = true;
        header('Location: edit_request.php?case=' . $_POST['parent_id'] . '&tab=devices');
        die;
    }
    $_POST['examiners_notes'] = "";
    if (strpos(strtoupper($_POST['device_identifier']), "IMEI") !== false) {
        $imei_TAC = substr(filter_numbers($_POST['device_identifier']), 0, 8);
        if (strlen($imei_TAC) === 8) {
            if (file_exists('conf/imei.txt')) {
                $imei_list = file('conf/imei.txt');
                foreach ($imei_list as $line) {
                    if (substr($line, 0, 8) === $imei_TAC) {
                        $imei_data = explode("|", $line);
                        if (!isset($imei_data[11])) {
                            continue; // Not a line in the expected IMEI database format.
                        }
                        $_POST['device_manuf'] = $imei_data[10];
                        $_POST['device_model'] = $imei_data[11];
                        $_POST['examiners_notes'] = implode(", ", $imei_data);
                    }
                }
            }
        }
    }

    $new_uid = array('id' => kirjuri_add_device($kirjuri_database, $id, array(
            'device_host_id' => $_POST['device_host_id'],
            'device_type' => $_POST['device_type'],
            'device_manuf' => $_POST['device_manuf'],
            'device_model' => $_POST['device_model'],
            'device_identifier' => $_POST['device_identifier'],
            'device_location' => $_POST['device_location'],
            'device_item_number' => $_POST['device_item_number'],
            'device_document' => $_POST['device_document'],
            'device_time_deviation' => $_POST['device_time_deviation'],
            'device_os' => $_POST['device_os'],
            'device_size_in_gb' => $_POST['device_size_in_gb'],
            'device_is_host' => $device_is_host,
            'device_owner' => $_POST['device_owner'],
            'case_request_description' => $_POST['case_request_description'],
            'device_action' => $_POST['device_action'],
            'is_removed' => $_POST['is_removed'],
            'examiners_notes' => filter_html($_POST['examiners_notes']),
        )));
    $audit_stamp = audit_log_write($_POST);
    event_log_write($id, 'Add', 'Added device UID' . $new_uid['id'] . ": ". $_POST['device_type'] . ' ' . $_POST['device_manuf'] . ' ' . $_POST['device_model'] . ' ' . $_POST['device_identifier'] . ' to case ' . $id . '. ' , $audit_stamp);
    $_SESSION['post_cache'] = '';
    $_SESSION['message']['type'] = 'info';
    $_SESSION['message']['content'] = 'Changes saved.';
    $_SESSION['message_set'] = true;
    header('Location: edit_request.php?case=' . $_POST['parent_id'] . '&tab=devices');
    die;
}
