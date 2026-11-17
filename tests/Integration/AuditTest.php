<?php

namespace Kirjuri\Tests\Integration;

use PDO;

/** The audit log chain as the pages write it: through the test installation's web server. */
final class AuditTest extends IntegrationTestCase
{
    private const AUDIT_FAILED = 'Not saved: the change could not be recorded in the audit log. Tell an administrator.';
    private const SEALED = 'Audit log entry %s: sealed, in sequence and unchanged.';
    private const MISMATCH = 'This entry does not match the audit log\'s record of it: it was changed, replaced or sealed with another key.';
    private const OLD_UNRECORDED = 'An old entry that was not there when the audit log was chained. It may have been added since.';
    private const NOT_INDEXED = 'This entry is not in the audit log\'s record. Its change may have failed, or the record was cut.';

    private function key(): ?string
    {
        return kirjuri_audit_key_string($this->server->dir . '/conf/audit_credentials.php');
    }

    /** The newest $count rows of the index, oldest first. */
    private function newestRows(int $count): array
    {
        return array_reverse($this->server->pdo()->query('SELECT * FROM audit_chain ORDER BY entry DESC LIMIT ' . $count)->fetchAll(PDO::FETCH_ASSOC));
    }

    private function chainLength(): int
    {
        return (int) $this->server->pdo()->query('SELECT COUNT(*) FROM audit_chain')->fetchColumn();
    }

    private function entryFiles(): int
    {
        return count(glob($this->server->dir . '/logs/audit/*/*.log'));
    }

    private function open(array $row): array
    {
        $path = kirjuri_audit_entry_path($row['file'], $this->server->dir . '/logs/audit');
        return kirjuri_audit_open_entry(file_get_contents($path), $row['file'], $this->key());
    }

    /** The description of the case log line that links to audit entry $stamp. */
    private function caseLogDescription(int $caseId, string $stamp): ?string
    {
        foreach (file($this->server->dir . '/logs/cases/uid' . $caseId . '/events.log', FILE_IGNORE_NEW_LINES) as $line) {
            $fields = str_getcsv($line, ';', '"', '');
            if (($fields[6] ?? null) === $stamp) {
                return $fields[3];
            }
        }
        return null;
    }

    private function requestDetails(string $name): array
    {
        return array(
            'case_name' => $name, 'case_file_number' => '1', 'case_crime' => 'Fraud', 'classification' => 'Public',
            'case_suspect' => 'Doe', 'case_investigation_lead' => 'Lead', 'case_investigator' => 'Inv',
            'forensic_investigator' => 'Administrator', 'phone_investigator' => '', 'case_investigator_tel' => '1',
            'case_investigator_unit' => 'Unit 1', 'case_request_description' => 'x', 'case_confiscation_date' => '2026-01-15',
            'case_contains_mob_dev' => '1', 'case_urgency' => '1',
        );
    }


