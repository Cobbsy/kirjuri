<?php
// Authentication: local accounts, LDAP and IP access lists.

/**
 * A real password hash to check unknown usernames against, so their failed logins take as long as
 * those of existing accounts: the built-in admin account's, which has current hash settings.
 */
function kirjuri_timing_hash(PDO $db) {
    $query = $db->prepare('SELECT password FROM users WHERE id = 2');
    $query->execute();
    $hash = $query->fetchColumn();
    return is_string($hash) ? $hash : password_hash(generate_token(16), PASSWORD_DEFAULT);
}


function local_authenticate($username, $password) {
    // Authenticate against a local account
    $username = filter_username($username);
    $kirjuri_database = connect_database('kirjuri-database');
    $query = $kirjuri_database->prepare('SELECT * FROM users WHERE username = :username AND (NOT attr_3 = :attr_3 OR attr_3 IS NULL) LIMIT 1');
    $query->execute(array(':username' => $username, ':attr_3' => "LDAP_AUTH_ONLY"));
    $user_record = $query->fetch(PDO::FETCH_ASSOC);


    // Unknown usernames are checked against another hash, so the response time does not reveal which
    // accounts exist. A match there logs nobody in, as there is no account.
    $hash = ($user_record !== false) ? (string) $user_record['password'] : kirjuri_timing_hash($kirjuri_database);
    $verified = password_verify($password, $hash);
    // Web forms used to run passwords through the HTML purifier, so a password set there with <, > or &
    // was hashed in its purified form ("&" as "&amp;"). Those hashes still match, and are replaced below.
    $purified = kirjuri_purify_html($password);
    $purified_match = !$verified && $purified !== $password && password_verify($purified, $hash);
    if (($verified || $purified_match) && ($user_record !== false)) {
        // Hashes made with older or weaker settings are upgraded while the password is at hand. Not for
        // accounts with API access: api_key_for() derives the key from the hash, so it would change.
        if (($purified_match || password_needs_rehash($hash, PASSWORD_DEFAULT)) && strpos((string) $user_record['flags'], 'A') === false) {
            kirjuri_set_password($kirjuri_database, $user_record['id'], $user_record['username'], password_hash($password, PASSWORD_DEFAULT));
        }
        kirjuri_set_session_user($user_record);
        event_log_write('0', "Auth", "Succesful local authentication for user " . $username);
        return true;
    } else {
        $_SESSION['user'] = array();
        event_log_write('0', "Auth", "Failure on local authentication for user " . $username);
        return false;
    }
}


