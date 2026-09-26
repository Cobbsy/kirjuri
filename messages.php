<?php

require_once './include_functions.php';
ksess_verify(3);

$open = isset($_GET['open']) ? $_GET['open'] : '';
$prefill_msgto = isset($_GET['msgto']) ? $_GET['msgto'] : '';
if (isset($_GET['subject'])) {
    $_SESSION['post_cache'] = array('subject' => urldecode($_GET['subject']));
}
else {
  $_SESSION['post_cache'] = array('subject' => '');
}

$show = isset($_GET['show']) ? $_GET['show'] : '';

foreach ($_POST as $key => $value) // Sanitize all POST data
{
    $value = is_array($value) ? '' : filter_html($value);
    $_POST[$key] = isset($value) ? $value : '';
}

$open = filter_numbers($open);

if ($open > 0) {
    kirjuri_mark_message_read($kirjuri_database, $open, $_SESSION['user']['username']);
}

$_SESSION['unread'] = kirjuri_message_counts($kirjuri_database, $_SESSION['user']['username']);
$messages = kirjuri_user_messages($kirjuri_database, $_SESSION['user']['username']);

$_SESSION['message_set'] = false;
echo kirjuri_render('messages.twig', array(
        'post' => $_POST,
        'messages' => $messages,
        'open' => $open,
        'show' => $show,
        'prefill_msgto' => $prefill_msgto
    ));
