<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher
$get_case = isset($_GET['case']) ? $_GET['case'] : '';
$case_number = kirjuri_uid_param($get_case);
verify_case_ownership($case_number);

$case = kirjuri_find_case($kirjuri_database, $case_number);
if ($case === null) {
    header('Location: index.php');
    die;
}
$caserow = array($case);

if (file_exists('logs/cases/uid' . $case_number . '/events.log')) {
    $caselog = array_reverse(file('logs/cases/uid' . $case_number . '/events.log'));
}
else {
    $caselog = "";
}

$_SESSION['message_set'] = false; // Prevent a message from being shown twice.
echo kirjuri_render('timeline.twig', array(
        'caselog' => $caselog,
        'caserow' => $caserow
    ));
