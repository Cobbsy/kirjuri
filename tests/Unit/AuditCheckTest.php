<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Checking an audit log chain built in a folder of the test's own, with the index as arrays: no database. */
final class AuditCheckTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const OTHER_KEY = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    private string $dir;
    private array $rows;
    private ?array $head;
    private array $legacy;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kirjuri_audit_check_' . generate_token(8);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        delete_directory($this->dir);
    }

    private static function entryName(int $number): string
    {
        return (1790201000 + $number) . '_E' . $number . '.log';
    }

    private function path(string $file): string
    {
        return kirjuri_audit_entry_path($file, $this->dir);
    }

    private function put(string $file, string $contents): string
    {
        @mkdir(dirname($this->path($file)));
        file_put_contents($this->path($file), $contents);
        return hash('sha256', $contents);
    }

    private function seal(int $number, string $previous, string $file, string $key = self::KEY, array $extra = array()): string
    {
        $payload = array('entry' => $number, 'time' => '2026-11-17T10:00:00+02:00', 'case' => null,
            'event' => array('level' => 'Update', 'description' => 'Change ' . $number . '.'), 'user' => array('username' => 'tester'),
            'request_contents' => array()) + $extra;
        return kirjuri_audit_seal_entry($number, $previous, $file, $payload, $key);
    }

    /** A sound chain of $count entries after two old entries; $name and $key may vary per entry. */
    private function build(int $count, ?callable $name = null, ?callable $key = null, ?callable $extra = null): void
    {
        $this->legacy = array();
        foreach (array('1790200001_OLDB.log' => 'old entry B', '1790200000_OLDA.log' => 'old entry A') as $file => $contents) {
            $this->legacy[$file] = $this->put($file, $contents);
        }
        ksort($this->legacy);
        $list = '';
        foreach ($this->legacy as $file => $fingerprint) {
            $list .= $file . ' ' . $fingerprint . "\n";
        }
        $previous = hash('sha256', $list);
        $this->rows = array();
        for ($number = 1; $number <= $count; $number++) {
            $file = $name ? $name($number) : self::entryName($number);
            $more = ($number === 1) ? array('old_entries' => array('count' => 2, 'list_sha256' => $previous)) : array();
            $more += $extra ? $extra($number) : array();
            $fingerprint = $this->put($file, $this->seal($number, $previous, $file, $key ? $key($number) : self::KEY, $more));
            $this->rows[] = array('entry' => (string) $number, 'file' => $file, 'fingerprint' => $fingerprint, 'previous' => $previous,
                'case_id' => null, 'username' => 'tester', 'created' => sprintf('2026-11-17 10:%02d:00', $number));
            $previous = $fingerprint;
        }
        $this->head = $this->headOf(end($this->rows));
    }

    private function headOf(array $row, ?int $key_replaced_at = null): array
    {
        return array('entry' => (int) $row['entry'], 'fingerprint' => $row['fingerprint'], 'created' => $row['created'], 'key_replaced_at' => $key_replaced_at);
    }

    private function check(?array $since = null, bool $partial = false, ?string $key = self::KEY): array
    {
        return kirjuri_audit_check($this->rows, $this->head, $this->legacy, $this->dir, $key, $since, $partial);
    }

    /** The findings as "kind @entry", or the kind alone for findings about no one entry. */
    private function kinds(array $result): array
    {
        return array_map(fn ($finding) => $finding['kind'] . ($finding['entry'] === null ? '' : ' @' . $finding['entry']), $result['findings']);
    }

    public function testSoundChainHasNoFindings(): void
    {
        $this->build(5);

        $result = $this->check();

        $this->assertSame(array(), $result['findings']);
        $this->assertSame(5, $result['entries']);
        $this->assertSame(2, $result['old']);
        $this->assertSame('entry 5, 17.11.2026 10:05:00, ' . kirjuri_audit_short_fingerprint($this->rows[4]['fingerprint']), $result['checkpoint']);
    }

    public function testChangedFileIsFound(): void
    {
        $this->build(5);
        $contents = file_get_contents($this->path(self::entryName(3)));
        $contents[strlen($contents) - 2] = ($contents[strlen($contents) - 2] === 'A') ? 'B' : 'A';
        file_put_contents($this->path(self::entryName(3)), $contents);

        $result = $this->check();

        $this->assertSame(array('changed @3'), $this->kinds($result));
        $this->assertSame(self::entryName(3), $result['findings'][0]['file']);
        $this->assertNotSame('', $result['findings'][0]['text']);
    }

    public function testEntryReplacedInFileAndIndexIsFound(): void
    {
        $this->build(5);
        $this->rows[2]['fingerprint'] = $this->put(self::entryName(3), $this->seal(3, $this->rows[2]['previous'], self::entryName(3), self::OTHER_KEY));

        $this->assertSame(array('seal @3', 'link @4'), $this->kinds($this->check()));
    }

    public function testRemovedEntryIsFound(): void
    {
        $this->build(5);
        unlink($this->path(self::entryName(3)));
        unset($this->rows[2]);

        $result = $this->check();

        $this->assertSame(array('link @4'), $this->kinds($result));
        $this->assertStringContainsString('3', $result['findings'][0]['text']);
    }

    public function testRemovedFileIsFound(): void
    {
        $this->build(5);
        unlink($this->path(self::entryName(3)));

        $this->assertSame(array('missing @3'), $this->kinds($this->check()));
    }

    public function testSwappedEntriesAreFound(): void
    {
        $this->build(5);
        $second = file_get_contents($this->path(self::entryName(2)));
        file_put_contents($this->path(self::entryName(2)), file_get_contents($this->path(self::entryName(3))));
        file_put_contents($this->path(self::entryName(3)), $second);

        $this->assertSame(array('changed @2', 'changed @3'), $this->kinds($this->check()));
    }

    public function testHeadAheadOfTheIndexIsFound(): void
    {
        $this->build(5);
        unlink($this->path(self::entryName(5)));
        array_pop($this->rows);

        $this->assertSame(array('head'), $this->kinds($this->check()));
    }

    public function testCutLogIsFoundOnlyAgainstACheckpoint(): void
    {
        $this->build(5);
        $fifth = array_pop($this->rows);
        unlink($this->path($fifth['file']));
        $this->head = $this->headOf(end($this->rows));

        $this->assertSame(array(), $this->kinds($this->check()), 'A consistent cut leaves nothing to find inside the server.');
        $this->assertSame(array('since'), $this->kinds($this->check(kirjuri_audit_parse_checkpoint('5:' . $fifth['fingerprint']))));
        $this->assertSame(array(), $this->kinds($this->check(kirjuri_audit_parse_checkpoint('4:' . substr($this->rows[3]['fingerprint'], 0, 20)))));
        $this->assertSame(array('since'), $this->kinds($this->check(kirjuri_audit_parse_checkpoint('4:' . str_repeat('0', 20)))));
    }

    public function testUnindexedFilesAreNamedForWhatTheyMayBe(): void
    {
        $this->build(5);
        $next = '1790209006_N6.log';
        $this->put($next, $this->seal(6, $this->rows[4]['fingerprint'], $next));
        $this->assertSame(array('not_indexed @6'), $this->kinds($this->check()), 'A change that failed, or the newest row removed.');
        unlink($this->path($next));

        $far = '1790209008_N8.log';
        $this->put($far, $this->seal(8, str_repeat('c', 64), $far));
        $this->assertSame(array('past_end @8'), $this->kinds($this->check()));
        unlink($this->path($far));

        $twin = '1790209003_N3.log';
        $this->put($twin, $this->seal(3, $this->rows[1]['fingerprint'], $twin));
        $this->assertSame(array('never_saved @3'), $this->kinds($this->check()));
    }

    public function testEntriesSealedBeforeALostKeyAreOneFinding(): void
    {
        $this->build(5, null, fn ($number) => ($number <= 3) ? self::KEY : self::OTHER_KEY,
            fn ($number) => ($number === 4) ? array('key_replaced' => true) : array());
        $this->head['key_replaced_at'] = 4;

        $result = $this->check(null, false, self::OTHER_KEY);

        $this->assertSame(array('unreadable_old_key'), $this->kinds($result));
        $this->assertSame('Entries 1 to 3 were sealed before the key was replaced at entry 4 and can not be read with it.', $result['findings'][0]['text']);
    }

    public function testKeyReplacementIsReadFromTheChainNotTheIndex(): void
    {
        // Someone who can change the database could set key_replaced_at to excuse an entry they changed.
        $this->build(5, null, fn ($number) => ($number === 3) ? self::OTHER_KEY : self::KEY);
        $this->head['key_replaced_at'] = 999;

        $this->assertSame(array('seal @3', 'key_record'), $this->kinds($this->check()));
    }

    public function testAKeyChangeWithoutItsSealedRecordIsNotAReplacement(): void
    {
        // Only Kirjuri, holding the new key, seals the entry that records a replacement.
        $this->build(5, null, fn ($number) => ($number <= 3) ? self::KEY : self::OTHER_KEY);
        $this->head['key_replaced_at'] = 4;

        $this->assertSame(array('seal @1', 'seal @2', 'seal @3', 'key_record'), $this->kinds($this->check(null, false, self::OTHER_KEY)));
    }

    public function testPartialCheckFoldsEntriesBeforeARecordedKeyReplacement(): void
    {
        $this->build(5, null, fn ($number) => ($number <= 3) ? self::KEY : self::OTHER_KEY,
            fn ($number) => ($number === 4) ? array('key_replaced' => true) : array());
        $this->head['key_replaced_at'] = 4;

        $this->assertSame(array('unreadable_old_key'), $this->kinds($this->check(null, true, self::OTHER_KEY)));
    }

    public function testNoChainYetIsNotAProblem(): void
    {
        // Upgraded, migrated on a page load, and nothing changed since: the old entries wait for the chain to start.
        $this->put('1790200000_OLDA.log', 'old entry A');
        $this->put('1790200001_OLDB.log', 'old entry B');

        $result = kirjuri_audit_check(array(), null, array(), $this->dir, self::KEY);

        $this->assertSame(array(), $result['findings']);
        $this->assertSame(2, $result['waiting']);
        $this->assertNull($result['checkpoint']);
    }

    public function testChainEntriesWithoutAnIndexAreFound(): void
    {
        // The index was emptied (or the database restored from before the log): the entries are still there.
        $this->build(3);

        $result = kirjuri_audit_check(array(), null, array(), $this->dir, self::KEY);

        $this->assertSame(array('earlier_chain'), $this->kinds($result));
        $this->assertStringContainsString('3 entries', $result['findings'][0]['text']);
    }

    public function testARestartedChainIsAlwaysReported(): void
    {
        $this->build(3, null, null, fn ($number) => ($number === 1) ? array('earlier_chain' => 4) : array());

        $result = $this->check();

        $this->assertSame(array('restarted'), $this->kinds($result));
        $this->assertStringContainsString('4 entries of an earlier chain', $result['findings'][0]['text']);
    }

    public function testOldFilesAreChecked(): void
    {
        $this->build(3);
        $old = $this->path('1790200000_OLDA.log');

        file_put_contents($old, 'edited');
        $this->assertSame(array('old_changed'), $this->kinds($this->check()));

        $this->legacy['1790200000_OLDA.log'] = hash('sha256', 'edited'); // The index edited to match.
        $this->assertSame(array('old_list'), $this->kinds($this->check()));

        $this->build(3);
        unlink($old);
        $this->assertSame(array('old_missing'), $this->kinds($this->check()));

        $this->build(3);
        $this->put('1790200002_OLDC.log', 'an old entry added later');
        $result = $this->check();
        $this->assertSame(array('old_added'), $this->kinds($result));
        $this->assertSame('1790200002_OLDC.log', $result['findings'][0]['file']);
    }

    public function testEntriesAreOrderedByNumberNotByFileName(): void
    {
        // The clock was set back between entries 1 and 2, so entry 2's name holds an earlier time.
        $this->build(3, fn ($number) => (1790201000 - 100 * $number) . '_E' . $number . '.log');

        $this->assertSame(array(), $this->kinds($this->check()));
    }

    public function testFilesThatAreNotEntriesAreIgnored(): void
    {
        $this->build(3);
        file_put_contents($this->dir . '/.gitignore', '*');
        file_put_contents($this->dir . '/179020/notes.txt', 'notes');
        file_put_contents($this->dir . '/179020/x.log~', 'backup');

        $this->assertSame(array(), $this->kinds($this->check()));
    }

    public function testPartialCheckLooksOnlyAtTheNewest(): void
    {
        $this->build(5);
        $this->rows = array_slice($this->rows, 2);
        unlink($this->path('1790200000_OLDA.log'));

        $result = $this->check(null, true);

        $this->assertSame(array(), $this->kinds($result));
        $this->assertSame(3, $result['entries']);
    }

    public function testCheckWithoutAKeyChecksTheLinks(): void
    {
        $this->build(5);

        $this->assertSame(array(), $this->kinds($this->check(null, false, null)));
        unlink($this->path(self::entryName(3)));
        $this->assertSame(array('missing @3'), $this->kinds($this->check(null, false, null)));
    }

    public function testCheckpointTextIsReadable(): void
    {
        $this->assertSame('entry 48213, 17.11.2026 10:22:31, 9f3c 1a2b 77de 0c41 5e9a',
            kirjuri_audit_checkpoint_text(48213, '2026-11-17 10:22:31', '9f3c1a2b77de0c415e9a' . str_repeat('0', 44)));
    }
}
