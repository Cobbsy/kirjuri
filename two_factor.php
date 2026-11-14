<?php
// Two-factor sign-in for the signed-in user: whether it is on, setting it up (again), recovery codes, turning it off.
require_once './include_functions.php';
ksess_verify(3);
kirjuri_two_factor_page_access();

$status = kirjuri_two_factor_status($kirjuri_database);
$own = isset($status[(string) $_SESSION['user']['id']]) ? $status[(string) $_SESSION['user']['id']] : null;

// New recovery codes are shown once, right after they are made.
$new_codes = isset($_SESSION['two_factor_new_codes']) && is_array($_SESSION['two_factor_new_codes']) ? $_SESSION['two_factor_new_codes'] : null;
unset($_SESSION['two_factor_new_codes']);

$setup = null;
if ($new_codes === null && isset($_GET['setup'])) {
    $setup = kirjuri_two_factor_setup_view(kirjuri_two_factor_setup_secret($_SESSION['user']['id']), $_SESSION['user']['username']);
}

$_SESSION['message_set'] = false;
echo kirjuri_render('two_factor.twig', array(
        'two_factor' => $own,
        'required' => kirjuri_two_factor_required_for($prefs['settings'], $_SESSION['user']),
        'ldap_account' => (string) $_SESSION['user']['attr_3'] === 'LDAP_AUTH_ONLY',
        'new_codes' => $new_codes,
        'setup' => $setup,
    ));
