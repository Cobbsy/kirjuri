<?php
// User accounts and passwords.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'create_user':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    foreach (array('allow' => 'ip_whitelist', 'deny' => 'ip_blacklist') as $list => $field) {
        try {
            $ip_access_control[$list] = kirjuri_parse_ip_list($_POST[$field]);
        } catch (InvalidArgumentException $e) {
            $error = ($list === 'allow') ? 'whitelist_not_a_valid_ip' : 'blacklist_not_a_valid_ip';
            message('error', $_SESSION['lang'][$error] . ": " . $e->getMessage());
            header('Location: users.php?populate=' . filter_numbers($_POST['user_id']));
            die;
        }
    }
    if (isset($_POST['password']) && $_POST['password'] !== '' && strlen($_POST['password']) < KIRJURI_MIN_PASSWORD_LENGTH) {
        message('error', sprintf($_SESSION['lang']['password_too_short'], KIRJURI_MIN_PASSWORD_LENGTH));
        header('Location: users.php?populate=' . filter_numbers($_POST['user_id']));
        die;
    }

    $ip_json = json_encode($ip_access_control);
    $username_input = filter_username($_POST['username']);

    if (
        !empty($username_input) &&
        !empty($_POST['name']) &&
        !empty($_POST['access']) &&
        password_verify($_POST['current_password'], kirjuri_session_user_credentials()['password'])
    ) {
        if (isset($_POST['delete_user']) && $_POST['delete_user'] === "delete" && $_SESSION['user']['access'] === "0") {
            // Never delete the built-in anonymous (1) and admin (2) accounts.
            if (!kirjuri_delete_user($kirjuri_database, $_POST['user_id'], $_POST['username'])) {
                message('error', $_SESSION['lang']['create_error']);
                header('Location: users.php?populate=' . filter_numbers($_POST['user_id']));
                die;
            }
            event_log_write('0', 'Remove', 'User deleted permanently: ' . $username_input);
            message('info', $_SESSION['lang']['user_deleted']);
            kirjuri_end_user_sessions($username_input); // Not empty, checked above.
            header('Location: users.php');
            die;
        }
        foreach (get_users_with_credentials() as $user) {
            if ($user['username'] === $username_input) {
                $oldname = $user['name'];
                $returnid = $user['id'];
                if (!empty($_POST['password'])) {
                    $user_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    event_log_write('0', 'Update', 'Password changed for user ' . $user['username'] . '.');
                    kirjuri_end_user_sessions($user['username']); // Whoever knew the old password is logged out.
                }
                else {
                    $user_password = $user['password'];
                }
                $new_name = ucwords(trim(substr($_POST['name'], 0, 256)));
                kirjuri_update_user($kirjuri_database, $username_input, array(
                        'name' => $new_name,
                        'password_hash' => $user_password,
                        'flags' => $_POST['flag1'] . $_POST['flag2'] . $_POST['flag3'] . $_POST['flag4'],
                        'access' => str_replace("A", "0", substr($_POST['access'], 0, 1)),
                        'note' => 'User modified by ' . $_SESSION['user']['username'] . ' at ' . date('Y-m-d H:i'),
                        'ip_access' => $ip_json,
                    ));
                kirjuri_rename_examiner($kirjuri_database, $oldname, $new_name);
                event_log_write('0', 'Update', 'User modified: ' . $username_input . ', access level ' . substr($_POST['access'], 0, 1));
                message('info', $_SESSION['lang']['user_modified']);
                header('Location: users.php?populate=' . $returnid . '#u');
                die;
            }
        }

        if (strlen($_POST['password']) < KIRJURI_MIN_PASSWORD_LENGTH) { // A new account needs a password.
            message('error', sprintf($_SESSION['lang']['password_too_short'], KIRJURI_MIN_PASSWORD_LENGTH));
            header('Location: users.php');
            die;
        }
        kirjuri_create_user($kirjuri_database, array(
                'username' => $username_input,
                'name' => $_POST['name'],
                'password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT),
                'flags' => $_POST['flag1'] . $_POST['flag2'],
                'access' => str_replace("A", "0", substr($_POST['access'], 0, 1)),
                'note' => 'User created by ' . $_SESSION['user']['username'] . ' at ' . date('Y-m-d H:i'),
                'ip_access' => $ip_access_control,
            ));
        event_log_write('0', 'Add', 'User created: ' . $username_input . ', access level ' . substr($_POST['access'], 0, 1));
        message('info', $_SESSION['lang']['user_created']);
        header('Location: users.php');
    }
    else {
        message('error', $_SESSION['lang']['create_error']);
        $_SESSION['message_set'] = true;
        header('Location: users.php');
    }
    die;

case 'update_password':
    ksess_verify(1);
    ksess_validate($_POST['token']);
    if (!empty($_POST['new_password']) && strlen($_POST['new_password']) < KIRJURI_MIN_PASSWORD_LENGTH) {
        message('error', sprintf($_SESSION['lang']['password_too_short'], KIRJURI_MIN_PASSWORD_LENGTH));
        header('Location: settings.php');
        die;
    }
    if ((!empty($_POST['new_password'])) && (password_verify($_POST['current_password'], kirjuri_session_user_credentials()['password']))) {
        kirjuri_set_password($kirjuri_database, $_SESSION['user']['id'], $_SESSION['user']['username'], password_hash($_POST['new_password'], PASSWORD_DEFAULT));
        event_log_write('0', 'Update', 'User changed password.');
        kirjuri_end_user_sessions($_SESSION['user']['username']); // This one and any others, as above.
        $_SESSION['user'] = array();
        session_destroy();
        header('Location: login.php');
    }
    else {
        message('error', $_SESSION['lang']['bad_password']);
        header('Location: settings.php');
    }
    die;
}
