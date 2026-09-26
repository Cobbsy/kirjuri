<?php
// Examination requests: creating, editing, access groups, notes, status and attachments.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'case_access':
    $id = filter_numbers($_GET['id']);
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $id);
    verify_case_ownership($id);
    $audit_stamp = audit_log_write($_POST);
    if ( (isset($_POST['access']['all_users'])) || (!isset($_POST['access']))) {
        $accessgroup = "";
    }
    elseif (isset($_POST['access']['admin_only'])) {
        $accessgroup = "admin";
    }
    else {
        $accessgroup = implode(";", $_POST['access']);
    }
    kirjuri_set_case_access_group($kirjuri_database, $id, $accessgroup);
    event_log_write($id, 'Access', 'Access group updated: ' . str_replace(";", ", ", $accessgroup) . ". " , $audit_stamp);
    header('Location: edit_request.php?case=' . $id . '&tab=access');
    die;

case 'examination_request':
    // Create an examination request.
    ksess_verify(3);
    ksess_validate($_POST['token']);
    if (empty($_POST['case_file_number']) || empty($_POST['case_investigator']) || empty($_POST['case_investigator_unit']) || empty($_POST['case_investigator_tel']) || empty($_POST['case_investigation_lead']) || empty($_POST['case_confiscation_date']) || empty($_POST['case_crime']) || empty($_POST['case_suspect']) || empty($_POST['case_request_description']) || empty($_POST['case_urgency']) || empty($_POST['case_requested_action'])) {
        message('error', 'Fill all required fields.');
        header('Location: add_case.php');
        die;
    }
    $new_case = kirjuri_create_case($kirjuri_database, array(
            'case_name' => $_POST['case_name'],
            'case_file_number' => $_POST['case_file_number'],
            'case_investigator' => $_POST['case_investigator'],
            'case_investigator_unit' => $_POST['case_investigator_unit'],
            'case_investigator_tel' => $_POST['case_investigator_tel'],
            'case_investigation_lead' => $_POST['case_investigation_lead'],
            'case_confiscation_date' => $_POST['case_confiscation_date'],
            'case_crime' => $_POST['case_crime'],
            'classification' => $_POST['classification'],
            'case_suspect' => $_POST['case_suspect'],
            'case_request_description' => $_POST['case_request_description'],
            'case_urgency' => $_POST['case_urgency'],
            'case_urg_justification' => $_POST['case_urg_justification'],
            'case_requested_action' => $_POST['case_requested_action'],
            'case_contains_mob_dev' => $_POST['case_contains_mob_dev'],
            'examiners_notes' => "<b>" . $_SESSION['lang']['passwords'] . "</b>: " . $_POST['examiners_notes'],
        ));
    $case_id = $new_case['case_id'];
    $audit_stamp = audit_log_write($_POST);
    $new_uid = array('id' => $new_case['id']);
    if (!file_exists('logs/cases/')) {
        mkdir('logs/cases');
    }
    if (!file_exists('logs/cases/uid'. $new_uid['id'])) {
        mkdir('logs/cases/uid'. $new_uid['id']);
    }
    $_SESSION['post_cache'] = '';
    event_log_write($new_uid['id'], 'Add', 'Added examination request ' . $case_id . ' / ' . $_POST['case_name'] . ".", $audit_stamp);
    echo kirjuri_render('thankyou.twig', array(
            'case_id' => $case_id,
            'id' => $new_uid['id']
        ));
    die;

