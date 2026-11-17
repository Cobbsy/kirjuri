<?php

namespace Kirjuri\Tests\Integration;

use KirjuriAuditException;
use PDO;

/** Writing audit log entries with lib/audit.php directly, against a scratch database and a folder of the test's own. */
final class AuditChainTest extends IntegrationTestCase
{
    use ScratchDatabase {
        tearDown as dropScratchDatabases;
    }

    private string $dir;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/kirjuri_audit_chain_' . generate_token(8);
        mkdir($base);
        $this->dir = $base . '/audit';
        mkdir($this->dir);
        $this->keyFile = $base . '/audit_credentials.php';
    }

    protected function tearDown(): void
    {
        delete_directory(dirname($this->dir));
        $this->dropScratchDatabases();
    }

    private function database(): PDO
    {
        $db = $this->scratchDatabase();
        kirjuri_migrate($db);
        return $db;
    }

    private function user(): array
    {
        return array('username' => 'tester', 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'referer' => '',
            'request_uri' => '/submit.php?type=test', 'request_method' => 'POST');
    }

    private function write(PDO $db, ?int $case = 12, string $description = 'Changed something.'): array
    {
        $db->beginTransaction();
        try {
            $result = kirjuri_audit_write($db, $case, 'Update', $description, array('field' => 'value', 'password' => 'secret'),
                $this->user(), $this->dir, $this->keyFile);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function rows(PDO $db): array
    {
        return $db->query('SELECT * FROM audit_chain ORDER BY entry')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function contents(array $row): string
    {
        return file_get_contents(kirjuri_audit_entry_path($row['file'], $this->dir));
    }

    private function open(array $row, ?string $key = null): array
    {
        return kirjuri_audit_open_entry($this->contents($row), $row['file'], $key ?? kirjuri_audit_key_string($this->keyFile));
    }

    private function writeOldEntry(string $file, string $contents): string
    {
        @mkdir($this->dir . '/' . substr($file, 0, 6));
        file_put_contents($this->dir . '/' . substr($file, 0, 6) . '/' . $file, $contents);
        return hash('sha256', $contents);
    }

    private function assertLinked(array $rows): void
    {
        foreach ($rows as $i => $row) {
            $this->assertSame((string) ($i + 1), $row['entry']);
            $this->assertSame(hash('sha256', $this->contents($row)), $row['fingerprint']);
            $opened = $this->open($row);
            $this->assertSame((int) $row['entry'], $opened['number']);
            $this->assertSame($row['previous'], $opened['previous']);
            $this->assertNotNull($opened['payload'], 'Entry ' . $row['entry'] . ' opens with the key.');
            $this->assertSame((int) $row['entry'], $opened['payload']['entry']);
            if ($i > 0) {
                $this->assertSame($rows[$i - 1]['fingerprint'], $row['previous'], 'Entry ' . $row['entry'] . ' links to the one before.');
            }
        }
    }

    public function testFirstWriteStartsTheChainWithTheOldEntries(): void
    {
        $db = $this->database();
        $this->assertNull(kirjuri_audit_head($db));
        kirjuri_audit_create_key($this->keyFile);
        $json = json_encode(array('request_contents' => array('case_name' => 'Old case')));
        $first = $this->writeOldEntry('1790200001_BBBB.log', encrypt($json, kirjuri_audit_key_string($this->keyFile)));
        $second = $this->writeOldEntry('1790200000_AAAA.log', encrypt($json, kirjuri_audit_key_string($this->keyFile)));

        $result = $this->write($db);

        $this->assertSame(2, $result['entry']);
        $this->assertSame(2, $result['started']);
        $this->assertNull($result['key_replaced']);
        $rows = $this->rows($db);
        $this->assertCount(2, $rows);
        $this->assertLinked($rows);
        $list = "1790200000_AAAA.log $second\n1790200001_BBBB.log $first\n";
        $this->assertSame(hash('sha256', $list), $rows[0]['previous']);
        $start = $this->open($rows[0])['payload'];
        $this->assertSame(array('level' => 'Audit', 'description' => 'Audit log chain started. 2 earlier entries fingerprinted.'), $start['event']);
        $this->assertSame(array('count' => 2, 'list_sha256' => hash('sha256', $list)), $start['old_entries']);
        $this->assertNull($start['case']);
        $this->assertNull($rows[0]['case_id']);
        $this->assertSame(array(
                array('file' => '1790200000_AAAA.log', 'fingerprint' => $second, 'size' => (string) filesize($this->dir . '/179020/1790200000_AAAA.log')),
                array('file' => '1790200001_BBBB.log', 'fingerprint' => $first, 'size' => (string) filesize($this->dir . '/179020/1790200001_BBBB.log')),
            ), $db->query('SELECT file, fingerprint, size FROM audit_legacy ORDER BY file')->fetchAll(PDO::FETCH_ASSOC));

        $change = $this->open($rows[1])['payload'];
        $this->assertSame(12, $change['case']);
        $this->assertSame(array('level' => 'Update', 'description' => 'Changed something.'), $change['event']);
        $this->assertSame('tester', $change['user']['username']);
        $this->assertSame(array('field' => 'value'), $change['request_contents'], 'No password in the entry.');
        $this->assertSame($result['file'], $rows[1]['file']);
        $this->assertSame($result['fingerprint'], $rows[1]['fingerprint']);

        $head = kirjuri_audit_head($db);
        $this->assertSame(array('entry' => 2, 'fingerprint' => $rows[1]['fingerprint'], 'created' => $rows[1]['created'], 'key_replaced_at' => null), $head);
    }

    public function testEntriesFollowEachOther(): void
    {
        $db = $this->database();
        $this->write($db);
        foreach (array(3, 4, 5) as $expected) {
            $this->assertSame($expected, $this->write($db, $expected * 10, 'Change ' . $expected . '.')['entry']);
        }

        $rows = $this->rows($db);
        $this->assertCount(5, $rows);
        $this->assertLinked($rows);
        $this->assertSame('50', $rows[4]['case_id']);
        $this->assertSame('tester', $rows[4]['username']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[4]['created']);
        $this->assertSame(5, kirjuri_audit_head($db)['entry']);
    }

    public function testFailedWriteLeavesNothingBehind(): void
    {
        $db = $this->database();
        $this->write($db);
        $head = kirjuri_audit_head($db);
        $files = kirjuri_audit_entry_files($this->dir);

        rename($this->dir, $this->dir . '.moved');
        file_put_contents($this->dir, 'not a folder');
        try {
            $this->write($db);
            $this->fail('The entry can not be written, so the write fails.');
        } catch (KirjuriAuditException $e) {
            $this->assertCount(2, $this->rows($db));
            $this->assertSame($head, kirjuri_audit_head($db));
        } finally {
            unlink($this->dir);
            rename($this->dir . '.moved', $this->dir);
        }

        unlink($this->keyFile);
        mkdir($this->keyFile);
        try {
            $this->write($db);
            $this->fail('The key can not be read, so the write fails.');
        } catch (KirjuriAuditException $e) {
            $this->assertCount(2, $this->rows($db));
            $this->assertSame($head, kirjuri_audit_head($db));
            $this->assertSame($files, kirjuri_audit_entry_files($this->dir), 'No entry file was left behind.');
        }
    }

    public function testLostKeyIsReplacedAndRecorded(): void
    {
        $db = $this->database();
        $this->write($db);
        $oldKey = kirjuri_audit_key_string($this->keyFile);
        unlink($this->keyFile);

        $result = $this->write($db);

        $this->assertSame(3, $result['key_replaced']);
        $this->assertSame(4, $result['entry']);
        $newKey = kirjuri_audit_key_string($this->keyFile);
        $this->assertNotNull($newKey);
        $this->assertNotSame($oldKey, $newKey);
        $rows = $this->rows($db);
        $this->assertSame(array('level' => 'Audit', 'description' => 'The audit log key was missing, and a new one was made. Entries 1 to 2 can no longer be read with it.'),
            $this->open($rows[2])['payload']['event']);
        $this->assertNull($this->open($rows[1])['payload'], 'Entry 2 was sealed with the lost key.');
        $this->assertNotNull($this->open($rows[1], $oldKey)['payload']);
        $this->assertSame($rows[1]['fingerprint'], $rows[2]['previous'], 'The chain goes on across the new key.');
        $this->assertSame(3, kirjuri_audit_head($db)['key_replaced_at']);

        $this->assertNull($this->write($db)['key_replaced'], 'Recorded once.');
    }

    public function testReplacedKeyIsRecorded(): void
    {
        // A key put in place by hand, or made by a write that then failed, makes the earlier entries unreadable too.
        $db = $this->database();
        $this->write($db);
        file_put_contents($this->keyFile, '<?php return "' . str_repeat('ab', 32) . '";' . "\n");

        $result = $this->write($db);

        $this->assertSame(3, $result['key_replaced']);
        $this->assertSame('The audit log key was replaced. Entries 1 to 2 can no longer be read with it.',
            $this->open($this->rows($db)[2])['payload']['event']['description']);
    }

    public function testWritersAtTheSameTimeKeepOneChain(): void
    {
        $db = $this->database();
        $go = dirname($this->dir) . '/go';
        $processes = array();
        for ($i = 0; $i < 4; $i++) {
            $processes[] = proc_open(array(PHP_BINARY, __DIR__ . '/fixtures/audit_writer.php', $this->scratchDsn(end($this->scratch)),
                    getenv('KIRJURI_TEST_DB_USER') ?: 'root', getenv('KIRJURI_TEST_DB_PASSWORD') ?: '', $this->dir, $this->keyFile, '25', $go),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i]);
        }
        touch($go);
        foreach ($processes as $i => $process) {
            $errors = stream_get_contents($pipes[$i][2]);
            stream_get_contents($pipes[$i][1]);
            $this->assertSame(0, proc_close($process), $errors);
        }

        $rows = $this->rows($db);
        $this->assertCount(101, $rows);
        $this->assertLinked($rows);
        $starts = array_filter($rows, fn ($row) => strpos($this->open($row)['payload']['event']['description'], 'Audit log chain started') === 0);
        $this->assertSame(array(0), array_keys($starts), 'One chain start, entry 1.');
        $this->assertSame(101, kirjuri_audit_head($db)['entry']);

        $check = kirjuri_audit_check(kirjuri_audit_rows($db), kirjuri_audit_head($db), kirjuri_audit_legacy_rows($db), $this->dir, kirjuri_audit_key_string($this->keyFile));
        $this->assertSame(array(), $check['findings']);
        $this->assertSame(101, $check['entries']);
        $newest = iterator_to_array(kirjuri_audit_rows($db, 3), false);
        $this->assertSame(array('99', '100', '101'), array_column($newest, 'entry'));
    }

    public function testChainStartFingerprintsThousandsOfOldFiles(): void
    {
        $db = $this->database();
        for ($i = 0; $i < 3000; $i++) {
            $this->writeOldEntry(sprintf('17902%05d_OLD.log', $i), 'old entry ' . $i);
        }

        $result = $this->write($db);

        $this->assertSame(3000, $result['started']);
        $this->assertSame('3000', $db->query('SELECT COUNT(*) FROM audit_legacy')->fetchColumn());
        $this->assertSame(3000, $this->open($this->rows($db)[0])['payload']['old_entries']['count']);
        $check = kirjuri_audit_check(kirjuri_audit_rows($db), kirjuri_audit_head($db), kirjuri_audit_legacy_rows($db), $this->dir, kirjuri_audit_key_string($this->keyFile));
        $this->assertSame(array(), $check['findings']);
        $this->assertSame(3000, $check['old']);
    }

    public function testStartingTheChainHappensOnce(): void
    {
        $db = $this->database();

        $this->assertSame(0, kirjuri_audit_start($db, $this->user(), $this->dir, $this->keyFile));
        $this->assertNull(kirjuri_audit_start($db, $this->user(), $this->dir, $this->keyFile));
        $this->assertSame(1, kirjuri_audit_head($db)['entry']);
        $this->assertFalse($db->inTransaction());
    }

    public function testWritingNeedsATransaction(): void
    {
        $this->expectException(\LogicException::class);
        kirjuri_audit_write($this->database(), null, 'Update', 'No transaction.', array(), $this->user(), $this->dir, $this->keyFile);
    }
}
