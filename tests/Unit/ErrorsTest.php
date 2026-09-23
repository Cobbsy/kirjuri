<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/kirjuri_error_' . generate_token(8) . '.log';
        putenv('KIRJURI_ERROR_LOG=' . $this->logFile);
    }

    protected function tearDown(): void
    {
        putenv('KIRJURI_ERROR_LOG');
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testRequestIdIsStableWithinARequest(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', kirjuri_request_id());
        $this->assertSame(kirjuri_request_id(), kirjuri_request_id());
    }

    public function testErrorsAreLoggedAsJsonLines(): void
    {
        kirjuri_log_error('TestError', "first\nmessage", '/some/file.php', 12, array('#0 frame'));
        kirjuri_log_error('TestError', 'second', '/some/file.php', 13);

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines, 'One line per error, even when the message has newlines.');
        $entry = json_decode($lines[0], true);
        $this->assertSame(kirjuri_request_id(), $entry['request_id']);
        $this->assertSame('TestError', $entry['type']);
        $this->assertSame("first\nmessage", $entry['message']);
        $this->assertSame(12, $entry['line']);
        $this->assertSame(array('#0 frame'), $entry['trace']);
    }

    public function testTraceLinesDoNotIncludeArguments(): void
    {
        $secret = 'hunter2-password';
        $exception = (function ($password) {
            return new \RuntimeException('boom');
        })($secret);
        $this->assertStringNotContainsString($secret, implode("\n", kirjuri_trace_lines($exception)));
    }

    public function testHintsForCommonDatabaseProblems(): void
    {
        $missingTable = new \PDOException('Base table or view not found');
        $this->setCode($missingTable, '42S02');
        $this->assertStringContainsString('bin/kirjuri migrate', kirjuri_error_hint($missingTable));

        $refused = new \PDOException('Connection refused');
        $this->setCode($refused, '2002');
        $this->assertStringContainsString('doctor', kirjuri_error_hint($refused));

        $this->assertSame('', kirjuri_error_hint(new \RuntimeException('other')));
    }

    private function setCode(\Exception $exception, string $code): void
    {
        $property = new \ReflectionProperty(\Exception::class, 'code');
        $property->setValue($exception, $code);
    }
}
