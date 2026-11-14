<?php
require_once 'include_functions.php';
if (isset($_SESSION['user']['username'])) {
    header('Location: index.php');
    die;
}

// After the right password, the second step of two-factor sign-in: the code, or setting it up when required.
$pending = kirjuri_two_factor_pending();
$two_factor = null;
if ($pending !== null) {
    $two_factor = array('mode' => $pending['mode'], 'token' => $pending['token']);
    if ($pending['mode'] === 'setup') {
        $two_factor['setup'] = kirjuri_two_factor_setup_view(kirjuri_two_factor_setup_secret($pending['user_id']), $pending['username']);
    }
}

$_SESSION['message_set'] = false;
echo kirjuri_render('login.twig', array(
        'two_factor' => $two_factor,
    ));
