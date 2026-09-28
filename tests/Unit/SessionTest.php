<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        require_once KIRJURI_ROOT . '/lib/session.php';
        $_SERVER['HTTP_HOST'] = 'kirjuri.example:8080';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']);
    }

    public static function referers(): array
    {
        return array(
            'same site' => array('http://kirjuri.example:8080/edit_request.php?case=5&tab=devices', '/edit_request.php?case=5&tab=devices'),
            'host case differs' => array('https://KIRJURI.example:8080/index.php', '/index.php'),
            'missing' => array(null, 'fallback.php'),
            'other site' => array('https://evil.example/phish', 'fallback.php'),
            'other port' => array('http://kirjuri.example:9090/index.php', 'fallback.php'),
            'look-alike host' => array('http://kirjuri.example:8080.evil.example/index.php', 'fallback.php'),
            'protocol relative path' => array('http://kirjuri.example:8080//evil.example/x', 'fallback.php'),
            'backslash path' => array('http://kirjuri.example:8080/\\evil.example/x', 'fallback.php'),
            'relative' => array('index.php', 'fallback.php'),
            'garbage' => array('http:///', 'fallback.php'),
        );
    }

    #[DataProvider('referers')]
    public function testSafeRefererOnlyReturnsPathsOnThisSite(?string $referer, string $expected): void
    {
        if ($referer !== null) {
            $_SERVER['HTTP_REFERER'] = $referer;
        }
        $this->assertSame($expected, kirjuri_safe_referer('fallback.php'));
    }
}