function ldap_authenticate($username, $password) {
    // Source: https://www.exchangecore.com/blog/how-use-ldap-active-directory-authentication-php/
    //$username = filter_username($username);
    global $prefs;
    if ($prefs['settings']['enable_ldap_authentication'] !== "1") {
        return false;
    }
    if (!function_exists('ldap_connect')) {
        event_log_write('0', 'Error', 'LDAP authentication is enabled but the PHP LDAP extension is not installed.');
        return false;
    }
    if ((string) $password === '') {
        // An empty password makes ldap_bind() perform an unauthenticated bind, which many servers accept.
        return false;
    }
    $ldap_domain = $prefs['settings']['ldap_domain'];
    $search_string = $prefs['settings']['ldap_search_string'];
    $ldaprdn = $ldap_domain . "\\" . $username;
    if (strpos($username, '@') !== false) {
        $ldaprdn = $username;
        $username = substr( $ldaprdn, 0, strpos($username, '@') );
    }
    event_log_write('0', 'Auth', 'LDAP login attempt: ' . $ldaprdn . ' for ' . $username);
    $ldap = ldap_connect($prefs['settings']['ldap_server_address']);
    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    $bind = @ldap_bind($ldap, $ldaprdn, $password);
    if ($bind) { // On succesfull LDAP auth.
        $allowedNetgroups = explode(',', str_replace(' ', '', $prefs['settings']['ldap_allowed_netgroups']) );
        // see if user is a member of an allowed netgroup
        $filter = "(sAMAccountName=" . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ")";
        $result = ldap_search($ldap, $search_string, $filter);
        $info = ldap_get_entries($ldap, $result);
        $isMember = false;
        $ldap_realname = $username;
        if ($prefs['settings']['ldap_allowed_netgroups'] == '') {
            $isMember = true;
        }
        for ($i=0; $i<$info['count']; $i++) {
            if ($info['count'] > 1)
                break;
            if (isset($info[$i]["displayname"][0])) {
                $ldap_realname = $info[$i]["displayname"][0];
            }
            $memberof_count = isset($info[$i]['memberof']['count']) ? $info[$i]['memberof']['count'] : 0;
            for ($j=0; $j<$memberof_count; $j++) {
                if ($isMember) {
                    break;
                }
                foreach ($allowedNetgroups as $thisInst) {
                    $thisGroup = 'CN=' . $thisInst . ',';
                    $thisLen = strlen($thisGroup);
                    if (substr($info[$i]['memberof'][$j], 0, $thisLen) === $thisGroup) {
                        $isMember = true;
                        event_log_write('0', 'Auth', 'LDAP netgroup match found: ' . $thisInst);
                        break;
                    }
                }
            }
        }
        if ($isMember === false) {
            event_log_write('0', 'Auth', 'LDAP user not a member of any required group');
            return false;
        }
        @ldap_close($ldap);

        $kirjuri_database = connect_database('kirjuri-database');
        $query = $kirjuri_database->prepare('SELECT * FROM users WHERE username = :username AND attr_3 = "LDAP_AUTH_ONLY" LIMIT 1');
        $query->execute(array(':username' => $username));
        $user_record = $query->fetch(PDO::FETCH_ASSOC);
        if (empty($user_record)) {
            $query = $kirjuri_database->prepare('SELECT * FROM users WHERE username = :username AND (NOT attr_3 = :attr_3 OR attr_3 IS NULL) LIMIT 1');
            $query->execute(array(':username' => $username, ':attr_3' => "LDAP_AUTH_ONLY"));
            $user_record = $query->fetch(PDO::FETCH_ASSOC);
            if ($user_record !== false) {
                event_log_write('0', "Error", "Local-only account exists for succesfully remote authenticated user " . $username);
                return false; // FAIL LOGIN IF LOCAL ACCOUNT EXISTS
            } else {
                $new_id = kirjuri_create_user($kirjuri_database, array(
                        'username' => $username,
                        'name' => $ldap_realname,
                        'password_hash' => "API_ONLY_" . generate_token(32), // Not a hash: LDAP accounts never log in locally.
                        'flags' => "MF",
                        'access' => "1",
                        'note' => 'User imported from LDAP at ' . date('Y-m-d H:i'),
                        'ldap_only' => true,
                    ));
            }
            event_log_write('0', "Auth", "LDAP: Created account for user " . $username);
            // By ID: the stored name is trimmed and shortened, so it need not equal the LDAP display name.
            $query = $kirjuri_database->prepare('SELECT * FROM users WHERE id = :id');
            $query->execute(array(':id' => $new_id));
            $user_record = $query->fetch(PDO::FETCH_ASSOC);
            if ($user_record === false) {
                return false;
            }
            kirjuri_set_session_user($user_record);
            event_log_write('0', "Auth", "Succesful remote authentication for user " . $username);
            return true;

        } elseif ($username === $user_record['username']) {
            event_log_write('0', "Auth", "Succesful remote authentication for user " . $username);
            kirjuri_set_session_user($user_record);
            return true;

        } else {
            echo "Something went really wrong.";
            die;
        }
    }
    // LDAP auth failure
    return false;
}


function ip_allowed() {
    $access_allowed_from_ip = false; // Deny access by default
    $ip_access_list = json_decode((string) $_SESSION['user']['attr_2'], TRUE); // Get blacklists
    if (!is_array($ip_access_list)) {
        $ip_access_list = array();
    }
    $ip_access_list += array('allow' => array(), 'deny' => array());
    if (file_exists('conf/access_list.php')) {
        $global_ip_access_list = include 'conf/access_list.php';
        foreach ($global_ip_access_list['allow'] as $ip) {
            array_push($ip_access_list['allow'], $ip);
        }
        foreach ($global_ip_access_list['deny'] as $ip) {
            array_push($ip_access_list['deny'], $ip);
        }
        unset($global_ip_access_list);
    }
    // Accounts without a list of their own store array(''); an empty entry must not switch a list off.
    foreach (array('allow', 'deny') as $list) {
        $ip_access_list[$list] = array_values(array_filter((array) $ip_access_list[$list], function ($ip) {
            return trim((string) $ip) !== '';
        }));
    }
    if (!empty($ip_access_list['allow'])) {
        foreach ($ip_access_list['allow'] as $ip) {
            if (ip_in_range($_SERVER['REMOTE_ADDR'], $ip)) {
                $access_allowed_from_ip = true;
            }
        }
    } else {
        $access_allowed_from_ip = true; // No whitelist set, default to allow.
    }
    if (!empty($ip_access_list['deny'])) {
        foreach ($ip_access_list['deny'] as $ip) {
            if (ip_in_range($_SERVER['REMOTE_ADDR'], $ip)) {
                $access_allowed_from_ip = false; // If IP is on blacklist, deny.
            }
        }
    }
    return $access_allowed_from_ip;
}


