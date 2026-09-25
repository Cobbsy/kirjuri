<?php

require_once './include_functions.php';
$case_number = filter_numbers((substr($_GET['case'], 0, 5)));
ksess_verify(2); // View only or higher

verify_case_ownership($case_number);

$request_items = kirjuri_case_rows_for_export($kirjuri_database, $case_number);
if (empty($request_items)) {
    header('Location: index.php');
    die;
}

$filename = 'Kirjuri '.$request_items[0]['case_id'].'-'.date('Y', strtotime($request_items[0]['case_added_date'])).' '.$request_items[0]['case_name'];
header('Content-Description: File Transfer');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.str_replace(array('"', "\r", "\n"), '', trim($filename)).'.csv"');
$output = fopen('php://output', 'w');
kirjuri_write_csv($output, $request_items);
fclose($output);
