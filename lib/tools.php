<?php
// Forensic tools and their reservations. Reservations are stored per tool as a JSON list in tools.attr_4,
// each with reserved_for, reserve_start, reserve_end and comment.

/**
 * Whether reservation [$start, $end) collides with an existing one [$existing_start, $existing_end).
 * Times are Unix timestamps. A reservation may start when another ends.
 */
function kirjuri_reservations_overlap($start, $end, $existing_start, $existing_end) {
    return ($existing_start <= $start && $start < $existing_end)   // Starts during the existing one.
        || ($existing_start < $end && $end <= $existing_end)       // Ends during it.
        || ($start < $existing_start && $end > $existing_end);     // Covers it.
}


/** The key of the first existing reservation that collides with $start - $end (date strings), or null. */
function kirjuri_find_reservation_conflict(array $reservations, $start, $end) {
    foreach ($reservations as $key => $reservation) {
        if (kirjuri_reservations_overlap(strtotime($start), strtotime($end), strtotime($reservation['reserve_start']), strtotime($reservation['reserve_end']))) {
            return $key;
        }
    }
    return null;
}


/** Reservations ordered by start time, renumbered from 0. */
function kirjuri_sort_reservations(array $reservations) {
    usort($reservations, function ($a, $b) {
        return strtotime($a['reserve_start']) - strtotime($b['reserve_start']);
    });
    return $reservations;
}


function kirjuri_list_tools(PDO $db) {
    $query = $db->prepare('SELECT * FROM tools ORDER BY product_name');
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** The reservations of a tool, keyed as stored. Empty for an unknown tool. */
function kirjuri_tool_reservations(PDO $db, $tool_id) {
    $query = $db->prepare('SELECT attr_4 FROM tools WHERE id = :tool_id');
    $query->execute(array(':tool_id' => $tool_id));
    $json = $query->fetchColumn();
    if (empty($json)) {
        return array();
    }
    $reservations = json_decode($json, true);
    return is_array($reservations) ? $reservations : array();
}


function kirjuri_save_tool_reservations(PDO $db, $tool_id, array $reservations) {
    $query = $db->prepare('UPDATE tools SET attr_4 = :attr_4 WHERE id = :tool_id');
    $query->execute(array(':tool_id' => $tool_id, ':attr_4' => json_encode($reservations)));
}


/** Add a tool; $tool has product_name, hw_version, sw_version, serialno, flags and comment. Returns the ID. */
function kirjuri_add_tool(PDO $db, array $tool) {
    $query = $db->prepare('INSERT INTO tools (product_name, hw_version, sw_version, serialno, flags, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
        :product_name, :hw_version, :sw_version, :serialno, :flags, NOW(), "", :comment, NULL, NULL, NULL, NULL, NULL)');
    $query->execute(array(
            ':product_name' => trim(substr($tool['product_name'], 0, 128)),
            ':hw_version' => trim(substr($tool['hw_version'], 0, 64)),
            ':sw_version' => trim(substr($tool['sw_version'], 0, 64)),
            ':serialno' => $tool['serialno'],
            ':comment' => $tool['comment'],
            ':flags' => $tool['flags'],
        ));
    return $db->lastInsertId();
}


function kirjuri_delete_tool(PDO $db, $tool_id) {
    $query = $db->prepare('DELETE FROM tools WHERE id = :tool_id');
    $query->execute(array(':tool_id' => $tool_id));
}


/**
 * Update a tool's versions, comment and flags. The change is prepended to the version history in attr_2
 * as "time;old hw -> hw;old sw -> sw;flags;, ". $tool has hw_version, sw_version, hw_version_old,
 * sw_version_old, comment and flags.
 */
function kirjuri_update_tool(PDO $db, $tool_id, array $tool) {
    $query = $db->prepare('UPDATE tools SET hw_version = :hw_version, sw_version = :sw_version, attr_3 = :comment, flags = :flags,
        attr_2 = CONCAT(NOW(),";", :hw_version_old, " -> ", :hw_version, ";", :sw_version_old, " -> ", :sw_version, ";", :flags, ";", ", ", IFNULL(attr_2,"")) WHERE id = :tool_id');
    $query->execute(array(
            ':tool_id' => $tool_id,
            ':hw_version' => trim(substr($tool['hw_version'], 0, 64)),
            ':sw_version' => trim(substr($tool['sw_version'], 0, 64)),
            ':hw_version_old' => trim(substr($tool['hw_version_old'], 0, 64)),
            ':sw_version_old' => trim(substr($tool['sw_version_old'], 0, 64)),
            ':comment' => $tool['comment'],
            ':flags' => $tool['flags'],
        ));
}
