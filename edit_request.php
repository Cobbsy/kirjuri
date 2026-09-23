<?php

require_once './include_functions.php';

ksess_verify(2); // View only or higher

// Force end session

// Declare variables
$session_cache = isset($_SESSION['post_cache']) ? $_SESSION['post_cache'] : ''; // Store form data if an error occurs
$sort_j = isset($_GET['j']) ? $_GET['j'] : '';
$get_case = isset($_GET['case']) ? $_GET['case'] : '';
$returntab = isset($_GET['tab']) ? $_GET['tab'] : '';
$dev_owner = isset($_GET['dev_owner']) ? urldecode($_GET['dev_owner']) : '';
$filelist = array();
$case_number = filter_numbers((substr($get_case, 0, 5)));
$confCrimes = strip_tags(file_get_contents('conf/crimes_autofill.conf'));
$kirjuri_database = connect_database('kirjuri-database');
$query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id=:id AND parent_id=:id LIMIT 1');
$query->execute(array(
        ':id' => $case_number,
    ));
$caserow = $query->fetchAll(PDO::FETCH_ASSOC);

if (count($caserow) === 0) {
    header('Location: index.php');
    die;
}

if (empty($_SESSION['case_token'][$case_number])) {
    $_SESSION['case_token'][$case_number] = generate_token(16); // Initialize case token
}

$case_owner = kirjuri_access_group($caserow[0]['case_owner']);
if (!empty($case_owner)) {
    if (!kirjuri_user_can_access_case($_SESSION['user'], $caserow[0]['case_owner'])) {
        event_log_write($caserow[0]['id'], "Access", "Denied, user not in access group.");
        $_SESSION['message']['type'] = 'error';
        $_SESSION['message']['content'] = sprintf($_SESSION['lang']['not_in_access_group']);
        $_SESSION['message_set'] = true;
        header('Location: index.php');
        die;
    }
}
else {
    $case_owner = array();
}

// The query result used to be thrown away, so the duplicate request warning never showed.
$samerequest_file_number = kirjuri_cases_with_file_number($kirjuri_database, $caserow[0]);

$query = $kirjuri_database->prepare('SELECT id, name, size, uploader, type FROM attachments WHERE request_id = :id');
$query->execute(array(':id' => $caserow[0]['id']));
$attachment_files = $query->fetchAll(PDO::FETCH_ASSOC);

if ($sort_j === 'dev_owner') {
    $j = 'device_owner';
} elseif ($sort_j === 'dev_manuf') {
    $j = 'device_manuf';
} elseif ($sort_j === 'dev_model') {
    $j = 'device_model';
} elseif ($sort_j === 'id') {
    $j = 'id';
} elseif ($sort_j === 'device_action') {
    $j = 'device_action';
} elseif ($sort_j === 'dev_location') {
    $j = 'device_location';
} elseif ($sort_j === 'tvp') {
    $j = 'device_document, device_item_number';
} else {
    $j = 'device_type';
}
$query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id != :id AND parent_id=:id AND device_type != "task" AND is_removed != "1" ORDER BY '.$j);
$query->execute(array(
        ':id' => $case_number,
    ));
$mediarow = $query->fetchAll(PDO::FETCH_ASSOC);

$query = $kirjuri_database->prepare('SELECT * FROM exam_requests WHERE id != :id AND parent_id=:id AND device_type = "task" AND is_removed != "1" ORDER BY '.$j);
$query->execute(array(
        ':id' => $case_number,
    ));
$tasks = $query->fetchAll(PDO::FETCH_ASSOC);

if (file_exists('attachments/'.$case_number.'/')) {
    $i = 0;
    $case_attachments = scandir('attachments/'.$case_number.'/', 0);
    natcasesort($case_attachments);
    foreach ($case_attachments as $file) {
        if ($file[0] !== '.') {
            $filelist[$i]['filename'] = $file;
            $filelist[$i]['filesize'] = filesize('attachments/'.$case_number.'/'.$file);
            $filelist[$i]['filetype'] = mime_content_type('attachments/'.$case_number.'/'.$file);
            ++$i;
        }
    }
}

if (file_exists('logs/cases/uid' . $case_number . '/events.log')) {
    $caselog = array_reverse(file('logs/cases/uid' . $case_number . '/events.log'));
}
else {
    $caselog = "";
}

$_SESSION['message_set'] = false; // Prevent a message from being shown twice.
echo kirjuri_render('edit_request.twig', array(
        'ct' => $_SESSION['case_token'][$case_number],
        'caselog' => $caselog,
        'case_owner' => $case_owner,
        'session_cache' => $session_cache,
        'free_disk_space' => disk_free_space('/'),
        'attachment_files' => $attachment_files,
        'filelist' => $filelist,
        'dev_owner' => $dev_owner,
        'j' => $sort_j,
        'sort_order' => $j,
        'returntab' => $returntab,
        'caserow' => $caserow,
        'samerequest_file_number' => $samerequest_file_number,
        'mediarow' => $mediarow,
        'tasks' => $tasks,
        'device_locations' => $_SESSION['lang']['device_locations'],
        'device_actions' => $_SESSION['lang']['device_actions'],
        'media_objs' => $_SESSION['lang']['media_objs'],
        'devices' => $_SESSION['lang']['devices'],
        'inv_units' => $prefs['inv_units'],
        'classifications' => $_SESSION['lang']['classifications'],
        'confCrimes' => $confCrimes
    ));
