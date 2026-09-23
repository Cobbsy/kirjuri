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
    $query = $kirjuri_database->prepare('select * FROM exam_requests WHERE id=:id AND parent_id=id');
    $query->execute(array(':id' => $sticker_uid));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
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
    $query = $kirjuri_database->prepare('select parent_id FROM exam_requests WHERE id=:uid');
    $query->execute(array(
            ':uid' => $sticker_uid,
        ));
    $parentrow = $query->fetch(PDO::FETCH_ASSOC);
    if ($parentrow === false) {
        exit;
    }
    $parent = $parentrow['parent_id'];
    verify_case_ownership($parent);
    $query = $kirjuri_database->prepare('select * FROM exam_requests WHERE id=:uid AND id = parent_id LIMIT 1');
    $query->execute(array(
            ':uid' => $parent,
        ));
    $parentrow = $query->fetch(PDO::FETCH_ASSOC);
    $query = $kirjuri_database->prepare('select * FROM exam_requests WHERE id=:uid AND id != parent_id LIMIT 1');
    $query->execute(array(
            ':uid' => $sticker_uid,
        ));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (($row === false) || ($parentrow === false)) {
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
