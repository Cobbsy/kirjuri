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


function kirjuri_template_session() {
    // What templates see as "session": the PHP session minus anything secret.
    $view = $_SESSION;
    unset($view['user']['password'], $view['case_token']);
    return $view;
}


function kirjuri_render($template, $variables = array()) {
    // Render a page template with the variables every page uses (session, settings, lang).
    // Variables passed in take precedence.
    global $twig, $prefs;
    return $twig->render($template, $variables + array(
            'session' => kirjuri_template_session(),
            'settings' => $prefs['settings'],
            'lang' => isset($_SESSION['lang']) ? $_SESSION['lang'] : array(),
        ));
}


/**
 * Kirjuri's own Twig filters. |purify prints rich text (notes, messages, the message of the day)
 * through HTMLPurifier. Use it instead of |raw: the API and KRF import store notes as received,
 * and older rows were saved before some of the checks on input.
 */
function kirjuri_add_twig_filters(\Twig\Environment $twig) {
    $twig->addFilter(new \Twig\TwigFilter('purify', 'filter_html', array('is_safe' => array('html'))));
}


/**
 * Headers for every page: no framing by other sites (clickjacking), no MIME sniffing, and no case
 * URLs in the Referer sent to other sites. Kirjuri frames only its own pages (the download frame).
 */
function kirjuri_send_security_headers() {
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
