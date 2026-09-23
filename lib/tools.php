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