case 'case_update':
    // Update examination request.
    ksess_verify(1);
    ksess_validate($_POST['token']);
    $_GET['uid'] = filter_numbers($_GET['uid']);
    verify_case_ownership($_GET['uid']);
    if (!isset($_POST['phone_investigator'])) {
        $_POST['phone_investigator'] = "-";
    }
    $audit_stamp = audit_log_write($_POST);
    if ($_POST['forensic_investigator'] !== '') {
        // Set the case as started if an f.investigator is assigned.

        $case_status = '2';
    }
    else {
        $case_status = '1';
    }
    kirjuri_update_case($kirjuri_database, $_GET['uid'], array(
            'case_name' => $_POST['case_name'],
            'case_file_number' => $_POST['case_file_number'],
            'case_crime' => $_POST['case_crime'],
            'classification' => $_POST['classification'],
            'case_suspect' => $_POST['case_suspect'],
            'case_investigation_lead' => $_POST['case_investigation_lead'],
            'case_investigator' => $_POST['case_investigator'],
            'forensic_investigator' => $_POST['forensic_investigator'],
            'phone_investigator' => $_POST['phone_investigator'],
            'case_investigator_tel' => $_POST['case_investigator_tel'],
            'case_investigator_unit' => $_POST['case_investigator_unit'],
            'case_request_description' => $_POST['case_request_description'],
            'case_confiscation_date' => $_POST['case_confiscation_date'],
            'case_contains_mob_dev' => $_POST['case_contains_mob_dev'],
            'case_status' => $case_status,
            'case_urgency' => $_POST['case_urgency'],
        ));
    event_log_write($_GET['uid'], 'Update', 'Updated request ' . $_POST['case_name'] . ". ", $audit_stamp);
    $_POST['returnid'] = $_GET['uid'];
    $_SESSION['post_cache'] = '';
    show_saved_succesfully();
    header('Location: edit_request.php?case=' . $_POST['returnid']);
    die;

case 'report_notes':
    // Save case report notes.
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $_POST['returnid']);
    verify_case_ownership($_POST['returnid']);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_update_case_notes($kirjuri_database, $_POST['returnid'], 'report_notes', filter_html($_POST['report_notes']));
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['report_notes_saved']);
    $_SESSION['message_set'] = true;
    event_log_write($_POST['returnid'], 'Update', 'Updated report notes. ', $audit_stamp);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=report_notes');
    die;

case 'examiners_notes':
    // Save examiners private notes
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $_POST['returnid']);
    verify_case_ownership($_POST['returnid']);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_update_case_notes($kirjuri_database, $_POST['returnid'], 'examiners_notes', $_POST['examiners_notes']); // Sanitised by submit.php.
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['exam_notes_saved']);
    event_log_write($_POST['returnid'], 'Update', 'Updated examiners notes. ' , $audit_stamp);
    header('Location: edit_request.php?case=' . $_POST['returnid'] . '&tab=examiners_notes');
    die;

case 'set_removed_case':
    // Remove case
    $id = filter_numbers($_POST['remove_exam_request']);
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $id);
    verify_case_ownership($id);
    $audit_stamp = audit_log_write($_GET);
    kirjuri_remove_case($kirjuri_database, $id);
    event_log_write($id, 'Remove', 'Removed case UID' . $id . ". " , $audit_stamp);
    $_SESSION['post_cache'] = '';
    message('info', $_SESSION['lang']['case_removed']);
    header('Location: index.php');
    die;

case 'update_request_status':
    // Set case status
    $id = filter_numbers($_POST['returnid']);
    ksess_verify(1);
    ksess_validate($_POST['token']);
    csrf_case_validate($_POST['ct'], $id);
    verify_case_ownership($id);
    $audit_stamp = audit_log_write($_POST);
    kirjuri_set_case_status($kirjuri_database, $id, $_POST['case_status']);
    event_log_write($id, 'Update', 'Changed request ' . $id . ' status: ' . $_POST['case_status'] . '. ' , $audit_stamp);
    $_SESSION['post_cache'] = '';
    show_saved_succesfully();
    header('Location: edit_request.php?case=' . $id);
    die;

case 'remove_attachment':
    ksess_verify(1);
    ksess_validate(posted_token());
    $file = kirjuri_find_attachment($kirjuri_database, filter_numbers($_GET['file']));
    if ($file === null) {
        header('Location: index.php');
        die;
    }
    csrf_case_validate(posted_case_token(), $file['request_id']);
    verify_case_ownership($file['request_id']);
    kirjuri_delete_attachment($kirjuri_database, $file['id']);
    event_log_write($file['request_id'], 'Remove', 'Attachment removed: '. $file['name'] . ", file sha256: " . $file['hash'], $file['attr_1']);
    header('Location: edit_request.php?case=' . $file['request_id']);
    die;
}
