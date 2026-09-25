<?php

namespace Kirjuri\Tests\Integration;

final class CaseWorkflowTest extends IntegrationTestCase
{
    public function testCaseNumbersIncrementWithinTheYear(): void
    {
        $admin = $this->admin();
        $first = $this->row($this->createCase($admin, $this->uniqueName('First ')));
        $second = $this->row($this->createCase($admin, $this->uniqueName('Second ')));
        $this->assertSame((int) $first['case_id'] + 1, (int) $second['case_id']);
        $this->assertSame($first['id'], $first['parent_id'], 'A case is its own parent.');
    }

    public function testMissingRequiredFieldsDoNotCreateACase(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Incomplete ');
        $response = $admin->post('submit.php?type=examination_request', array('token' => $this->token($admin), 'case_name' => $name));
        $this->assertSame('add_case.php', $response->location());
        $this->assertSame('0', $this->server->pdo()->query("SELECT COUNT(*) FROM exam_requests WHERE case_name = '$name'")->fetchColumn());
    }

    public function testFrontPageListsTheCase(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Listed ');
        $this->createCase($admin, $name);
        $response = $admin->get('index.php');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString($name, $response->body);
    }

    public function testSearchByUidJumpsToTheCaseOrDevice(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Searchable '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('M'));
        $this->assertSame('edit_request.php?case=' . $caseId, $admin->get('index.php?search=UID' . $caseId)->location());
        $this->assertSame('device_memo.php?uid=' . $deviceId, $admin->get('index.php?search=UID' . $deviceId)->location());
        $this->assertSame('index.php', $admin->get('index.php?search=UID99999999')->location());
    }

    public function testDeviceLifecycle(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Devices '));
        $host = $this->addDevice($admin, $caseId, $this->uniqueName('Host'));
        $media = $this->addDevice($admin, $caseId, $this->uniqueName('Card'));
        $this->assertSame('2', $this->row($caseId)['case_devicecount']);

        $token = $this->token($admin);
        $ct = $this->caseToken($admin, $caseId);

        $status = $admin->post('submit.php?type=change_device_status&uid=' . $host, array('token' => $token, 'ct' => $ct, 'device_action' => '3'));
        $this->assertStringContainsString('progress', $status->body);
        $admin->post('submit.php?type=change_device_location&uid=' . $host, array('token' => $token, 'ct' => $ct, 'device_location' => 'Lab'));
        $this->assertSame('3', $this->row($host)['device_action']);
        $this->assertSame('Lab', $this->row($host)['device_location']);

        $admin->post('submit.php?type=device_attach&uid=' . $media . '&returnid=' . $caseId, array('token' => $token, 'ct' => $ct, 'isanta' => (string) $host));
        $this->assertSame((string) $host, $this->row($media)['device_host_id']);
        $admin->post('submit.php?type=device_detach&uid=' . $media . '&returnid=' . $caseId, array('token' => $token));
        $this->assertSame('0', $this->row($media)['device_host_id']);

        $admin->post('submit.php?type=move_all&returnid=' . $caseId, array('token' => $token, 'ct' => $ct, 'device_action' => 'NO_CHANGE', 'device_location' => 'Vault'));
        $this->assertSame('Vault', $this->row($host)['device_location']);
        $this->assertSame('Vault', $this->row($media)['device_location']);

        $admin->post("submit.php?type=set_removed&uid=$media&returnid=$caseId", array('token' => $token, 'ct' => $ct));
        $this->assertSame('1', $this->row($media)['is_removed']);
        $this->assertSame('1', $this->row($caseId)['case_devicecount']);
    }

    public function testDeviceFormKeepsInputAfterAValidationError(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Refill '));
        $response = $admin->post('submit.php?type=device', array(
                'token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'parent_id' => (string) $caseId,
                'device_host_id' => '0', 'device_type' => '', 'device_manuf' => 'Remembered Manufacturer', 'device_model' => 'M1',
                'device_action' => '1', 'device_location' => 'Locker', 'is_removed' => '0',
            ));
        $this->assertSame('edit_request.php?case=' . $caseId . '&tab=devices', $response->location());
        // The form refills from the failed submission (the template used to check a variable that never existed).
        $this->assertStringContainsString('value="Remembered Manufacturer"', $admin->get('edit_request.php?case=' . $caseId . '&tab=devices')->body);
    }

