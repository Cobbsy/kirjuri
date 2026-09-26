<?php

require_once './include_functions.php';
require_once __DIR__ . '/lib/api.php';

function api_case_access($id) {
    // The same rule as the pages, for the case that $id (a case or device UID) belongs to.
    global $kirjuri_database;
    return kirjuri_user_can_access_case($_SESSION['user'], kirjuri_case_owner_of($kirjuri_database, $id));
}


function return_with_code($i) {
    if ($i === '403') {
        header('HTTP/1.0 403 Forbidden');
        header('X-Message: API access denied. Key error, API access denied or user account disabled.');
        die;
    } elseif ($i === '500') {
        header('HTTP/1.0 500 Internal server error');
        header('X-Message: API error.');
        die;
    } elseif ($i === '200') {
        header('X-Message: Fields updated.');
        die;
    }
}


// Set which year to read from, default to current year.
if (!empty($_GET['year'])) {
    $year = (int) filter_numbers(substr($_GET['year'], 0, 4));
} else {
    $year = (int) date('Y');
}

// Define date range.

$dateRange = array('start' => $year.'-01-01 00:00:00', 'stop' => ($year + 1).'-01-01 00:00:00');
$key = preg_replace('/[^a-z0-9]/', '', (substr(isset($_GET['key']) ? $_GET['key'] : '', 0, 40)));
$request_id = filter_numbers(substr(isset($_GET['id']) ? $_GET['id'] : '', 0, 9));
$key_found = false;
$output = array();

$operation = substr(filter_letters_and_numbers(isset($_GET['operation']) ? $_GET['operation'] : ''), 0, 6);

if (in_array($operation, array(
            'add',
            'update',
            'get',
            'info',
            'find',
        )) === false) {
    return_with_code('500');
}

foreach (get_users_with_credentials() as $user) {
    if ((strlen($key) === 40) && hash_equals(api_key_for($user), $key) && (strpos((string) $user['flags'], 'A') !== false) && (strpos((string) $user['flags'], 'I') === false)) {
        kirjuri_set_session_user($user);
        $key_found = true;
        break;
    }
}

// The access level each operation needs, as on the pages (0 admin, 1 user, 2 view only, 3 add only).
$required_access = array('add' => 3, 'get' => 2, 'find' => 2, 'info' => 2, 'update' => 1);

if ($key_found === false || (int) $_SESSION['user']['access'] > $required_access[$operation]) {
    return_with_code('403');
} else {
    // Get information about a case or device with UID
    if ($operation === 'get') {
        if (!api_case_access($request_id)) {
            return_with_code('403');
        }
        $output = kirjuri_api_get($kirjuri_database, $request_id);
    }
    // Get information on cases in Kirjuri.
    elseif ($operation === 'find') {
        $search_term = substr(isset($_POST['find']) ? $_POST['find'] : '', 0, 128);
        $output = kirjuri_api_find($kirjuri_database, $search_term, $dateRange);
    }
    // List all cases and devices.
    elseif ($operation === 'info') {
        $output = kirjuri_api_info($kirjuri_database);
    }
    // Update case information fields.
    elseif ($operation === 'update') {
        if (!api_case_access($request_id)) {
            return_with_code('403');
        }
        try {
            kirjuri_api_update_item($kirjuri_database, $request_id, $_POST);
            $case_of_item = kirjuri_case_of($kirjuri_database, $request_id);
            if ($case_of_item !== null) {
                kirjuri_update_device_count($kirjuri_database, $case_of_item); // is_removed may have changed.
            }
            event_log_write('0', 'API', 'Updated UID' . $request_id . ': ' . implode(', ', array_map('filter_letters_and_numbers', array_keys($_POST))) . '.');
        } catch (Exception $e) {
            event_log_write('0', 'Error', 'API update failed: ' . $e->getMessage());
            return_with_code('500');
        }
    }
    // Add a new case to Kirjuri
    elseif ($operation === 'add') {
        foreach (array('case_name', 'case_file_number', 'forensic_investigator', 'phone_investigator', 'case_investigator',
                'case_investigator_unit', 'case_investigator_tel', 'case_investigation_lead', 'case_confiscation_date', 'case_crime',
                'classification', 'case_suspect', 'case_request_description', 'case_urgency', 'case_urg_justification',
                'case_requested_action', 'case_contains_mob_dev') as $field) {
            // Missing or empty fields are stored as NULL, as MySQL strict mode rejects '' for dates and numbers.
            $_POST[$field] = (isset($_POST[$field]) && $_POST[$field] !== '') ? $_POST[$field] : null;
        }
        try {
            $output = kirjuri_create_case($kirjuri_database, array(
                    'case_name' => $_POST['case_name'],
                    'case_file_number' => $_POST['case_file_number'],
                    'forensic_investigator' => $_POST['forensic_investigator'],
                    'phone_investigator' => $_POST['phone_investigator'],
                    'case_investigator' => $_POST['case_investigator'],
                    'case_investigator_unit' => $_POST['case_investigator_unit'],
                    'case_investigator_tel' => $_POST['case_investigator_tel'],
                    'case_investigation_lead' => $_POST['case_investigation_lead'],
                    'case_confiscation_date' => $_POST['case_confiscation_date'],
                    'case_crime' => $_POST['case_crime'],
                    'classification' => $_POST['classification'],
                    'case_suspect' => $_POST['case_suspect'],
                    'case_request_description' => $_POST['case_request_description'],
                    'case_urgency' => ($_POST['case_urgency'] === null) ? null : filter_numbers($_POST['case_urgency']),
                    'case_urg_justification' => $_POST['case_urg_justification'],
                    'case_requested_action' => $_POST['case_requested_action'],
                    'case_contains_mob_dev' => ($_POST['case_contains_mob_dev'] === null) ? null : filter_numbers($_POST['case_contains_mob_dev']),
                ));
            event_log_write('0', 'API', 'Row inserted.');
        } catch (Exception $e) {
            event_log_write('0', 'Error', 'API add failed: ' . $e->getMessage());
            return_with_code('500');
        }
    }
}

// Leave out cases and devices the API user has no access to, looking up every access group at once.
if (isset($output['cases']) || isset($output['devices'])) {
    $owners = kirjuri_case_owners($kirjuri_database);
    foreach (array('cases', 'devices') as $section) {
        if (isset($output[$section])) {
            $output[$section] = array_values(array_filter($output[$section], function ($row) use ($owners) {
                return kirjuri_user_can_access_case($_SESSION['user'], isset($owners[$row['id']]) ? $owners[$row['id']] : null);
            }));
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
if (!empty($output)) {
    echo json_encode($output, JSON_PRETTY_PRINT);
} else {
    // Return empty.
}
echo "\r\n";
die;
