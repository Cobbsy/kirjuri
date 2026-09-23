<?php
/* Devices and media in a case. Every change takes the case the user was authorised for and only
** touches devices of that case, so a device UID from another case changes nothing.
*/

/** Mark the case as changed now. */
function kirjuri_touch_case(PDO $db, $case_id) {
    $db->prepare('UPDATE exam_requests SET last_updated = NOW() WHERE id = :id AND parent_id = :id')->execute(array(':id' => $case_id));
}


/** A device of the given case, or null if the UID is not a device of that case. */
function kirjuri_case_device(PDO $db, $case_id, $device_id, $include_removed = false) {
    $device = kirjuri_find_device($db, $device_id, $include_removed);
    return ($device !== null && $device['parent_id'] === (string) $case_id) ? $device : null;
}


/** Add a device to a case and return its UID. $fields maps device columns to values. */
function kirjuri_add_device(PDO $db, $case_id, array $fields) {
    $columns = array('device_host_id', 'device_type', 'device_manuf', 'device_model', 'device_identifier', 'device_location',
        'device_item_number', 'device_document', 'device_time_deviation', 'device_os', 'device_size_in_gb', 'device_is_host',
        'device_owner', 'case_request_description', 'device_action', 'is_removed', 'examiners_notes');
    $values = array(':parent_id' => $case_id);
    foreach ($columns as $column) {
        $values[':' . $column] = isset($fields[$column]) ? $fields[$column] : null;
    }
    $query = $db->prepare('INSERT INTO exam_requests (parent_id, ' . implode(', ', $columns) . ', device_include_in_report, device_contains_evidence, case_added_date, last_updated)
        VALUES (:parent_id, :' . implode(', :', $columns) . ', "1", "0", NOW(), NOW())');
    $query->execute($values);
    $id = $db->lastInsertId();
    kirjuri_touch_case($db, $case_id);
    kirjuri_update_device_count($db, $case_id);
    return $id;
}


/** Remove a device, and the media attached to it, from its case. Returns false if it is not in the case. */
function kirjuri_remove_device(PDO $db, $case_id, $device_id) {
    $query = $db->prepare('UPDATE exam_requests SET is_removed = "1", last_updated = NOW() WHERE (id = :id OR device_host_id = :id) AND parent_id = :case AND id != parent_id');
    $query->execute(array(':id' => $device_id, ':case' => $case_id));
    kirjuri_update_device_count($db, $case_id);
    kirjuri_touch_case($db, $case_id);
    return $query->rowCount() > 0;
}


/** Attach a device (media) to a host device of the same case. */
function kirjuri_attach_device(PDO $db, $case_id, $device_id, $host_id) {
    if ((string) $device_id === (string) $host_id || kirjuri_case_device($db, $case_id, $device_id) === null || kirjuri_case_device($db, $case_id, $host_id) === null) {
        return false;
    }
    $db->prepare('UPDATE exam_requests SET device_host_id = :host, last_updated = NOW() WHERE id = :id AND parent_id = :case')
        ->execute(array(':host' => $host_id, ':id' => $device_id, ':case' => $case_id));
    $db->prepare('UPDATE exam_requests SET device_is_host = "1" WHERE id = :host AND parent_id = :case')
        ->execute(array(':host' => $host_id, ':case' => $case_id));
    return true;
}


function kirjuri_detach_device(PDO $db, $case_id, $device_id) {
    $query = $db->prepare('UPDATE exam_requests SET device_host_id = "0", last_updated = NOW() WHERE id = :id AND parent_id = :case AND id != parent_id');
    $query->execute(array(':id' => $device_id, ':case' => $case_id));
    return $query->rowCount() > 0;
}


/**
 * Set the action status and/or location of all devices of a case; null leaves it unchanged. As before,
 * the status is also written to removed devices and the case row, the location only to devices in use.
 */
function kirjuri_update_all_devices(PDO $db, $case_id, $action, $location) {
    if ($action !== null) {
        $db->prepare('UPDATE exam_requests SET device_action = :action, last_updated = NOW() WHERE parent_id = :case')
            ->execute(array(':action' => $action, ':case' => $case_id));
    }
    if ($location !== null) {
        $db->prepare('UPDATE exam_requests SET device_location = :location, last_updated = NOW() WHERE parent_id = :case AND is_removed != "1"')
            ->execute(array(':location' => $location, ':case' => $case_id));
    }
}


/** Set a device's action status or location. */
function kirjuri_update_device_field(PDO $db, $case_id, $device_id, $field, $value) {
    if (!in_array($field, array('device_action', 'device_location'), true)) {
        throw new InvalidArgumentException('Unknown device field: ' . $field);
    }
    $query = $db->prepare('UPDATE exam_requests SET ' . $field . ' = :value, last_updated = NOW() WHERE id = :id AND parent_id = :case AND id != parent_id');
    $query->execute(array(':value' => $value, ':id' => $device_id, ':case' => $case_id));
    return $query->rowCount() > 0;
}


/** Save the device memo fields of a device of the given case. Returns false if it is not in the case. */
function kirjuri_update_device_memo(PDO $db, $case_id, $device_id, array $fields) {
    $columns = array('report_notes', 'examiners_notes', 'device_type', 'device_manuf', 'device_model', 'device_size_in_gb',
        'device_owner', 'device_os', 'device_time_deviation', 'case_request_description', 'device_item_number',
        'device_document', 'device_identifier', 'device_contains_evidence', 'device_include_in_report');
    $values = array(':id' => $device_id, ':case' => $case_id);
    $assignments = array();
    foreach ($columns as $column) {
        $assignments[] = $column . ' = :' . $column;
        $values[':' . $column] = isset($fields[$column]) ? $fields[$column] : null;
    }
    if (kirjuri_case_device($db, $case_id, $device_id, true) === null) {
        return false;
    }
    $db->prepare('UPDATE exam_requests SET ' . implode(', ', $assignments) . ', last_updated = NOW() WHERE id = :id AND parent_id = :case')->execute($values);
    kirjuri_touch_case($db, $case_id);
    return true;
}


/** Move a device, and the media attached to it, to another case, and update both device counts. */
function kirjuri_move_device(PDO $db, $from_case, $device_id, $to_case) {
    $db->prepare('UPDATE exam_requests SET parent_id = :to WHERE (id = :id OR device_host_id = :id) AND parent_id = :from AND id != parent_id')
        ->execute(array(':to' => $to_case, ':id' => $device_id, ':from' => $from_case));
    kirjuri_update_device_count($db, $from_case);
    kirjuri_update_device_count($db, $to_case);
    kirjuri_touch_case($db, $to_case);
}


/** Media attached to a device. */
function kirjuri_attached_media(PDO $db, $device_id) {
    $query = $db->prepare('SELECT id, device_type, device_manuf, device_model, device_host_id FROM exam_requests WHERE is_removed != "1" AND device_host_id = :id');
    $query->execute(array(':id' => $device_id));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Open cases (new or in progress) the user may open, for moving devices between cases. */
function kirjuri_open_cases_for_user(PDO $db, array $user) {
    $cases = $db->query('SELECT id, case_id, case_name, case_suspect, case_added_date, case_owner FROM exam_requests
        WHERE case_status <= 2 AND parent_id = id AND is_removed = "0" ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($cases, function ($case) use ($user) {
        return kirjuri_user_can_access_case($user, $case['case_owner']);
    }));
}


/**
 * Insert a device row from a KRF export into a case and return its new UID. Case, status and date
 * columns are reset; the column names must already be validated against the exam_requests columns.
 */
function kirjuri_import_device(PDO $db, $case_id, array $row) {
    foreach (array('id', 'parent_id', 'case_id', 'is_removed', 'case_status', 'case_added_date', 'case_start_date', 'case_devicecount', 'case_owner', 'last_updated') as $managed) {
        unset($row[$managed]);
    }
    foreach (array_keys($row) as $column) {
        if (!preg_match('/^[a-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid column name: ' . $column);
        }
    }
    $columns = array_keys($row);
    $values = array(':parent_id' => $case_id);
    foreach ($row as $column => $value) {
        $values[':' . $column] = $value;
    }
    $db->prepare('INSERT INTO exam_requests (parent_id, is_removed, case_added_date, last_updated, case_devicecount'
        . (empty($columns) ? '' : ', ' . implode(', ', $columns)) . ') VALUES (:parent_id, "0", NOW(), NOW(), "0"'
        . (empty($columns) ? '' : ', :' . implode(', :', $columns)) . ')')->execute($values);
    return $db->lastInsertId();
}


/** After an import, point attached media at the new UIDs of their host devices. $new_ids maps old UIDs to new. */
function kirjuri_remap_device_hosts(PDO $db, $case_id, array $new_ids) {
    $query = $db->prepare('UPDATE exam_requests SET device_host_id = :new_id WHERE device_host_id = :old_id AND parent_id = :case');
    foreach ($new_ids as $old_id => $new_id) {
        $query->execute(array(':new_id' => $new_id, ':old_id' => $old_id, ':case' => $case_id));
    }
}
