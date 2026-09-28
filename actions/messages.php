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
    $recipients = array_column($_SESSION['all_users'], 'username');
    // An unknown recipient would get the message if an account with that name were created later.
    if (!empty($_POST['body']) && !empty($_POST['msgto']) && ($_POST['msgto'] === 'ALL_USERS' || in_array($_POST['msgto'], $recipients, true))) {
        $subject = filter_html($_POST['subject']);
        $body = filter_html($_POST['body']);
        if ($_POST['msgto'] === "ALL_USERS") {
            foreach ($_SESSION['all_users'] as $user) {
                if ($user['username'] !== $_SESSION['user']['username']) {
                    kirjuri_send_message($kirjuri_database, $_SESSION['user']['username'], $user['username'], $subject, $body);
                }
            }
        }
        else {
            // Messages to oneself are sent from "Myself".
            $msgfrom = ($_POST['msgto'] === $_SESSION['user']['username']) ? "Myself" : $_SESSION['user']['username'];
            kirjuri_send_message($kirjuri_database, $msgfrom, $_POST['msgto'], $subject, $body);
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



case 'archive_received':
case 'restore_received':
case 'delete_received':
case 'archive_sent':
case 'restore_sent':
case 'delete_sent':
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_change_message($kirjuri_database, $action, filter_numbers($_GET['id']), $_SESSION['user']['username']);
    $message_actions = kirjuri_message_actions();
    header('Location: ' . $message_actions[$action]['return']);
    die;

case 'delete_all':
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_delete_archived_messages($kirjuri_database, $_SESSION['user']['username']);
    header('Location: messages.php#inbox');
    die;





case 'delete_message':
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_delete_message($kirjuri_database, filter_numbers($_GET['id']), $_SESSION['user']['username']);
    header('Location: messages.php');
    die;
}
