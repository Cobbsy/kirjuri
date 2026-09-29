<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher.

if (empty($_GET['year'])) {
    $year = date('Y'); // Use current year if none specified
} else {
    $year = (int) filter_numbers((substr($_GET['year'], 0, 4))); // Get year from GET
}

$statistics = kirjuri_statistics($kirjuri_database, $year, $prefs['inv_units']);
if ($statistics === null) {
    message('error', $_SESSION['lang']['no_cases']);
    header('Location: index.php');
    die();
}

$_SESSION['message_set'] = false;
echo kirjuri_render('statistics.twig', $statistics + array(
        'cases_by_classification' => kirjuri_count_by($statistics['all_cases'], 'classification', $_SESSION['lang']['classifications']),
        'cases_by_unit' => kirjuri_count_by($statistics['all_cases'], 'case_investigator_unit', $prefs['inv_units']),
        'devices' => $_SESSION['lang']['devices'],
        'media_objs' => $_SESSION['lang']['media_objs'],
        'inv_units' => $prefs['inv_units'],
        'classifications' => $_SESSION['lang']['classifications'],
    ));
