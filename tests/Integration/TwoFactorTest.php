<?php

namespace Kirjuri\Tests\Integration;

/** Two-factor sign-in against a real installation: the code step, setup, recovery codes, the admin controls and logs. */
final class TwoFactorTest extends IntegrationTestCase
{
    private const PASSWORD = 'correct-password';

    private array $lang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lang = json_decode(file_get_contents(KIRJURI_ROOT . '/conf/lang_EN.JSON'), true);
    }

    public function testAPasswordAloneNoLongerSignsIn(): void
    {
        [$username] = $this->userWithTwoFactor();
        $client = $this->signInWithPassword($username);

        // Half signed in: no page or action works yet, and the password is kept nowhere.
        $this->assertSame('login.php', $client->get('index.php')->location());
        $this->assertSame('login.php', $client->get('two_factor.php')->location());
        $this->assertSame('login.php', $client->get('settings.php')->location());
        $this->assertSame('login.php', $client->post('submit.php?type=update_password', array('token' => $this->pendingToken($client), 'current_password' => self::PASSWORD, 'new_password' => 'another-password'))->location());
        $page = $client->get('login.php');
        $this->assertStringContainsString('name="two_factor_code"', $page->body);
        $this->assertStringContainsString('name="recovery_code"', $page->body);
        $this->assertStringNotContainsString('name="password"', $page->body);
        foreach ($this->server->sessionFiles() as $session) {
            $this->assertStringNotContainsString(self::PASSWORD, $session);
        }
        $this->assertLogged('Password accepted for ' . $username . ', waiting for the two-factor code.');
    }

    public function testTheRightCodeSignsIn(): void
    {
        [$username, $secret] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $client = $this->signInWithPassword($username);
        $response = $this->enterCode($client, $this->code($secret));
        $this->assertSame('index.php', $response->location());
        $this->assertSame(200, $client->get('index.php')->status);
        $this->assertLogged('Two-factor code accepted for ' . $username . '.');
        // The sign-in step is over: the sign-in page sends signed-in users on.
        $this->assertSame('index.php', $client->get('login.php')->location());
    }

    public function testACodeWorksOnce(): void
    {
        [$username, $secret] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $code = $this->code($secret);
        $this->assertSame('index.php', $this->enterCode($this->signInWithPassword($username), $code)->location());
        // Someone who saw the code being typed can not use it again.
        $other = $this->signInWithPassword($username);
        $this->assertSame('login.php', $this->enterCode($other, $code)->location());
        $this->assertSame('login.php', $other->get('index.php')->location());
        $this->assertSame('1', $this->twoFactorRow($username)['wrong_codes']);
    }

    public function testWrongCodesAreCountedAndReportedAfterSignIn(): void
    {
        [$username, $secret] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $client = $this->signInWithPassword($username);
        $wrong = str_pad((string) ((intval($this->code($secret)) + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->assertSame('login.php', $this->enterCode($client, $wrong)->location());
        $this->assertShows($this->lang['two_factor_wrong_code'], $client->get('login.php')->body);
        $this->assertSame('1', $this->twoFactorRow($username)['wrong_codes']);
        $this->assertLogged('Wrong two-factor code for ' . $username . ', 1 in a row.');

        // The right code then signs in, with a warning that someone else may know the password.
        $this->assertSame('index.php', $this->enterCode($client, $this->code($secret))->location());
        $this->assertShows(sprintf($this->lang['two_factor_wrong_codes_warning'], 1), $client->get('index.php')->body);
        $this->assertSame('0', $this->twoFactorRow($username)['wrong_codes']);
    }

    public function testWrongCodesShareTheSignInThrottle(): void
    {
        [$username, $secret] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $client = $this->signInWithPassword($username);
        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            $this->enterCode($client, '000000' === $this->code($secret) ? '111111' : '000000');
        }
        $this->assertSame('login.php', $this->enterCode($client, $this->code($secret))->location());
        $this->assertShows($this->lang['two_factor_throttled'], $client->get('login.php')->body);
        // The password step counts the same failures.
        $response = $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => self::PASSWORD, 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location());

        $this->assertSame(0, $this->server->cli(array('user:unlock', $username))[0]);
        $this->assertSame('index.php', $this->enterCode($this->signInWithPassword($username), $this->code($secret))->location());
    }

    public function testThirtyWrongCodesInARowBlockTheAppButNotRecoveryCodes(): void
    {
        [$username, $secret, $codes] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $this->setWrongCodes($username, TWO_FACTOR_MAX_WRONG - 1);
        $client = $this->signInWithPassword($username);
        $this->assertSame('login.php', $this->enterCode($client, '000000' === $this->code($secret) ? '111111' : '000000')->location());
        $this->assertShows($this->lang['two_factor_blocked'], $client->get('login.php')->body);
        $this->assertLogged('Codes from the authenticator app are refused for ' . $username . ' after ' . TWO_FACTOR_MAX_WRONG . ' wrong codes in a row');

        // The right code is refused too, and does not count against the throttle as nothing was checked.
        $this->assertSame('login.php', $this->enterCode($client, $this->code($secret))->location());
        $this->assertShows($this->lang['two_factor_blocked'], $client->get('login.php')->body);
        $this->assertSame((string) TWO_FACTOR_MAX_WRONG, $this->twoFactorRow($username)['wrong_codes']);

        // The owner, who has the recovery codes, still gets in, and that ends the block.
        $this->assertSame('two_factor.php', $this->enterCode($client, $codes[0], 'recovery_code')->location());
        $this->assertSame('0', $this->twoFactorRow($username)['wrong_codes']);

        // bin/kirjuri user:unlock ends a block too.
        $this->setWrongCodes($username, TWO_FACTOR_MAX_WRONG);
        [$status, $out] = $this->server->cli(array('user:unlock', $username));
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Cleared wrong two-factor codes', $out);
        $this->assertSame('0', $this->twoFactorRow($username)['wrong_codes']);
    }

    public function testARecoveryCodeSignsInOnce(): void
    {
        [$username, , $codes] = $this->userWithTwoFactor();
        // Typed in capitals, without the dash.
        $client = $this->signInWithPassword($username);
        $this->assertSame('two_factor.php', $this->enterCode($client, strtoupper(str_replace('-', '', $codes[0])), 'recovery_code')->location());
        $page = $client->get('two_factor.php');
        $this->assertSame(200, $page->status);
        $this->assertShows(sprintf($this->lang['two_factor_recovery_used'], TWO_FACTOR_RECOVERY_CODES - 1), $page->body);
        $this->assertLogged('Recovery code used by ' . $username . ', ' . (TWO_FACTOR_RECOVERY_CODES - 1) . ' left.');

        $again = $this->signInWithPassword($username);
        $this->assertSame('login.php', $this->enterCode($again, $codes[0], 'recovery_code')->location());
        $this->assertShows($this->lang['two_factor_wrong_recovery_code'], $again->get('login.php')->body);
        $this->assertSame('two_factor.php', $this->enterCode($again, $codes[1], 'recovery_code')->location());
    }

    public function testSecretsAndCodesAreNotStoredOrLoggedInTheClear(): void
    {
        [$username, $secret, $codes] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $client = $this->signInWithPassword($username);
        $this->enterCode($client, '000000' === $this->code($secret) ? '111111' : '000000');
        $this->enterCode($client, $codes[0], 'recovery_code');

        $row = $this->twoFactorRow($username);
        $this->assertStringStartsWith('v1:', $row['secret']);
        $this->assertStringNotContainsString(kirjuri_base32_encode($secret), $row['secret']);
        $query = $this->server->pdo()->prepare('SELECT code_hash FROM two_factor_recovery_codes WHERE user_id = :id');
        $query->execute(array(':id' => $this->userId($username)));
        $hashes = $query->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(TWO_FACTOR_RECOVERY_CODES - 1, $hashes);
        foreach ($hashes as $hash) {
            $this->assertSame('2y', explode('$', $hash)[1], 'password_hash()');
        }

        // Neither the session files, the event log nor the audit entries hold the secret, a code or the password.
        $secrets = array_merge(array(kirjuri_base32_encode($secret), kirjuri_two_factor_readable_key($secret), self::PASSWORD), $codes, array_map(fn ($c) => str_replace('-', '', $c), $codes));
        $audited = 0;
        foreach ($this->server->eventLog() as $line) {
            if (strpos($line, $username) === false) {
                continue;
            }
            foreach ($secrets as $value) {
                $this->assertStringNotContainsString($value, $line);
            }
            if (preg_match('/;"(?:Two-factor|Wrong two-factor|Recovery code)[^"]*";[^;]*;[^;]*;(\d{10}_[A-Z0-9]+\.log);/', $line, $match)) {
                [$status, $entry] = $this->server->cli(array('audit:show', $match[1]));
                $this->assertSame(0, $status);
                $this->assertStringContainsString('"two_factor"', $entry);
                $this->assertStringContainsString('"account": "' . $username . '"', $entry);
                foreach ($secrets as $value) {
                    $this->assertStringNotContainsString($value, $entry);
                }
                $audited++;
            }
        }
        $this->assertGreaterThanOrEqual(3, $audited, 'Setup, the wrong code and the recovery code are audited.');
        foreach ($this->server->sessionFiles() as $session) {
            foreach ($secrets as $value) {
                $this->assertStringNotContainsString($value, $session);
            }
        }
    }

    public function testSettingUpNeedsThePasswordAndAWorkingCode(): void
    {
        $username = $this->uniqueName('setup');
        $this->createUser($username, self::PASSWORD, 1);
        $client = $this->login($username, self::PASSWORD);
        $elsewhere = $this->login($username, self::PASSWORD);
        $page = $client->get('two_factor.php?setup=1');
        $this->assertStringContainsString('<svg ', $page->body);
        $secret = $this->keyOnPage($page->body, 'two_factor_key');
        $this->assertSame($secret, $this->keyOnPage($client->get('two_factor.php?setup=1')->body, 'two_factor_key'), 'A reload shows the same key');

        $setup = fn (string $password, string $code) => $client->post('submit.php?type=two_factor_setup', array('token' => $this->token($client), 'current_password' => $password, 'two_factor_code' => $code));
        $this->assertSame('two_factor.php?setup=1', $setup('wrong-password', $this->code($secret))->location());
        $this->assertNull($this->twoFactorRow($username));
        $this->assertSame('two_factor.php?setup=1', $setup(self::PASSWORD, '000000' === $this->code($secret) ? '111111' : '000000')->location());
        $this->assertNull($this->twoFactorRow($username));
        $this->assertSame('two_factor.php', $setup(self::PASSWORD, $this->code($secret))->location());
        $this->assertNotNull($this->twoFactorRow($username));
        $this->assertCount(TWO_FACTOR_RECOVERY_CODES, $this->recoveryCodes($client->get('two_factor.php')->body));
        $this->assertCount(0, $this->recoveryCodes($client->get('two_factor.php')->body), 'Shown once');
        // Other sessions end; this one goes on.
        $this->assertSame('login.php', $elsewhere->get('index.php')->location());
        $this->assertSame(200, $client->get('index.php')->status);
        $this->assertLogged('Two-factor sign-in set up by ' . $username . '. Other sessions ended.');
    }

    public function testSettingUpOnANewPhoneReplacesTheSecretAndCodes(): void
    {
        [$username, $secret, $codes, $client] = $this->userWithTwoFactor();
        $page = $client->get('two_factor.php?setup=1');
        $newSecret = $this->keyOnPage($page->body, 'two_factor_key');
        $this->assertNotSame($secret, $newSecret);
        $response = $client->post('submit.php?type=two_factor_setup', array('token' => $this->token($client), 'current_password' => self::PASSWORD, 'two_factor_code' => $this->code($newSecret, 1)));
        $this->assertSame('two_factor.php', $response->location());
        $this->assertLogged('Two-factor sign-in set up again, on a new phone, by ' . $username . '.');
        $this->forgetUsedCodes($username);
        $this->assertSame('login.php', $this->enterCode($this->signInWithPassword($username), $this->code($secret))->location(), 'The old phone');
        $this->assertSame('login.php', $this->enterCode($this->signInWithPassword($username), $codes[0], 'recovery_code')->location(), 'The old codes');
        $this->assertSame('index.php', $this->enterCode($this->signInWithPassword($username), $this->code($newSecret))->location());
    }

    public function testNewRecoveryCodesReplaceTheOldOnes(): void
    {
        [$username, , $codes, $client] = $this->userWithTwoFactor();
        $this->assertSame('two_factor.php', $client->post('submit.php?type=two_factor_new_codes', array('token' => $this->token($client), 'current_password' => 'wrong-password'))->location());
        $this->assertShows($this->lang['bad_password'], $client->get('two_factor.php')->body);
        $client->post('submit.php?type=two_factor_new_codes', array('token' => $this->token($client), 'current_password' => self::PASSWORD));
        $newCodes = $this->recoveryCodes($client->get('two_factor.php')->body);
        $this->assertCount(TWO_FACTOR_RECOVERY_CODES, $newCodes);
        $this->assertSame(array(), array_intersect($codes, $newCodes));
        $this->assertSame('login.php', $this->enterCode($this->signInWithPassword($username), $codes[0], 'recovery_code')->location());
        $this->assertSame('two_factor.php', $this->enterCode($this->signInWithPassword($username), $newCodes[0], 'recovery_code')->location());
        $this->assertLogged('New recovery codes made by ' . $username);
    }

    public function testTurningItOffNeedsThePasswordAndIsRefusedWhenRequired(): void
    {
        $admin = $this->admin();
        [$username, , , $client] = $this->userWithTwoFactor();
        $elsewhere = $this->signInWithPassword($username);
        $off = fn (string $password) => $client->post('submit.php?type=two_factor_off', array('token' => $this->token($client), 'current_password' => $password));

        $this->saveSettings($admin, array('two_factor_required' => 'everyone'));
        try {
            $off(self::PASSWORD);
            $this->assertNotNull($this->twoFactorRow($username), 'Required for everyone');
            $this->assertShows($this->lang['two_factor_required_for_you'], $client->get('two_factor.php')->body);
        } finally {
            $admin->post('submit.php?type=reset_default_settings', array('token' => $this->token($admin)));
        }
        $off('wrong-password');
        $this->assertNotNull($this->twoFactorRow($username));
        $off(self::PASSWORD);
        $this->assertNull($this->twoFactorRow($username));
        $this->assertSame(0, $this->recoveryCodesLeft($username));
        $this->assertLogged('Two-factor sign-in turned off by ' . $username . '. Other sessions ended.');
        $this->login($username, self::PASSWORD);
        $this->assertSame('login.php', $this->enterCode($elsewhere, '123456')->location(), 'A sign-in waiting for its code does not get in either');
        $this->assertSame('login.php', $elsewhere->get('index.php')->location());
    }

    public function testRequiredTwoFactorIsSetUpAtSignIn(): void
    {
        $admin = $this->admin();
        $username = $this->uniqueName('required');
        $this->createUser($username, self::PASSWORD, 2);
        $this->saveSettings($admin, array('two_factor_required' => 'everyone'));
        try {
            $client = $this->signInWithPassword($username);
            $this->assertSame('login.php', $client->get('index.php')->location());
            $page = $client->get('login.php');
            $this->assertShows($this->lang['two_factor_setup_required'], $page->body);
            $this->assertStringContainsString('<svg ', $page->body);
            $secret = $this->keyOnPage($page->body, 'enrol_two_factor_key');
            $enrol = fn (string $code) => $client->post('submit.php?type=two_factor_enrol', array('token' => $this->pendingToken($client), 'two_factor_code' => $code));

            $this->assertSame('login.php', $enrol('000000' === $this->code($secret) ? '111111' : '000000')->location());
            $this->assertShows($this->lang['two_factor_wrong_code'], $client->get('login.php')->body);
            $this->assertNull($this->twoFactorRow($username));
            $this->assertSame('two_factor.php', $enrol($this->code($secret))->location());
            $this->assertCount(TWO_FACTOR_RECOVERY_CODES, $this->recoveryCodes($client->get('two_factor.php')->body));
            $this->assertSame(200, $client->get('index.php')->status);
            $this->assertLogged('Two-factor sign-in set up by ' . $username . ' at sign-in, as it is required.');

            // Once it is on, signing in asks for a code. Setting up at sign-in is never offered again: someone who
            // has only the password could otherwise put their own phone in place of the owner's.
            $row = $this->twoFactorRow($username);
            $other = $this->signInWithPassword($username);
            $page = $other->get('login.php');
            $this->assertStringContainsString('name="two_factor_code"', $page->body);
            $this->assertStringNotContainsString('two_factor_enrol', $page->body);
            $attack = new HttpClient($this->server->baseUrl);
            $this->signInWithPasswordOn($attack, $username);
            $attackSecret = kirjuri_two_factor_new_secret();
            $this->assertSame('login.php', $attack->post('submit.php?type=two_factor_enrol', array('token' => $this->pendingToken($attack), 'two_factor_code' => $this->code($attackSecret)))->location());
            $this->assertSame($row['secret'], $this->twoFactorRow($username)['secret']);
            $this->assertSame('login.php', $attack->get('index.php')->location());

            // The admin who changed the setting stayed signed in.
            $this->assertSame(200, $admin->get('index.php')->status);
        } finally {
            $admin->post('submit.php?type=reset_default_settings', array('token' => $this->token($admin)));
        }
    }

    public function testRequiringItForAdministratorsLeavesOtherUsersAlone(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->uniqueName('admin');
        $user = $this->uniqueName('user');
        $this->assertSame(0, $this->server->cli(array('user:create', $otherAdmin, $otherAdmin, '0'), self::PASSWORD . "\n")[0]);
        $this->createUser($user, self::PASSWORD, 1);
        $this->saveSettings($admin, array('two_factor_required' => 'admins'));
        try {
            $this->login($user, self::PASSWORD);
            $client = $this->signInWithPassword($otherAdmin);
            $this->assertStringContainsString('two_factor_enrol', $client->get('login.php')->body);
            $this->assertSame('login.php', $client->get('index.php')->location());
        } finally {
            $admin->post('submit.php?type=reset_default_settings', array('token' => $this->token($admin)));
        }
    }

    public function testASignInWaitingForItsCodeExpires(): void
    {
        [$username, $secret] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $client = $this->signInWithPassword($username);
        $token = $this->pendingToken($client);
        // Move the time the password was accepted back past the limit, in the stored session.
        $changed = 0;
        foreach (glob($this->server->dir . '/sessions/sess_*') as $file) {
            $session = file_get_contents($file);
            if (strpos($session, $token) !== false) {
                file_put_contents($file, preg_replace('/s:5:"since";i:\d+;/', 's:5:"since";i:' . (time() - TWO_FACTOR_PENDING_SECONDS - 5) . ';', $session, 1, $changed));
            }
        }
        $this->assertSame(1, $changed);
        $this->assertSame('login.php', $client->post('submit.php?type=two_factor_code', array('token' => $token, 'two_factor_code' => $this->code($secret)))->location());
        $page = $client->get('login.php');
        $this->assertShows($this->lang['two_factor_expired'], $page->body);
        $this->assertStringContainsString('name="password"', $page->body);
        $this->assertSame('login.php', $client->get('index.php')->location());
    }

    public function testCancellingGoesBackToThePassword(): void
    {
        [$username] = $this->userWithTwoFactor();
        $client = $this->signInWithPassword($username);
        $this->assertSame('login.php', $client->post('submit.php?type=two_factor_cancel', array('token' => $this->pendingToken($client)))->location());
        $this->assertStringContainsString('name="password"', $client->get('login.php')->body);
    }

    public function testAnAdministratorCanResetIt(): void
    {
        [$username, , , $client] = $this->userWithTwoFactor();
        $admin = $this->admin();
        $page = $admin->get('users.php?populate=' . $this->userId($username));
        $this->assertStringContainsString('name="reset_two_factor"', $page->body);
        $this->assertShows(sprintf($this->lang['two_factor_codes_left'], TWO_FACTOR_RECOVERY_CODES), $page->body);

        // Not by anyone else: saving users is for administrators.
        $user = $this->uniqueName('plain');
        $this->createUser($user, self::PASSWORD, 1);
        $plain = $this->login($user, self::PASSWORD);
        $this->resetTwoFactor($plain, $username, self::PASSWORD);
        $this->assertNotNull($this->twoFactorRow($username));

        $response = $this->resetTwoFactor($admin, $username, KirjuriServer::ADMIN_PASSWORD);
        $this->assertStringStartsWith('users.php?populate=', (string) $response->location());
        $this->assertNull($this->twoFactorRow($username));
        $this->assertSame(0, $this->recoveryCodesLeft($username));
        $this->assertSame('login.php', $client->get('index.php')->location(), 'Their sessions ended.');
        $this->assertLogged('Two-factor sign-in reset for ' . $username . ' by admin. Their sessions ended.');
        $this->login($username, self::PASSWORD);
        $this->assertStringNotContainsString('name="reset_two_factor"', $admin->get('users.php?populate=' . $this->userId($username))->body);
    }

    public function testDeletingAUserDeletesTheirTwoFactorSignIn(): void
    {
        [$username] = $this->userWithTwoFactor();
        $id = $this->userId($username);
        $admin = $this->admin();
        $admin->post('submit.php?type=create_user', array('delete_user' => 'delete') + $this->userForm($username, KirjuriServer::ADMIN_PASSWORD, $this->token($admin)));
        $this->assertSame('', $this->userId($username), 'Deleted');
        $count = $this->server->pdo()->prepare('SELECT (SELECT COUNT(*) FROM two_factor WHERE user_id = :id) + (SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = :id2)');
        $count->execute(array(':id' => $id, ':id2' => $id));
        $this->assertSame('0', $count->fetchColumn());
    }

    public function testRecoveryCodesStillWorkWhenTheKeyFileIsMissing(): void
    {
        [$username, $secret, $codes] = $this->userWithTwoFactor();
        $this->forgetUsedCodes($username);
        $key = $this->server->dir . '/conf/two_factor_key.php';
        rename($key, $key . '.moved');
        try {
            $this->expectLoggedError('can not be checked: conf/two_factor_key.php is missing');
            $client = $this->signInWithPassword($username);
            $this->assertSame('login.php', $this->enterCode($client, $this->code($secret))->location());
            $this->assertShows($this->lang['two_factor_unavailable'], $client->get('login.php')->body);
            [$status, $out] = $this->server->cli(array('doctor'));
            $this->assertSame(1, $status, 'doctor reports the missing key');
            $this->assertStringContainsString('[FAIL] Two-factor sign-in key conf/two_factor_key.php', $out);
            $this->assertSame('two_factor.php', $this->enterCode($client, $codes[0], 'recovery_code')->location());
        } finally {
            rename($key . '.moved', $key);
        }
    }

    public function testTheCommandLineResetsAndListsIt(): void
    {
        [$username, , , $client] = $this->userWithTwoFactor();
        [$status, $out] = $this->server->cli(array('user:list'));
        $this->assertSame(0, $status);
        $this->assertMatchesRegularExpression('/^\d+\s+' . preg_quote($username, '/') . '\s.*\bon\b/m', $out);

        [$status, $out] = $this->server->cli(array('user:two-factor-reset', $username));
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Two-factor sign-in is off for "' . $username . '"', $out);
        $this->assertNull($this->twoFactorRow($username));
        $this->assertSame('login.php', $client->get('index.php')->location(), 'Their sessions ended.');
        $this->assertLogged('Two-factor sign-in reset from the command line for ' . $username . '.');
        $this->login($username, self::PASSWORD);

        $this->assertStringContainsString('does not use two-factor sign-in', $this->server->cli(array('user:two-factor-reset', $username))[1]);
        $this->assertSame(1, $this->server->cli(array('user:two-factor-reset', 'nobody-' . generate_token(6)))[0]);
        $this->assertSame(1, $this->server->cli(array('user:two-factor-reset'))[0]);
    }

    public function testTheCommandLineWorksBeforeTheMigration(): void
    {
        // bin/kirjuri does not migrate by itself, so after an upgrade the two-factor tables may not exist yet.
        [$username] = $this->userWithTwoFactor();
        $pdo = $this->server->pdo();
        $pdo->exec('RENAME TABLE two_factor TO two_factor_moved');
        try {
            [$status, $out] = $this->server->cli(array('user:list'));
            $this->assertSame(0, $status);
            $this->assertMatchesRegularExpression('/^\d+\s+' . preg_quote($username, '/') . '\s.*\boff\b/m', $out);
            $this->assertSame(0, $this->server->cli(array('user:unlock', $username))[0]);
            [$status, , $err] = $this->server->cli(array('user:two-factor-reset', $username));
            $this->assertSame(1, $status);
            $this->assertStringContainsString('Run "php bin/kirjuri migrate" first', $err);
        } finally {
            $pdo->exec('RENAME TABLE two_factor_moved TO two_factor');
        }
        $this->assertNotNull($this->twoFactorRow($username));
    }

    public function testTheAnonymousSignInIsNotAsked(): void
    {
        $client = $this->client();
        $this->assertSame('index.php', $client->get('submit.php?type=anon_login')->location());
        $this->assertSame('index.php', $client->get('two_factor.php')->location(), 'It has no password to protect.');
    }

    /** A new user with two-factor sign-in set up from two_factor.php: username, secret, recovery codes, a signed-in client. */
    private function userWithTwoFactor(int $access = 1): array
    {
        $username = $this->uniqueName('tf');
        $this->createUser($username, self::PASSWORD, $access);
        $client = $this->login($username, self::PASSWORD);
        $page = $client->get('two_factor.php?setup=1');
        $secret = $this->keyOnPage($page->body, 'two_factor_key');
        $response = $client->post('submit.php?type=two_factor_setup', array('token' => $page->inputValue('token'), 'current_password' => self::PASSWORD, 'two_factor_code' => $this->code($secret)));
        $this->assertSame('two_factor.php', $response->location());
        $codes = $this->recoveryCodes($client->get('two_factor.php')->body);
        $this->assertCount(TWO_FACTOR_RECOVERY_CODES, $codes);
        return array($username, $secret, $codes, $client);
    }

    private function signInWithPassword(string $username): HttpClient
    {
        $client = $this->client();
        $this->signInWithPasswordOn($client, $username);
        return $client;
    }

    private function signInWithPasswordOn(HttpClient $client, string $username): void
    {
        $response = $client->post('submit.php?type=login', array('username' => $username, 'password' => self::PASSWORD, 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location());
    }

    /** The token of the second step's forms on the sign-in page. */
    private function pendingToken(HttpClient $client): string
    {
        $token = $client->get('login.php')->inputValue('token');
        $this->assertNotEmpty($token);
        return (string) $token;
    }

    private function enterCode(HttpClient $client, string $code, string $field = 'two_factor_code'): Response
    {
        return $client->post('submit.php?type=two_factor_code', array('token' => $this->pendingToken($client), $field => $code));
    }

    /** The code an authenticator app with $secret shows now, or $steps periods later. */
    private function code(string $secret, int $steps = 0): string
    {
        return kirjuri_hotp($secret, kirjuri_totp_step(time()) + $steps);
    }

    /** The secret behind the key shown for typing into an app. */
    private function keyOnPage(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/id="' . $id . '">([A-Z2-7 ]+)</', $html, $match), 'No key on the page');
        return (string) kirjuri_base32_decode($match[1]);
    }

    private function recoveryCodes(string $html): array
    {
        preg_match_all('#<li><code>([0-9a-z]{5}-[0-9a-z]{5})</code></li>#', $html, $matches);
        return $matches[1];
    }

    /**
     * Each code signs in once, so a test signing in again within the same 30 seconds would need the next code.
     * Tests that are not about that forget which codes were used.
     */
    private function forgetUsedCodes(string $username): void
    {
        $this->server->pdo()->prepare('UPDATE two_factor SET last_step = 0 WHERE user_id = :id')->execute(array(':id' => $this->userId($username)));
    }

    private function setWrongCodes(string $username, int $count): void
    {
        $this->server->pdo()->prepare('UPDATE two_factor SET wrong_codes = :count WHERE user_id = :id')->execute(array(':count' => $count, ':id' => $this->userId($username)));
    }

    private function userId(string $username): string
    {
        $query = $this->server->pdo()->prepare('SELECT id FROM users WHERE username = :username');
        $query->execute(array(':username' => $username));
        return (string) $query->fetchColumn();
    }

    private function twoFactorRow(string $username): ?array
    {
        $query = $this->server->pdo()->prepare('SELECT * FROM two_factor WHERE user_id = :id');
        $query->execute(array(':id' => $this->userId($username)));
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function recoveryCodesLeft(string $username): int
    {
        $query = $this->server->pdo()->prepare('SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = :id');
        $query->execute(array(':id' => $this->userId($username)));
        return (int) $query->fetchColumn();
    }

    /** The edit user form of users.php for $username, saved with $password, the password of whoever saves it. */
    private function userForm(string $username, string $password, string $token): array
    {
        return array('token' => $token, 'user_id' => $this->userId($username), 'username' => $username, 'name' => $username,
            'access' => '1', 'password' => '', 'current_password' => $password, 'flag1' => '', 'flag2' => '', 'flag3' => '', 'flag4' => '',
            'ip_whitelist' => '', 'ip_blacklist' => '', 'delete_user' => 'no');
    }

    private function resetTwoFactor(HttpClient $client, string $username, string $password): Response
    {
        return $client->post('submit.php?type=create_user', $this->userForm($username, $password, $this->token($client)) + array('reset_two_factor' => 'reset'));
    }

    private function saveSettings(HttpClient $admin, array $settings): void
    {
        $defaults = parse_ini_file(KIRJURI_ROOT . '/conf/settings.conf', true);
        $admin->post('submit.php?type=save_settings', array('token' => $this->token($admin),
            'settings' => $settings + $defaults['settings'], 'inv_units' => implode(', ', $defaults['inv_units'])));
    }

    private function assertShows(string $text, string $html): void
    {
        $this->assertStringContainsString(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
    }

    private function assertLogged(string $text): void
    {
        foreach ($this->server->eventLog() as $line) {
            if (strpos($line, $text) !== false) {
                $this->assertTrue(true);
                return;
            }
        }
        $this->fail('Not in the event log: ' . $text);
    }
}
