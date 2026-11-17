<?php
// Two-factor sign-in: the code step after the password, setting it up at sign-in or from two_factor.php,
// new recovery codes and turning it off. See lib/two_factor.php.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'two_factor_code':
    // The code from the app, or a recovery code, after the right password.
    $user = kirjuri_two_factor_posted_pending($kirjuri_database, 'code');
    $attempt = kirjuri_login_take_attempt($user['username'], $prefs); // Wrong codes count as failed sign-ins.
    if (!$attempt['allowed']) {
        message('error', $_SESSION['lang']['two_factor_throttled']);
        event_log_write('0', 'Auth', 'Two-factor code refused for ' . $user['username'] . ': too many failed sign-ins'
            . ($attempt['refused_by'] === 'address' ? ' from ' . $_SERVER['REMOTE_ADDR'] : '') . '.');
        header('Location: login.php');
        die;
    }
    $recovery = isset($_POST['recovery_code']) && is_string($_POST['recovery_code']) && trim($_POST['recovery_code']) !== '';
    $check = $recovery
        ? kirjuri_two_factor_use_recovery_code($kirjuri_database, $user['id'], $_POST['recovery_code'])
        : kirjuri_two_factor_check_code($kirjuri_database, $user['id'], isset($_POST['two_factor_code']) ? (string) $_POST['two_factor_code'] : '', time());

    if ($check['result'] === 'ok') {
        login_throttle_release($attempt['ip']);
        if ($recovery) {
            kirjuri_two_factor_log('Recovery code used by ' . $user['username'] . ', ' . $check['left'] . ' left.', 'recovery code used', $user['username'], array('recovery_codes_left' => $check['left']));
        } else {
            kirjuri_two_factor_log('Two-factor code accepted for ' . $user['username'] . '.', 'code accepted', $user['username']);
        }
        if (!kirjuri_two_factor_finish($user)) {
            header('Location: login.php');
            die;
        }
        // Wrong codes since the last sign-in mean that someone else knows the password.
        $warning = ($check['wrong_codes'] > 0) ? sprintf($_SESSION['lang']['two_factor_wrong_codes_warning'], $check['wrong_codes']) : '';
        if ($recovery) {
            message('error', trim($warning . ' ' . sprintf($_SESSION['lang']['two_factor_recovery_used'], $check['left'])));
        } elseif ($warning !== '') {
            message('error', $warning);
        } else {
            message('info', $_SESSION['lang']['logged_in_as'] . ' ' . $_SESSION['user']['username']);
        }
        header('Location: ' . ($recovery ? 'two_factor.php' : 'index.php'));
        die;
    }

    if ($check['result'] === 'wrong') {
        // The attempt stays counted.
        kirjuri_two_factor_log('Wrong two-factor ' . ($recovery ? 'recovery code' : 'code') . ' for ' . $user['username'] . ', ' . $check['wrong_codes'] . ' in a row.',
            'wrong code', $user['username'], array('kind' => $recovery ? 'recovery code' : 'code', 'wrong_codes_in_a_row' => $check['wrong_codes']));
        if ($check['wrong_codes'] === TWO_FACTOR_MAX_WRONG) {
            kirjuri_two_factor_log('Codes from the authenticator app are refused for ' . $user['username'] . ' after ' . TWO_FACTOR_MAX_WRONG
                . ' wrong codes in a row, until an administrator resets two-factor sign-in or runs bin/kirjuri user:unlock. Someone else may know the password.',
                'blocked', $user['username']);
        }
        if ($check['wrong_codes'] >= TWO_FACTOR_MAX_WRONG) {
            message('error', $_SESSION['lang']['two_factor_blocked']);
        } else {
            message('error', $_SESSION['lang'][$recovery ? 'two_factor_wrong_recovery_code' : 'two_factor_wrong_code']);
        }
        header('Location: login.php');
        die;
    }

    // Nothing was checked, so the attempt is given back.
    login_throttle_release($attempt['name']);
    login_throttle_release($attempt['ip']);
    if ($check['result'] === 'blocked') {
        message('error', $_SESSION['lang']['two_factor_blocked']);
        event_log_write('0', 'Auth', 'Two-factor code refused for ' . $user['username'] . ': ' . $check['wrong_codes'] . ' wrong codes in a row.');
    } elseif ($check['result'] === 'unavailable') {
        message('error', $_SESSION['lang']['two_factor_unavailable']);
        event_log_write('0', 'Error', 'The two-factor code of ' . $user['username'] . ' can not be checked: ' . TWO_FACTOR_KEY_FILE
            . ' is missing or is not the key the secret was stored with. Recovery codes still work; bin/kirjuri user:two-factor-reset turns two-factor sign-in off.');
    } else {
        // Reset by an administrator since the password: start again.
        kirjuri_two_factor_drop();
        message('error', $_SESSION['lang']['two_factor_expired']);
    }
    header('Location: login.php');
    die;

