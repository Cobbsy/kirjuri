<?php
require_once './include_functions.php';
ksess_verify(2); // View only or higher

function sticker_text($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$sticker_type = isset($_GET['type']) ? $_GET['type'] : '';
$sticker_uid = isset($_GET['uid']) ? filter_numbers($_GET['uid']) : '';
?>
<html>
  <head>
    <meta charset="utf-8">
    <script type="text/javascript">
    window.print();
    window.onfocus=function(){ window.close();}
  </script>
  </head>
<!-- <body onload="window.print()"> -->
<body>
<?php
if ($sticker_type === 'examination_request') {
    $row = kirjuri_find_case($kirjuri_database, $sticker_uid);
    if ($row === null) {
        exit;
    }
    verify_case_ownership($row['id']);
    $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
    echo $generator->getBarcode('UID'.$row['id'], $generator::TYPE_CODE_128);
    echo '<br><b>[UID'.sticker_text($row['id']).'] '.sticker_text($row['case_id']).'/'.date('Y', strtotime($row['case_added_date'])).', '.sticker_text($row['case_file_number']).'</b>';
    echo '<br><b>'.sticker_text($row['case_name']).' '.sticker_text($row['case_suspect']).'</b>';
    echo '<br>' . sticker_text($row['case_investigator']).' ' .sticker_text($row['case_investigator_unit']);
    exit;
}


if ($sticker_type === 'device') {
    $row = kirjuri_find_device($kirjuri_database, $sticker_uid, true);
    if ($row === null) {
        exit;
    }
    verify_case_ownership($row['parent_id']);
    $parentrow = kirjuri_find_case($kirjuri_database, $row['parent_id']);
    if ($parentrow === null) {
        exit;
    }
    $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
    echo $generator->getBarcode('UID'.$row['id'], $generator::TYPE_CODE_128);
    echo '<br><b>[UID'.sticker_text($row['id']).'] '.sticker_text($parentrow['case_id']).'/'.date('Y', strtotime($parentrow['case_added_date'])).', '.sticker_text($parentrow['case_file_number']).'</b>';
    echo '<br>'.sticker_text($row['device_type']).' '.sticker_text($row['device_manuf']).' '.sticker_text($row['device_model']).'<br>';
    exit;
}

?>
</body>
