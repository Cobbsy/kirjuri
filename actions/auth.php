<?php
// Logging in and out, and ending sessions.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'anon_login':
    foreach (get_users_with_credentials() as $user) {
        if (((string) $user['id'] === '1') && ($user['password'] === "Not set.")) {
            if (strpos($user['flags'], 'I') !== false) {
                message('error', $_SESSION['lang']['account_inactive']);
                header('Location: login.php');
                die;
            }
            else {
                kirjuri_set_session_user($user);
                ksess_init();
                event_log_write('0', 'Action', 'Anonymous login, created session ' . $_SESSION['user']['token']);
                message('info', $_SESSION['lang']['anon_login']);
                header('Location: index.php');
                die;
            }
        }
    }
    header('Location: login.php'); // Anonymous account not available.
    die;

case 'login':
    $_SESSION['user'] = array();
    $auth_success = false;
    $_POST['username'] = filter_username(isset($_POST['username']) ? $_POST['username'] : '');

    if (empty($_POST['password']) || empty($_POST['username']) || empty($_POST['auth_type'])) {
        header('Location: login.php');
        die;
    }

    if (login_throttled($_POST['username'])) {
        message('error', $_SESSION['lang']['invalid_credentials']);
        event_log_write('0', 'Auth', 'Login throttled after repeated failures: ' . $_POST['username']);
        header('Location: login.php');
        die;
    }

    if (upgrade_insecure_password($_POST['username'], $_POST['password']) !== 0) {
        event_log_write('0', "Auth", "Upgraded insecure password for user " . $_POST['username']);
    }

    if ($_POST['auth_type'] === "local") {
        $auth_success = local_authenticate($_POST['username'], $_POST['password']);
    } elseif ($_POST['auth_type'] === "ldap") {
        $auth_success = ldap_authenticate($_POST['username'], $_POST['password']);
    } else {
        $auth_success = false;
    }
    // Authenticate function sets $_SESSION['user'] on success.
    if ( ($auth_success === true) && (isset($_SESSION['user'])) ) {
        if (strpos($_SESSION['user']['flags'], 'I') !== false) {
            $_SESSION['user'] = array();
            message('error', $_SESSION['lang']['account_inactive']);
            event_log_write('0', 'Auth', 'Login attempt with inactivated account: ' . $_POST['username']);
            header('Location: login.php');
            die;
        } elseif (ip_allowed() === false) {
            $_SESSION['user'] = array();
            message('error', $_SERVER['REMOTE_ADDR'] . ": " . $_SESSION['lang']['ip_address_restricted']);
            event_log_write('0', 'Auth', 'Login attempt from restricted IP address '.$_SERVER['REMOTE_ADDR'].': ' . $_POST['username']);
            header('Location: login.php');
            die;
        } else {
            login_throttle_clear($_POST['username']);
            ksess_init();
            message('info', $_SESSION['lang']['logged_in_as'] . ' ' . $_SESSION['user']['username']);
            event_log_write('0', 'Auth', 'Login, created session ' . $_SESSION['user']['token']);
            header('Location: index.php');
            die;
        }
    } elseif ($auth_success === false) {
        $_SESSION['user'] = array();
        login_throttle_record_failure($_POST['username']);
        message('error', $_SESSION['lang']['invalid_credentials']);
        event_log_write('0', 'Auth', 'Invalid login attempt: ' . $_POST['username']);
        header('Location: login.php');
        die;
    } else {
        $_SESSION['user'] = array();
        header('Location: login.php');
        die;
    }

case 'logout':
    event_log_write('0', 'Auth', 'Logged out.');
    ksess_destroy();
    header('Location: index.php');
    die;

case 'drop_session':
    ksess_verify(0);
    ksess_validate($_GET['token']);
    $session_file = 'cache/user_' . filter_username(urldecode($_GET['user'])) . '/session_' . filter_letters_and_numbers($_GET['session']) . '.txt';
    if (file_exists($session_file)) {
        unlink($session_file);
    }
    event_log_write('0', 'Auth', 'Admin destroyed session ' . filter_letters_and_numbers($_GET['session']));
    header('Location: users.php?populate=' . filter_numbers($_GET['user_id']) . '#u');
    die;

case 'force_logout':
    // Force end session
    ksess_verify(0);
    ksess_validate($_GET['token']);
    $logout_user = filter_username(urldecode($_GET['user']));
    if (($logout_user !== '') && file_exists('cache/user_' . $logout_user)) {
        delete_directory('cache/user_' . $logout_user);
        message('info', $_SESSION['lang']['user_logged_out']);
    }
    event_log_write('0', "Auth", "Admin terminated sessions: " . $logout_user);
    header('Location: '.(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'users.php'));
    die;
}