    public function testDeviceMemoSaves(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Memo '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('M'));
        $this->assertSame(200, $admin->get('device_memo.php?uid=' . $deviceId)->status);

        $response = $admin->post('submit.php?type=devicememo&returnid=' . $deviceId, array(
                'token' => $this->token($admin),
                'ct' => $this->caseToken($admin, $caseId),
                'parent_id' => (string) $caseId,
                'id' => (string) $deviceId,
                'report_notes' => '<p>Found evidence</p>',
                'template_report_notes' => '<p>template</p>',
                'examiners_notes' => '<p>Private notes</p>',
                'device_type' => 'Smartphone',
                'device_manuf' => 'Acme',
                'device_model' => 'Renamed',
                'device_size_in_gb' => '128',
                'device_owner' => 'Doe',
                'device_os' => 'Android',
                'device_time_deviation' => '',
                'case_request_description' => '',
                'device_item_number' => '1',
                'device_document' => 'D1',
                'device_identifier' => 'IMEI 1',
                'device_include_in_report' => '1',
                'device_contains_evidence' => '1',
            ));
        $this->assertSame('device_memo.php?uid=' . $deviceId, $response->location());
        $row = $this->row($deviceId);
        $this->assertSame('Renamed', $row['device_model']);
        $this->assertSame('<p>Found evidence</p>', $row['report_notes']);
    }

