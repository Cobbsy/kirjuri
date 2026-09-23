<?php
// Messages between users.

/** Number of unread messages for a user. */
function kirjuri_unread_count(PDO $db, $username) {
    $query = $db->prepare('SELECT COUNT(id) FROM messages WHERE msgto = :username AND received = "0"');
    $query->execute(array(':username' => $username));
    return (int) $query->fetchColumn();
}
