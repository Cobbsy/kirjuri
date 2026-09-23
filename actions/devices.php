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
    ksess_validate($_GET['token']);
    csrf_case_validate($_GET['ct'], $_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    $audit_stamp = audit_log_write($_GET);
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET is_removed = "1", last_updated = NOW() where id=:id AND parent_id = :returnid;
        UPDATE exam_requests SET is_removed = "1" where device_host_id=:id');
    $query->execute(array(
            ':id' => $_GET['uid'],
            ':returnid' => $_GET['returnid']
        ));
    $query = $kirjuri_database->prepare('SELECT count(id) from exam_requests where id != parent_id AND parent_id=:id AND is_removed="0"');
    $query->execute(array(
            ':id' => $_GET['returnid']
        ));
    $devicecount = $query->fetch(PDO::FETCH_ASSOC);
    $devicecount = $devicecount['count(id)'];
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET case_devicecount = :devicecount, last_updated = NOW() where id=:id');
    $query->execute(array(
            ':devicecount' => $devicecount,
            ':id' => $_GET['returnid']
        ));
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
    if (isset($_POST['isanta'])) {
        $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_host_id = :isanta, last_updated = NOW() where id=:id AND parent_id != id;
        UPDATE exam_requests SET device_is_host = "1" where id = :isanta;');
        $query->execute(array(
                ':id' => $_GET['uid'],
                ':isanta' => $_POST['isanta']
            ));
    }
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['device_attached']);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'device_detach':
    // Remove device association
    ksess_verify(1);
    ksess_validate($_GET['token']);
    $_GET['returnid'] = filter_numbers($_GET['returnid']);
    verify_case_ownership($_GET['returnid']);
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_host_id = "0", last_updated = NOW() where id=:id AND parent_id != id');
    $query->execute(array(
            ':id' => $_GET['uid']
        ));
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
    if ($_POST['device_action'] != 'NO_CHANGE') {
        $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_action = :device_action, last_updated = NOW() WHERE parent_id=:parent_id');
        $query->execute(array(
                ':parent_id' => $_GET['returnid'],
                ':device_action' => $_POST['device_action']
            ));
    }
    if ($_POST['device_location'] != 'NO_CHANGE') {
        $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_location = :device_location, last_updated = NOW() WHERE parent_id=:parent_id AND is_removed != "1"');
        $query->execute(array(
                ':parent_id' => $_GET['returnid'],
                ':device_location' => $_POST['device_location']
            ));
    }
    $_POST['returnid'] = $_GET['returnid'];
    $_SESSION['post_cache'] = '';
    if ($_POST['device_action'] === 'NO_CHANGE' && $_POST['device_location'] === 'NO_CHANGE') {
        // Do nothing
    }
    else {
    }
    event_log_write($_GET['returnid'], 'Update', 'Set all devices in case UID' . $_GET['returnid'] . ". " , $audit_stamp);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=devices');
    die;

case 'change_device_status':
    // Dynamically set device action
    ksess_verify(1);
    $query = $kirjuri_database->prepare('SELECT parent_id FROM exam_requests where id=:id');
    $query->execute(array(
            ':id' => $_GET['uid']
        ));
    $case_id = $query->fetch(PDO::FETCH_ASSOC);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $case_id['parent_id']);
    verify_case_ownership($case_id['parent_id']);
    $audit_stamp = audit_log_write($_POST);
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_action = :device_action, last_updated = NOW() where id=:id AND parent_id != id');
    $query->execute(array(
            ':device_action' => $_POST['device_action'],
            ':id' => $_GET['uid']
        ));
    $_SESSION['post_cache'] = '';
    event_log_write($case_id['parent_id'], "Update", "Changed device UID".$_GET['uid']." status to " .$_POST['device_action']. ". " , $audit_stamp);
    echo $twig->render('progress_bar.twig', array(
            'device_action' => $_POST['device_action'],
            'settings' => $prefs['settings']
        ));
    die;

