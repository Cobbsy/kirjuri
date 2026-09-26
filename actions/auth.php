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

    // Failures count against the account, whichever spelling of its name reaches it, and against the
    // address they come from, so that trying a few passwords on each of many accounts is limited too.
    // Each attempt is counted before the password is checked and given back unless it fails.
    $throttle_name = kirjuri_account_username($kirjuri_database, $_POST['username']);
    $ip_limit = isset($prefs['settings']['login_max_failures_per_ip']) ? (int) $prefs['settings']['login_max_failures_per_ip'] : LOGIN_MAX_FAILURES_PER_IP;
    $throttle_ip = ($ip_limit > 0) ? login_throttle_ip_key($_SERVER['REMOTE_ADDR']) : null; // 0 turns the address limit off.
    if (!login_throttle_attempt($throttle_name, LOGIN_MAX_FAILURES)) {
        message('error', $_SESSION['lang']['invalid_credentials']);
        event_log_write('0', 'Auth', 'Login throttled after repeated failures: ' . $_POST['username']);
        header('Location: login.php');
        die;
    }
    if ($throttle_ip !== null && !login_throttle_attempt($throttle_ip, $ip_limit)) {
        login_throttle_release($throttle_name);
        message('error', $_SESSION['lang']['invalid_credentials']);
        event_log_write('0', 'Auth', 'Login throttled after repeated failures from ' . $_SERVER['REMOTE_ADDR'] . ': ' . $_POST['username']);
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
        // The password was right, so the attempt is given back whatever else stops the login.
        login_throttle_release($throttle_ip);
        if (strpos($_SESSION['user']['flags'], 'I') !== false) {
            login_throttle_release($throttle_name);
            $_SESSION['user'] = array();
            message('error', $_SESSION['lang']['account_inactive']);
            event_log_write('0', 'Auth', 'Login attempt with inactivated account: ' . $_POST['username']);
            header('Location: login.php');
            die;
        } elseif (ip_allowed() === false) {
            login_throttle_release($throttle_name);
            $_SESSION['user'] = array();
            message('error', $_SERVER['REMOTE_ADDR'] . ": " . $_SESSION['lang']['ip_address_restricted']);
            event_log_write('0', 'Auth', 'Login attempt from restricted IP address '.$_SERVER['REMOTE_ADDR'].': ' . $_POST['username']);
            header('Location: login.php');
            die;
        } else {
            login_throttle_clear($throttle_name);
            ksess_init();
            message('info', $_SESSION['lang']['logged_in_as'] . ' ' . $_SESSION['user']['username']);
            event_log_write('0', 'Auth', 'Login, created session ' . $_SESSION['user']['token']);
            header('Location: index.php');
            die;
        }
    } elseif ($auth_success === false) {
        $_SESSION['user'] = array(); // The attempt was counted as a failure above.
        message('error', $_SESSION['lang']['invalid_credentials']);
        event_log_write('0', 'Auth', 'Invalid login attempt: ' . $_POST['username']);
        header('Location: login.php');
        die;
    } else {
        login_throttle_release($throttle_name);
        login_throttle_release($throttle_ip);
        $_SESSION['user'] = array();
        header('Location: login.php');
        die;
    }

case 'logout':
    if (isset($_SESSION['user']['token'])) {
        ksess_validate(posted_token()); // Other sites must not be able to log users out.
    }
    event_log_write('0', 'Auth', 'Logged out.');
    ksess_destroy();
    header('Location: index.php');
    die;

case 'drop_session':
    ksess_verify(0);
    ksess_validate(posted_token());
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
    ksess_validate(posted_token());
    $logout_user = filter_username(urldecode($_GET['user']));
    if (($logout_user !== '') && file_exists('cache/user_' . $logout_user)) {
        delete_directory('cache/user_' . $logout_user);
        message('info', $_SESSION['lang']['user_logged_out']);
    }
    event_log_write('0', "Auth", "Admin terminated sessions: " . $logout_user);
    kirjuri_redirect_back('users.php');
    die;
}
