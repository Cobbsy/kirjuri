<?php
// Queries behind api.php. Callers check case access; the listings here are filtered by api.php
// before they are returned.

/** The case or device row with UID $id, as a list of zero or one rows. */
function kirjuri_api_get(PDO $db, $id) {
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id = :id');
    $query->execute(array(':id' => $id));
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Cases and devices added between $date_range['start'] and ['stop'] matching a boolean full text search. */
function kirjuri_api_find(PDO $db, $search_term, array $date_range) {
    $params = array(':search_term' => $search_term, ':dateStart' => $date_range['start'], ':dateStop' => $date_range['stop']);
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id = parent_id AND is_removed = "0" AND MATCH (
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
    $query->execute($params);
    $found = array('cases' => $query->fetchAll(PDO::FETCH_ASSOC));
    $query = $db->prepare('SELECT * FROM exam_requests WHERE id != parent_id AND is_removed = "0" AND MATCH (
        report_notes,
        examiners_notes,
        device_manuf,
        device_model,
        device_identifier,
        device_owner)
        AGAINST
        (:search_term IN BOOLEAN MODE) AND case_added_date BETWEEN :dateStart AND :dateStop ORDER BY id');
    $query->execute($params);
    $found['devices'] = $query->fetchAll(PDO::FETCH_ASSOC);
    return $found;
}


/** A short listing of every case and device. */
function kirjuri_api_info(PDO $db) {
    $query = $db->prepare('SELECT id, case_id, case_name, case_status, forensic_investigator, phone_investigator FROM exam_requests WHERE id = parent_id');
    $query->execute();
    $info = array('cases' => $query->fetchAll(PDO::FETCH_ASSOC));
    $query = $db->prepare('SELECT id, device_type, device_manuf, device_model, device_identifier, device_owner, device_action, device_location FROM exam_requests WHERE id != parent_id');
    $query->execute();
    $info['devices'] = $query->fetchAll(PDO::FETCH_ASSOC);
    return $info;
}


/**
 * Set the columns in $fields (column => value) on the case or device with UID $id. Column names are
 * reduced to letters, digits and underscores; unknown ones make the query fail. id, parent_id and
 * case_owner are skipped, as moving items between cases or changing access groups is not allowed
 * through the API. Report and examiner's notes are appended to as a new paragraph, not replaced.
 */
function kirjuri_api_update_item(PDO $db, $id, array $fields) {
    $set = array('last_updated = NOW()');
    $params = array(':id' => $id);
    $i = 0;
    foreach ($fields as $column => $value) {
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
        if (in_array($column, array('', 'id', 'parent_id', 'case_owner'), true)) {
            continue;
        }
        $placeholder = ':v' . $i++;
        if (($column === 'examiners_notes') || ($column === 'report_notes')) {
            $set[] = $column . ' = CONCAT(IFNULL(' . $column . ', ""), ' . $placeholder . ')';
            $params[$placeholder] = '<p>' . $value . '</p>';
        } else {
            $set[] = $column . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }
    }
    $query = $db->prepare('UPDATE exam_requests SET ' . implode(', ', $set) . ' WHERE id = :id');
    $query->execute($params);
}
