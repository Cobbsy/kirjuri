<?php

namespace Kirjuri\Tests\Integration;

final class AttachmentTest extends IntegrationTestCase
{
    private array $tempFiles = array();

    protected function tearDown(): void
    {
        array_map('unlink', $this->tempFiles);
    }

    private function file(string $name, string $contents): \CURLFile
    {
        $path = sys_get_temp_dir() . '/' . generate_token(8) . '_' . $name;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return new \CURLFile($path, 'application/octet-stream', $name);
    }

    private function attachments(int $caseId): array
    {
        return $this->server->pdo()->query("SELECT id, name, size FROM attachments WHERE request_id = $caseId ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testUploadDownloadAndRemove(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Attach '));
        $token = $this->token($admin);
        $ct = $this->caseToken($admin, $caseId);

        $response = $admin->post('upload.php', array(
                'case' => (string) $caseId,
                'token' => $token,
                'ct' => $ct,
                'fileToUpload[0]' => $this->file('notes.txt', "hello world\n"),
                // Empty files used to crash with a division by zero.
                'fileToUpload[1]' => $this->file('empty.txt', ''),
            ), true);
        $this->assertSame('edit_request.php?case=' . $caseId, $response->location());
        $files = $this->attachments($caseId);
        $this->assertSame(array('notes.txt', 'empty.txt'), array_column($files, 'name'));

        $download = $admin->get("get_file.php?file={$files[0]['id']}"); // No tokens in the URL.
        $this->assertSame("hello world\n", $download->body);
        $this->assertStringContainsString('filename="notes.txt"', $download->header('Content-Disposition'));
        $this->assertSame('nosniff', $download->header('X-Content-Type-Options'));

        $admin->post("submit.php?type=remove_attachment&file={$files[0]['id']}", array('token' => $token, 'ct' => $ct));
        $this->assertSame(array('empty.txt'), array_column($this->attachments($caseId), 'name'));
    }

    public function testDuplicateUploadIsSkipped(): void
    {
        $this->expectLoggedError('Upload failed (file exists)');
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Duplicate '));
        $fields = array('case' => (string) $caseId, 'token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId));
        $admin->post('upload.php', $fields + array('fileToUpload[0]' => $this->file('a.txt', 'same')), true);
        $admin->post('upload.php', $fields + array('fileToUpload[0]' => $this->file('b.txt', 'same')), true);
        $this->assertCount(1, $this->attachments($caseId));
    }

    public function testUploadRequiresTheCaseToken(): void
    {
        $this->expectLoggedError('Case access token missing');
        $username = $this->uniqueName('uploader');
        $this->createUser($username, 'password1', 1);
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('No token '));

        $user = $this->login($username, 'password1');
        $user->post('upload.php', array('case' => (string) $caseId, 'token' => $this->token($user), 'fileToUpload[0]' => $this->file('x.txt', 'x')), true);
        $this->assertCount(0, $this->attachments($caseId));
    }

    public function testAttachmentsOfRestrictedCasesCannotBeDownloaded(): void
    {
        $this->expectLoggedError('not in access group');
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Secret files '));
        $admin->post('upload.php', array('case' => (string) $caseId, 'token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
                'fileToUpload[0]' => $this->file('secret.txt', 'classified')), true);
        $admin->post('submit.php?type=case_access&id=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'access' => array('admin_only' => 'admin_only')));
        $fileId = $this->attachments($caseId)[0]['id'];

        $username = $this->uniqueName('outsider');
        $this->createUser($username, 'password1', 1);
        $response = $this->login($username, 'password1')->get('get_file.php?file=' . $fileId);
        $this->assertStringNotContainsString('classified', $response->body);
        $this->assertSame('index.php', $response->location());
        $this->assertStringNotContainsString('classified', $this->client()->get('get_file.php?file=' . $fileId)->body);
    }
}
