<?php
// Session handling, CSRF tokens, access levels and case access groups.

function ksess_init() {
    // Initialize a session token.
    session_regenerate_id(true); // Prevent session fixation.
    $_SESSION['user']['token'] = generate_token(16); // Set session token
    if (!file_exists('cache/user_' . $_SESSION['user']['username'])) {
        mkdir('cache/user_' . $_SESSION['user']['username']);
    }
    file_put_contents('cache/user_' . $_SESSION['user']['username'] . '/session_' . $_SESSION['user']['token'] . '.txt', $_SESSION['user']['username'] . ' is logged in at ' . $_SERVER['REMOTE_ADDR'] . ', user agent ' . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '-') . '. Request timestamp ' . gmdate("Y-m-d\TH:i:s\Z", $_SERVER['REQUEST_TIME']) . ". Remove this file to force logout.\r\n");
}


function ksess_validate($token) {
    // Validate a session token against token stored on user session.
    if (is_string($token) && isset($_SESSION['user']['token']) && hash_equals($_SESSION['user']['token'], $token)) {
        return true;
    }
    else {
        trigger_error("CSRF token mismatch. Try again.");
        if (isset($_SERVER['HTTP_REFERER'])) {
            header('Location: '.$_SERVER['HTTP_REFERER']);
        } else {
            header('Location: index.php');
        }
        die();
    }
}


function ksess_destroy() {
    // Destroy a session file.
    if (isset($_SESSION['user']['username'])) {
        if (file_exists('cache/user_' . $_SESSION['user']['username'] . '/session_' . $_SESSION['user']['token'] . '.txt')) {
            unlink('cache/user_' . $_SESSION['user']['username'] . '/session_' . $_SESSION['user']['token'] . '.txt');
        }
    }
    if (!isset($_SESSION['user']['token'])) {
        $_SESSION['user']['token'] = "Not set.";
    }
    event_log_write('0', "Auth", "Destroyed session " . $_SESSION['user']['token']);
    $_SESSION = null;
    session_destroy();
    header('Location: login.php');
    die;
}


function csrf_case_validate($token, $case_id) {
    // Validate a case access token. A case access token is generated on succesful
    // opening of a case.
    if (empty($token)) {
        trigger_error("Case access token missing. Try again.");
        if (isset($_SERVER['HTTP_REFERER'])) {
            header('Location: '.$_SERVER['HTTP_REFERER']);
        } else {
            header('Location: index.php');
        }
        die();
    }
    if ((is_string($token) && isset($_SESSION['case_token'][$case_id]) && hash_equals($_SESSION['case_token'][$case_id], $token)) || ($_SESSION['user']['access'] === "0")) {
        return true;
    }
    else {
        trigger_error("Case access token mismatch. Try again.");
        if (isset($_SERVER['HTTP_REFERER'])) {
            header('Location: '.$_SERVER['HTTP_REFERER']);
        } else {
            header('Location: index.php');
        }
        die();
    }
}


function ksess_verify($required_access_level) {
    // Check user access level before rendering page. User details are stored in a session variable.
    if (!isset($_SESSION['user']['username'], $_SESSION['user']['token'])) {
        ksess_destroy();
    }
    if (!file_exists('cache/user_' . $_SESSION['user']['username'] . "/session_" . $_SESSION['user']['token'] . ".txt" )) {
        // Drop session if session file has been removed.
        $_SESSION = array();
        session_destroy();
        header('Location: login.php');
        die;
    }
    if ((empty($_SESSION['user']) && $_SERVER['PHP_SELF'] !== '/api.php')) {
        // Check if user variable is set.
        header('Location: login.php');
        die;
    } else {
        if ($_SESSION['user']['access'] > $required_access_level) {
            message('Access', $_SESSION['lang']['insufficient_privileges']);
            if (isset($_SERVER['HTTP_REFERER'])) {
                header('Location: '.$_SERVER['HTTP_REFERER']);
            } else {
                header('Location: index.php');
            }
            die;
        } else {
            return true;
        }
    }
}


function verify_case_ownership($id) {
    global $kirjuri_database;
    if ($_SESSION['user']['access'] === "0") {
        return true;
    }
    $query = $kirjuri_database->prepare('SELECT case_owner FROM exam_requests WHERE id = :id');
    $query->execute(array(':id' => $id));
    $case_owner = $query->fetch(PDO::FETCH_ASSOC);
    $case_owner = explode(";", $case_owner['case_owner']);
    if ( (in_array($_SESSION['user']['username'], $case_owner)) || (empty($case_owner[0])) ) {
        return true;
    }
    else {
        event_log_write($id, 'Error', 'User initiated out-of-bounds POST request to case where not in access group.');
        message('error', $_SESSION['lang']['not_in_access_group']);
        header('Location: index.php');
        die;
    }
}
