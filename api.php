<?php

require_once './include_functions.php';

function api_case_access($id) {
    // Mirror verify_case_ownership() for API users: admins and access group members only.
    global $kirjuri_database;
    if ($_SESSION['user']['access'] === "0") {
        return true;
    }
    $query = $kirjuri_database->prepare('SELECT case_owner FROM exam_requests WHERE id = (SELECT parent_id FROM exam_requests WHERE id = :id)');
    $query->execute(array(':id' => $id));
    $case_owner = $query->fetch(PDO::FETCH_ASSOC);
    if ($case_owner === false) {
        return true; // Nothing to protect, the query will return nothing.
    }
    $case_owner = explode(";", (string) $case_owner['case_owner']);
    return (empty($case_owner[0]) || in_array($_SESSION['user']['username'], $case_owner, true));
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

if ($key_found === false) {
    return_with_code('403');
} else {
    // Get information about a case or device with UID
    if ($operation === 'get') {
        if (!api_case_access($request_id)) {
            return_with_code('403');
        }
        $query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id = :id');
        $query->execute(array(
                ':id' => $request_id,
            ));
        $output = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // Get information on cases in Kirjuri.
    elseif ($operation === 'find') {
        $search_term = substr(isset($_POST['find']) ? $_POST['find'] : '', 0, 128);
        $query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id = parent_id AND is_removed = "0" AND MATCH (
      case_name,
      case_suspect,
      case_file_number,
      case_investigator,
      forensic_investigator,
      phone_investigator,
      case_investigation_lead,
      case_investigator_unit,
      case_crime,
      case_requested_action,
      case_request_description,
      report_notes,
      examiners_notes)
      AGAINST
      (:search_term IN BOOLEAN MODE) AND case_added_date BETWEEN :dateStart AND :dateStop ORDER BY id');
        $query->execute(array(
                ':search_term' => $search_term,
                ':dateStart' => $dateRange['start'],
                ':dateStop' => $dateRange['stop'],
            ));
        $output['cases'] = $query->fetchAll(PDO::FETCH_ASSOC);
        $query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id != parent_id AND is_removed = "0" AND MATCH (
      report_notes,
      examiners_notes,
      device_manuf,
      device_model,
      device_identifier,
      device_owner)
      AGAINST
      (:search_term IN BOOLEAN MODE) AND case_added_date BETWEEN :dateStart AND :dateStop ORDER BY id');
        $query->execute(array(
                ':search_term' => $search_term,
                ':dateStart' => $dateRange['start'],
                ':dateStop' => $dateRange['stop'],
            ));
        $output['devices'] = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // Update case information fields.
    elseif ($operation === 'info') {
        $query = $kirjuri_database->prepare('SELECT id, case_id, case_name, case_status, forensic_investigator, phone_investigator FROM exam_requests WHERE id = parent_id');
        $query->execute();
        $output['cases'] = $query->fetchAll(PDO::FETCH_ASSOC);
        $query = $kirjuri_database->prepare('SELECT id, device_type, device_manuf, device_model, device_identifier, device_owner, device_action, device_location FROM exam_requests WHERE id != parent_id');
        $query->execute();
        $output['devices'] = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // Update case information fields.
    elseif ($operation === 'update') {
        if (!api_case_access($request_id)) {
            return_with_code('403');
        }
        $build_query = 'UPDATE exam_requests SET last_updated = NOW()';

        // This loop will build an SQL query out of the POST fields submitted.
        foreach ($_POST as $key => $field) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            if (in_array($key, array('', 'id', 'parent_id', 'case_owner'), true)) {
                continue; // Moving items between cases or changing access groups is not allowed via the API.
            }
            // Do not overwrite existing data for report notes or examination notes but append instead.
            if (($key === 'examiners_notes') || ($key === 'report_notes')) {
                $field = '<p>'.$field.'</p>';
                $build_query = $build_query.', '.$key.' = concat(ifnull('.$key.',""), '.$kirjuri_database->quote($field).')';
            } else {
                $build_query = $build_query.', '.$key.' = '.$kirjuri_database->quote($field);
            }
        }

        $build_query = $build_query.' WHERE id = :id';

        try {
            $query = $kirjuri_database->prepare($build_query);
            $query->execute(array(
                    ':id' => $request_id,
                ));
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

// Leave out cases and devices the API user has no access to.
foreach (array('cases', 'devices') as $section) {
    if (isset($output[$section])) {
        $output[$section] = array_values(array_filter($output[$section], function ($row) {
            return api_case_access($row['id']);
        }));
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
