<?php

namespace Kirjuri\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/** Every page except the login page and the installer must send anonymous visitors to the login page. */
final class UnauthenticatedAccessTest extends IntegrationTestCase
{
    public static function protectedPages(): array
    {
        $pages = array();
        foreach (array(
                'index.php', 'add_case.php', 'edit_request.php?case=1', 'device_memo.php?uid=2', 'case_report.php?case=1',
                'timeline.php?case=1', 'statistics.php', 'settings.php', 'users.php', 'tools.php', 'messages.php',
                'help.php', 'lang_editor.php', 'auditor.php', 'log.php', 'backup.php', 'download_csv.php?case=1',
                'download_krf.php?case=1', 'get_file.php?file=1', 'upload.php', 'upload_IMEI.php',
                // These three showed case data without a login.
                'print_sticker.php?type=examination_request&uid=1', 'request.php?case_file_number=1', 'progress_bar_static.php?uid=2',
                // This one imported cases, and injected SQL, without a login.
                'import_krf.php',
                'submit.php?type=examination_request', 'submit.php?type=change_device_status&uid=2',
                'submit.php?type=change_device_location&uid=2', 'submit.php?type=send_message',
                'submit.php?type=delete_message&id=1', 'submit.php?type=clear_cache', 'submit.php?type=save_settings',
                'submit.php?type=create_user',
            ) as $page) {
            $pages[$page] = array($page);
        }
        return $pages;
    }

    #[DataProvider('protectedPages')]
    public function testRedirectsToLogin(string $page): void
    {
        foreach (array('GET', 'POST') as $method) {
            $response = $this->client()->request($method, $page, $method === 'POST' ? '' : null);
            $this->assertSame(302, $response->status, "$method $page");
            $this->assertSame('login.php', $response->location(), "$method $page");
            $this->assertSame('', trim(strip_tags($response->body)), "$method $page leaked content.");
        }
    }

    public function testActionFilesCanNotBeRequestedDirectly(): void
    {
        foreach (glob(KIRJURI_ROOT . '/actions/*.php') as $file) {
            $response = $this->client()->post('actions/' . basename($file) . '?type=create_user', array('username' => 'x'));
            $this->assertSame(404, $response->status, basename($file));
            $this->assertSame('', $response->body, basename($file));
        }
    }

    public function testUnknownActionGoesToTheFrontPage(): void
    {
        $this->expectLoggedError('submit.php called with erroneous value');
        $this->assertSame('index.php', $this->client()->get('submit.php?type=no_such_action')->location());
        $this->assertSame('index.php', $this->client()->get('submit.php')->location());
    }

    public function testApiRejectsMissingOrWrongKeys(): void
    {
        $this->assertSame(403, $this->client()->get('api.php?operation=info')->status);
        $this->assertSame(403, $this->client()->get('api.php?operation=info&key=' . sha1('x'))->status);
    }

    public function testLoginPageAndInstallerDoNotRequireLogin(): void
    {
        $this->assertSame(200, $this->client()->get('login.php')->status);
        $installer = $this->client()->get('install.php');
        $this->assertStringContainsString('Installer has already been run', $installer->body);
    }

    public function testDataFoldersAreProtectedFromApache(): void
    {
        foreach (array('conf', 'logs', 'cache', 'lib', 'bin', 'actions', 'docker', 'tests', 'attachments') as $folder) {
            $this->assertFileExists(KIRJURI_ROOT . "/$folder/.htaccess");
            $this->assertStringContainsString('Require all denied', file_get_contents(KIRJURI_ROOT . "/$folder/.htaccess"));
        }
    }
}
