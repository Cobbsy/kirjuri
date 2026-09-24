<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testFilterNumbersKeepsOnlyDigits(): void
    {
        $this->assertSame('123', filter_numbers('1a2;3 OR x'));
        $this->assertSame('', filter_numbers(null));
        $this->assertSame('42', filter_numbers(42));
    }

    public function testFilterLettersAndNumbers(): void
    {
        $this->assertSame('abc_123', filter_letters_and_numbers('abc_123'));
        $this->assertSame('etcpasswd', filter_letters_and_numbers('../etc/passwd'));
        $this->assertSame('', filter_letters_and_numbers(null));
    }

    public function testFilterUsernameLowercasesAndStripsPathAndQuoteCharacters(): void
    {
        $this->assertSame('admin', filter_username('  Admin '));
        $this->assertSame('..etcpasswd', filter_username('../etc/passwd'));
        $this->assertSame('bobx', filter_username('bob"<x>'));
    }

    public static function ipRanges(): array
    {
        return array(
            'exact match' => array('10.0.0.5', '10.0.0.5', true),
            'exact mismatch' => array('10.0.0.6', '10.0.0.5', false),
            'inside /24' => array('192.168.1.200', '192.168.1.0/24', true),
            'outside /24' => array('192.168.2.1', '192.168.1.0/24', false),
            '/0 matches everything' => array('8.8.8.8', '0.0.0.0/0', true),
            'ipv6 is never matched' => array('::1', '127.0.0.1', false),
            'invalid range' => array('10.0.0.1', 'not-an-ip', false),
            'empty netmask is not /0' => array('8.8.8.8', '10.0.0.1/', false),
            'non-numeric netmask' => array('10.0.0.1', '10.0.0.1/x', false),
            'netmask over 32' => array('10.0.0.1', '10.0.0.1/33', false),
            'invalid address' => array('garbage', '10.0.0.0/8', false),
        );
    }

    #[DataProvider('ipRanges')]
    public function testIpInRange(string $ip, string $range, bool $expected): void
    {
        $this->assertSame($expected, ip_in_range($ip, $range));
    }

    public function testParseIpListAcceptsAddressesAndRanges(): void
    {
        $this->assertSame(array('10.0.0.1', '10.1.0.0/16', '0.0.0.0/0'), kirjuri_parse_ip_list(' 10.0.0.1, ,10.1.0.0/16,0.0.0.0/0 '));
        $this->assertSame(array(''), kirjuri_parse_ip_list(''));
        $this->assertSame(array('10.0.0.1'), kirjuri_parse_ip_list(',10.0.0.1'), 'A leading empty entry used to disable the allow list.');
    }

    public static function invalidIpEntries(): array
    {
        return array(
            'empty netmask' => array('10.0.0.1/'),
            'netmask over 32' => array('10.0.0.0/33'),
            'signed netmask' => array('10.0.0.0/-1'),
            'not an address' => array('10.0.0'),
            'hostname' => array('intranet.example'),
            'ipv6' => array('::1'),
        );
    }

    #[DataProvider('invalidIpEntries')]
    public function testParseIpListRejectsInvalidEntries(string $entry): void
    {
        try {
            kirjuri_parse_ip_list('10.0.0.1, ' . $entry);
            $this->fail('Accepted ' . $entry);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($entry, $e->getMessage());
        }
    }

    public function testGenerateTokenIsRandomHexOfRequestedLength(): void
    {
        foreach (array(4, 15, 16, 64) as $length) {
            $token = generate_token($length);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{' . $length . '}$/', $token);
        }
        $this->assertNotSame(generate_token(16), generate_token(16));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $key = generate_token(64);
        $plain = json_encode(array('request' => 'contents', 'unicode' => 'äöå'));
        $first = encrypt($plain, $key);
        $second = encrypt($plain, $key);

        $this->assertNotSame($plain, $first);
        $this->assertNotSame($first, $second, 'Each encryption should use a fresh IV.');
        $this->assertSame($plain, decrypt($first, $key));
        $this->assertSame($plain, decrypt($second, $key));
    }

    public function testIniValueCannotBreakOutOfQuotes(): void
    {
        $value = ini_value("Title \"quoted\"\r\ninjected = \"1\"");
        $this->assertStringNotContainsString('"', $value);
        $this->assertStringNotContainsString("\n", $value);

        $parsed = parse_ini_string('[settings]' . "\n" . 'title = "' . $value . '"' . "\n", true);
        $this->assertSame(array('title'), array_keys($parsed['settings']));
    }

    public function testArrayTrimRemovesOnlyEmptyStringsAndNulls(): void
    {
        $this->assertSame(array('a' => '1', 'c' => '0'), array_trim(array('a' => '1', 'b' => '', 'c' => '0', 'd' => null)));
    }

    public function testSecondsToTime(): void
    {
        $this->assertSame('1 days, 1 hours, 1 minutes and 1 seconds', seconds_to_time(90061));
    }

    public function testApiKeyDependsOnUsernameAndPasswordHash(): void
    {
        $user = array('username' => 'bob', 'password' => 'hash1');
        $this->assertSame(sha1('bobhash1'), api_key_for($user));
        $this->assertNotSame(api_key_for($user), api_key_for(array('username' => 'bob', 'password' => 'hash2')));
    }

    public function testDeleteDirectoryRemovesNestedTree(): void
    {
        $dir = sys_get_temp_dir() . '/kirjuri_test_' . generate_token(8);
        mkdir($dir . '/a/b', 0777, true);
        file_put_contents($dir . '/a/b/file.txt', 'x');
        file_put_contents($dir . '/top.txt', 'x');

        $this->assertTrue(delete_directory($dir));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertTrue(delete_directory($dir), 'Deleting a missing directory is not an error.');
    }

    public function testDeleteDirectoryRemovesSymlinksWithoutFollowingThem(): void
    {
        $base = sys_get_temp_dir() . '/kirjuri_test_' . generate_token(8);
        mkdir($base . '/outside', 0777, true);
        file_put_contents($base . '/outside/keep.txt', 'x');
        mkdir($base . '/target');
        symlink($base . '/outside', $base . '/target/link');
        symlink($base . '/missing', $base . '/target/dangling');

        $this->assertTrue(delete_directory($base . '/target'));
        $this->assertDirectoryDoesNotExist($base . '/target');
        $this->assertFileExists($base . '/outside/keep.txt', 'The symlink target must not be deleted.');
        delete_directory($base);
    }
}
