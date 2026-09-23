<?php

namespace Kirjuri\Tests\Integration;

final class CliTest extends IntegrationTestCase
{
    public function testHelpListsCommandsAndUnknownCommandsFail(): void
    {
        [$status, $out] = $this->server->cli(array('help'));
        $this->assertSame(0, $status);
        $this->assertStringContainsString('doctor', $out);
        $this->assertSame(1, $this->server->cli(array('no-such-command'))[0]);
    }

    public function testDoctorPassesOnAWorkingInstallation(): void
    {
        [$status, $out] = $this->server->cli(array('doctor'));
        $this->assertSame(0, $status, $out);
        $this->assertStringContainsString('[ OK ] Connected to the database', $out);
        $this->assertStringContainsString('[ OK ] Database schema is up to date', $out);
        $this->assertStringNotContainsString('[FAIL]', $out);
    }

    public function testDoctorReportsABrokenDatabaseConnection(): void
    {
        $credentials = $this->server->dir . '/conf/mysql_credentials.php';
        $original = file_get_contents($credentials);
        $config = include $credentials;
        $config['mysql_password'] = 'wrong-password';
        file_put_contents($credentials, '<?php return ' . var_export($config, true) . ';');
        try {
            [$status, $out] = $this->server->cli(array('doctor'));
        } finally {
            file_put_contents($credentials, $original);
        }
        $this->assertSame(1, $status);
        $this->assertStringContainsString('[FAIL] Can not connect to the database', $out);
    }

    public function testMigrateStatus(): void
    {
        [$status, $out] = $this->server->cli(array('migrate', '--status'));
        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('[pending]', $out);
        $this->assertSame(0, $this->server->cli(array('migrate'))[0]);
    }

    public function testUserCreateAndPasswordReset(): void
    {
        $username = $this->uniqueName('cliuser');
        [$status, $out, $err] = $this->server->cli(array('user:create', $username, 'Cli User', '1', '--api'), "first-password\n");
        $this->assertSame(0, $status, $err);
        $this->login($username, 'first-password');
        $this->assertSame('A', $this->server->pdo()->query("SELECT flags FROM users WHERE username = '$username'")->fetchColumn());

        [$status] = $this->server->cli(array('user:create', $username, 'Again', '1'), "other-password\n");
        $this->assertSame(1, $status, 'Existing users are not overwritten.');

        [$status, , $err] = $this->server->cli(array('user:password', $username), "short\n");
        $this->assertSame(1, $status);
        $this->assertStringContainsString('at least 8 characters', $err);

        $this->assertSame(0, $this->server->cli(array('user:password', $username), "second-password\n")[0]);
        $this->login($username, 'second-password');

        [$status, $out] = $this->server->cli(array('user:list'));
        $this->assertStringContainsString($username, $out);
        $this->assertStringNotContainsString('$2y$', $out, 'Password hashes are never printed.');
    }

    public function testPasswordResetAndUnlockClearTheLoginThrottle(): void
    {
        $username = $this->uniqueName('locked');
        $this->server->cli(array('user:create', $username, 'Locked', '1'), "right-password\n");
        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'wrong', 'auth_type' => 'local'));
        }
        $this->assertSame('login.php', $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'right-password', 'auth_type' => 'local'))->location());

        $this->assertSame(0, $this->server->cli(array('user:unlock', $username))[0]);
        $this->login($username, 'right-password');
    }

    public function testErrorsCommandFindsARequestById(): void
    {
        $this->expectLoggedError('Uncaught PDOException');
        $this->expectLoggedError('Base table or view not found');
        $pdo = $this->server->pdo();
        $pdo->exec('RENAME TABLE tools TO tools_hidden');
        try {
            $id = $this->client()->get('login.php')->header('X-Request-Id');
        } finally {
            $pdo->exec('RENAME TABLE tools_hidden TO tools');
        }
        [$status, $out] = $this->server->cli(array('errors', '--id', $id));
        $this->assertSame(0, $status);
        $this->assertStringContainsString('request ' . $id, $out);
        $this->assertStringContainsString('PDOException', $out);
        $this->assertStringContainsString('login.php', $out);

        $this->assertSame(1, $this->server->cli(array('errors', '--id', 'ffffffffffff'))[0]);
    }

    public function testAuditShowDecryptsAnEntry(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Audited ');
        $this->createCase($admin, $name);
        $files = glob($this->server->dir . '/logs/audit/*/*.log');
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        [$status, $out, $err] = $this->server->cli(array('audit:show', basename($files[0])));
        $this->assertSame(0, $status, $err);
        $this->assertStringContainsString('"request_contents"', $out);
        $this->assertStringContainsString($name, $out);
        $this->assertSame(1, $this->server->cli(array('audit:show', '../../conf/mysql_credentials.php'))[0]);
    }

    public function testCacheClearKeepsSessions(): void
    {
        $admin = $this->admin();
        $admin->get('index.php');
        [$status] = $this->server->cli(array('cache:clear'));
        $this->assertSame(0, $status);
        $this->assertSame(200, $admin->get('index.php')->status, 'Logged in users stay logged in.');
    }

    public function testLogCommandShowsCaseEvents(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Logged '));
        [$status, $out] = $this->server->cli(array('log', '--case', (string) $caseId));
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Added examination request', $out);
    }
}