    public function testCaseUpdateAndStatus(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Update '));
        $name = $this->uniqueName('Renamed ');
        $admin->post('submit.php?type=case_update&uid=' . $caseId, array(
                'token' => $this->token($admin),
                'case_name' => $name, 'case_file_number' => '1', 'case_crime' => 'Fraud', 'classification' => 'Public',
                'case_suspect' => 'Doe', 'case_investigation_lead' => 'Lead', 'case_investigator' => 'Inv',
                'forensic_investigator' => 'Administrator', 'phone_investigator' => '', 'case_investigator_tel' => '1',
                'case_investigator_unit' => 'Unit 1', 'case_request_description' => 'x', 'case_confiscation_date' => '2026-01-15',
                'case_contains_mob_dev' => '1', 'case_urgency' => '1',
            ));
        $row = $this->row($caseId);
        $this->assertSame($name, $row['case_name']);
        $this->assertSame('2', $row['case_status'], 'Assigning an examiner starts the case.');

        $admin->post('submit.php?type=update_request_status', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'returnid' => (string) $caseId, 'case_status' => '3'));
        $this->assertSame('3', $this->row($caseId)['case_status']);
    }

    public function testReportNotesAreSanitised(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Notes '));
        $admin->post('submit.php?type=report_notes', array(
                'token' => $this->token($admin),
                'ct' => $this->caseToken($admin, $caseId),
                'returnid' => (string) $caseId,
                'report_notes' => '<p onclick="alert(1)">Report</p><script>alert(2)</script>',
            ));
        $this->assertSame('<p>Report</p>', $this->row($caseId)['report_notes']);
    }

    public function testWrongCaseTokenIsRejected(): void
    {
        $this->expectLoggedError('Case access token mismatch');
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Token '));
        $this->caseToken($admin, $caseId);
        // Log in as a regular user, as admins bypass the case token check.
        $username = $this->uniqueName('ctuser');
        $this->createUser($username, 'password1', 1);
        $user = $this->login($username, 'password1');
        $user->get('edit_request.php?case=' . $caseId);
        $user->post('submit.php?type=report_notes', array('token' => $this->token($user), 'ct' => 'forged', 'returnid' => (string) $caseId, 'report_notes' => '<p>Forged</p>'));
        $this->assertNull($this->row($caseId)['report_notes']);
    }

    public function testReportsAndExports(): void
    {
        $admin = $this->admin();
        $name = $this->uniqueName('Export ');
        $caseId = $this->createCase($admin, $name);
        $this->addDevice($admin, $caseId, $this->uniqueName('M'));

        // The printed report identifies the case by file number and suspect rather than by name.
        foreach (array('case_report.php?case=' => 'Doe John', 'timeline.php?case=' => $name, 'print_sticker.php?type=examination_request&uid=' => $name) as $page => $expected) {
            $response = $admin->get($page . $caseId);
            $this->assertSame(200, $response->status, $page);
            $this->assertStringContainsString($expected, $response->body, $page);
        }

        $csv = $admin->get('download_csv.php?case=' . $caseId);
        $this->assertStringStartsWith("sep=;\n", $csv->body);
        $this->assertSame(4, count(array_filter(explode("\n", $csv->body))), 'Separator, header, case and device rows.');
        $this->assertStringContainsString('attachment; filename="', $csv->header('Content-Disposition'));

        $krf = json_decode(gzdecode($admin->get('download_krf.php?case=' . $caseId)->body), true);
        $this->assertSame($name, $krf['parent']['case_name']);
        $this->assertCount(1, $krf['children']);
        $this->assertArrayNotHasKey('case_owner', $krf['parent']);
    }

    public function testCsvExportKeepsValuesAndDefusesFormulas(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, '=HYPERLINK("http://evil.example","Open")', array('case_suspect' => "O'Brien; C:\\evidence"));
        $csv = $admin->get('download_csv.php?case=' . $caseId);
        $this->assertStringStartsWith('text/csv', $csv->header('Content-Type'));
        $this->assertNull($csv->header('Content-Encoding'));

        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $csv->body);
        rewind($handle);
        fgets($handle); // sep=;
        $header = fgetcsv($handle, null, ';', '"', '');
        $case = array_combine($header, fgetcsv($handle, null, ';', '"', ''));
        // Values used to lose apostrophes, backslashes and semicolons, and formulas ran when opened in Excel.
        $this->assertSame("'=HYPERLINK(\"http://evil.example\",\"Open\")", $case['case_name']);
        $this->assertSame("O'Brien; C:\\evidence", $case['case_suspect']);
    }

    public function testCaseWithTheSameFileNumberIsPointedOut(): void
    {
        $admin = $this->admin();
        $fileNumber = '7777/R/' . generate_token(4);
        $first = $this->createCase($admin, $this->uniqueName('Original '), array('case_file_number' => $fileNumber));
        $second = $this->createCase($admin, $this->uniqueName('Duplicate '), array('case_file_number' => $fileNumber));
        $page = $admin->get('edit_request.php?case=' . $second)->body;
        $this->assertStringContainsString('edit_request.php?case=' . $first . '"', $page, 'The warning links to the other request.');
        $this->assertStringNotContainsString('edit_request.php?case=' . $second . '"', $page, 'A case is not its own duplicate.');
    }

    public function testFrontPageSearch(): void
    {
        $admin = $this->admin();
        $suspect = 'Zq' . generate_token(6);
        $caseId = $this->createCase($admin, $this->uniqueName('Searched '), array('case_suspect' => $suspect));
        $page = $admin->get('index.php?search=' . $suspect)->body;
        $this->assertStringContainsString('edit_request.php?case=' . $caseId, $page);
        $this->assertStringNotContainsString('edit_request.php?case=' . $caseId, $admin->get('index.php?search=' . $suspect . '&s=3')->body, 'The status filter applies to searches.');
    }

    public function testDuplicateLookupLeavesOutRestrictedCases(): void
    {
        $admin = $this->admin();
        $fileNumber = '8888/R/' . generate_token(4);
        $open = $this->createCase($admin, $this->uniqueName('Open '), array('case_file_number' => $fileNumber));
        $restricted = $this->createCase($admin, $this->uniqueName('Hidden '), array('case_file_number' => $fileNumber));
        $admin->post('submit.php?type=case_access&id=' . $restricted, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $restricted), 'access' => array('admin_only' => 'admin_only')));

        $username = $this->uniqueName('lookup');
        $this->createUser($username, 'password1', 3);
        $body = $this->login($username, 'password1')->get('request.php?case_file_number=' . urlencode($fileNumber))->body;
        $this->assertStringContainsString('case=' . $open . "'", $body);
        $this->assertStringNotContainsString('case=' . $restricted . "'", $body, 'The notice showed restricted cases\' names and suspects.');
        $this->assertStringContainsString('case=' . $restricted . "'", $admin->get('request.php?case_file_number=' . urlencode($fileNumber))->body);
    }

    public function testMissingCasePagesGoToTheFrontPage(): void
    {
        $admin = $this->admin();
        foreach (array('case_report.php?case=99999999', 'timeline.php?case=99999', 'download_krf.php?case=99999') as $page) {
            $this->assertSame('index.php', $admin->get($page)->location(), $page);
        }
    }

    public function testStatisticsPage(): void
    {
        $admin = $this->admin();
        $this->createCase($admin, $this->uniqueName('Stats '));
        $this->assertSame(200, $admin->get('statistics.php')->status);
    }
}
