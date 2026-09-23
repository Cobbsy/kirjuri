<?php
/* Examination requests (cases) and the devices in them.
**
** A case is a row in exam_requests whose parent_id is its own id; its devices are rows with
** parent_id pointing at the case. Case numbers (case_id) restart from 1 every year.
*/

/**
 * Create a case and return array('id' => UID, 'case_id' => number within the year).
 * $columns maps exam_requests columns to values; the case number, parent_id and the added and
 * updated timestamps are set here. Column names must come from code or be validated first.
 */
function kirjuri_create_case(PDO $db, array $columns) {
    foreach (array('id', 'parent_id', 'case_id', 'case_added_date', 'last_updated') as $managed) {
        unset($columns[$managed]);
    }
    $columns += array('is_removed' => '0', 'case_status' => '1', 'case_devicecount' => '0');
    foreach (array_keys($columns) as $column) {
        if (!preg_match('/^[a-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid column name: ' . $column);
        }
    }

    // Two requests reading the same highest case number would otherwise both use the next one.
    if ((int) $db->query("SELECT GET_LOCK('kirjuri_case_number', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Timed out waiting for the case number lock.');
    }
    try {
        $query = $db->prepare('SELECT MAX(case_id) FROM exam_requests WHERE id = parent_id AND case_added_date BETWEEN :start AND :stop');
        $query->execute(kirjuri_year_range(date('Y')));
        $case_id = (int) $query->fetchColumn() + 1;

        $names = array_keys($columns);
        $placeholders = array_map(function ($column) {
            return ':' . $column;
        }, $names);
        $query = $db->prepare('INSERT INTO exam_requests (parent_id, case_id, case_added_date, last_updated, ' . implode(', ', $names) . ')
            VALUES (0, :new_case_id, NOW(), NOW(), ' . implode(', ', $placeholders) . ')');
        $values = array(':new_case_id' => $case_id);
        foreach ($columns as $column => $value) {
            $values[':' . $column] = $value;
        }
        $query->execute($values);
        $id = $db->lastInsertId();
        $db->prepare('UPDATE exam_requests SET parent_id = id WHERE id = :id')->execute(array(':id' => $id));
    } finally {
        $db->query("SELECT RELEASE_LOCK('kirjuri_case_number')");
    }
    return array('id' => (string) $id, 'case_id' => (string) $case_id);
}


function kirjuri_year_range($year) {
    // Placeholders for "case_added_date BETWEEN :start AND :stop" covering a calendar year.
    return array(':start' => $year . '-01-01 00:00:00', ':stop' => ($year + 1) . '-01-01 00:00:00');
}


/**
 * Recalculate the number of devices in a case. Call this after adding, removing or moving
 * devices; the front page and reports read case_devicecount.
 */
function kirjuri_update_device_count(PDO $db, $case_id) {
    $query = $db->prepare('SELECT COUNT(*) FROM exam_requests WHERE parent_id = :id AND id != :id AND is_removed = "0"');
    $query->execute(array(':id' => $case_id));
    $count = $query->fetchColumn();
    $query = $db->prepare('UPDATE exam_requests SET case_devicecount = :count WHERE id = :id AND parent_id = :id');
    $query->execute(array(':count' => $count, ':id' => $case_id));
    return (int) $count;
}


/** The case a case or device belongs to, or null if the UID does not exist. */
function kirjuri_case_of($db, $uid) {
    $query = $db->prepare('SELECT parent_id FROM exam_requests WHERE id = :id');
    $query->execute(array(':id' => $uid));
    $parent = $query->fetchColumn();
    return $parent === false ? null : $parent;
}


/**
 * Usernames in a case's access group. case_owner holds them separated by semicolons; empty means
 * every user may open the case. "admin" is stored for cases restricted to administrators.
 */
function kirjuri_access_group($case_owner) {
    return array_values(array_filter(array_map('trim', explode(';', (string) $case_owner)), function ($name) {
        return $name !== '';
    }));
}


/**
 * The one rule for opening a case: admins always can, and anyone can when the case has no access
 * group; otherwise the username must be in the group. Pure function, see tests/Unit/CaseAccessTest.
 */
function kirjuri_user_can_access_case($user, $case_owner) {
    if (isset($user['access']) && (string) $user['access'] === '0') {
        return true;
    }
    $group = kirjuri_access_group($case_owner);
    return empty($group) || (isset($user['username']) && in_array((string) $user['username'], $group, true));
}


/** The access group of the case containing $uid (a case or one of its devices), or null if it does not exist. */
function kirjuri_case_owner_of(PDO $db, $uid) {
    $query = $db->prepare('SELECT c.case_owner FROM exam_requests d JOIN exam_requests c ON c.id = d.parent_id WHERE d.id = :id');
    $query->execute(array(':id' => $uid));
    $owner = $query->fetchColumn();
    return $owner === false ? null : (string) $owner;
}


/** A case by UID, or null. Removed cases are included unless $include_removed is false. */
function kirjuri_find_case(PDO $db, $id, $include_removed = true) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id = :id AND parent_id = :id' . ($include_removed ? '' : ' AND is_removed != "1"'));
    $query->execute(array(':id' => $id));
    $case = $query->fetch(PDO::FETCH_ASSOC);
    return $case === false ? null : $case;
}


/** A device by UID, or null. Removed devices are left out unless $include_removed is true. */
function kirjuri_find_device(PDO $db, $uid, $include_removed = false) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id = :id AND id != parent_id' . ($include_removed ? '' : ' AND is_removed != "1"'));
    $query->execute(array(':id' => $uid));
    $device = $query->fetch(PDO::FETCH_ASSOC);
    return $device === false ? null : $device;
}