case 'two_factor_cancel':
    $pending = kirjuri_two_factor_pending();
    if ($pending !== null && hash_equals($pending['token'], posted_token())) {
        kirjuri_two_factor_drop();
    }
    header('Location: login.php');
    die;

case 'two_factor_enrol':
    // Setting two-factor sign-in up after the password, when it is required and the account does not have it yet.
    $user = kirjuri_two_factor_posted_pending($kirjuri_database, 'setup');
    $codes = null;
    if (!kirjuri_two_factor_enabled($kirjuri_database, $user['id'])) {
        $codes = kirjuri_two_factor_enable($kirjuri_database, $user['id'], kirjuri_two_factor_setup_secret($user['id']),
            isset($_POST['two_factor_code']) ? (string) $_POST['two_factor_code'] : '', time(), false);
    }
    if ($codes === null) {
        if (kirjuri_two_factor_enabled($kirjuri_database, $user['id'])) {
            // Never replaced from here: someone who has only the password could put their own phone in place of the owner's.
            kirjuri_two_factor_drop();
            message('error', $_SESSION['lang']['two_factor_expired']);
        } else {
            message('error', $_SESSION['lang']['two_factor_wrong_code']);
        }
        header('Location: login.php');
        die;
    }
    kirjuri_two_factor_log('Two-factor sign-in set up by ' . $user['username'] . ' at sign-in, as it is required.', 'set up', $user['username'], array('where' => 'sign-in'));
    if (!kirjuri_two_factor_finish($user)) {
        header('Location: login.php');
        die;
    }
    $_SESSION['two_factor_new_codes'] = $codes;
    message('info', $_SESSION['lang']['two_factor_turned_on']);
    header('Location: two_factor.php');
    die;

case 'two_factor_setup':
    // Setting it up, or up again on a new phone, from two_factor.php.
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_two_factor_page_access();
    if (!kirjuri_two_factor_confirmed(isset($_POST['current_password']) ? $_POST['current_password'] : '')) {
        message('error', kirjuri_two_factor_confirm_error());
        header('Location: two_factor.php?setup=1');
        die;
    }
    $again = kirjuri_two_factor_enabled($kirjuri_database, $_SESSION['user']['id']);
    $codes = kirjuri_two_factor_enable($kirjuri_database, $_SESSION['user']['id'], kirjuri_two_factor_setup_secret($_SESSION['user']['id']),
        isset($_POST['two_factor_code']) ? (string) $_POST['two_factor_code'] : '', time());
    if ($codes === null) {
        message('error', $_SESSION['lang']['two_factor_wrong_code']);
        header('Location: two_factor.php?setup=1');
        die;
    }
    unset($_SESSION['two_factor_setup']);
    kirjuri_end_other_sessions();
    kirjuri_two_factor_log('Two-factor sign-in ' . ($again ? 'set up again, on a new phone,' : 'set up') . ' by ' . $_SESSION['user']['username'] . '. Other sessions ended.',
        $again ? 'set up again' : 'set up', $_SESSION['user']['username']);
    $_SESSION['two_factor_new_codes'] = $codes;
    message('info', $_SESSION['lang']['two_factor_turned_on']);
    header('Location: two_factor.php');
    die;

case 'two_factor_new_codes':
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_two_factor_page_access();
    if (!kirjuri_two_factor_enabled($kirjuri_database, $_SESSION['user']['id'])) {
        header('Location: two_factor.php');
        die;
    }
    if (!kirjuri_two_factor_confirmed(isset($_POST['current_password']) ? $_POST['current_password'] : '')) {
        message('error', kirjuri_two_factor_confirm_error());
        header('Location: two_factor.php');
        die;
    }
    $_SESSION['two_factor_new_codes'] = kirjuri_two_factor_replace_recovery_codes($kirjuri_database, $_SESSION['user']['id']);
    kirjuri_two_factor_log('New recovery codes made by ' . $_SESSION['user']['username'] . '; the old ones no longer work.', 'new recovery codes', $_SESSION['user']['username']);
    header('Location: two_factor.php');
    die;

case 'two_factor_off':
    ksess_verify(3);
    ksess_validate(posted_token());
    kirjuri_two_factor_page_access();
    if (kirjuri_two_factor_required_for($prefs['settings'], $_SESSION['user'])) {
        message('error', $_SESSION['lang']['two_factor_required_for_you']);
        header('Location: two_factor.php');
        die;
    }
    if (!kirjuri_two_factor_confirmed(isset($_POST['current_password']) ? $_POST['current_password'] : '')) {
        message('error', kirjuri_two_factor_confirm_error());
        header('Location: two_factor.php');
        die;
    }
    if (kirjuri_two_factor_remove($kirjuri_database, $_SESSION['user']['id'])) {
        kirjuri_end_other_sessions();
        kirjuri_two_factor_log('Two-factor sign-in turned off by ' . $_SESSION['user']['username'] . '. Other sessions ended.', 'turned off', $_SESSION['user']['username']);
        message('info', $_SESSION['lang']['two_factor_turned_off']);
    }
    header('Location: two_factor.php');
    die;
}
