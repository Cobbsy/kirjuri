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
