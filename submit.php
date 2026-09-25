<?php

require_once './include_functions.php';

$year = date('Y');
$dateRange = array(
    'start' => $year . '-01-01 00:00:00',
    'stop' => ($year + 1) . '-01-01 00:00:00'
);

// Passwords are compared and hashed as typed, and never kept in the session's form cache below.
$password_fields = array('password', 'current_password', 'new_password');

foreach ($_POST as $key => $value) // Sanitize all POST data
    {
    if (in_array($key, $password_fields, true)) {
        $value = is_string($value) ? $value : '';
    } elseif (!is_array($value)) {
        if (isset($value[3])) // Don't bother to sanitize string under 4 characters.
            {
            $value = filter_html($value);
        }
    }
    $_POST[$key] = isset($value) ? $value : '';
}

/* MySQL used to populate empty INT fields with 0. The newer versions throw an
* error so some vars need to be set to 0 if empty.
*/

$default_to_zero = array("is_removed", "device_host_id", "device_item_number", "device_size_in_gb", "device_contains_evidence", "device_include_in_report", "case_contains_mob_dev");

foreach ($default_to_zero as $key) {
    if ( (isset($_POST[$key])) && (empty($_POST[$key]) )) {
        $_POST[$key] = "0";
    }
}

// Unchecked checkboxes are not sent at all, so default the user and tool flag checkboxes to unset.
foreach (array('flag1', 'flag2', 'flag3', 'flag4') as $flag) {
    $_POST[$flag] = isset($_POST[$flag]) ? $_POST[$flag] : '';
}

if ( (isset($_POST['phone_investigator'])) && (empty($_POST['phone_investigator']) )) {
    $_POST['phone_investigator'] = "-";
}


// Get entires from cache if filling a form fails.
$_SESSION['post_cache'] = array_diff_key($_POST, array_flip($password_fields));
$_GET['type'] = isset($_GET['type']) ? $_GET['type'] : '';

// ----- User management

// Each action lives in actions/<area>.php and ends the request itself (a redirect or output, then die).
$actions = array(
    'anon_login' => 'auth',
    'login' => 'auth',
    'logout' => 'auth',
    'drop_session' => 'auth',
    'force_logout' => 'auth',
    'create_user' => 'users',
    'update_password' => 'users',
    'send_message' => 'messages',
    'delete_received' => 'messages',
    'delete_sent' => 'messages',
    'delete_all' => 'messages',
    'archive_received' => 'messages',
    'archive_sent' => 'messages',
    'restore_received' => 'messages',
    'restore_sent' => 'messages',
    'delete_message' => 'messages',
    'reserve_tool' => 'tools',
    'add_tool' => 'tools',
    'update_tool' => 'tools',
    'case_access' => 'cases',
    'examination_request' => 'cases',
    'case_update' => 'cases',
    'report_notes' => 'cases',
    'examiners_notes' => 'cases',
    'set_removed_case' => 'cases',
    'update_request_status' => 'cases',
    'remove_attachment' => 'cases',
    'set_removed' => 'devices',
    'device_attach' => 'devices',
    'device_detach' => 'devices',
    'move_all' => 'devices',
    'change_device_status' => 'devices',
    'change_device_location' => 'devices',
    'devicememo' => 'devices',
    'device' => 'devices',
    'clear_cache' => 'admin',
    'save_template' => 'admin',
    'reset_default_settings' => 'admin',
    'save_langfile' => 'admin',
    'save_settings' => 'admin',
);

$action = $_GET['type'];
if (!isset($actions[$action])) {
    event_log_write('0', 'Error', 'submit.php called with erroneous value.');
    header('Location: index.php'); // Fall back to index with an error if no conditions are met.
    die;
}

define('KIRJURI_ACTION', true);
require __DIR__ . '/actions/' . $actions[$action] . '.php';
die;
