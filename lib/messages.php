<?php
// Messages between users.

/** Number of unread messages for a user. */
function kirjuri_unread_count(PDO $db, $username) {
    $query = $db->prepare('SELECT COUNT(id) FROM messages WHERE msgto = :username AND received = "0"');
    $query->execute(array(':username' => $username));
    return (int) $query->fetchColumn();
}


/**
 * The archive, restore and delete actions. A message has a copy for its recipient (the *_to columns)
 * and for its sender (*_from). Received-side actions need the user to be the recipient, sent-side
 * actions the sender; messages to oneself are stored with msgfrom "Myself".
 */
function kirjuri_message_actions() {
    return array(
        'archive_received' => array('set' => 'archived_to = "1"', 'side' => 'received', 'when' => 'received != "0"', 'return' => 'messages.php#inbox'),
        'restore_received' => array('set' => 'archived_to = "0"', 'side' => 'received', 'when' => '1 = 1', 'return' => 'messages.php'),
        'delete_received' => array('set' => 'deleted_to = "1"', 'side' => 'received', 'when' => 'archived_to = "1"', 'return' => 'messages.php#archive'),
        'archive_sent' => array('set' => 'archived_from = "1"', 'side' => 'sent', 'when' => '1 = 1', 'return' => 'messages.php#outbox'),
        'restore_sent' => array('set' => 'archived_from = "0"', 'side' => 'sent', 'when' => '1 = 1', 'return' => 'messages.php'),
        'delete_sent' => array('set' => 'deleted_from = "1"', 'side' => 'sent', 'when' => 'archived_from = "1"', 'return' => 'messages.php#archive'),
    );
}


/** Apply one of kirjuri_message_actions() to a message of $username. Returns the number of messages changed. */
function kirjuri_change_message(PDO $db, $action, $message_id, $username) {
    $actions = kirjuri_message_actions();
    if (!isset($actions[$action])) {
        throw new InvalidArgumentException('Unknown message action: ' . $action);
    }
    $owner = ($actions[$action]['side'] === 'received')
        ? 'msgto = :user'
        : '(msgfrom = :user OR (msgfrom = "Myself" AND msgto = :user))';
    $query = $db->prepare('UPDATE messages SET ' . $actions[$action]['set'] . ' WHERE id = :id AND ' . $owner . ' AND ' . $actions[$action]['when']);
    $query->execute(array(':id' => $message_id, ':user' => $username));
    return $query->rowCount();
}
