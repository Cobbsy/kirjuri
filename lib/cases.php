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
