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
    if (password_verify($password, $hash) && ($user_record !== false)) {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            // Hashes made with older or weaker settings are upgraded while the password is at hand.
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
                kirjuri_create_user($kirjuri_database, array(
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
            $query = $kirjuri_database->prepare('SELECT * FROM users WHERE username = :username AND name = :name AND attr_3 = :attr_3');
            $query->execute(array(
                    ':username' => $username,
                    ':name' => $ldap_realname,
                    ':attr_3' => "LDAP_AUTH_ONLY"
                ));
            $user_record = $query->fetch(PDO::FETCH_ASSOC);
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
    if (!empty($ip_access_list['allow'][0])) {
        foreach ($ip_access_list['allow'] as $ip) {
            if (ip_in_range($_SERVER['REMOTE_ADDR'], $ip)) {
                $access_allowed_from_ip = true;
            }
        }
    } else {
        $access_allowed_from_ip = true; // No whitelist set, default to allow.
    }
    if (!empty($ip_access_list['deny'][0])) {
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
    $query = $kirjuri_database->prepare('UPDATE users SET password = :secure_password_hash WHERE username = :username AND password = :legacy_password');
    $query->execute(array(
            ':secure_password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':username' => $username,
            ':legacy_password' => hash('sha256', $password)
        ));
    return $query->rowCount();
}