case 'change_device_location':
    // Dynamically set device location.
    ksess_verify(1);
    $query = $kirjuri_database->prepare('SELECT parent_id FROM exam_requests where id=:id');
    $query->execute(array(
            ':id' => $_GET['uid']
        ));
    $case_id = $query->fetch(PDO::FETCH_ASSOC);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $case_id['parent_id']);
    verify_case_ownership($case_id['parent_id']);
    $audit_stamp = audit_log_write($_POST);
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET device_location = :device_location, last_updated = NOW() where id=:id AND parent_id != id');
    $query->execute(array(
            ':device_location' => $_POST['device_location'],
            ':id' => $_GET['uid']
        ));
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
    if (isset($_POST['new_parent_id']) && ($id !== $_POST['new_parent_id'])) {
        $id = filter_numbers($_POST['new_parent_id']);
    }

    if (!empty($_POST['used_tool'])) {
        $_POST['examiners_notes'] = filter_html($_POST['examiners_notes']) . '<p>' . $_POST['used_tool'] . '</p>';
    }
    $query = $kirjuri_database->prepare('UPDATE exam_requests SET report_notes = :report_notes, examiners_notes = :examiners_notes, device_type = :device_type, device_manuf = :device_manuf, device_model = :device_model, device_size_in_gb = :device_size_in_gb,
      device_owner = :device_owner, device_os = :device_os, device_time_deviation = :device_time_deviation, last_updated = NOW(),
      case_request_description = :case_request_description, device_item_number = :device_item_number, device_document = :device_document, device_identifier = :device_identifier,
      device_contains_evidence = :device_contains_evidence, device_include_in_report = :device_include_in_report WHERE id = :id AND parent_id != id;
        UPDATE exam_requests SET last_updated = NOW() where id = :parent_id;
        UPDATE exam_requests SET parent_id = :parent_id WHERE id = :id OR device_host_id = :id;');
    $query->execute(array(
            ':report_notes' => filter_html($_POST['report_notes']),
            ':examiners_notes' => filter_html($_POST['examiners_notes']),
            ':device_type' => $_POST['device_type'],
            ':device_manuf' => $_POST['device_manuf'],
            ':device_model' => $_POST['device_model'],
            ':device_size_in_gb' => $_POST['device_size_in_gb'],
            ':device_owner' => $_POST['device_owner'],
            ':device_os' => $_POST['device_os'],
            ':device_time_deviation' => $_POST['device_time_deviation'],
            ':case_request_description' => $_POST['case_request_description'],
            ':device_item_number' => $_POST['device_item_number'],
            ':device_document' => $_POST['device_document'],
            ':device_identifier' => $_POST['device_identifier'],
            ':parent_id' => $id,
            ':id' => $_POST['id'],
            ':device_include_in_report' => $_POST['device_include_in_report'],
            ':device_contains_evidence' => $_POST['device_contains_evidence']
        ));
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

    $query = $kirjuri_database->prepare('UPDATE exam_requests SET case_devicecount = case_devicecount + 1, last_updated = NOW() WHERE id = :parent_id'); // Update device count
    $query->execute(array(
            ':parent_id' => $_POST['parent_id']
        ));

    $query = $kirjuri_database->prepare('INSERT INTO exam_requests (parent_id, device_host_id, device_type, device_manuf, device_model, device_identifier, device_location, device_item_number, device_document, device_time_deviation, device_os, device_size_in_gb, device_is_host, device_owner, device_include_in_report, device_contains_evidence, case_added_date, case_request_description, device_action, is_removed, last_updated, examiners_notes ) VALUES (:parent_id, :device_host_id, :device_type, :device_manuf, :device_model, :device_identifier, :device_location, :device_item_number, :device_document, :device_time_deviation, :device_os, :device_size_in_gb, :device_is_host, :device_owner, "1", "0", NOW(), :case_request_description, :device_action, :is_removed, NOW(), :examiners_notes);
        ');
    $query->execute(array(
            ':parent_id' => $id,
            ':device_host_id' => $_POST['device_host_id'],
            ':device_type' => $_POST['device_type'],
            ':device_manuf' => $_POST['device_manuf'],
            ':device_model' => $_POST['device_model'],
            ':device_identifier' => $_POST['device_identifier'],
            ':device_location' => $_POST['device_location'],
            ':device_item_number' => $_POST['device_item_number'],
            ':device_document' => $_POST['device_document'],
            ':device_time_deviation' => $_POST['device_time_deviation'],
            ':device_os' => $_POST['device_os'],
            ':device_size_in_gb' => $_POST['device_size_in_gb'],
            ':device_is_host' => $device_is_host,
            ':device_owner' => $_POST['device_owner'],
            ':case_request_description' => $_POST['case_request_description'],
            ':device_action' => $_POST['device_action'],
            ':is_removed' => $_POST['is_removed'],
            ':examiners_notes' => filter_html($_POST['examiners_notes'])
        ));
    $query = $kirjuri_database->prepare('SELECT LAST_INSERT_ID() as id'); // Update device count
    $query->execute();
    $new_uid = $query->fetch(PDO::FETCH_ASSOC);
    $audit_stamp = audit_log_write($_POST);
    event_log_write($id, 'Add', 'Added device UID' . $new_uid['id'] . ": ". $_POST['device_type'] . ' ' . $_POST['device_manuf'] . ' ' . $_POST['device_model'] . ' ' . $_POST['device_identifier'] . ' to case ' . $id . '. ' , $audit_stamp);
    $_SESSION['post_cache'] = '';
    $_SESSION['message']['type'] = 'info';
    $_SESSION['message']['content'] = 'Changes saved.';
    $_SESSION['message_set'] = true;
    header('Location: edit_request.php?case=' . $_POST['parent_id'] . '&tab=devices');
    die;
}
