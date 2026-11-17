<?php
require_once './include_functions.php';

ksess_verify(0);
if (isset($_GET['view'])) {

    $audit_file = filter_numbers(substr($_GET['view'], 0, 10)) . "_" . preg_replace('/[^A-Za-z0-9.]/', '', substr($_GET['view'], 11, 19));
    $audit_file_epoch = (int) filter_numbers(substr($_GET['view'], 0, 10));
    $output_format = "";
    $audit_file_date = date('d/M/Y:H:i:s O', $audit_file_epoch);
    $audit_file_age = seconds_to_time(time() - $audit_file_epoch);
    if (isset($_GET['format'])) {
        $output_format = $_GET['format'];
    }
    $encrypted = false;
    $audit_path = kirjuri_audit_entry_path($audit_file);
    if ($audit_path === null || !is_file($audit_path)) {
        message('error', 'Audit file not found: ' . $audit_file);
        header('Location: index.php');
        die;
    }
    $contents = (string) file_get_contents($audit_path);
    $key_string = kirjuri_audit_key_string();
    $opened = kirjuri_audit_open_entry($contents, $audit_file, $key_string);
    // Whether the entry is sealed, in sequence and unchanged, or what is wrong with it.
    $status = kirjuri_audit_entry_status($kirjuri_database, $audit_file, KIRJURI_AUDIT_DIR, $key_string);
    $alerts = array('sealed' => 'success', 'old_unchanged' => 'success', 'old_unrecorded' => 'warning', 'unreadable' => 'warning');
    $entry_status = ($status === null) ? null : array(
        'class' => isset($alerts[$status['status']]) ? $alerts[$status['status']] : 'danger',
        'text' => kirjuri_audit_status_text($status, $_SESSION['lang']),
    );
    $encrypted = ($opened['format'] !== 'plain');
    $data_array = ($opened['payload'] === null) ? array() : $opened['payload'];
    $data = ($opened['payload'] === null) ? '' : json_encode($opened['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $data_array['user']['entry_fingerprint'] = kirjuri_audit_fingerprint($contents);

    //event_log_write('0', 'Audit', 'Request viewed: ' . $audit_file);

    // https://stackoverflow.com/questions/3686177/php-to-search-within-txt-file-and-echo-the-whole-line
    $matches = array();
    $handle = @fopen("logs/kirjuri.log", "r");
    if ($handle) {
        while (!feof($handle)) {
            $buffer = fgets($handle);
            if (strpos($buffer, $audit_file) !== FALSE)
                $matches[] = $buffer;
        }
        fclose($handle);
    }

    //show results:

    $_SESSION['message_set'] = false;
    echo kirjuri_render('auditor.twig', array(
            'returnid' => isset($_GET['returnid']) ? filter_numbers($_GET['returnid']) : '',
            'log_matches' => $matches,
            'output_format' => $output_format,
            'request_contents' => isset($data_array['request_contents']) ? $data_array['request_contents'] : array(),
            'entry_status' => $entry_status,
            'filename' => $audit_file,
            'encrypted' => $encrypted,
            'data' => $data,
            'data_array' => $data_array,
            'timestamp' => $audit_file_date,
            'file_age' => $audit_file_age
        ));

} else {
    echo kirjuri_render('auditor.twig', array(
            'returnid' => isset($_GET['returnid']) ? filter_numbers($_GET['returnid']) : '',
            'output_format' => "",
            'request_contents' => "",
            'filename' => "",
            'data' => ""
        ));
}
?>
