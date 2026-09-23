<?php
// HTML sanitising and messages shown to the user.

function filter_html($string) // Purify HTML content for raw presentation.
{
    if (empty($string)) {
        return "";
    }
    else {
        global $purifier;
        $out = $purifier->purify($string);
        if (empty($out)) {
            message('error', 'Invalid HTML input.');
            header('Location: '.(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php'));
            die;
        }
        return $out;
    }
}


function show_saved_succesfully() {
    // Display a "changes saved"-message
    $_SESSION['message']['type'] = 'info';
    $_SESSION['message']['content'] = $_SESSION['lang']['changes_saved'];
    $_SESSION['message_set'] = true;
    return true;
}


function message($type, $content) {
    // Display a message. Message is rendered by Twig in base.twig, class set according to $type, either error or info.
    $_SESSION['message']['type'] = $type;
    $_SESSION['message']['content'] = $content;
    $_SESSION['message_set'] = true;
    return true;
}
