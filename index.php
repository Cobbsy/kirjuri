<?php
require_once './include_functions.php';
ksess_verify(3); // View only or higher
if ($_SESSION['user']['access'] === "3") {
    header('Location: add_case.php');
    die;
}

$sort_j = isset($_GET['j']) ? $_GET['j'] : '';
$sort_d = isset($_GET['d']) ? $_GET['d'] : '';
$sort_s = isset($_GET['s']) ? $_GET['s'] : '';
$search_term = '';

if (empty($_GET['year'])) {
    $year = date('Y'); // Use current year if none specified
} else {
    $year = (int) filter_numbers((substr($_GET['year'], 0, 4))); // Get year from GET
}

$dateRange = array('start' => $year.'-01-01 00:00:00', 'stop' => ($year + 1).'-01-01 00:00:00');
$ascending = ($sort_d === 'a');
$order_by = kirjuri_case_list_order($sort_j, $ascending); // The template marks the sorted column.

if (isset($_GET['search']) && (!empty($_GET['search']))) {
    // If a search string is present, handle that. Handled via GET for bookmarking a search.
    // if the search is for UID, jump to that case.
    $search_term = substr($_GET['search'], 0, 128);
    if (substr($search_term, 0, 3) === "UID") {
        $get_uid_result = kirjuri_find_uid($kirjuri_database, filter_numbers(substr($search_term, 3, 11)));
        if ($get_uid_result === null) { // If no or an unknown UID, return to index.
            header('Location: index.php');
            die;
        }
        if ($get_uid_result['id'] === $get_uid_result['parent_id']) { // Jump to case.
            header('Location: edit_request.php?case='.$get_uid_result['parent_id']);
            die;
        }
        header('Location: device_memo.php?uid='.$get_uid_result['id']); // Jump to device.
        die;
    }
    $row_cases = kirjuri_search_cases($kirjuri_database, $search_term, $year, $sort_j, $ascending, $sort_s);
}
else {
    $row_cases = kirjuri_list_cases($kirjuri_database, $year, $sort_j, $ascending, $sort_s);
}
$case_owners = array();
foreach ($row_cases as $key => $case) {
    // The template hides details of cases the user may not open. Search results can include
    // devices, which take the access group of their case.
    if (!array_key_exists($case['parent_id'], $case_owners)) {
        $case_owners[$case['parent_id']] = ($case['id'] === $case['parent_id']) ? $case['case_owner'] : kirjuri_case_owner_of($kirjuri_database, $case['id']);
    }
    $row_cases[$key]['can_access'] = kirjuri_user_can_access_case($_SESSION['user'], $case_owners[$case['parent_id']]);
}

$row_devices = kirjuri_device_actions_for_year($kirjuri_database, $year);
$attachments = kirjuri_cases_with_attachments($kirjuri_database);

$_SESSION['message_set'] = false;

if (file_exists('conf/index_columns.local')) {
    $show_columns = parse_ini_file('conf/index_columns.local', true);
}
elseif (file_exists('conf/index_columns.conf')) {
    $show_columns = parse_ini_file('conf/index_columns.conf', true);
}
else {
    $show_columns = "";
}

echo kirjuri_render('index.twig', array(
        'show_columns' => $show_columns,
        'attachments' => $attachments,
        'search_term' => $search_term,
        'sort_s' => $sort_s,
        'query_d' => $sort_d,
        'query_j' => $sort_j,
        'query_s' => $sort_s,
        'order_by' => $order_by,
        'dateStart' => $dateRange['start'],
        'row_cases' => $row_cases,
        'row_devices' => $row_devices
    ));
