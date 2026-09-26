<?php

namespace Kirjuri\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticationTest extends IntegrationTestCase
{
    public function testAdminCanLogInAndOpenTheFrontPage(): void
    {
        $admin = $this->admin();
        $response = $admin->get('index.php');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Administrator', $response->body);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $response = $this->client()->post('submit.php?type=login', array('username' => 'admin', 'password' => 'wrong', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location());
    }

    public static function incompleteLogins(): array
    {
        return array(
            'no password' => array(array('username' => 'admin', 'auth_type' => 'local')),
            'no username' => array(array('password' => KirjuriServer::ADMIN_PASSWORD, 'auth_type' => 'local')),
            // The auth_type check used to be inside the username empty() call and never ran.
            'no auth type' => array(array('username' => 'admin', 'password' => KirjuriServer::ADMIN_PASSWORD)),
            'unknown auth type' => array(array('username' => 'admin', 'password' => KirjuriServer::ADMIN_PASSWORD, 'auth_type' => 'other')),
        );
    }

    #[DataProvider('incompleteLogins')]
    public function testIncompleteLoginIsRejected(array $fields): void
    {
        $client = $this->client();
        $this->assertSame('login.php', $client->post('submit.php?type=login', $fields)->location());
        $this->assertSame('login.php', $client->get('index.php')->location());
    }

