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
    // HTTPS is turned on only from this page opened over HTTPS at that address (lib/https.php); a refused value keeps
    // its current one, and the other settings are saved.
    [$_POST['settings'], $https_problem] = kirjuri_check_https_settings((array) $_POST['settings'], $prefs['settings'], $_SERVER);
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
    // The settings are a file, not a database row, so their entry is written before they change: the new file is
    // written beside the old one first, which is where writing fails (a full disk, a folder the web server may not
    // write to), and nothing is recorded or changed then. After the entry, renaming it into place does not half-happen.
    $settings_new = 'conf/.settings.local.new';
    if (@file_put_contents($settings_new, $settings_output) === false) {
        event_log_write('0', 'Error', 'Settings could not be written to conf/settings.local.');
        message('error', $_SESSION['lang']['settings_not_saved']);
        header('Location: settings.php');
        die;
    }
    try {
        kirjuri_audit($kirjuri_database, null, 'Admin', 'Settings saved.', $_POST);
    } catch (Throwable $e) {
        @unlink($settings_new);
        throw $e;
    }
    if (!@rename($settings_new, 'conf/settings.local')) {
        @unlink($settings_new);
        event_log_write('0', 'Error', 'Settings could not be written to conf/settings.local.');
        kirjuri_audit($kirjuri_database, null, 'Error', 'Settings could not be written to conf/settings.local: the entry before this one did not take effect.', array());
        message('error', $_SESSION['lang']['settings_not_saved']);
        header('Location: settings.php');
        die;
    }
    if ($https_problem !== null) {
        message('error', $_SESSION['lang'][$https_problem]);
    } else {
        show_saved_succesfully();
    }
    $_SESSION['post_cache'] = '';
    header('Location: settings.php');
    die;
}
