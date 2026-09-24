<?php

namespace Kirjuri\Tests\Integration;

final class KrfImportTest extends IntegrationTestCase
{
    private function upload(HttpClient $client, string $contents): Response
    {
        $file = tempnam(sys_get_temp_dir(), 'krf');
        file_put_contents($file, $contents);
        try {
            return $client->post('import_krf.php', array(
                    'token' => $this->token($client),
                    'fileToUpload[0]' => new \CURLFile($file, 'application/x-gzip', 'case.krf'),
                ), true);
        } finally {
            unlink($file);
        }
    }

    private function exportCase(HttpClient $admin, string $name): array
    {
        $caseId = $this->createCase($admin, $name);
        $host = $this->addDevice($admin, $caseId, $this->uniqueName('Host'));
        $media = $this->addDevice($admin, $caseId, $this->uniqueName('Card'));
        $admin->post('submit.php?type=device_attach&uid=' . $media . '&returnid=' . $caseId,
            array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'isanta' => (string) $host));
        return json_decode(gzdecode($admin->get('download_krf.php?case=' . $caseId)->body), true);
    }

    private function rowCount(): int
    {
        return (int) $this->server->pdo()->query('SELECT (SELECT COUNT(*) FROM exam_requests) + (SELECT COUNT(*) FROM attachments)')->fetchColumn();
    }

    public function testExportedCaseCanBeImported(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Roundtrip ');
        $krf = $this->exportCase($admin, $name);

        $response = $this->upload($admin, gzencode(json_encode($krf)));
        $this->assertMatchesRegularExpression('/^edit_request\.php\?case=\d+$/', $response->location());
        $newId = (int) substr($response->location(), strlen('edit_request.php?case='));
        $this->assertNotSame((int) $krf['parent']['id'], $newId);

        $devices = $this->server->pdo()->query("SELECT id, device_host_id FROM exam_requests WHERE parent_id = $newId AND id != $newId ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $devices);
        $this->assertSame($devices[0]['id'], $devices[1]['device_host_id'], 'Device associations point at the new UIDs.');
    }

    public function testImportedNotesCannotRunScripts(): void
    {
        $admin = $this->admin();
        $krf = $this->exportCase($admin, $this->uniqueName('Scripted '));
        $krf['parent']['report_notes'] = '<p>Imported text</p><script>alert("krf")</script>';
        $krf['parent']['examiners_notes'] = '<img src="x.png" onerror="alert(1)">';

        $newId = (int) substr($this->upload($admin, gzencode(json_encode($krf)))->location(), strlen('edit_request.php?case='));
        $this->assertGreaterThan(0, $newId);
        foreach (array('edit_request.php?case=' . $newId, 'case_report.php?case=' . $newId) as $page) {
            $body = $admin->get($page)->body;
            $this->assertStringContainsString('<p>Imported text</p>', $body, $page);
            $this->assertDoesNotMatchRegularExpression('/<script>alert|<img[^>]*onerror/i', $body, $page);
        }
    }

    public function testInjectedColumnNameIsRejectedBeforeAnythingIsWritten(): void
    {
        $admin = $this->admin();
        $krf = $this->exportCase($admin, $this->uniqueName('Evil '));
        $krf['children'][0]['device_model) VALUES (1); DROP TABLE users; -- '] = 'x';
        $before = $this->rowCount();

        $response = $this->upload($admin, gzencode(json_encode($krf)));
        $this->assertStringContainsString('KEY INTEGRITY CHECK FAILURE', $response->body);
        $this->assertSame($before, $this->rowCount(), 'A rejected file must not leave a partial import.');
        $this->assertGreaterThan(0, (int) $this->server->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testInjectedColumnNameInParentIsRejected(): void
    {
        $admin = $this->admin();
        $krf = $this->exportCase($admin, $this->uniqueName('Evil parent '));
        $krf['parent']['case_name, case_owner'] = 'x';
        $before = $this->rowCount();
        $this->assertStringContainsString('KEY INTEGRITY CHECK FAILURE', $this->upload($admin, gzencode(json_encode($krf)))->body);
        $this->assertSame($before, $this->rowCount());
    }

    public function testInvalidFilesAreRejected(): void
    {
        $this->expectLoggedError('Invalid KRF file');
        $admin = $this->admin();
        $before = $this->rowCount();
        foreach (array('not gzip at all', gzencode('not json'), gzencode('{"children": []}')) as $contents) {
            $this->assertSame('index.php', $this->upload($admin, $contents)->location());
        }
        $this->assertSame($before, $this->rowCount());
    }

    public function testImportRequiresCsrfToken(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $admin = $this->admin();
        $file = tempnam(sys_get_temp_dir(), 'krf');
        file_put_contents($file, gzencode('{}'));
        $response = $admin->post('import_krf.php', array('fileToUpload[0]' => new \CURLFile($file)), true);
        unlink($file);
        $this->assertSame('index.php', $response->location());
    }
}
