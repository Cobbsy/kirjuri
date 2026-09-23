<?php

require_once './include_functions.php';
ksess_verify(2); // View only or higher
$id = filter_numbers($_GET['case']);
verify_case_ownership($id);


$query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id=:id AND parent_id=:id LIMIT 1');
$query->execute(array(
        ':id' => $id,
    ));
$caserow = $query->fetchAll(PDO::FETCH_ASSOC);
$query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id != :id AND parent_id=:id ORDER BY device_type');
$query->execute(array(
        ':id' => $id,
    ));
$mediarow = $query->fetchAll(PDO::FETCH_ASSOC);
echo kirjuri_render('case_report.twig', array(
        'caserow' => $caserow,
        'mediarow' => $mediarow
    ));
