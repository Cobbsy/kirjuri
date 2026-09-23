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

    public function testInstallWithoutABrowser(): void
    {
        $dir = sys_get_temp_dir() . '/kirjuri_cli_install_' . generate_token(8);
        $database = 'kirjuritestcli' . generate_token(8);
        mkdir($dir);
        foreach (array('bin', 'lib', 'conf') as $folder) {
            exec('cp -r ' . escapeshellarg(KIRJURI_ROOT . '/' . $folder) . ' ' . escapeshellarg($dir . '/'));
        }
        foreach (array('mysql_credentials.php', 'audit_credentials.php', 'settings.local') as $file) {
            @unlink($dir . '/conf/' . $file);
        }
        mkdir($dir . '/logs');
        mkdir($dir . '/cache');
        $run = function (array $env) use ($dir) {
            $process = proc_open(array(PHP_BINARY, 'bin/kirjuri', 'install'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir, array_merge(getenv(), $env));
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return array(proc_close($process), $out);
        };
        $env = array(
            'KIRJURI_DB_HOST' => getenv('KIRJURI_TEST_DB_HOST'),
            'KIRJURI_DB_USER' => getenv('KIRJURI_TEST_DB_USER') ?: 'root',
            'KIRJURI_DB_PASSWORD' => getenv('KIRJURI_TEST_DB_PASSWORD') ?: '',
            'KIRJURI_DB_NAME' => $database,
        );
        try {
            [$status, $out] = $run($env + array('KIRJURI_ADMIN_PASSWORD' => 'short'));
            $this->assertSame(1, $status, 'Admin passwords shorter than 8 characters are refused.');
            $this->assertFileDoesNotExist($dir . '/conf/mysql_credentials.php');

            [$status, $out] = $run($env + array('KIRJURI_ADMIN_PASSWORD' => 'long-enough-password'));
            $this->assertSame(0, $status, $out);
            $this->assertFileExists($dir . '/conf/mysql_credentials.php');
            $pdo = new \PDO('mysql:host=' . $env['KIRJURI_DB_HOST'] . ';dbname=' . $database, $env['KIRJURI_DB_USER'], $env['KIRJURI_DB_PASSWORD']);
            $admin = $pdo->query("SELECT password FROM users WHERE id = 2 AND username = 'admin'")->fetchColumn();
            $this->assertTrue(password_verify('long-enough-password', $admin));
            $this->assertSame(count(kirjuri_migrations()), (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());

            [$status, $out] = $run($env + array('KIRJURI_ADMIN_PASSWORD' => 'another-password'));
            $this->assertSame(1, $status, 'A second install is refused.');
            $this->assertStringContainsString('already installed', $out);
        } finally {
            (new \PDO('mysql:host=' . $env['KIRJURI_DB_HOST'], $env['KIRJURI_DB_USER'], $env['KIRJURI_DB_PASSWORD']))->exec('DROP DATABASE IF EXISTS `' . $database . '`');
            delete_directory($dir);
        }
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
