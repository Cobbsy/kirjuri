<?php
// User accounts. Access levels: 0 admin, 1 user, 2 view only, 3 add only. Flags: A API access,
// I inactive, S built-in system account. attr_2 holds the IP allow and deny lists as JSON,
// attr_3 is "LDAP_AUTH_ONLY" for accounts created by LDAP logins.

function kirjuri_username_exists(PDO $db, $username) {
    $query = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
    $query->execute(array(':username' => $username));
    return (int) $query->fetchColumn() > 0;
}


/**
 * Create an account and return its ID. $account needs username, name, access and password_hash;
 * flags, note (stored in attr_1), ip_access (array with allow and deny lists) and ldap_only are optional.
 */
function kirjuri_create_user(PDO $db, array $account) {
    $account += array('flags' => '', 'note' => '', 'ip_access' => array('allow' => array(''), 'deny' => array('')), 'ldap_only' => false);
    if (!in_array((string) $account['access'], array('0', '1', '2', '3'), true)) {
        throw new InvalidArgumentException('Unknown access level: ' . $account['access']);
    }
    $query = $db->prepare('INSERT INTO users (username, password, name, access, flags, attr_1, attr_2, attr_3)
        VALUES (:username, :password, :name, :access, :flags, :attr_1, :attr_2, :attr_3)');
    $query->execute(array(
            ':username' => $account['username'],
            ':password' => $account['password_hash'],
            ':name' => ucwords(trim(substr($account['name'], 0, 256))),
            ':access' => (string) $account['access'],
            ':flags' => $account['flags'],
            ':attr_1' => $account['note'],
            ':attr_2' => $account['ldap_only'] ? '' : json_encode($account['ip_access']),
            ':attr_3' => $account['ldap_only'] ? 'LDAP_AUTH_ONLY' : null,
        ));
    return $db->lastInsertId();
}


/** All accounts without their password hashes, for the session and templates. */
function kirjuri_list_users(PDO $db) {
    $query = $db->prepare('SELECT id, username, name, access, flags, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8 FROM users ORDER BY access, username');
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** All accounts including password hashes. */
function kirjuri_list_users_with_credentials(PDO $db) {
    $query = $db->prepare('SELECT * FROM users ORDER BY access, username');
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}


/** Delete an account; the built-in anonymous (1) and admin (2) accounts are never deleted. Returns whether one was. */
function kirjuri_delete_user(PDO $db, $user_id, $username) {
    $query = $db->prepare('DELETE FROM users WHERE username = :username AND id = :id AND id != 2 AND id != 1');
    $query->execute(array(':username' => $username, ':id' => $user_id));
    return $query->rowCount() > 0;
}


/** Update an account. $account has password_hash, name, access, flags, note (attr_1) and ip_access (JSON, attr_2). */
function kirjuri_update_user(PDO $db, $username, array $account) {
    if (!in_array((string) $account['access'], array('0', '1', '2', '3'), true)) {
        throw new InvalidArgumentException('Unknown access level: ' . $account['access']);
    }
    $query = $db->prepare('UPDATE users SET password = :password, name = :name, access = :access,
        flags = :flags, attr_1 = :attr_1, attr_2 = :attr_2 WHERE username = :username');
    $query->execute(array(
            ':username' => $username,
            ':name' => $account['name'],
            ':password' => $account['password_hash'],
            ':flags' => $account['flags'],
            ':access' => (string) $account['access'],
            ':attr_1' => $account['note'],
            ':attr_2' => $account['ip_access'],
        ));
}


/** Cases name their examiners by real name, so follow a rename. */
function kirjuri_rename_examiner(PDO $db, $old_name, $new_name) {
    foreach (array('forensic_investigator', 'phone_investigator') as $column) {
        $query = $db->prepare('UPDATE exam_requests SET ' . $column . ' = :name WHERE ' . $column . ' = :oldname');
        $query->execute(array(':name' => $new_name, ':oldname' => $old_name));
    }
}


function kirjuri_set_password(PDO $db, $user_id, $username, $password_hash) {
    $query = $db->prepare('UPDATE users SET password = :newpassword WHERE username = :username AND id = :id');
    $query->execute(array(':newpassword' => $password_hash, ':username' => $username, ':id' => $user_id));
}
