<?php

// AJAX generator page

require_once './include_functions.php';
ksess_verify(3); // Used by the add request page, which add only users can see.

$case_file_number = substr(isset($_GET['case_file_number']) ? $_GET['case_file_number'] : '', 0, 18);
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

if (!empty($case_file_number)) {
    // Cases the user may not open are left out, as the notice shows names and suspects.
    $out = kirjuri_find_cases_by_file_number($kirjuri_database, $case_file_number, $_SESSION['user']);
    if (!empty($out)) {
        echo "<h4><i class='fa fa-exclamation' style='color:red;'></i> " .$_SESSION['lang']['notice_duplicate_request']. ":</h4>";
        foreach ($out as $entry) {
            if (empty($entry['case_name'])) {
                $case_name = $_SESSION['lang']['suspect_abbrev']." ".$entry['case_suspect'];
            } else {
                $case_name = $entry['case_name'].', RE '.$entry['case_suspect'];
            }
            ;
            if ($entry['case_status'] === '3') {
                $case_status = 'success';
                $case_progress = $_SESSION['lang']['ready'];
            } elseif ($entry['case_status'] === '2') {
                $case_status = 'warning';
                $case_progress = $_SESSION['lang']['open'];
            } elseif ($entry['case_status'] === '1') {
                $case_status = 'danger';
                $case_progress = $_SESSION['lang']['new'];
            } else {
                $case_status = 'danger';
                $case_progress = '???';
            }
            ;
            echo "<p><a class='btn btn-".$case_status." btn-xs' href='edit_request.php?case=".(int) $entry['id']."'>".htmlspecialchars($entry['case_id'].'/'.substr($entry['case_added_date'], 0, 4).' '.$case_name.' ('.$case_progress.')', ENT_QUOTES, 'UTF-8').'</a></p>';
        }
        ;
        echo '';
    }
    ;
}
;
