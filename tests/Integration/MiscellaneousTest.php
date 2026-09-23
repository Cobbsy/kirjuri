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
        $user->get("submit.php?type=archive_received&id=$id&token=" . $this->token($user));
        $this->assertSame('1', $this->server->pdo()->query("SELECT archived_to FROM messages WHERE id = $id")->fetchColumn());
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
        $admin->get('submit.php?type=reset_default_settings&token=' . $this->token($admin));
        $this->assertFileDoesNotExist($this->server->dir . '/conf/settings.local');
    }

    public function testAdminPagesRender(): void
    {
        $admin = $this->admin();
        foreach (array('users.php?populate=2', 'settings.php', 'lang_editor.php', 'log.php', 'help.php', 'tools.php', 'auditor.php') as $page) {
            $this->assertSame(200, $admin->get($page)->status, $page);
        }
        $audit = glob($this->server->dir . '/logs/audit/*/*.log');
        $this->assertNotEmpty($audit, 'Changes should have written audit files.');
        $view = $admin->get('auditor.php?view=' . basename($audit[0]));
        $this->assertSame(200, $view->status);
        $this->assertStringContainsString('request_contents', $view->body);
    }
}
