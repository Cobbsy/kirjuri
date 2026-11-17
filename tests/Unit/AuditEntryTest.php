<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The audit log's entry format, its key and its helpers, without a database. */
final class AuditEntryTest extends TestCase
{
    private const FILE = '1790201586_AE66.log';
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kirjuri_audit_entry_' . generate_token(8);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        delete_directory($this->dir);
    }

    private function payload(): array
    {
        return array(
            'entry' => 7,
            'time' => '2026-11-17T10:22:31+02:00',
            'case' => 12,
            'event' => array('level' => 'Update', 'description' => 'Changed device UID13 location to Evidence locker 3.'),
            'user' => array('username' => 'examiner'),
            'request_contents' => array('device_location' => 'Evidence locker 3'),
        );
    }

    public function testSealedEntryOpensWithItsKeyAndPlace(): void
    {
        $previous = str_repeat('a', 64);
        $contents = kirjuri_audit_seal_entry(7, $previous, self::FILE, $this->payload(), self::KEY);

        $this->assertStringStartsWith('KIRJURI-AUDIT 2 7 ' . $previous . ' v1:', $contents);
        $this->assertStringEndsWith("\n", $contents);
        $this->assertSame(1, substr_count($contents, "\n"), 'An entry is one line.');
        $opened = kirjuri_audit_open_entry($contents, self::FILE, self::KEY);
        $this->assertSame('chain', $opened['format']);
        $this->assertSame(7, $opened['number']);
        $this->assertSame($previous, $opened['previous']);
        $this->assertSame($this->payload(), $opened['payload']);
    }

    public function testSealedEntryOpensNowhereElse(): void
    {
        $previous = str_repeat('a', 64);
        $contents = kirjuri_audit_seal_entry(7, $previous, self::FILE, $this->payload(), self::KEY);

        $this->assertNull(kirjuri_audit_open_entry($contents, self::FILE, str_repeat('f', 64))['payload'], 'Another key.');
        $this->assertNull(kirjuri_audit_open_entry($contents, '1790201586_AE67.log', self::KEY)['payload'], 'Another file name.');
        $renumbered = str_replace('KIRJURI-AUDIT 2 7 ', 'KIRJURI-AUDIT 2 8 ', $contents);
        $this->assertSame(8, kirjuri_audit_open_entry($renumbered, self::FILE, self::KEY)['number']);
        $this->assertNull(kirjuri_audit_open_entry($renumbered, self::FILE, self::KEY)['payload'], 'Another number.');
        $relinked = str_replace($previous, str_repeat('b', 64), $contents);
        $this->assertNull(kirjuri_audit_open_entry($relinked, self::FILE, self::KEY)['payload'], 'Another previous fingerprint.');
        $this->assertNull(kirjuri_audit_open_entry($contents, self::FILE, null)['payload'], 'No key.');
    }

    public function testKeyUsesAllOfTheKeyString(): void
    {
        // The old encrypt() base64-encoded the key and OpenSSL cut that to 32 characters: 24 hex characters of the key.
        $a = str_repeat('1', 24) . str_repeat('a', 40);
        $b = str_repeat('1', 24) . str_repeat('b', 40);

        $this->assertSame(32, strlen(kirjuri_audit_entry_key($a)));
        $this->assertNotSame(kirjuri_audit_entry_key($a), kirjuri_audit_entry_key($b));
    }

    public function testKeyFileIsReadAsText(): void
    {
        $file = $this->dir . '/audit_credentials.php';
        foreach (array('<?php return "K" ?>', "<?php return 'K';\r\n", "\xEF\xBB\xBF<?php return \"K\";\n") as $contents) {
            file_put_contents($file, $contents);
            $this->assertSame('K', kirjuri_audit_key_string($file), json_encode($contents));
        }

        file_put_contents($file, '<?php echo "x";');
        $this->assertNull(kirjuri_audit_key_string($file));
        $this->assertNull(kirjuri_audit_key_string($this->dir . '/missing.php'));

        file_put_contents($file, '<?php function kirjuri_test_ran() {} return "K";');
        $this->assertSame('K', kirjuri_audit_key_string($file));
        $this->assertFalse(function_exists('kirjuri_test_ran'), 'The key file is read, never run.');
    }

    public function testKeyIsCreatedOnce(): void
    {
        $file = $this->dir . '/audit_credentials.php';

        $this->assertTrue(kirjuri_audit_create_key($file));
        $contents = file_get_contents($file);
        $this->assertMatchesRegularExpression('/^<\?php return "[0-9a-f]{64}";\n$/', $contents);
        $this->assertFalse(kirjuri_audit_create_key($file));
        $this->assertSame($contents, file_get_contents($file), 'An existing key is never replaced.');
        $this->assertSame(substr($contents, 14, 64), kirjuri_audit_key_string($file));
    }

    public function testOldEntriesStillOpen(): void
    {
        $json = json_encode(array('user' => array('username' => 'old'), 'request_contents' => array('case_name' => 'Old case')));

        $old = kirjuri_audit_open_entry(encrypt($json, self::KEY), self::FILE, self::KEY);
        $this->assertSame('legacy', $old['format']);
        $this->assertNull($old['number']);
        $this->assertSame('Old case', $old['payload']['request_contents']['case_name']);

        $plain = kirjuri_audit_open_entry('{"user":{}}', self::FILE, null);
        $this->assertSame('plain', $plain['format']);
        $this->assertSame(array('user' => array()), $plain['payload']);

        $this->assertNull(kirjuri_audit_open_entry(encrypt($json, self::KEY), self::FILE, str_repeat('f', 64))['payload']);
    }

    public function testPayloadWithInvalidUtf8SealsAndOpens(): void
    {
        $payload = $this->payload();
        $payload['request_contents'] = array('note' => "caf\xe9");

        $contents = kirjuri_audit_seal_entry(7, str_repeat('a', 64), self::FILE, $payload, self::KEY);
        $this->assertSame("caf\u{FFFD}", kirjuri_audit_open_entry($contents, self::FILE, self::KEY)['payload']['request_contents']['note']);
    }

    public function testPasswordsAreRemovedFromRequests(): void
    {
        $this->assertSame(array('access' => array('a' => 'b'), 'case_name' => 'c'), kirjuri_audit_clean_request(array(
            'password' => 'x', 'new_password' => 'y', 'Current_Password' => 'z',
            'access' => array('password2' => 'w', 'a' => 'b'), 'case_name' => 'c')));
    }

    public function testCheckpointText(): void
    {
        $this->assertSame('9f3c 1a2b 77de 0c41 5e9a', kirjuri_audit_short_fingerprint('9f3c1a2b77de0c415e9a' . str_repeat('0', 44)));
        $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c415e9a'), kirjuri_audit_parse_checkpoint('48213:9f3c 1a2b 77de 0c41 5e9a'));
        $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c41'), kirjuri_audit_parse_checkpoint('entry 48213, 17.11.2026 10:22:31, 9F3C 1A2B 77DE 0C41'));
        $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c415e9a'),
            kirjuri_audit_parse_checkpoint('Checkpoint: entry 48213, 17.11.2026 10:22:31, 9f3c 1a2b 77de 0c41 5e9a'));
        // A line pasted from a printed case report, in each language: the fingerprint is not after the last comma.
        foreach (array('EN', 'DE', 'ES', 'FI', 'PT_BR') as $code) {
            $lang = json_decode(file_get_contents(KIRJURI_ROOT . '/conf/lang_' . $code . '.JSON'), true);
            $line = sprintf($lang['audit_report_checkpoint'], 48213, '17.11.2026 10:22', '9f3c 1a2b 77de 0c41 5e9a');
            $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c415e9a'), kirjuri_audit_parse_checkpoint($line), $line);
        }
        foreach (array('48213:9f3c1a2b', 'abc', '', '48213:9f3c1a2b77de0c41zz') as $text) {
            $this->assertNull(kirjuri_audit_parse_checkpoint($text), $text);
        }
    }

    public function testOnlyEntryNamesAreEntryFiles(): void
    {
        foreach (array('179020/1790201586_AE66.log', '.gitignore', '179020/notes.txt', '179020/1790201586_AE66.log~', 'other/1790201586_AE66.log') as $path) {
            @mkdir(dirname($this->dir . '/' . $path));
            file_put_contents($this->dir . '/' . $path, 'x');
        }

        $this->assertSame(array('1790201586_AE66.log' => $this->dir . '/179020/1790201586_AE66.log'), kirjuri_audit_entry_files($this->dir));
        $this->assertSame($this->dir . '/179020/1790201586_AE66.log', kirjuri_audit_entry_path('1790201586_AE66.log', $this->dir));
        $this->assertNull(kirjuri_audit_entry_path('../../conf/mysql_credentials.php', $this->dir));
        $this->assertNull(kirjuri_audit_entry_path('1790201586_AE66.log~', $this->dir));
    }

    public function testFingerprintIsTheSha256OfTheFile(): void
    {
        $this->assertSame(hash('sha256', "line\n"), kirjuri_audit_fingerprint("line\n"));
    }
}