    public function testChangesWriteChainedEntries(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Chained '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Chained device '));
        $admin->post('submit.php?type=change_device_location&uid=' . $deviceId, array('token' => $this->token($admin),
            'ct' => $this->caseToken($admin, $caseId), 'device_location' => 'Evidence locker 3'));

        $rows = $this->newestRows(3);
        $this->assertSame(array_fill(0, 3, (string) $caseId), array_column($rows, 'case_id'));
        foreach ($rows as $i => $row) {
            if ($i > 0) {
                $this->assertSame((int) $rows[$i - 1]['entry'] + 1, (int) $row['entry']);
                $this->assertSame($rows[$i - 1]['fingerprint'], $row['previous']);
            }
            $payload = $this->open($row)['payload'];
            $this->assertNotNull($payload, 'Entry ' . $row['entry'] . ' opens with the installation\'s key.');
            $this->assertSame($caseId, $payload['case']);
            $this->assertSame('admin', $payload['user']['username']);
            $this->assertSame($this->caseLogDescription($caseId, $row['file']), $payload['event']['description'], 'The entry holds its case log line.');
        }
        $this->assertSame('Changed device UID' . $deviceId . ' location to Evidence locker 3. ', $payload['event']['description']);
        $this->assertSame('Evidence locker 3', $payload['request_contents']['device_location']);
    }

    public function testRemovingACaseRecordsItsForm(): void
    {
        // The removal recorded $_GET, which holds only the action's name: the form was not in the audit log.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Removed '));
        $admin->post('submit.php?type=set_removed_case', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
            'remove_exam_request' => (string) $caseId));

        $row = $this->newestRows(1)[0];
        $this->assertSame((string) $caseId, $row['case_id']);
        $this->assertSame((string) $caseId, $this->open($row)['payload']['request_contents']['remove_exam_request']);
    }

    public function testChangeIsRefusedWhenItCanNotBeRecorded(): void
    {
        $this->expectLoggedError('the audit log could not be written');
        $admin = $this->admin();
        $name = $this->uniqueName('Kept name ');
        $caseId = $this->createCase($admin, $name);
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Kept status '));
        $token = $this->token($admin);
        $ct = $this->caseToken($admin, $caseId);
        $before = $this->chainLength();

        $this->withoutAuditFolder(function () use ($admin, $caseId, $deviceId, $token, $ct, $name, $before) {
            $response = $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $token, 'ct' => $ct) + $this->requestDetails('Changed name'));
            $this->assertSame($name, $this->row($caseId)['case_name'], 'The change is not saved without its entry.');
            $this->assertSame($before, $this->chainLength());
            $this->assertNotNull($response->location());
            $this->assertStringContainsString(self::AUDIT_FAILED, $admin->get($response->location())->body);

            $status = $admin->post('submit.php?type=change_device_status&uid=' . $deviceId, array('token' => $token, 'ct' => $ct, 'device_action' => '5'));
            $this->assertSame(array('saved' => false), json_decode($status->body, true));
            $this->assertSame('1', $this->row($deviceId)['device_action']);
        });

        $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $token, 'ct' => $ct) + $this->requestDetails('Changed name'));
        $this->assertSame('Changed name', $this->row($caseId)['case_name'], 'Saved once the audit log can be written again.');
    }

    /** $text as a template prints it. */
    private function assertPageSays(string $text, string $body): void
    {
        $this->assertStringContainsString(htmlspecialchars($text, ENT_QUOTES), $body);
    }

    public function testViewerSaysAnEntryIsSealed(): void
    {
        $admin = $this->admin();
        $this->createCase($admin, $this->uniqueName('Viewed '));
        $row = $this->newestRows(1)[0];

        $page = $admin->get('auditor.php?view=' . $row['file']);

        $this->assertSame(200, $page->status);
        $this->assertPageSays(sprintf(self::SEALED, $row['entry']), $page->body);
        $this->assertStringContainsString('alert-success', $page->body);
    }

    public function testViewerSaysAChangedEntryDoesNotMatch(): void
    {
        $admin = $this->admin();
        $this->createCase($admin, $this->uniqueName('Changed and viewed '));
        $row = $this->newestRows(1)[0];
        $path = kirjuri_audit_entry_path($row['file'], $this->server->dir . '/logs/audit');
        $contents = file_get_contents($path);
        $changed = $contents;
        $changed[strlen($changed) - 3] = ($changed[strlen($changed) - 3] === 'A') ? 'B' : 'A';
        try {
            file_put_contents($path, $changed);
            $page = $admin->get('auditor.php?view=' . $row['file']);
            $this->assertSame(200, $page->status);
            $this->assertPageSays(self::MISMATCH, $page->body);
            $this->assertStringContainsString('alert-danger', $page->body);
        } finally {
            file_put_contents($path, $contents);
        }
    }

    public function testViewerSaysAnOldEntryWasNotRecorded(): void
    {
        $admin = $this->admin();
        $this->createCase($admin, $this->uniqueName('Before the old entry ')); // So that there is a chain.
        $path = $this->server->dir . '/logs/audit/170000/1700000000_OLD2.log';
        @mkdir(dirname($path));
        try {
            file_put_contents($path, encrypt(json_encode(array('request_contents' => array('case_name' => 'An old case'))), $this->key()));
            $page = $admin->get('auditor.php?view=1700000000_OLD2.log');
            $this->assertSame(200, $page->status);
            $this->assertPageSays(self::OLD_UNRECORDED, $page->body);
            $this->assertStringContainsString('An old case', $page->body);
        } finally {
            unlink($path);
            rmdir(dirname($path));
        }
    }

    public function testViewerSaysAnEntryIsNotInTheIndex(): void
    {
        $admin = $this->admin();
        $this->createCase($admin, $this->uniqueName('Before the stray entry '));
        $row = $this->newestRows(1)[0];
        $file = '1700000001_STRAY.log';
        $path = $this->server->dir . '/logs/audit/170000/' . $file;
        @mkdir(dirname($path));
        try {
            file_put_contents($path, kirjuri_audit_seal_entry((int) $row['entry'] + 1, $row['fingerprint'], $file, array('entry' => (int) $row['entry'] + 1), $this->key()));
            $page = $admin->get('auditor.php?view=' . $file);
            $this->assertSame(200, $page->status);
            $this->assertPageSays(self::NOT_INDEXED, $page->body);
        } finally {
            unlink($path);
            rmdir(dirname($path));
        }
    }

    public function testRefusedMemoWritesNoEntry(): void
    {
        $this->expectLoggedError('which is not in this case');
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Memo case '));
        $otherCase = $this->createCase($admin, $this->uniqueName('Other memo case '));
        $otherDevice = $this->addDevice($admin, $otherCase, $this->uniqueName('Other device '));
        $files = $this->entryFiles();

        $admin->post('submit.php?type=devicememo&returnid=' . $otherDevice, array(
            'token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'parent_id' => (string) $caseId,
            'id' => (string) $otherDevice, 'report_notes' => '', 'template_report_notes' => '', 'examiners_notes' => '',
            'device_type' => 'Smartphone', 'device_manuf' => 'Acme', 'device_model' => 'Moved', 'device_size_in_gb' => '1',
            'device_owner' => '', 'device_os' => '', 'device_time_deviation' => '', 'case_request_description' => '',
            'device_item_number' => '1', 'device_document' => '', 'device_identifier' => '', 'device_include_in_report' => '1',
            'device_contains_evidence' => '0'));

        $this->assertSame($files, $this->entryFiles(), 'A refused memo writes no audit entry.');
    }
}