/**
 * The devices of a case. $order_by is one of the whitelisted sort orders below, as it can not be
 * passed as a query parameter. $kind is "all", "devices" or "tasks".
 */
function kirjuri_case_devices(PDO $db, $case_id, $include_removed = false, $order_by = 'id', $kind = 'all') {
    $orders = array('id', 'device_type', 'device_owner', 'device_manuf', 'device_model', 'device_action', 'device_location', 'device_document, device_item_number');
    if (!in_array($order_by, $orders, true)) {
        throw new InvalidArgumentException('Unknown sort order: ' . $order_by);
    }
    // Tasks are stored as devices of type "task" and listed separately on the case page.
    $kinds = array('all' => '', 'devices' => ' AND device_type != "task"', 'tasks' => ' AND device_type = "task"');
    $query = $db->prepare('SELECT * FROM exam_requests WHERE parent_id = :id AND id != :id' . ($include_removed ? '' : ' AND is_removed != "1"') . $kinds[$kind] . ' ORDER BY ' . $order_by);
    $query->execute(array(':id' => $case_id));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Other open cases with the same case file number, to warn about duplicate requests. */
function kirjuri_cases_with_file_number(PDO $db, $case) {
    $query = $db->prepare('SELECT id, case_id, case_suspect, case_name, case_devicecount, case_added_date FROM exam_requests
        WHERE case_file_number = :file_number AND id = parent_id AND is_removed = 0 AND id != :id ORDER BY id');
    $query->execute(array(':file_number' => $case['case_file_number'], ':id' => $case['id']));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Update the details of a case from the case form. */
function kirjuri_update_case(PDO $db, $case_id, array $fields) {
    $columns = array('case_name', 'case_file_number', 'case_crime', 'classification', 'case_suspect', 'case_investigation_lead',
        'case_investigator', 'forensic_investigator', 'phone_investigator', 'case_investigator_tel', 'case_investigator_unit',
        'case_request_description', 'case_confiscation_date', 'case_contains_mob_dev', 'case_status', 'case_urgency');
    $values = array(':id' => $case_id);
    $assignments = array();
    foreach ($columns as $column) {
        $assignments[] = $column . ' = :' . $column;
        $values[':' . $column] = isset($fields[$column]) ? $fields[$column] : null;
    }
    $db->prepare('UPDATE exam_requests SET ' . implode(', ', $assignments) . ', case_start_date = NOW(), last_updated = NOW(), is_removed = "0"
        WHERE id = :id AND parent_id = :id')->execute($values);
}


/** Save the report notes or the examiner's private notes of a case. */
function kirjuri_update_case_notes(PDO $db, $case_id, $column, $html) {
    if (!in_array($column, array('report_notes', 'examiners_notes'), true)) {
        throw new InvalidArgumentException('Unknown notes column: ' . $column);
    }
    $db->prepare('UPDATE exam_requests SET ' . $column . ' = :notes, last_updated = NOW() WHERE id = :id AND parent_id = :id AND is_removed != "1"')
        ->execute(array(':notes' => $html, ':id' => $case_id));
}


/** Set the access group: usernames separated by semicolons, "admin", or empty for everyone. */
function kirjuri_set_case_access_group(PDO $db, $case_id, $group) {
    $db->prepare('UPDATE exam_requests SET case_owner = :group WHERE id = :id AND parent_id = :id')->execute(array(':group' => $group, ':id' => $case_id));
}


/** Mark a case removed. Its devices stay as they are. */
function kirjuri_remove_case(PDO $db, $case_id) {
    $db->prepare('UPDATE exam_requests SET is_removed = "1", last_updated = NOW() WHERE id = :id AND parent_id = :id')->execute(array(':id' => $case_id));
}


/**
 * Set the status of a case: 1 new, 2 in progress, 3 ready. The status is written to the case and
 * its devices; back to new also clears the assigned examiners.
 */
function kirjuri_set_case_status(PDO $db, $case_id, $status) {
    $clear_examiners = ((string) $status === '1') ? 'forensic_investigator = "", phone_investigator = "", ' : '';
    $db->prepare('UPDATE exam_requests SET case_status = :status, ' . $clear_examiners . 'case_ready_date = NOW(), last_updated = NOW() WHERE parent_id = :id')
        ->execute(array(':status' => $status, ':id' => $case_id));
}


/** A case and its devices in UID order, for the CSV export. */
function kirjuri_case_rows_for_export(PDO $db, $case_id) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE parent_id = :id AND is_removed != "1" ORDER BY id');
    $query->execute(array(':id' => $case_id));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** The row with this UID (case or device), for jumping to it from the search box, or null. */
function kirjuri_find_uid(PDO $db, $uid) {
    $query = $db->prepare('SELECT id, parent_id FROM exam_requests WHERE id = :id');
    $query->execute(array(':id' => $uid));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}


/**
 * The front page sort orders by column number (the j parameter), and the status filters (s).
 * Anything else falls back to the default order and no filter.
 */
function kirjuri_case_list_order($sort, $ascending) {
    $columns = array('1' => 'case_id', '2' => 'case_name', '3' => 'case_file_number', '4' => 'case_crime', '5' => 'case_suspect',
        '6' => 'case_investigator', '7' => 'forensic_investigator', '8' => 'phone_investigator', '9' => 'case_added_date');
    return isset($columns[$sort]) ? $columns[$sort] . ($ascending ? ' ASC' : ' DESC') : 'case_status ASC, case_id DESC';
}


function kirjuri_case_status_filter($status) {
    return in_array((string) $status, array('1', '2', '3'), true) ? ' AND case_status = "' . $status . '"' : '';
}


/** The cases added in $year for the front page, sorted and filtered as kirjuri_case_list_order() describes. */
function kirjuri_list_cases(PDO $db, $year, $sort, $ascending, $status) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id = parent_id' . kirjuri_case_status_filter($status) . ' AND is_removed = "0"
        AND case_added_date BETWEEN :start AND :stop ORDER BY ' . kirjuri_case_list_order($sort, $ascending));
    $query->execute(kirjuri_year_range($year));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Full text search over the cases and devices added in $year. */
function kirjuri_search_cases(PDO $db, $term, $year, $sort, $ascending, $status) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE is_removed = "0"' . kirjuri_case_status_filter($status) . ' AND MATCH (case_name, case_suspect,
        case_file_number, case_investigator, forensic_investigator, phone_investigator, case_investigation_lead, case_investigator_unit,
        case_crime, case_requested_action, case_request_description, report_notes, examiners_notes, device_manuf, device_model,
        device_identifier, device_owner) AGAINST (:term IN BOOLEAN MODE) AND case_added_date BETWEEN :start AND :stop
        ORDER BY ' . kirjuri_case_list_order($sort, $ascending));
    $query->execute(array(':term' => $term) + kirjuri_year_range($year));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Device status per case for the year, for the progress shown on the front page. */
function kirjuri_device_actions_for_year(PDO $db, $year) {
    $query = $db->prepare('SELECT parent_id, device_action FROM exam_requests WHERE id != parent_id AND is_removed = "0"
        AND case_added_date BETWEEN :start AND :stop ORDER BY parent_id, device_action ASC');
    $query->execute(kirjuri_year_range($year));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Cases with this case file number, for the duplicate warning on the add request form. Cases the
 * user may not open are left out, as the warning shows their names and suspects.
 */
function kirjuri_find_cases_by_file_number(PDO $db, $file_number, array $user) {
    $query = $db->prepare('SELECT case_status, case_name, case_suspect, id, case_added_date, case_id, case_owner FROM exam_requests
        WHERE case_file_number = :file_number AND id = parent_id AND is_removed != "1" ORDER BY case_id');
    $query->execute(array(':file_number' => $file_number));
    return array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC), function ($case) use ($user) {
        return kirjuri_user_can_access_case($user, $case['case_owner']);
    }));
}