function upgrade_insecure_password($username, $password) {
    $kirjuri_database = connect_database('kirjuri-database');
    $query = $kirjuri_database->prepare('UPDATE users SET password = :secure_password_hash WHERE username = :username AND password IN (:legacy_password, :legacy_purified)');
    $query->execute(array(
            ':secure_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':username' => $username,
            ':legacy_password' => hash('sha256', $password),
            ':legacy_purified' => hash('sha256', kirjuri_purify_html($password)), // See local_authenticate().
        ));
    return $query->rowCount();
}


/** Failed sign-ins allowed from one address: the login_max_failures_per_ip setting, or the default when it is not a number. */
function kirjuri_login_ip_limit(array $prefs) {
    // Only a number counts: a blank or mistyped setting keeps the default rather than reading as 0 (off).
    $setting = trim((string) (isset($prefs['settings']['login_max_failures_per_ip']) ? $prefs['settings']['login_max_failures_per_ip'] : ''));
    return ctype_digit($setting) ? (int) $setting : LOGIN_MAX_FAILURES_PER_IP;
}


/**
 * Take a sign-in attempt, a password or a two-factor code, for the account $username and the address the
 * request comes from. Failures count against both, so that trying a few passwords on each of many accounts
 * is limited too. The attempt is counted before it is checked, and given back with login_throttle_release()
 * unless it fails. Returns array('allowed' => bool, 'refused_by' => 'account', 'address' or null, 'name' and
 * 'ip', the throttle keys; 'ip' is null when the address limit is off).
 */
function kirjuri_login_take_attempt($username, array $prefs) {
    $ip_limit = kirjuri_login_ip_limit($prefs);
    $attempt = array(
        'allowed' => false,
        'refused_by' => null,
        'name' => $username,
        'ip' => ($ip_limit > 0) ? login_throttle_ip_key($_SERVER['REMOTE_ADDR']) : null, // 0 turns the address limit off.
    );
    if (!login_throttle_attempt($attempt['name'], LOGIN_MAX_FAILURES)) {
        $attempt['refused_by'] = 'account';
    } elseif ($attempt['ip'] !== null && !login_throttle_attempt($attempt['ip'], $ip_limit)) {
        login_throttle_release($attempt['name']);
        $attempt['refused_by'] = 'address';
    } else {
        $attempt['allowed'] = true;
    }
    return $attempt;
}


/** Start the Kirjuri session of a user whose sign-in is complete. */
function kirjuri_start_session(array $user_record) {
    kirjuri_set_session_user($user_record);
    ksess_init();
    $_SESSION['signed_in_at'] = time(); // For changes that need a recent sign-in, see kirjuri_two_factor_confirmed().
}


/**
 * Hold a sign-in whose password was right until its second step is done: the code from the app ($mode "code"),
 * or setting two-factor sign-in up when it is required ("setup"). Only this marker is kept, never the password,
 * and no Kirjuri session exists yet, so every page and action treats the browser as signed out.
 */
function kirjuri_two_factor_hold(array $user_record, $mode) {
    $_SESSION['user'] = array();
    unset($_SESSION['two_factor_setup']);
    session_regenerate_id(true); // A session ID fixed before the password does not carry over.
    $_SESSION['two_factor_pending'] = array(
        'user_id' => (string) $user_record['id'],
        'username' => (string) $user_record['username'],
        'mode' => $mode,
        'since' => time(),
        'token' => generate_token(32),
    );
}


/**
 * The sign-in held by kirjuri_two_factor_hold(), or null. One that waited too long is dropped, with a message:
 * TWO_FACTOR_PENDING_SECONDS for a code, TWO_FACTOR_SETUP_SECONDS for setting up.
 */
function kirjuri_two_factor_pending() {
    $pending = isset($_SESSION['two_factor_pending']) ? $_SESSION['two_factor_pending'] : null;
    if (!is_array($pending) || !isset($pending['user_id'], $pending['username'], $pending['mode'], $pending['since'], $pending['token'])) {
        return null;
    }
    $limit = ($pending['mode'] === 'setup') ? TWO_FACTOR_SETUP_SECONDS : TWO_FACTOR_PENDING_SECONDS;
    if ((time() - (int) $pending['since']) > $limit) {
        kirjuri_two_factor_drop();
        message('error', $_SESSION['lang']['two_factor_expired']);
        return null;
    }
    return $pending;
}


function kirjuri_two_factor_drop() {
    unset($_SESSION['two_factor_pending'], $_SESSION['two_factor_setup']);
}


/**
 * The secret $user_id is setting up, kept in this session (never in the database) until a code from the app
 * confirms it. Reloading the page shows the same one; after TWO_FACTOR_SETUP_SECONDS there is a new one.
 */
