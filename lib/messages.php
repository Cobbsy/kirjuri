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


/** Subjects and bodies are stored compressed and base64 encoded. */
function kirjuri_encode_message_text($text) {
    return base64_encode(gzdeflate($text));
}


function kirjuri_decode_message_text($stored) {
    return gzinflate(base64_decode($stored));
}


/** Store a message. $subject and $body should already be filtered with filter_html(). */
function kirjuri_send_message(PDO $db, $from, $to, $subject, $body) {
    $query = $db->prepare('INSERT INTO messages (msgfrom, msgto, subject, body, received, archived_from, archived_to, deleted_from, deleted_to, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
        :msgfrom, :msgto, :subject, :body, "0", "0", "0", "0", "0", NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL)');
    $query->execute(array(
            ':msgfrom' => $from,
            ':msgto' => $to,
            ':subject' => kirjuri_encode_message_text($subject),
            ':body' => kirjuri_encode_message_text($body),
        ));
}


/** Delete the archived messages of $username, both received and sent. */
function kirjuri_delete_archived_messages(PDO $db, $username) {
    $query = $db->prepare('UPDATE messages SET deleted_to = "1" WHERE msgto = :user AND archived_to = "1"');
    $query->execute(array(':user' => $username));
    $query = $db->prepare('UPDATE messages SET deleted_from = "1" WHERE msgfrom = :user AND archived_from = "1"');
    $query->execute(array(':user' => $username));
}


/** Remove a received message from the database for good. */
function kirjuri_delete_message(PDO $db, $message_id, $username) {
    $query = $db->prepare('DELETE FROM messages WHERE id = :id AND msgto = :user');
    $query->execute(array(':id' => $message_id, ':user' => $username));
}


function kirjuri_mark_message_read(PDO $db, $message_id, $username) {
    $query = $db->prepare('UPDATE messages SET received = NOW() WHERE id = :open AND msgto = :username');
    $query->execute(array(':open' => $message_id, ':username' => $username));
}


/** Counts for the message folders: new, msgcount_to, msgcount_from and msgcount_archived. */
function kirjuri_message_counts(PDO $db, $username) {
    $query = $db->prepare('SELECT
        (SELECT COUNT(id) FROM messages WHERE msgto = :username AND received = "0") as new,
        (SELECT COUNT(id) FROM messages WHERE msgto = :username AND archived_to = "0") AS msgcount_to,
        (SELECT COUNT(id) FROM messages WHERE msgfrom = :username AND archived_from = "0") AS msgcount_from,
        (SELECT COUNT(id) FROM messages WHERE (msgto = :username AND archived_to = "1" AND deleted_to = "0") OR (msgfrom = :username AND archived_from = "1" AND deleted_from = "0")) AS msgcount_archived');
    $query->execute(array(':username' => $username));
    return $query->fetch(PDO::FETCH_ASSOC);
}


/** Messages to and from $username, newest first, with subjects and bodies decoded. */
function kirjuri_user_messages(PDO $db, $username) {
    $query = $db->prepare('SELECT * FROM messages WHERE msgfrom = :username OR msgto = :username ORDER BY attr_1 DESC');
    $query->execute(array(':username' => $username));
    $messages = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($messages as $i => $message) {
        $messages[$i]['body'] = kirjuri_decode_message_text($message['body']);
        $messages[$i]['subject'] = kirjuri_decode_message_text($message['subject']);
    }
    return $messages;
}
