<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher
$id = filter_numbers($_GET['case']);
verify_case_ownership($id);


$case = kirjuri_find_case($kirjuri_database, $id);
if ($case === null) {
    header('Location: index.php');
    die;
}
$caserow = array($case);
$mediarow = kirjuri_case_devices($kirjuri_database, $id, true, 'device_type'); // The template leaves out removed devices.
echo kirjuri_render('case_report.twig', array(
        'caserow' => $caserow,
        'mediarow' => $mediarow
    ));
