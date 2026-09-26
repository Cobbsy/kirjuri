<?php
// Figures for the statistics page.

/**
 * Cases and devices of $year and the totals shown on the statistics page, or null when the year
 * has no cases. $units are the investigating units from the settings, in display order.
 */
function kirjuri_statistics(PDO $db, $year, array $units) {
    $range = kirjuri_year_range($year);

    $query = $db->prepare('SELECT * FROM exam_requests WHERE is_removed != "1" AND id = parent_id AND case_added_date BETWEEN :start AND :stop');
    $query->execute($range);
    $all_cases = $query->fetchAll(PDO::FETCH_ASSOC);
    if (empty($all_cases)) {
        return null;
    }

    $query = $db->prepare('SELECT * FROM exam_requests WHERE is_removed != "1" AND id != parent_id AND case_added_date BETWEEN :start AND :stop');
    $query->execute($range);
    $all_devices = $query->fetchAll(PDO::FETCH_ASSOC);

    // Case counts by status come from the rows already loaded, rather than a query each.
    $statuses = array_count_values(array_column($all_cases, 'case_status'));
    $count_total = $db->query('SELECT COUNT(id) FROM exam_requests WHERE is_removed != "1" AND id = parent_id')->fetchColumn();

    // Device count and data size per investigating unit, for the cases of the year. Devices are
    // counted by the case they belong to, whenever they were added. Units are matched in SQL, as
    // before, so that letter case and trailing spaces do not matter.
    $units = array_values($units);
    $by_unit = array();
    if (!empty($units)) {
        $match = '';
        $params = $range;
        foreach ($units as $i => $unit) {
            $match .= ' WHEN c.case_investigator_unit = :unit' . $i . ' THEN ' . $i;
            $params[':unit' . $i] = $unit;
        }
        $query = $db->prepare('SELECT CASE' . $match . ' END AS unit, COUNT(d.id) AS devices, SUM(d.device_size_in_gb) AS size
            FROM exam_requests c
            JOIN exam_requests d ON d.parent_id = c.id AND d.id != c.id AND d.is_removed != "1"
            WHERE c.is_removed != "1" AND c.id = c.parent_id AND c.case_added_date BETWEEN :start AND :stop
            GROUP BY 1');
        $query->execute($params);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['unit'] !== null) {
                $by_unit[(int) $row['unit']] = $row;
            }
        }
    }
    $device_data_by_unit = array();
    $device_count_by_unit = array();
    foreach ($units as $i => $unit) {
        $device_data_by_unit[$unit] = isset($by_unit[$i]) ? (int) $by_unit[$i]['size'] : 0;
        $device_count_by_unit[$unit] = isset($by_unit[$i]) ? (int) $by_unit[$i]['devices'] : 0;
    }

    $sizes = array_filter(array_column($all_devices, 'device_size_in_gb'), function ($size) {
        return $size !== null;
    });
    return array(
        'all_cases' => $all_cases,
        'all_devices' => $all_devices,
        'device_count' => count($all_devices),
        'count_total' => $count_total,
        'count_new' => isset($statuses['1']) ? $statuses['1'] : 0,
        'count_open' => isset($statuses['2']) ? $statuses['2'] : 0,
        'count_finished' => isset($statuses['3']) ? $statuses['3'] : 0,
        'count_alldevs' => count($all_devices),
        'count_phones' => count(array_filter($all_cases, function ($case) {
            return $case['case_contains_mob_dev'] === '1';
        })),
        'summed_size' => empty($sizes) ? null : (string) array_sum($sizes),
        'device_data_by_unit' => $device_data_by_unit,
        'device_count_by_unit' => $device_count_by_unit,
        'dateStart' => $range[':start'],
        'dateStop' => $range[':stop'],
    );
}
