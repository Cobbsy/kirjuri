<?php

namespace Kirjuri\Tests\Integration;

/** Case numbering and device counts (lib/cases.php) across the web pages, the API and imports. */
final class CaseDataTest extends IntegrationTestCase
{
    private function apiKey(): string
    {
        $username = $this->uniqueName('casedata');
        $this->createUser($username, 'password1', 1, 'A');
        return api_key_for($this->server->pdo()->query("SELECT * FROM users WHERE username = '$username'")->fetch(\PDO::FETCH_ASSOC));
    }

    private function deviceCount(int $caseId): int
    {
        return (int) $this->row($caseId)['case_devicecount'];
    }

    public function testParallelRequestsNeverShareACaseNumber(): void
    {
        $key = $this->apiKey();
        $prefix = $this->uniqueName('Parallel ');
        $multi = curl_multi_init();
        $handles = array();
        for ($i = 0; $i < 12; $i++) {
            $handle = curl_init($this->server->baseUrl . '/api.php?operation=add&key=' . $key);
            curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(array('case_name' => $prefix . $i)), CURLOPT_RETURNTRANSFER => true));
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $returned = array();
        foreach ($handles as $handle) {
            $this->assertSame(200, curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
            $returned[] = json_decode(curl_multi_getcontent($handle), true)['case_id'];
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        $stored = $this->server->pdo()->query("SELECT case_id FROM exam_requests WHERE case_name LIKE '$prefix%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(12, $stored);
        $this->assertCount(12, array_unique($stored), 'Every case got its own number.');
        sort($returned);
        sort($stored);
        $this->assertSame($stored, $returned, 'The API returns the number it stored.');
    }

    public function testMovingADeviceUpdatesBothCases(): void
    {
        $admin = $this->admin();
        $from = $this->createCase($admin, $this->uniqueName('From '));
        $to = $this->createCase($admin, $this->uniqueName('To '));
        $device = $this->addDevice($admin, $from, $this->uniqueName('Moved'));
        $this->addDevice($admin, $from, $this->uniqueName('Stays'));
        $this->assertSame(2, $this->deviceCount($from));

        $this->saveDeviceMemo($admin, $from, $device, array('new_parent_id' => (string) $to));
        $this->assertSame((string) $to, $this->row($device)['parent_id']);
        $this->assertSame(1, $this->deviceCount($from));
        $this->assertSame(1, $this->deviceCount($to));
    }

    public function testMediaMovedWithoutTheirHostAreDetached(): void
    {
        // A medium kept pointing at its host in the old case, so the new case did not list it.
        $admin = $this->admin();
        $from = $this->createCase($admin, $this->uniqueName('From '));
        $to = $this->createCase($admin, $this->uniqueName('To '));
        $host = $this->addDevice($admin, $from, $this->uniqueName('Host'));
        $model = $this->uniqueName('Card');
        $card = $this->addDevice($admin, $from, $model);
        $admin->post('submit.php?type=device_attach&uid=' . $card . '&returnid=' . $from,
            array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $from), 'isanta' => (string) $host));
        $this->assertSame((string) $host, $this->row($card)['device_host_id']);

        $this->saveDeviceMemo($admin, $from, $card, array('new_parent_id' => (string) $to));
        $this->assertSame((string) $to, $this->row($card)['parent_id']);
        $this->assertSame('0', $this->row($card)['device_host_id']);
        $this->assertStringContainsString($model, $admin->get('edit_request.php?case=' . $to)->body);
    }

    public function testDevicesCanNotBeMovedIntoOtherPeoplesCasesOrOntoDevices(): void
    {
        $this->expectLoggedError('out-of-bounds POST request');
        $admin = $this->admin();
        $username = $this->uniqueName('mover');
        $this->createUser($username, 'password1', 1);
        $own = $this->createCase($admin, $this->uniqueName('Own '));
        $restricted = $this->createCase($admin, $this->uniqueName('Restricted '));
        $admin->post('submit.php?type=case_access&id=' . $restricted, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $restricted), 'access' => array('admin_only' => 'admin_only')));
        $device = $this->addDevice($admin, $own, $this->uniqueName('Mine'));
        $other = $this->addDevice($admin, $own, $this->uniqueName('Other'));

        $user = $this->login($username, 'password1');
        $this->saveDeviceMemo($user, $own, $device, array('new_parent_id' => (string) $restricted));
        $this->assertSame((string) $own, $this->row($device)['parent_id'], 'No access to the target case.');
        $this->saveDeviceMemo($user, $own, $device, array('new_parent_id' => (string) $other));
        $this->assertSame((string) $own, $this->row($device)['parent_id'], 'The target must be a case, not a device.');
    }

    public function testRemovingAndImportingKeepCountsRight(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Counted '));
        $first = $this->addDevice($admin, $caseId, $this->uniqueName('A'));
        $this->addDevice($admin, $caseId, $this->uniqueName('B'));
        $admin->post("submit.php?type=set_removed&uid=$first&returnid=$caseId", array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId)));
        $this->assertSame(1, $this->deviceCount($caseId));

        $krf = $admin->get('download_krf.php?case=' . $caseId)->body;
        $file = tempnam(sys_get_temp_dir(), 'krf');
        file_put_contents($file, $krf);
        $response = $admin->post('import_krf.php', array('token' => $this->token($admin), 'fileToUpload[0]' => new \CURLFile($file)), true);
        unlink($file);
        $imported = (int) substr($response->location(), strlen('edit_request.php?case='));
        $this->assertSame(1, $this->deviceCount($imported), 'Imports used to leave the count at 0 until the front page was opened.');
    }

    public function testApiUpdatesKeepCountsRight(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Api count '));
        $device = $this->addDevice($admin, $caseId, $this->uniqueName('A'));
        $this->client()->post('api.php?operation=update&id=' . $device . '&key=' . $this->apiKey(), array('is_removed' => '1'));
        $this->assertSame(0, $this->deviceCount($caseId));
    }

    public function testFrontPageDoesNotWrite(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Read only '));
        $this->server->pdo()->exec("UPDATE exam_requests SET case_devicecount = 5 WHERE id = $caseId");
        $admin->get('index.php');
        $this->assertSame(5, $this->deviceCount($caseId), 'Viewing the front page must not update rows.');
    }

    private function saveDeviceMemo(HttpClient $client, int $caseId, int $deviceId, array $extra): Response
    {
        $row = $this->row($deviceId);
        return $client->post('submit.php?type=devicememo&returnid=' . $deviceId, $extra + array(
                'token' => $this->token($client),
                'ct' => $this->caseToken($client, $caseId),
                'parent_id' => (string) $caseId,
                'id' => (string) $deviceId,
                'report_notes' => '', 'template_report_notes' => '', 'examiners_notes' => '',
                'device_type' => $row['device_type'], 'device_manuf' => $row['device_manuf'], 'device_model' => $row['device_model'],
                'device_size_in_gb' => '1', 'device_owner' => '', 'device_os' => '', 'device_time_deviation' => '',
                'case_request_description' => '', 'device_item_number' => '1', 'device_document' => '', 'device_identifier' => '',
                'device_include_in_report' => '1', 'device_contains_evidence' => '0',
            ));
    }
}