function kirjuri_two_factor_setup_secret($user_id) {
    $setup = isset($_SESSION['two_factor_setup']) ? $_SESSION['two_factor_setup'] : null;
    if (!is_array($setup) || !isset($setup['user_id'], $setup['secret'], $setup['since']) || $setup['user_id'] !== (string) $user_id
        || (time() - (int) $setup['since']) > TWO_FACTOR_SETUP_SECONDS) {
        $setup = array('user_id' => (string) $user_id, 'secret' => kirjuri_base32_encode(kirjuri_two_factor_new_secret()), 'since' => time());
        $_SESSION['two_factor_setup'] = $setup;
    }
    return (string) kirjuri_base32_decode($setup['secret']);
}


/** What the setup form shows for $secret: the QR code for the app and the key to type in instead. */
function kirjuri_two_factor_setup_view($secret, $username) {
    global $prefs;
    $issuer = isset($prefs['settings']['title_text']) ? $prefs['settings']['title_text'] : 'Kirjuri';
    return array(
        'qr' => kirjuri_two_factor_qr_svg(kirjuri_two_factor_uri($secret, $username, $issuer)),
        'key' => kirjuri_two_factor_readable_key($secret),
    );
}


/**
 * Whether the signed-in user confirmed a change to their two-factor sign-in: with their password, or, for LDAP
 * accounts, which have no password here, by having signed in within TWO_FACTOR_RECENT_SIGN_IN seconds. A wrong
 * password counts as a failed sign-in, so this form can not be used to guess it.
 */
function kirjuri_two_factor_confirmed($password) {
    global $prefs;
    if ((string) $_SESSION['user']['attr_3'] === 'LDAP_AUTH_ONLY') {
        return isset($_SESSION['signed_in_at']) && (time() - (int) $_SESSION['signed_in_at']) <= TWO_FACTOR_RECENT_SIGN_IN;
    }
    $attempt = kirjuri_login_take_attempt($_SESSION['user']['username'], $prefs);
    if (!$attempt['allowed']) {
        return false;
    }
    $credentials = kirjuri_session_user_credentials();
    if (is_array($credentials) && is_string($password) && $password !== '' && password_verify($password, $credentials['password'])) {
        login_throttle_release($attempt['name']);
        login_throttle_release($attempt['ip']);
        return true;
    }
    return false;
}


/** The held sign-in in $mode whose token the form posted, and its account; otherwise back to the sign-in page. */
function kirjuri_two_factor_posted_pending(PDO $db, $mode) {
    $pending = kirjuri_two_factor_pending();
    if ($pending === null || $pending['mode'] !== $mode || !hash_equals($pending['token'], posted_token())) {
        header('Location: login.php');
        die;
    }
    $user = kirjuri_user_by_id($db, $pending['user_id']);
    if ($user === null || $user['username'] !== $pending['username']) { // Removed in the meantime.
        kirjuri_two_factor_drop();
        header('Location: login.php');
        die;
    }
    return $user;
}


/**
 * Start the session of a sign-in held for its second step, now done. The account is read again, as it may have
 * been made inactive or limited to other addresses since the password. Returns whether the session started.
 */
function kirjuri_two_factor_finish(array $user_record) {
    kirjuri_two_factor_drop();
    login_throttle_clear($user_record['username']);
    kirjuri_set_session_user($user_record);
    if (strpos((string) $user_record['flags'], 'I') !== false) {
        $_SESSION['user'] = array();
        message('error', $_SESSION['lang']['account_inactive']);
        event_log_write('0', 'Auth', 'Login attempt with inactivated account: ' . $user_record['username']);
        return false;
    }
    if (!ip_allowed()) {
        $_SESSION['user'] = array();
        message('error', $_SERVER['REMOTE_ADDR'] . ": " . $_SESSION['lang']['ip_address_restricted']);
        event_log_write('0', 'Auth', 'Login attempt from restricted IP address ' . $_SERVER['REMOTE_ADDR'] . ': ' . $user_record['username']);
        return false;
    }
    kirjuri_start_session($user_record);
    event_log_write('0', 'Auth', 'Login, created session ' . $_SESSION['user']['token']);
    return true;
}


/** Stop the request unless the signed-in account may use two_factor.php: not the anonymous one, which has no password to protect. */
function kirjuri_two_factor_page_access() {
    if ((string) $_SESSION['user']['id'] === '1') {
        header('Location: index.php');
        die;
    }
}


/** Why kirjuri_two_factor_confirmed() said no, for the user. */
function kirjuri_two_factor_confirm_error() {
    return ((string) $_SESSION['user']['attr_3'] === 'LDAP_AUTH_ONLY') ? $_SESSION['lang']['two_factor_sign_in_again'] : $_SESSION['lang']['bad_password'];
}
