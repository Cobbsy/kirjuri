<?php

namespace Kirjuri\Tests\Integration;

final class MiscellaneousTest extends IntegrationTestCase
{
    public function testMessagesCanBeSentReadAndArchived(): void
    {
        $recipient = $this->uniqueName('recipient');
        $this->createUser($recipient, 'password1', 1);
        $admin = $this->admin();
        $subject = $this->uniqueName('Subject ');

        $response = $admin->post('submit.php?type=send_message', array('token' => $this->token($admin), 'msgto' => $recipient, 'subject' => $subject, 'body' => '<p>Hello</p>'));
        $this->assertSame('messages.php', $response->location());

        $user = $this->login($recipient, 'password1');
        $this->assertStringContainsString('label-danger', $user->get('unreadcounter.php')->body);
        $inbox = $user->get('messages.php');
        $this->assertStringContainsString($subject, $inbox->body);

        $id = $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$recipient'")->fetchColumn();
        $user->get('messages.php?open=' . $id);
        $user->post("submit.php?type=archive_received&id=$id", array('token' => $this->token($user)));
        $this->assertSame('1', $this->server->pdo()->query("SELECT archived_to FROM messages WHERE id = $id")->fetchColumn());
    }

    public function testOnlyTheRecipientCanArchiveOrDeleteTheirCopy(): void
    {
        $sender = $this->uniqueName('sender');
        $recipient = $this->uniqueName('recipient');
        $this->createUser($sender, 'password1', 1);
        $this->createUser($recipient, 'password1', 1);
        $from = $this->login($sender, 'password1');
        $from->post('submit.php?type=send_message', array('token' => $this->token($from), 'msgto' => $recipient, 'subject' => 'Owned', 'body' => '<p>x</p>'));
        $id = (int) $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$recipient'")->fetchColumn();
        $to = $this->login($recipient, 'password1');
        $to->get('messages.php?open=' . $id);
        $state = fn () => $this->server->pdo()->query("SELECT archived_to, archived_from FROM messages WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);

        // The sender used to be able to archive the recipient's copy.
        $from->post("submit.php?type=archive_received&id=$id", array('token' => $this->token($from)));
        $this->assertSame('0', $state()['archived_to']);
        $to->post("submit.php?type=archive_sent&id=$id", array('token' => $this->token($to)));
        $this->assertSame('0', $state()['archived_from'], 'The recipient can not archive the sender\'s copy.');

        $to->post("submit.php?type=archive_received&id=$id", array('token' => $this->token($to)));
        $from->post("submit.php?type=archive_sent&id=$id", array('token' => $this->token($from)));
        $this->assertSame(array('archived_to' => '1', 'archived_from' => '1'), $state());
    }

    public function testMessagesToYourselfCanBeArchivedOnBothSides(): void
    {
        $username = $this->uniqueName('self');
        $this->createUser($username, 'password1', 1);
        $user = $this->login($username, 'password1');
        $user->post('submit.php?type=send_message', array('token' => $this->token($user), 'msgto' => $username, 'subject' => 'Note to self', 'body' => '<p>x</p>'));
        $id = (int) $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$username'")->fetchColumn();
        $this->assertSame('Myself', $this->server->pdo()->query("SELECT msgfrom FROM messages WHERE id = $id")->fetchColumn());
        $user->get('messages.php?open=' . $id);
        $user->post("submit.php?type=archive_received&id=$id", array('token' => $this->token($user)));
        $user->post("submit.php?type=archive_sent&id=$id", array('token' => $this->token($user)));
        $this->assertSame(array('archived_to' => '1', 'archived_from' => '1'),
            $this->server->pdo()->query("SELECT archived_to, archived_from FROM messages WHERE id = $id")->fetch(\PDO::FETCH_ASSOC));
    }

    public function testMessageToAllUsersSkipsTheSender(): void
    {
        $recipient = $this->uniqueName('everyone');
        $this->createUser($recipient, 'password1', 1);
        $admin = $this->admin();
        $subject = $this->uniqueName('Broadcast ');
        $admin->post('submit.php?type=send_message', array('token' => $this->token($admin), 'msgto' => 'ALL_USERS', 'subject' => $subject, 'body' => '<p>All</p>'));

        $count = fn ($to) => (int) $this->server->pdo()->query("SELECT COUNT(*) FROM messages WHERE msgto = '$to' AND subject = '" . base64_encode(gzdeflate($subject)) . "'")->fetchColumn();
        $this->assertSame(1, $count($recipient));
        $this->assertSame(1, $count('anonymous'));
        $this->assertSame(0, $count('admin'));
        $this->assertStringContainsString($subject, $this->login($recipient, 'password1')->get('messages.php')->body);
    }

    public function testDeleteAllRemovesOnlyArchivedMessages(): void
    {
        $username = $this->uniqueName('tidy');
        $this->createUser($username, 'password1', 1);
        $admin = $this->admin();
        foreach (array('Keep', 'Archive') as $subject) {
            $admin->post('submit.php?type=send_message', array('token' => $this->token($admin), 'msgto' => $username, 'subject' => $subject, 'body' => '<p>x</p>'));
        }
        $ids = $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$username' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $user = $this->login($username, 'password1');
        $user->get('messages.php?open=' . $ids[1]);
        $user->post("submit.php?type=archive_received&id={$ids[1]}", array('token' => $this->token($user)));
        $user->post('submit.php?type=delete_all', array('token' => $this->token($user)));

        $deleted = $this->server->pdo()->query("SELECT id, deleted_to FROM messages WHERE msgto = '$username' ORDER BY id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(array($ids[0] => '0', $ids[1] => '1'), $deleted);

        $user->post("submit.php?type=delete_message&id={$ids[0]}", array('token' => $this->token($user)));
        $this->assertSame(array($ids[1]), $this->server->pdo()->query("SELECT id FROM messages WHERE msgto = '$username'")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testMessagesToUnknownRecipientsAreRefused(): void
    {
        $admin = $this->admin();
        $nobody = $this->uniqueName('nobody');
        $response = $admin->post('submit.php?type=send_message', array('token' => $this->token($admin), 'msgto' => $nobody, 'subject' => 'Early', 'body' => '<p>x</p>'));
        $this->assertSame('messages.php?show=compose', $response->location());
        $this->assertSame(0, (int) $this->server->pdo()->query("SELECT COUNT(*) FROM messages WHERE msgto = '$nobody'")->fetchColumn());
    }

    public function testErrorsSendUsersBackOnlyToThisSite(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $admin = $this->admin();
        $admin->setReferer('https://evil.example/phish');
        $this->assertSame('index.php', $admin->post('submit.php?type=delete_all', array('token' => 'wrong'))->location());
        $admin->setReferer($this->server->baseUrl . '/messages.php?show=compose');
        $this->assertSame('messages.php?show=compose', $admin->post('submit.php?type=delete_all', array('token' => 'wrong'))->location());
    }

    public function testComposeSubjectIsPrefilled(): void
    {
        $admin = $this->admin();
        // The subject used to be replaced by the result of isset(), so it always read "1".
        $response = $admin->get('messages.php?show=compose&subject=' . urlencode('Re: case 12'));
        $this->assertStringContainsString('value="Re: case 12"', $response->body);
    }

    public function testToolReservationsRejectOverlaps(): void
    {
        $admin = $this->admin();
        $token = $this->token($admin);
        $name = $this->uniqueName('Imager ');
        $admin->post('submit.php?type=add_tool', array('token' => $token, 'product_name' => $name, 'hw_version' => '1', 'sw_version' => '1', 'serialno' => 'S', 'comment' => '', 'flag1' => '', 'flag2' => ''));
        $toolId = $this->server->pdo()->query("SELECT id FROM tools WHERE product_name = '$name'")->fetchColumn();
        $reserve = function ($start, $end) use ($admin, $token, $toolId) {
            return $admin->post('submit.php?type=reserve_tool', array('token' => $token, 'tool_id' => $toolId, 'reserved_for' => 'Administrator', 'comment' => '',
                'reserve_start_date' => $start, 'reserve_start_time' => '08:00', 'reserve_end_date' => $end, 'reserve_end_time' => '08:00'));
        };

        $this->assertSame('tools.php?populate=' . $toolId, $reserve('2030-01-01', '2030-01-03')->location());
        $this->assertSame('tools.php?populate=' . $toolId . '&highlight=0', $reserve('2030-01-02', '2030-01-04')->location());
        $this->assertSame('tools.php?populate=' . $toolId, $reserve('2030-01-03', '2030-01-05')->location(), 'Back to back reservations are fine.');
        $this->assertCount(2, json_decode($this->server->pdo()->query("SELECT attr_4 FROM tools WHERE id = $toolId")->fetchColumn(), true));

        // Missing dates used to pass the check, because " " (date and time joined by a space) is not empty.
        $incomplete = $admin->post('submit.php?type=reserve_tool', array('token' => $token, 'tool_id' => $toolId, 'reserved_for' => 'Administrator', 'comment' => '',
            'reserve_start_date' => '', 'reserve_start_time' => '', 'reserve_end_date' => '2030-02-01', 'reserve_end_time' => '08:00'));
        $this->assertSame('tools.php?populate=' . $toolId, $incomplete->location());
        $this->assertCount(2, json_decode($this->server->pdo()->query("SELECT attr_4 FROM tools WHERE id = $toolId")->fetchColumn(), true));
        $this->assertSame(200, $admin->get('tools.php?populate=' . $toolId)->status);
    }

    public function testToolUpdatesKeepAVersionHistoryAndToolsCanBeRemoved(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Writeblocker ');
        $admin->post('submit.php?type=add_tool', array('token' => $this->token($admin), 'product_name' => $name, 'hw_version' => ' 1.0 ', 'sw_version' => '2.0', 'serialno' => 'S1', 'comment' => 'New', 'flag1' => 'X', 'flag2' => ''));
        $tool = fn () => $this->server->pdo()->query("SELECT * FROM tools WHERE product_name = '$name'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(array('1.0', '2.0', 'S1', 'X', '', 'New'), array_values(array_intersect_key($tool(), array_flip(array('hw_version', 'sw_version', 'serialno', 'flags', 'attr_2', 'attr_3')))));
        $toolId = $tool()['id'];

        $update = array('token' => $this->token($admin), 'tool_id' => $toolId, 'drop_tool' => 'no', 'product_name' => $name, 'comment_old' => 'New', 'flag1' => '');
        $admin->post('submit.php?type=update_tool', $update + array('hw_version' => '1.1', 'hw_version_old' => '1.0', 'sw_version' => '2.0', 'sw_version_old' => '2.0', 'comment' => 'Firmware'));
        $admin->post('submit.php?type=update_tool', $update + array('hw_version' => '1.1', 'hw_version_old' => '1.1', 'sw_version' => '3.0', 'sw_version_old' => '2.0', 'comment' => 'Software'));
        $updated = $tool();
        $this->assertSame(array('1.1', '3.0', 'Software', ''), array($updated['hw_version'], $updated['sw_version'], $updated['attr_3'], $updated['flags']));
        // Newest change first: "time;hw;sw;flags;, " per change.
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d;1\.1 -> 1\.1;2\.0 -> 3\.0;;, \d{4}-\d\d-\d\d \d\d:\d\d:\d\d;1\.0 -> 1\.1;2\.0 -> 2\.0;;, $/', $updated['attr_2']);

        $admin->post('submit.php?type=update_tool', array('drop_tool' => 'yes') + $update);
        $this->assertFalse($tool());
    }

    public function testPagesCannotBeFramedByOtherSites(): void
    {
        foreach (array($this->client()->get('login.php'), $this->admin()->get('index.php')) as $response) {
            $this->assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
            $this->assertSame("frame-ancestors 'self'", $response->header('Content-Security-Policy'));
            $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
            $this->assertSame('same-origin', $response->header('Referrer-Policy'));
        }
    }

    public function testImeiDatabaseUploadNeedsTheCsrfToken(): void
    {
        $this->expectLoggedError('CSRF token mismatch');
        $admin = $this->admin();
        $target = $this->server->dir . '/conf/imei.txt';
        $file = tempnam(sys_get_temp_dir(), 'imei');
        try {
            file_put_contents($file, "35000000|forged\n");
            $admin->post('upload_IMEI.php', array('fileToUpload' => new \CURLFile($file)), true);
            $this->assertFalse(file_exists($target) && strpos(file_get_contents($target), 'forged') !== false);

            file_put_contents($file, "35000000|uploaded\n");
            $response = $admin->post('upload_IMEI.php', array('token' => $this->token($admin), 'fileToUpload' => new \CURLFile($file)), true);
            $this->assertSame('settings.php', $response->location());
            $this->assertSame("35000000|uploaded\n", file_get_contents($target));
        } finally {
            unlink($file);
        }
    }

    public function testOnlyShippedTemplatesCanBeSaved(): void
    {
        $admin = $this->admin();
        $file = $this->server->dir . '/conf/settings.local';
        $state = fn () => file_exists($file) ? file_get_contents($file) : null;
        $before = $state();
        $admin->post('submit.php?type=save_template&template=settings', array('token' => $this->token($admin), 'templatefile' => '<p>Not settings</p>'));
        $this->assertSame($before, $state(), 'save_template used to write any conf/<name>.local.');

        $admin->post('submit.php?type=save_template&template=report_notes', array('token' => $this->token($admin), 'templatefile' => '<p>Our template</p>'));
        $this->assertSame('<p>Our template</p>', file_get_contents($this->server->dir . '/conf/report_notes.local'));
    }

    public function testSettingsCannotBeUsedToInjectIniDirectives(): void
    {
        $admin = $this->admin();
        $defaults = parse_ini_file(KIRJURI_ROOT . '/conf/settings.conf', true);
        $settings = $defaults['settings'];
        $settings['title_text'] = "Kirjuri \"test\"\nshow_errors = \"0\"";
        $response = $admin->post('submit.php?type=save_settings', array(
                'token' => $this->token($admin),
                'settings' => $settings,
                'inv_units' => implode(', ', $defaults['inv_units']),
                'chart' => $defaults['statistics_chart_colors'],
            ));
        $this->assertSame('settings.php', $response->location());

        $saved = parse_ini_file($this->server->dir . '/conf/settings.local', true);
        $this->assertSame("Kirjuri 'test' show_errors = '0'", $saved['settings']['title_text']);
        $this->assertSame('1', $saved['settings']['show_errors']);
        $this->assertStringContainsString("<title>Settings - Kirjuri &#039;test&#039;", $admin->get('settings.php')->body);

        // Put the defaults back for the other tests.
        $admin->post('submit.php?type=reset_default_settings', array('token' => $this->token($admin)));
        $this->assertFileDoesNotExist($this->server->dir . '/conf/settings.local');
    }

    public function testAdminPagesRender(): void
    {
        $admin = $this->admin();
        foreach (array('users.php?populate=2', 'settings.php', 'lang_editor.php', 'log.php', 'help.php', 'tools.php', 'auditor.php') as $page) {
            $this->assertSame(200, $admin->get($page)->status, $page);
        }
        $caseId = $this->createCase($admin, $this->uniqueName('Audited '));
        $this->addDevice($admin, $caseId, 'Audited device'); // Adding a device writes an audit file.
        $audit = glob($this->server->dir . '/logs/audit/*/*.log');
        $this->assertNotEmpty($audit, 'Changes should have written audit files.');
        $view = $admin->get('auditor.php?view=' . basename($audit[0]));
        $this->assertSame(200, $view->status);
        $this->assertStringContainsString('request_contents', $view->body);
    }
}
