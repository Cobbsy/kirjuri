<?php

namespace Kirjuri\Tests\Integration;

/**
 * Device actions check access to one case and then act on device UIDs from the request. These
 * pair the user's own case with a device of a case restricted to admins.
 */
final class DeviceAccessTest extends IntegrationTestCase
{
    private HttpClient $user;
    private int $ownCase;
    private int $ownDevice;
    private int $restrictedCase;
    private int $restrictedDevice;

    protected function setUp(): void
    {
        parent::setUp();
        $username = $this->uniqueName('devuser');
        $this->createUser($username, 'password1', 1);
        $admin = $this->admin();
        $this->ownCase = $this->createCase($admin, $this->uniqueName('Own '));
        $this->ownDevice = $this->addDevice($admin, $this->ownCase, $this->uniqueName('Mine'));
        $this->restrictedCase = $this->createCase($admin, $this->uniqueName('Restricted '), array('case_suspect' => 'Secret'));
        $this->restrictedDevice = $this->addDevice($admin, $this->restrictedCase, $this->uniqueName('Theirs'));
        $admin->post('submit.php?type=case_access&id=' . $this->restrictedCase, array('token' => $this->token($admin),
            'ct' => $this->caseToken($admin, $this->restrictedCase), 'access' => array('admin_only' => 'admin_only')));
        $this->user = $this->login($username, 'password1');
    }

    private function ownTokens(): array
    {
        return array('token' => $this->token($this->user), 'ct' => $this->caseToken($this->user, $this->ownCase));
    }

    public function testDeviceMemoCanNotEditADeviceOfAnotherCase(): void
    {
        $this->expectLoggedError('which is not in this case');
        $this->user->post('submit.php?type=devicememo&returnid=' . $this->restrictedDevice, $this->ownTokens() + array(
                'parent_id' => (string) $this->ownCase, 'id' => (string) $this->restrictedDevice,
                'new_parent_id' => (string) $this->ownCase,
                'report_notes' => '<p>Tampered</p>', 'template_report_notes' => '', 'examiners_notes' => '', 'device_type' => 'Tampered',
                'device_manuf' => '', 'device_model' => '', 'device_size_in_gb' => '1', 'device_owner' => '', 'device_os' => '',
                'device_time_deviation' => '', 'case_request_description' => '', 'device_item_number' => '1', 'device_document' => '',
                'device_identifier' => '', 'device_include_in_report' => '1', 'device_contains_evidence' => '0',
            ));
        $row = $this->row($this->restrictedDevice);
        $this->assertSame('Smartphone', $row['device_type']);
        $this->assertSame((string) $this->restrictedCase, $row['parent_id'], 'The device must not be moved into the attacker\'s case.');
    }

    public function testDetachAndAttachStayWithinTheCase(): void
    {
        $this->server->pdo()->exec('UPDATE exam_requests SET device_host_id = 42 WHERE id = ' . $this->restrictedDevice);
        $this->user->post('submit.php?type=device_detach&uid=' . $this->restrictedDevice . '&returnid=' . $this->ownCase, $this->ownTokens());
        $this->assertSame('42', $this->row($this->restrictedDevice)['device_host_id']);

        $this->user->post('submit.php?type=device_attach&uid=' . $this->ownDevice . '&returnid=' . $this->ownCase, $this->ownTokens() + array('isanta' => (string) $this->restrictedDevice));
        $this->assertSame('0', $this->row($this->ownDevice)['device_host_id'], 'The host must be a device of the same case.');
    }

    public function testRemovingAndStatusChangesStayWithinTheCase(): void
    {
        $this->expectLoggedError('out-of-bounds POST request');
        $this->expectLoggedError('Case access token mismatch');
        $this->user->post('submit.php?type=set_removed&uid=' . $this->restrictedDevice . '&returnid=' . $this->ownCase, $this->ownTokens());
        $this->assertSame('0', $this->row($this->restrictedDevice)['is_removed']);
        $this->user->post('submit.php?type=change_device_status&uid=' . $this->restrictedDevice, $this->ownTokens() + array('device_action' => '9'));
        $this->assertSame('1', $this->row($this->restrictedDevice)['device_action']);
    }

    public function testReadOnlyPagesRespectAccessGroups(): void
    {
        $this->expectLoggedError('out-of-bounds POST request');
        // The progress bar showed the status of any device.
        $this->assertSame('index.php', $this->user->get('progress_bar_static.php?uid=' . $this->restrictedDevice)->location());
        // The "move to case" list on the device page listed restricted cases, with their suspects.
        $page = $this->user->get('device_memo.php?uid=' . $this->ownDevice)->body;
        $this->assertStringContainsString('value="' . $this->ownCase . '"', $page);
        $this->assertStringNotContainsString('value="' . $this->restrictedCase . '"', $page);
    }
}
