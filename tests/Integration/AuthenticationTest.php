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
}
