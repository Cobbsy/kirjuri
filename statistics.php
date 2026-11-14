<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher.

if (empty($_GET['year'])) {
    $year = date('Y'); // Use current year if none specified
} else {
    $year = (int) filter_numbers((substr($_GET['year'], 0, 4))); // Get year from GET
}
if ($year < 1000) {
    $year = (int) date('Y'); // Not a year: "?year=x" used to end on the front page with an error.
}

$statistics = kirjuri_statistics($kirjuri_database, $year, $prefs['inv_units']);
$neighbours = kirjuri_statistics_neighbours($kirjuri_database, $year);

$_SESSION['message_set'] = false;
if ($statistics === null) {
    // A year without requests is shown as an empty page, with arrows to the nearest years that have some.
    echo kirjuri_render('statistics.twig', array(
            'year' => $year,
            'neighbours' => $neighbours,
            'no_requests' => true,
        ));
    die();
}

echo kirjuri_render('statistics.twig', $statistics + array(
        'year' => $year,
        'neighbours' => $neighbours,
        'no_requests' => false,
        'cases_by_classification' => kirjuri_count_by($statistics['all_cases'], 'classification', $_SESSION['lang']['classifications']),
        'cases_by_unit' => kirjuri_count_by($statistics['all_cases'], 'case_investigator_unit', $prefs['inv_units']),
        'devices' => $_SESSION['lang']['devices'],
        'media_objs' => $_SESSION['lang']['media_objs'],
        'inv_units' => $prefs['inv_units'],
        'classifications' => $_SESSION['lang']['classifications'],
    ));
