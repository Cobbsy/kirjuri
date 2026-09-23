<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private string $previousDir;
    private string $workDir;

    protected function setUp(): void
    {
        // The throttle stores its state under cache/ relative to the working directory.
        $this->previousDir = getcwd();
        $this->workDir = sys_get_temp_dir() . '/kirjuri_throttle_' . generate_token(8);
        mkdir($this->workDir . '/cache', 0777, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->previousDir);
        delete_directory($this->workDir);
    }

    public function testThrottlesAfterMaximumFailures(): void
    {
        for ($i = 1; $i < LOGIN_MAX_FAILURES; $i++) {
            login_throttle_record_failure('bob');
        }
        $this->assertFalse(login_throttled('bob'));

        login_throttle_record_failure('bob');
        $this->assertTrue(login_throttled('bob'));
    }

    public function testUsernamesAreTrackedSeparatelyAndCaseInsensitively(): void
    {
        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            login_throttle_record_failure('Bob');
        }
        $this->assertTrue(login_throttled('bob'));
        $this->assertFalse(login_throttled('alice'));
    }

    public function testClearResetsTheCounter(): void
    {
        for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
            login_throttle_record_failure('bob');
        }
        login_throttle_clear('bob');
        $this->assertFalse(login_throttled('bob'));
    }

    public function testFailuresExpireAfterTheWindow(): void
    {
        $stale = array('failures' => LOGIN_MAX_FAILURES, 'last_failure' => time() - LOGIN_FAILURE_WINDOW - 1);
        file_put_contents(login_throttle_file('bob'), json_encode($stale));
        $this->assertFalse(login_throttled('bob'));

        login_throttle_record_failure('bob');
        $this->assertFalse(login_throttled('bob'), 'An expired window starts counting from zero.');
    }

    public function testStateFileNameDoesNotContainTheUsername(): void
    {
        $this->assertStringNotContainsString('..', login_throttle_file('../../etc/passwd'));
        $this->assertMatchesRegularExpression('#^cache/login_throttle/[0-9a-f]{64}\.json$#', login_throttle_file('../../etc/passwd'));
    }
}