    public function testRepeatedFailuresLockTheAccountTemporarily(): void
    {
        $username = $this->uniqueName('throttle');
        $this->createUser($username, 'correct-password', 1);

        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'));
        }
        $response = $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'correct-password', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location(), 'The correct password should be refused while throttled.');

        // Other accounts are not affected.
        $this->admin();
    }

    public function testParallelAttemptsDoNotGetPastTheThrottle(): void
    {
        // The count was checked before the password and written after it, without a lock, so attempts
        // sent at the same time were all checked and counted over each other.
        $username = $this->uniqueName('parallel');
        $this->createUser($username, 'correct-password', 1);
        $multi = curl_multi_init();
        $handles = array();
        for ($i = 0; $i < 3 * LOGIN_MAX_FAILURES; $i++) {
            $handle = curl_init($this->server->baseUrl . '/submit.php?type=login');
            curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query(array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'))));
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        $checked = array_filter($this->server->eventLog(), fn ($line) => strpos($line, 'Invalid login attempt: ' . $username) !== false);
        $this->assertCount(LOGIN_MAX_FAILURES, $checked, 'Only as many passwords are checked as the throttle allows.');
        $response = $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'correct-password', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location());
    }

    public function testSpellingsOfTheSameAccountShareTheThrottle(): void
    {
        // The database compares usernames without accents, so "ádmin" signs in as "admin". Each spelling
        // used to count its own failures, which multiplied the attempts allowed on one account.
        $username = $this->uniqueName('accent');
        $this->createUser($username, 'correct-password', 1);
        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'));
        }
        $variant = 'á' . substr($username, 1);
        $response = $this->client()->post('submit.php?type=login', array('username' => $variant, 'password' => 'correct-password', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location(), 'Another spelling of a throttled account is throttled too.');
    }

    public function testFailuresFromOneAddressAreLimitedAcrossAccounts(): void
    {
        // Trying a few passwords on each of many accounts stayed under the per-account limit.
        $username = $this->uniqueName('sprayed');
        $this->createUser($username, 'correct-password', 1);
        for ($i = 0; $i < LOGIN_MAX_FAILURES_PER_IP; $i++) {
            $this->client()->post('submit.php?type=login', array('username' => 'spray' . $i, 'password' => 'wrong', 'auth_type' => 'local'));
        }
        $response = $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'correct-password', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location(), 'Logins from the address are refused.');
        // A successful login does not reset the address's count; bin/kirjuri or the end of the window does.
        $this->assertSame(0, $this->server->cli(array('user:unlock', '--ip', '127.0.0.1'))[0]);
        $this->login($username, 'correct-password');
    }

    public function testSuccessfulLoginResetsTheFailureCount(): void
    {
        $username = $this->uniqueName('reset');
        $this->createUser($username, 'correct-password', 1);
        for ($i = 0; $i < LOGIN_MAX_FAILURES - 1; $i++) {
            $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'));
        }
        $this->login($username, 'correct-password');
        $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'));
        $this->login($username, 'correct-password');
    }

    public function testSessionIdChangesAtLoginAndCookieIsHttpOnly(): void
    {
        $client = $this->client();
        $before = $client->get('login.php');
        $this->assertMatchesRegularExpression('/^Set-Cookie: KirjuriSessionID=[^;]+;.*HttpOnly/mi', $before->headers);
        $this->assertMatchesRegularExpression('/SameSite=Lax/i', $before->headers);
        preg_match('/KirjuriSessionID=([^;]+)/', $before->headers, $old);

        $after = $client->post('submit.php?type=login', array('username' => 'admin', 'password' => KirjuriServer::ADMIN_PASSWORD, 'auth_type' => 'local'));
        $this->assertTrue((bool) preg_match('/KirjuriSessionID=([^;]+)/', $after->headers, $new), 'No new session cookie at login.');
        $this->assertNotSame($old[1], $new[1]);
    }

    public function testLogoutEndsTheSession(): void
    {
        $admin = $this->admin();
        $this->assertSame('login.php', $admin->post('submit.php?type=logout', array('token' => $this->token($admin)))->location());
        $this->assertSame('login.php', $admin->get('index.php')->location());
    }

    public function testLogoutLinkFromAnotherSiteDoesNothing(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $admin = $this->admin();
        $admin->get('submit.php?type=logout');
        $this->assertSame(200, $admin->get('index.php')->status, 'Still logged in.');
    }

    public function testTokensInTheUrlAreNotAccepted(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $recipient = $this->uniqueName('urltoken');
        $this->createUser($recipient, 'password1', 1);
        $admin = $this->admin();
        $admin->post('submit.php?type=send_message', array('token' => $this->token($admin), 'msgto' => $recipient, 'subject' => 'Keep me', 'body' => '<p>x</p>'));
        $id = $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$recipient'")->fetchColumn();

        $user = $this->login($recipient, 'password1');
        $user->get('messages.php?open=' . $id);
        // A token in the query string is ignored, even when it is the right one.
        $user->get("submit.php?type=archive_received&id=$id&token=" . $this->token($user));
        $this->assertSame('0', $this->server->pdo()->query("SELECT archived_to FROM messages WHERE id = $id")->fetchColumn());
        $this->assertStringNotContainsString('token=', $user->get('messages.php')->body, 'Pages must not put tokens in links.');
    }

    public function testAdminCanForceLogoutOtherSessions(): void
    {
        $username = $this->uniqueName('forced');
        $this->createUser($username, 'password1', 1);
        $user = $this->login($username, 'password1');
        $this->assertSame(200, $user->get('index.php')->status);

        $admin = $this->admin();
        $admin->post('submit.php?type=force_logout&user=' . $username, array('token' => $this->token($admin)));
        $this->assertSame('login.php', $user->get('index.php')->location());
    }

    public function testCsrfTokenIsRequiredForStateChanges(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $admin = $this->admin();
        $response = $admin->post('submit.php?type=add_tool', array('token' => 'forged', 'product_name' => 'Forged tool'));
        $this->assertNotSame('tools.php', $response->location());
        $count = $this->server->pdo()->query("SELECT COUNT(*) FROM tools WHERE product_name = 'Forged tool'")->fetchColumn();
        $this->assertSame('0', $count);
    }

    public function testWeakPasswordHashesAreUpgradedAtLogin(): void
    {
        $username = $this->uniqueName('rehash');
        $this->createUser($username, 'password1', 1);
        $pdo = $this->server->pdo();
        $pdo->prepare('UPDATE users SET password = :hash WHERE username = :username')
            ->execute(array(':hash' => password_hash('password1', PASSWORD_BCRYPT, array('cost' => 4)), ':username' => $username));
        $stored = fn () => $pdo->query("SELECT password FROM users WHERE username = '$username'")->fetchColumn();

        $this->login($username, 'password1');
        $this->assertFalse(password_needs_rehash($stored(), PASSWORD_DEFAULT), 'The hash is stored with the current settings.');
        $this->assertTrue(password_verify('password1', $stored()));
        $this->login($username, 'password1');
    }

    public function testApiKeysSurviveLogin(): void
    {
        $username = $this->uniqueName('apikey');
        $this->createUser($username, 'password1', 1, 'A');
        $pdo = $this->server->pdo();
        $pdo->prepare('UPDATE users SET password = :hash WHERE username = :username')
            ->execute(array(':hash' => password_hash('password1', PASSWORD_BCRYPT, array('cost' => 4)), ':username' => $username));
        $key = fn () => api_key_for($pdo->query("SELECT * FROM users WHERE username = '$username'")->fetch(\PDO::FETCH_ASSOC));
        $before = $key();

        $this->login($username, 'password1');
        // The key is derived from the password hash, so upgrading the hash would break the account's integrations.
        $this->assertSame($before, $key());
        $this->assertSame(200, $this->client()->get('api.php?operation=info&key=' . $before)->status);
    }

    public function testPasswordsAreNotKeptInTheSession(): void
    {
        // submit.php kept every submitted form in the session to refill it after an error, passwords included.
        $username = $this->uniqueName('cached');
        $password = $this->uniqueName('Created-');
        $this->createUser($username, $password, 1);
        $user = $this->login($username, $password);
        $rejected = $this->uniqueName('Rejected-');
        $user->post('submit.php?type=update_password', array('token' => $this->token($user), 'current_password' => 'wrong-password', 'new_password' => $rejected));
        $mistyped = $this->uniqueName('Mistyped-');
        $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => $mistyped, 'auth_type' => 'local'));

        $this->assertNotEmpty($this->server->sessionFiles());
        foreach ($this->server->sessionFiles() as $session) {
            foreach (array(KirjuriServer::ADMIN_PASSWORD, $password, $rejected, $mistyped) as $secret) {
                $this->assertStringNotContainsString($secret, $session);
            }
        }
    }

    public function testPasswordsAreUsedAsTyped(): void
    {
        // Web forms ran passwords through the HTML purifier, so "&" was hashed as "&amp;". The same password
        // set by the installer or bin/kirjuri, or checked against LDAP, did not match.
        $username = $this->uniqueName('typed');
        $password = 'Tom&Jerry<3-' . generate_token(4);
        $this->createUser($username, $password, 1);
        $stored = $this->server->pdo()->query("SELECT password FROM users WHERE username = '$username'")->fetchColumn();
        $this->assertTrue(password_verify($password, $stored));
        $this->login($username, $password);

        $this->assertSame(0, $this->server->cli(array('user:password', $username), $password . "\n")[0]);
        $this->login($username, $password);
    }

    public function testHashesOfPurifiedPasswordsStillMatch(): void
    {
        $pdo = $this->server->pdo();
        $password = 'Tom&Jerry-' . generate_token(4);
        $purified = str_replace('&', '&amp;', $password); // How the web forms used to store it.
        foreach (array('' => 'upgraded', 'A' => 'kept, as the API key derives from it') as $flags => $expected) {
            $username = $this->uniqueName('purified');
            $this->createUser($username, 'password1', 1, $flags);
            $old = password_hash($purified, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password = :hash WHERE username = :username')->execute(array(':hash' => $old, ':username' => $username));

            $this->login($username, $password);
            $stored = $pdo->query("SELECT password FROM users WHERE username = '$username'")->fetchColumn();
            if ($flags === '') {
                $this->assertTrue(password_verify($password, $stored), "The hash is $expected.");
            } else {
                $this->assertSame($old, $stored, "The hash is $expected.");
            }
            $this->login($username, $password);
        }
    }
}
