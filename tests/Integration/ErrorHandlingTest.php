<?php

namespace Kirjuri\Tests\Integration;

final class ErrorHandlingTest extends IntegrationTestCase
{
    private function errorLog(): array
    {
        $file = $this->server->dir . '/logs/error.log';
        return file_exists($file) ? array_map(fn ($line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : array();
    }

    /** Break every page by hiding a table that the bootstrap reads, run $test, then restore it. */
    private function withBrokenDatabase(callable $test): void
    {
        $pdo = $this->server->pdo();
        $pdo->exec('RENAME TABLE tools TO tools_hidden');
        try {
            $test();
        } finally {
            $pdo->exec('RENAME TABLE tools_hidden TO tools');
        }
    }

    public function testEveryResponseCarriesARequestId(): void
    {
        $first = $this->client()->get('login.php')->header('X-Request-Id');
        $second = $this->client()->get('login.php')->header('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function testUncaughtExceptionShowsAReferenceAndLogsTheDetails(): void
    {
        $this->expectLoggedError('Uncaught PDOException');
        $this->expectLoggedError('Base table or view not found');
        $this->withBrokenDatabase(function () {
            $response = $this->client()->get('login.php');
            $this->assertSame(500, $response->status);
            $id = $response->header('X-Request-Id');
            $this->assertStringContainsString('<code id="request-id">' . $id . '</code>', $response->body);
            $this->assertStringNotContainsString('SQLSTATE', $response->body, 'Database details must not reach anonymous visitors.');
            $this->assertStringContainsString('bin/kirjuri migrate', $response->body, 'A missing table should point at the migrate command.');

            $entries = array_values(array_filter($this->errorLog(), fn ($e) => $e['request_id'] === $id));
            $this->assertCount(1, $entries);
            $this->assertSame('PDOException', $entries[0]['type']);
            $this->assertStringContainsString('tools', $entries[0]['message']);
            $this->assertNotEmpty($entries[0]['trace']);
            $this->assertSame('/login.php', $entries[0]['uri']);
        });
    }

    public function testFatalErrorsDoNotStoreRequestDataInTheSession(): void
    {
        // The error page ends the request inside a shutdown function, which skipped the later one that
        // keeps the user list and language strings out of the session file.
        $this->expectLoggedError('Allowed memory size');
        $admin = $this->admin();
        $page = $this->server->dir . '/fatal_error_test.php';
        file_put_contents($page, "<?php require './include_functions.php'; ini_set('memory_limit', '48M'); \$x = str_repeat('x', 96 * 1024 * 1024);");
        try {
            $response = $admin->get('fatal_error_test.php');
            $this->assertSame(500, $response->status);
            $this->assertStringContainsString('request-id', $response->body);
            $this->assertStringNotContainsString('Allowed memory size', $response->body, 'PHP\'s own message, with the file path, is not shown.');
        } finally {
            unlink($page);
        }
        foreach ($this->server->sessionFiles() as $session) {
            $this->assertStringNotContainsString('all_users', $session);
            $this->assertStringNotContainsString('lang|', $session);
        }
    }

    public function testAdminsSeeTheErrorWhenShowErrorsIsOn(): void
    {
        $this->expectLoggedError('Uncaught PDOException');
        $this->expectLoggedError('Base table or view not found');
        $admin = $this->admin();
        $this->withBrokenDatabase(function () use ($admin) {
            $response = $admin->get('index.php');
            $this->assertSame(500, $response->status);
            $this->assertStringContainsString('PDOException', $response->body);
        });
    }
}
