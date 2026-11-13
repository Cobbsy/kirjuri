<?php
// HTML sanitising and messages shown to the user.

function filter_html($string) // Purify HTML input; rejects input that purifies to nothing.
{
    if (empty($string)) {
        return "";
    }
    $out = kirjuri_purify_html($string);
    if (empty($out)) {
        message('error', 'Invalid HTML input.');
        kirjuri_redirect_back('index.php');
        die;
    }
    return $out;
}


/** Purified HTML for printing. Unlike filter_html(), never redirects: content that purifies to nothing prints nothing. */
function kirjuri_purify_html($string) {
    global $purifier;
    return empty($string) ? '' : $purifier->purify((string) $string);
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
            'html_lang' => kirjuri_html_lang(isset($prefs['settings']['lang']) ? $prefs['settings']['lang'] : ''),
        ));
}


/**
 * The language tag for <html lang> from the language setting: "lang_PT_BR" is "pt-BR". Screen readers
 * pick their voice by it and browsers their hyphenation and translation offer. '' for a name that is
 * not a language code.
 */
function kirjuri_html_lang($setting) {
    $code = preg_replace('/\.(conf|JSON)$/', '', preg_replace('/^lang_/', '', (string) $setting));
    if (!preg_match('/^([A-Za-z]{2,3})(?:_([A-Za-z]{2}))?$/', $code, $parts)) {
        return '';
    }
    return strtolower($parts[1]) . (isset($parts[2]) ? '-' . strtoupper($parts[2]) : '');
}


/**
 * Kirjuri's own Twig filters. |purify prints rich text (notes, messages, the message of the day)
 * through HTMLPurifier. Use it instead of |raw: the API and KRF import store notes as received,
 * and older rows were saved before some of the checks on input.
 */
function kirjuri_add_twig_filters(\Twig\Environment $twig) {
    $twig->addFilter(new \Twig\TwigFilter('purify', 'kirjuri_purify_html', array('is_safe' => array('html'))));
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
