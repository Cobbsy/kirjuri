<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher
$id = filter_numbers(isset($_GET['case']) ? $_GET['case'] : '');
verify_case_ownership($id);


$case = kirjuri_find_case($kirjuri_database, $id);
if ($case === null) {
    header('Location: index.php');
    die;
}
$caserow = array($case);
$mediarow = kirjuri_case_devices($kirjuri_database, $id, true, 'device_type'); // The template leaves out removed devices.
// The audit log's newest entry, printed on the report: a checkpoint outside the server that the log can be checked
// against later (bin/kirjuri audit:verify --since). No entry about this case can be newer.
$head = kirjuri_audit_head($kirjuri_database);
echo kirjuri_render('case_report.twig', array(
        'caserow' => $caserow,
        'mediarow' => $mediarow,
        'audit_head' => ($head === null) ? null : array('entry' => $head['entry'], 'created' => $head['created'], 'short' => kirjuri_audit_short_fingerprint($head['fingerprint'])),
    ));
