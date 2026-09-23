<?php
// Messages between users.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'send_message':
    ksess_verify(3);
    ksess_validate($_POST['token']);
    if (!empty($_POST['body']) && !empty($_POST['msgto'])) {
        if ($_POST['msgto'] === "ALL_USERS") {
            foreach ($_SESSION['all_users'] as $user) {
                if ($user['username'] !== $_SESSION['user']['username']) {
                    $query = $kirjuri_database->prepare('INSERT INTO messages (msgfrom, msgto, subject, body, received, archived_from, archived_to, deleted_from, deleted_to, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
        :msgfrom, :msgto, :subject, :body, "0", "0", "0", "0", "0", NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL);');
                    $query->execute(array(
                            ':msgfrom' => $_SESSION['user']['username'],
                            ':msgto' => $user['username'],
                            ':subject' => base64_encode(gzdeflate(filter_html($_POST['subject']))),
                            ':body' => base64_encode(gzdeflate(filter_html($_POST['body'])))
                        ));
                }
            }
        }
        else {
            if ($_POST['msgto'] === $_SESSION['user']['username']) {
                $msgfrom = "Myself";
            }
            else {
                $msgfrom = $_SESSION['user']['username'];
            }
            $query = $kirjuri_database->prepare('INSERT INTO messages (msgfrom, msgto, subject, body, received, archived_from, archived_to, deleted_from, deleted_to, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
    :msgfrom, :msgto, :subject, :body, "0", "0", "0", "0", "0", NOW(), NULL, NULL, NULL, NULL, NULL, NULL, NULL);');
            $query->execute(array(
                    ':msgfrom' => $msgfrom,
                    ':msgto' => $_POST['msgto'],
                    ':subject' => base64_encode(gzdeflate(filter_html($_POST['subject']))),
                    ':body' => base64_encode(gzdeflate(filter_html($_POST['body'])))
                ));
        }
        event_log_write('0', 'Add', 'message sent.');
        message('info', $_SESSION['lang']['message_sent']);
        $_SESSION['post_cache'] = "";
        header('Location: messages.php');
        die;
    }
    else {
        message('error', $_SESSION['lang']['message_or_to_missing']);
        header('Location: messages.php?show=compose');
        die;
    }

case 'delete_received':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET deleted_to = "1" WHERE id = :id AND (msgto = :user OR msgfrom = :user) AND archived_to = "1"');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php#archive');
    die;

case 'delete_sent':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET deleted_from = "1" WHERE id = :id AND (msgto = :user OR msgfrom = :user) AND archived_from = "1"');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php#archive');
    die;

case 'delete_all':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET deleted_to = "1" WHERE msgto = :user AND archived_to = "1"');
    $query->execute(array(
            ':user' => $_SESSION['user']['username']
        ));
    $query = $kirjuri_database->prepare('UPDATE messages SET deleted_from = "1" WHERE msgfrom = :user AND archived_from = "1"');
    $query->execute(array(
            ':user' => $_SESSION['user']['username']
        ));
    header('Location: messages.php#inbox');
    die;

case 'archive_received':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET archived_to = "1" WHERE id = :id AND received != "0" AND (msgto = :user OR msgfrom = :user)');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php#inbox');
    die;

case 'archive_sent':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET archived_from = "1" WHERE id = :id AND (msgto = :user OR msgfrom = :user)');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php#outbox');
    die;

case 'restore_received':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET archived_to = "0" WHERE id = :id AND (msgto = :user OR msgfrom = :user)');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php');
    die;

case 'restore_sent':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('UPDATE messages SET archived_from = "0" WHERE id = :id AND (msgto = :user OR msgfrom = :user)');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php');
    die;

case 'delete_message':
    ksess_verify(3);
    ksess_validate(posted_token());
    $query = $kirjuri_database->prepare('DELETE FROM messages WHERE id = :id AND msgto = :user');
    $query->execute(array(
            ':user' => $_SESSION['user']['username'],
            ':id' => filter_numbers($_GET['id'])
        ));
    header('Location: messages.php');
    die;
}
