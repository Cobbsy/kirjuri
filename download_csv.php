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
header('Content-Encoding: UTF-8');
header('Content-Type: text; charset=utf-8');
header('Content-Disposition: attachment; filename="'.str_replace(array('"', "\r", "\n"), '', trim($filename)).'.csv"');
echo 'sep=;';
echo "\n";
foreach (array_keys($request_items[0]) as $key) {
    echo $key.';';
}
echo "\n";
foreach ($request_items as $result) {
    foreach ($result as $result) {
        $item = str_replace("'", '"', $result);
        $item = str_replace('"', '""', $item);
        $item = str_replace('\\', '', $item);
        $item = str_replace(';', ',', $item);
        echo '"'.$item.'";';
    }
    echo "\n";
}
