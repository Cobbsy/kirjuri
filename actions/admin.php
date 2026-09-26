<?php
// Administration: settings, templates, language files and caches.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'clear_cache':
    ksess_verify(0);
    ksess_validate(posted_token());
    kirjuri_clear_cache(); // As bin/kirjuri cache:clear: sessions and the login throttle stay.
    event_log_write('0', 'Admin', 'Template cache cleared.');
    header('Location: login.php');
    die;

case 'save_template':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    $template = filter_letters_and_numbers($_GET['template']);
    if (!file_exists('conf/' . $template . '.template')) {
        // Only templates Kirjuri ships; any other name would write conf/<name>.local, settings.local included.
        header('Location: settings.php');
        die;
    }
    $templatefile = filter_html($_POST['templatefile']);
    file_put_contents('conf/' . $template . ".local", $templatefile);
    header('Location: settings.php');
    die;

case 'reset_default_settings':
    ksess_verify(0);
    ksess_validate(posted_token());
    unlink('conf/settings.local');
    event_log_write('0', 'Admin', 'Default settings restored.');
    header('Location: settings.php');
    die;

case 'save_langfile':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    $langfile_name = 'conf/lang_' . substr(filter_letters_and_numbers($_POST['countrycode']), 0, 3) . ".JSON";
    unset($_POST['countrycode']);
    unset($_POST['token']);
    $langfile = json_encode($_POST, JSON_PRETTY_PRINT);
    file_put_contents($langfile_name, $langfile);
    show_saved_succesfully();
    header('Location: lang_editor.php');
    die;

case 'save_settings':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    $audit_stamp = audit_log_write($_POST);
    $settings_output = "; Saved settings\r\n\r\n[settings]\r\n";
    foreach ($_POST['settings'] as $key => $value) {
        $settings_output = $settings_output . filter_letters_and_numbers($key) . " = \"" . ini_value($value) . "\";\r\n";
    }
    $settings_output = $settings_output . "\r\n[inv_units]\r\n";
    $units = explode(",", $_POST['inv_units']);
    $unit_key = 1;
    foreach ($units as $value) {
        $unit_key++;
        $settings_output = $settings_output . $unit_key . " = \"" . ini_value(trim($value)) . "\";\r\n";
    }
    $settings_output = $settings_output . "\r\n[statistics_chart_colors]\r\n";
    foreach ($_POST['chart'] as $key => $value) {
        $settings_output = $settings_output . filter_letters_and_numbers($key) . " = \"" . ini_value($value) . "\";\r\n";
    }
    file_put_contents('conf/settings.local', $settings_output);
    event_log_write('0', 'Admin', 'Settings saved.', $audit_stamp);
    show_saved_succesfully();
    $_SESSION['post_cache'] = '';
    header('Location: settings.php');
    die;
}
