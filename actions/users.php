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
    $ip_access_control['allow'] = explode(",", preg_replace("/[^0-9,\.\/]+/", "", $_POST['ip_whitelist']));
    $ip_access_control['deny'] = explode(",", preg_replace("/[^0-9,\.\/]+/", "", $_POST['ip_blacklist']));
    foreach ($ip_access_control['allow'] as $ip) {
        if ((!empty($ip) && ((filter_var(explode("/", $ip)[0], FILTER_VALIDATE_IP) === false) || (explode("/", $ip)[1]) > "32")) ) {
            message('error', $_SESSION['lang']['whitelist_not_a_valid_ip'] . ": " . $ip);
            header('Location: users.php?populate=' . $_POST['user_id']);
            die;
        }
    }

    foreach ($ip_access_control['deny'] as $ip) {
        if ((!empty($ip) && ((filter_var(explode("/", $ip)[0], FILTER_VALIDATE_IP) === false) || (explode("/", $ip)[1]) > "32")) ) {
            message('error', $_SESSION['lang']['blacklist_not_a_valid_ip'] . ": " . $ip);
            header('Location: users.php?populate=' . $_POST['user_id']);
            die;
        }
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
            $query = $kirjuri_database->prepare('DELETE FROM users WHERE username = :username AND id = :id AND id != 2 AND id != 1');
            $query->execute(array(
                    ':username' => $_POST['username'],
                    ':id' => $_POST['user_id']
                ));
            if ($query->rowCount() === 0) {
                message('error', $_SESSION['lang']['create_error']);
                header('Location: users.php?populate=' . filter_numbers($_POST['user_id']));
                die;
            }
            event_log_write('0', 'Remove', 'User deleted permanently: ' . $username_input);
            message('info', $_SESSION['lang']['user_deleted']);
            // End the deleted user's sessions.
            if (file_exists('cache/user_' . $username_input)) { // Not empty, checked above.
                delete_directory('cache/user_' . $username_input);
            }
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
                }
                else {
                    $user_password = $user['password'];
                }
                $query = $kirjuri_database->prepare('UPDATE users SET password = :password, name = :name, access = :access,
          flags = :flags, attr_1 = :attr_1, attr_2 = :attr_2 WHERE username = :username;
          UPDATE exam_requests SET forensic_investigator = :name WHERE forensic_investigator = :oldname;
          UPDATE exam_requests SET phone_investigator = :name WHERE phone_investigator = :oldname;');
                $query->execute(array(
                        ':oldname' => $oldname,
                        ':username' => $username_input,
                        ':name' => ucwords(trim(substr($_POST['name'], 0, 256))),
                        ':password' => $user_password,
                        ':flags' => $_POST['flag1'] . $_POST['flag2'] . $_POST['flag3'] . $_POST['flag4'],
                        ':access' => str_replace("A", "0", substr($_POST['access'], 0, 1)),
                        ':attr_1' => 'User modified by ' . $_SESSION['user']['username'] . ' at ' . date('Y-m-d H:m'),
                        ':attr_2' => $ip_json
                    ));
                event_log_write('0', 'Update', 'User modified: ' . $username_input . ', access level ' . substr($_POST['access'], 0, 1));
                message('info', $_SESSION['lang']['user_modified']);
                header('Location: users.php?populate=' . $returnid . '#u');
                die;
            }
        }

        $query = $kirjuri_database->prepare('INSERT INTO users (username, password, name, access, flags, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
    :username, :password, :name, :access, :flags, :attr_1, :attr_2,
    NULL, NULL, NULL, NULL, NULL, NULL);');
        $query->execute(array(
                ':username' => $username_input,
                ':name' => ucwords(trim(substr($_POST['name'], 0, 256))),
                ':password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
                ':flags' => $_POST['flag1'] . $_POST['flag2'],
                ':access' => str_replace("A", "0", substr($_POST['access'], 0, 1)),
                ':attr_1' => 'User created by ' . $_SESSION['user']['username'] . ' at ' . date('Y-m-d H:i'),
                ':attr_2' => $ip_json
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
    if ((!empty($_POST['new_password'])) && (password_verify($_POST['current_password'], kirjuri_session_user_credentials()['password']))) {
        $query = $kirjuri_database->prepare('UPDATE users SET password = :newpassword WHERE username = :username AND id = :id');
        $query->execute(array(
                ':newpassword' => password_hash($_POST['new_password'], PASSWORD_DEFAULT),
                ':username' => $_SESSION['user']['username'],
                ':id' => $_SESSION['user']['id']
            ));
        event_log_write('0', 'Update', 'User changed password.');
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
