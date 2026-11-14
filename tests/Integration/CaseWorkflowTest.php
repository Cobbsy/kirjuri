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

    public function testCasePagesAcceptUidsLongerThanFiveDigits(): void
    {
        // The pages cut the UID to five digits, so case 1000042 opened, exported or showed the timeline of case 10000.
        $admin = $this->admin();
        $name = $this->uniqueName('Long UID ');
        $uid = $this->renumberCase($this->createCase($admin, $name));

        $this->assertStringContainsString($name, $admin->get("edit_request.php?case=$uid")->body);
        $caseNumber = $this->row($uid)['case_id'] . '/' . date('Y');
        $this->assertStringContainsString("Case log $caseNumber<", $admin->get("timeline.php?case=$uid")->body);
        $this->assertStringContainsString($name, $admin->get("download_csv.php?case=$uid")->body);
        $krf = json_decode((string) gzdecode($admin->get("download_krf.php?case=$uid")->body), true);
        $this->assertSame($name, $krf['parent']['case_name'] ?? null);
    }

    public function testEmojiAndOtherFourByteCharactersAreSaved(): void
    {
        // MySQL's "utf8" holds three bytes a character, so saving an emoji failed with an error page.
        $admin = $this->admin();
        $name = $this->uniqueName('Phone 📱 ');
        $caseId = $this->createCase($admin, $name, array('case_crime' => 'Fraud 🔒', 'case_suspect' => 'Doe 𝔍ohn'));
        $model = $this->uniqueName('Pixel 😀 ');
        $deviceId = $this->addDevice($admin, $caseId, $model);
        $case = $this->row($caseId);
        $this->assertSame(array($name, 'Fraud 🔒', 'Doe 𝔍ohn'), array($case['case_name'], $case['case_crime'], $case['case_suspect']));
        $this->assertSame($model, $this->row($deviceId)['device_model']);
        $this->assertStringContainsString($name, $admin->get('edit_request.php?case=' . $caseId)->body);

        $tool = $this->uniqueName('Imager 💾 ');
        $admin->post('submit.php?type=add_tool', array('token' => $this->token($admin), 'product_name' => $tool, 'hw_version' => '1',
            'sw_version' => '1', 'serialno' => 'S', 'comment' => '', 'flag1' => '', 'flag2' => ''));
        $this->assertSame('1', $this->server->pdo()->query('SELECT COUNT(*) FROM tools WHERE product_name = ' . $this->server->pdo()->quote($tool))->fetchColumn());
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
        $this->assertTrue(json_decode($status->body, true)['saved']);
        $admin->post('submit.php?type=change_device_location&uid=' . $host, array('token' => $token, 'ct' => $ct, 'device_location' => 'Lab'));
        $this->assertSame('3', $this->row($host)['device_action']);
        $this->assertSame('Lab', $this->row($host)['device_location']);

        $admin->post('submit.php?type=device_attach&uid=' . $media . '&returnid=' . $caseId, array('token' => $token, 'ct' => $ct, 'isanta' => (string) $host));
        $this->assertSame((string) $host, $this->row($media)['device_host_id']);
        $admin->post('submit.php?type=device_detach&uid=' . $media . '&returnid=' . $caseId, array('token' => $token, 'ct' => $ct));
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
                'token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
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

    public function testEditingRequestDetailsKeepsTheStartDateAndAFinishedStatus(): void
    {
        // Every edit of the details set the start date to now, so the report's "examination started" moved with
        // each correction; and an edit of a finished case, from a page opened before it was finished, reopened it.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Start date '));
        $fields = array(
            'case_name' => 'Start date', 'case_file_number' => '1', 'case_crime' => 'Fraud', 'classification' => 'Public',
            'case_suspect' => 'Doe', 'case_investigation_lead' => 'Lead', 'case_investigator' => 'Inv',
            'forensic_investigator' => 'Administrator', 'phone_investigator' => '', 'case_investigator_tel' => '1',
            'case_investigator_unit' => 'Unit 1', 'case_request_description' => 'x', 'case_confiscation_date' => '2026-01-15',
            'case_contains_mob_dev' => '1', 'case_urgency' => '1',
        );
        $this->assertNull($this->row($caseId)['case_start_date']);
        $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId)) + $fields);
        $this->assertNotNull($this->row($caseId)['case_start_date'], 'Assigning an examiner starts the examination.');

        $this->server->pdo()->exec("UPDATE exam_requests SET case_start_date = '2020-02-03 04:05:06' WHERE id = $caseId");
        $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'case_crime' => 'Theft') + $fields);
        $row = $this->row($caseId);
        $this->assertSame('Theft', $row['case_crime']);
        $this->assertSame('2020-02-03 04:05:06', $row['case_start_date']);

        $admin->post('submit.php?type=update_request_status', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'returnid' => (string) $caseId, 'case_status' => '3'));
        $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'case_crime' => 'Arson') + $fields);
        $row = $this->row($caseId);
        $this->assertSame('Arson', $row['case_crime']);
        $this->assertSame('3', $row['case_status']);
        $this->assertSame('2020-02-03 04:05:06', $row['case_start_date']);
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

    public function testSavingRequestDetailsNeedsTheCaseToken(): void
    {
        // case_update checked the session token only, unlike the other case actions, so a user who had never
        // opened the case page could save its details.
        $this->expectLoggedError('Case access token');
        $username = 'ct' . strtolower(generate_token(6));
        $this->createUser($username, 'Password-123', 1);
        $user = $this->login($username, 'Password-123');
        $caseId = $this->createCase($user, $this->uniqueName('Token '));
        $original = $this->row($caseId)['case_name'];

        $user->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($user)) + $this->requestDetails('No case token'));
        $this->assertSame($original, $this->row($caseId)['case_name'], 'Saved without a case token.');
        $user->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($user), 'ct' => 'wrong') + $this->requestDetails('Wrong case token'));
        $this->assertSame($original, $this->row($caseId)['case_name'], 'Saved with a wrong case token.');

        $user->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($user), 'ct' => $this->caseToken($user, $caseId)) + $this->requestDetails('With case token'));
        $this->assertSame('With case token', $this->row($caseId)['case_name']);
    }

    public function testDevicesKeepTheStatusOfTheirCase(): void
    {
        // Saving the request details changed the status of the case row only, so assigning an examiner left the
        // devices "new"; devices added to an open case, or moved into one, kept the status they had.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Device status '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Before '));

        $admin->post('submit.php?type=case_update&uid=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId)) + $this->requestDetails('Device status'));
        $this->assertSame('2', $this->row($caseId)['case_status']);
        $this->assertSame('2', $this->row($deviceId)['case_status'], 'Assigning an examiner opens the devices too.');
        $later = $this->addDevice($admin, $caseId, $this->uniqueName('After '));
        $this->assertSame('2', $this->row($later)['case_status'], 'A device added to an open case is open.');

        $other = $this->createCase($admin, $this->uniqueName('Other case '));
        $this->server->pdo()->exec("UPDATE exam_requests SET case_status = '1' WHERE id = $later");
        kirjuri_move_device($this->server->pdo(), $caseId, $later, $other);
        $this->assertSame('1', $this->row($later)['case_status'], 'A device moved to a new case is new.');
        kirjuri_move_device($this->server->pdo(), $other, $later, $caseId);
        $this->assertSame('2', $this->row($later)['case_status'], 'A device moved back to the open case is open again.');
    }

    public function testMigrationBringsDeviceStatusesInStep(): void
    {
        $pdo = $this->server->pdo();
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Stale status '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Stale '));
        $pdo->exec("UPDATE exam_requests SET case_status = '3' WHERE id = $caseId");
        $pdo->exec("UPDATE exam_requests SET case_status = NULL WHERE id = $deviceId");
        kirjuri_migration_007_device_status($pdo);
        $this->assertSame('3', $this->row($deviceId)['case_status']);
        $this->assertSame('3', $this->row($caseId)['case_status']);
    }

    public function testDevicePageIsOneFormWithOneSaveBar(): void
    {
        // The device page opened its edit form inside a table cell and closed it after the table, and had a save
        // button above the notes and another below them. It is now cards in one form, with a save bar.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Device page '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Page '));
        $page = $admin->get('device_memo.php?uid=' . $deviceId)->body;

        $this->assertSame(1, substr_count($page, 'id="save_button'), 'One save button.');
        $this->assertMatchesRegularExpression('/<div class="form-actions">.*id="save_button".*<\/form>/s', $page);
        $form = substr($page, strpos($page, 'type=devicememo'));
        $form = substr($form, 0, strpos($form, '</form>'));
        foreach (array('device_manuf', 'device_identifier', 'used_tool', 'report_notes', 'examiners_notes', 'device_contains_evidence') as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $form, "$field is in the form.");
        }
        $this->assertStringNotContainsString('<table', $form, 'The form is not split across a table.');
        $this->assertStringContainsString('aria-label="Jump to device..."', $admin->get('device_memo.php?uid=' . $this->addDevice($admin, $caseId, $this->uniqueName('Other ')))->body,
            'The device menu has a name.');
    }

    public function testANewRequestAssignsItsExaminersInACardThatChangesNothingElse(): void
    {
        // A new request opened a dialog over its page every time until an examiner was assigned, and the dialog
        // saved the whole request from hidden copies of its fields, so an edit made meanwhile was written over.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Assign '), array('case_contains_mob_dev' => '1'));
        $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        $this->assertStringContainsString('<section class="card assign-card">', $page);
        $this->assertStringNotContainsString(".modal('show')", $page, 'No dialog opens by itself.');
        $this->assertMatchesRegularExpression('/<form method="post" action="submit\.php\?type=assign_examiners&uid=' . $caseId . '">/', $page);
        $this->assertStringContainsString('<label for="assign_phone_investigator">', $page, 'With mobile devices, a mobile examiner too.');

        // Someone corrects the offence while the card is open.
        $this->server->pdo()->exec("UPDATE exam_requests SET case_crime = 'Corrected meanwhile' WHERE id = $caseId");
        $response = $admin->post('submit.php?type=assign_examiners&uid=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
            'forensic_investigator' => 'Administrator', 'phone_investigator' => '-'));
        $this->assertSame('edit_request.php?case=' . $caseId, $response->location());
        $row = $this->row($caseId);
        $this->assertSame(array('Administrator', '-', '2', 'Corrected meanwhile'), array($row['forensic_investigator'], $row['phone_investigator'], $row['case_status'], $row['case_crime']));
        $this->assertNotNull($row['case_start_date'], 'Assigning starts the examination.');
        $this->assertStringNotContainsString('assign-card', $admin->get('edit_request.php?case=' . $caseId)->body);
    }

    public function testFormsWithTextWorthKeepingWarnBeforeLeaving(): void
    {
        // Leaving the device page, the notes or a new request with unsaved text lost it without a word. The forms
        // are marked for kirjuri.js, and the device page's Enter key sends its form through requestSubmit(), which
        // keeps the submit event; form.submit() skipped it, so the page would have warned about its own save.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Unsaved '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('Unsaved '));
        // The notes editors are there once an examiner is assigned.
        $this->server->pdo()->exec("UPDATE exam_requests SET case_status = '2', forensic_investigator = 'Administrator' WHERE id = $caseId");
        $device = $admin->get('device_memo.php?uid=' . $deviceId)->body;
        $this->assertMatchesRegularExpression('/<form class="form-sections"[^>]*type=devicememo[^>]*data-warn-unsaved>/', $device);
        $this->assertStringNotContainsString('form.submit();', str_replace('requestSubmit() : form.submit();', '', $device));
        $case = $admin->get('edit_request.php?case=' . $caseId)->body;
        foreach (array('report_notes', 'examiners_notes') as $notes) {
            $this->assertMatchesRegularExpression('/action="submit\.php\?type=' . $notes . '" data-warn-unsaved>/', $case, $notes);
        }
        $this->assertMatchesRegularExpression('/type=examination_request" data-validate novalidate data-warn-unsaved>/', $admin->get('add_case.php')->body);
        // The status and location menus save themselves: they are not in a form that warns.
        $this->assertDoesNotMatchRegularExpression('/data-autosave[^>]*data-warn-unsaved|data-warn-unsaved[^>]*data-autosave/', $device . $case);
    }

    public function testRequestDetailsDialogChecksItsFieldsAndWarnsOfOldConfiscations(): void
    {
        // The request details dialog marked nothing required, two labels pointed at the offence field, and the
        // warning for a confiscation over four months old compared the setting with " 1 " and never showed.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Dialog '), array('case_confiscation_date' => '2020-01-15'));
        $settings = $this->server->dir . '/conf/settings.local';
        $saved = file_exists($settings) ? file_get_contents($settings) : null;
        try {
            file_put_contents($settings, preg_replace('/^notify_after_4_months = .*$/m', 'notify_after_4_months = "1"', file_get_contents(KIRJURI_ROOT . '/conf/settings.conf')));
            $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        } finally {
            $saved === null ? @unlink($settings) : file_put_contents($settings, $saved);
        }
        $dialog = substr($page, strpos($page, 'id="perustiedot"'));
        $dialog = substr($dialog, 0, strpos($dialog, '</form>'));
        $this->assertMatchesRegularExpression('/<form class="modal-content"[^>]*data-validate novalidate>/', $dialog);
        foreach (array('case_file_number', 'case_crime', 'case_suspect', 'case_investigator', 'case_investigator_tel', 'case_request_description', 'case_confiscation_date') as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]* id="' . $field . '"[^>]*required>/', $dialog, "$field is required.");
        }
        $this->assertSame(1, substr_count($dialog, 'for="case_crime"'), 'One label for the offence.');
        $this->assertStringContainsString('for="classification"', $dialog);
        $this->assertStringContainsString('for="case_investigator_unit"', $dialog);
        $this->assertMatchesRegularExpression('/<div class="field has-warning">\s*<label for="case_confiscation_date">/', $dialog, 'An old confiscation is marked.');
    }

    public function testDeviceStatusAnswersWithWhatThePageUpdates(): void
    {
        // The status and location menus saved into hidden frames: the device's own bar was the only thing that
        // changed, and the case's progress and its close button waited for "Reload page".
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Live '));
        $admin->post('submit.php?type=update_request_status', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'returnid' => (string) $caseId, 'case_status' => '2'));
        $first = $this->addDevice($admin, $caseId, $this->uniqueName('First '));
        $second = $this->addDevice($admin, $caseId, $this->uniqueName('Second '));
        $set = function (int $device, string $field, string $value) use ($admin, $caseId) {
            $response = $admin->post('submit.php?type=change_device_' . ($field === 'device_action' ? 'status' : 'location') . '&uid=' . $device,
                array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), $field => $value));
            $this->assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
            return json_decode($response->body, true);
        };

        $answer = $set($first, 'device_action', '5|Processed');
        $this->assertSame(array('step' => 5, 'label' => 'Processed', 'percent' => 56, 'bar' => 'warning'), $answer['device']);
        // Steps 5 and 1 of nine each: 6 of 18.
        $this->assertSame(array('percent' => 34, 'bar' => 'warning', 'started' => true, 'all_done' => false), $answer['case']);
        $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        $this->assertStringContainsString('data-close-request hidden', $page, 'The close button waits, hidden.');
        $this->assertStringContainsString('<strong data-case-percent>34%</strong>', $page, 'The page shows what the answer said.');
        $this->assertStringNotContainsString('<iframe', $page);
        $this->assertStringNotContainsString('edit_request.php?case=' . $caseId . '"><i class="fa fa-refresh"', $page, 'No "Reload page".');

        $set($first, 'device_action', '9|Done');
        $answer = $set($second, 'device_action', '0|Empty');
        $this->assertSame(array('percent' => 100, 'bar' => 'success', 'started' => true, 'all_done' => true), $answer['case']);
        $this->assertMatchesRegularExpression('/data-close-request>/', $admin->get('edit_request.php?case=' . $caseId)->body, 'The case can be closed.');

        $this->assertSame(array('saved' => true), $set($second, 'device_location', 'Lab'));
        $this->assertSame('Lab', $this->row($second)['device_location']);
    }

    public function testWorkOnDevicesKeepsTheCaseFromStalling(): void
    {
        // The stalled mark reads the case's last update, which changing a device's status or location, attaching
        // or detaching it did not touch: a case worked on only through its devices was marked stalled.
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Busy '));
        $host = $this->addDevice($admin, $caseId, $this->uniqueName('Host '));
        $media = $this->addDevice($admin, $caseId, $this->uniqueName('Media '));
        $pdo = $this->server->pdo();
        $steps = array(
            'status' => fn () => $admin->post('submit.php?type=change_device_status&uid=' . $host, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'device_action' => '2')),
            'location' => fn () => $admin->post('submit.php?type=change_device_location&uid=' . $host, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'device_location' => 'Lab')),
            'attach' => fn () => $admin->post("submit.php?type=device_attach&uid=$media&returnid=$caseId", array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'isanta' => (string) $host)),
            'detach' => fn () => $admin->post("submit.php?type=device_detach&uid=$media&returnid=$caseId", array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId))),
        );
        foreach ($steps as $step => $post) {
            $pdo->exec("UPDATE exam_requests SET last_updated = '2020-01-01 00:00:00' WHERE id = $caseId");
            $post();
            $this->assertNotSame('2020-01-01 00:00:00', $this->row($caseId)['last_updated'], "Changing the $step of a device updates its case.");
        }
        $this->assertSame('0', $this->row($media)['device_host_id'], 'Detached.');
    }

    public function testDetachingADeviceNeedsTheCaseToken(): void
    {
        // device_detach checked the session token only, unlike attaching and the other case actions.
        $this->expectLoggedError('Case access token');
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Detach '));
        $host = $this->addDevice($admin, $caseId, $this->uniqueName('Host '));
        $media = $this->addDevice($admin, $caseId, $this->uniqueName('Media '));
        $admin->post("submit.php?type=device_attach&uid=$media&returnid=$caseId", array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId), 'isanta' => (string) $host));
        $this->assertSame((string) $host, $this->row($media)['device_host_id']);

        $admin->post("submit.php?type=device_detach&uid=$media&returnid=$caseId", array('token' => $this->token($admin)));
        $this->assertSame((string) $host, $this->row($media)['device_host_id'], 'Detached without a case token.');
        $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        $this->assertMatchesRegularExpression('/type=device_detach&uid=' . $media . '&returnid=' . $caseId . '" data-post data-ct="[0-9a-f]+"/', $page, 'The link sends the case token.');
    }

    public function testSearchShowsAtMostItsLimit(): void
    {
        // A search covers every year, and a short word finds words that start with it: with no limit, it could
        // put every row of a large installation on one page.
        $admin = $this->admin();
        $stem = 'Zl' . strtolower(generate_token(5));
        $ids = array();
        foreach (array('a', 'b', 'c') as $suffix) {
            $ids[] = $this->createCase($admin, $this->uniqueName('Limited '), array('case_suspect' => $stem . $suffix));
        }
        $found = kirjuri_search_cases($this->server->pdo(), $stem, '', false, 2);
        $this->assertCount(2, $found);
        $this->assertCount(3, kirjuri_search_cases($this->server->pdo(), $stem, '', false));
        $page = $admin->get('index.php?search=' . $stem)->body;
        $this->assertStringNotContainsString(sprintf(json_decode(file_get_contents(KIRJURI_ROOT . '/conf/lang_EN.JSON'), true)['search_limited'], KIRJURI_SEARCH_LIMIT), $page,
            'No note while everything fits.');
    }

    public function testSearchLeavesOutDevicesOfRemovedRequests(): void
    {
        // Removing a request marks its own row only, and the search checked each found row on its own, so the devices
        // of a removed request were still found, from every year once searches covered them all.
        $admin = $this->admin();
        $model = 'Zm' . strtolower(generate_token(8));
        $caseId = $this->createCase($admin, $this->uniqueName('Removed request '));
        $deviceId = $this->addDevice($admin, $caseId, $model);
        $this->assertStringContainsString('device_memo.php?uid=' . $deviceId . '"', $admin->get('index.php?search=' . $model)->body);

        $admin->post('submit.php?type=set_removed_case', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
            'remove_exam_request' => (string) $caseId));
        $this->assertSame('1', $this->row($caseId)['is_removed']);
        $page = $admin->get('index.php?search=' . $model)->body;
        $this->assertStringNotContainsString('device_memo.php?uid=' . $deviceId . '"', $page);
        $this->assertStringContainsString('class="alert alert-warning"', $page, 'Nothing is found.');
    }

    public function testFrontPageLinksExaminersByTheirWholeNameAndOnlyWhenThereIsOne(): void
    {
        // The examiner links were written for every row, as empty links when there was no examiner, and the
        // name went into the link unencoded, so one with an ampersand searched for half of it.
        $admin = $this->admin();
        $suspect = 'Ze' . generate_token(6);
        $caseId = $this->createCase($admin, $this->uniqueName('Examiners '), array('case_suspect' => $suspect));
        $page = $admin->get('index.php?search=' . $suspect)->body;
        $this->assertStringContainsString('edit_request.php?case=' . $caseId, $page);
        $this->assertDoesNotMatchRegularExpression('/<a href="index\.php\?search=[^"]*"><\/a>/', $page);

        $this->server->pdo()->prepare('UPDATE exam_requests SET case_status = "2", forensic_investigator = :f, phone_investigator = :p WHERE id = :id')
            ->execute(array(':f' => 'Smith & Jones', ':p' => 'Mobile #1', ':id' => $caseId));
        $page = $admin->get('index.php?search=' . $suspect)->body;
        $this->assertStringContainsString('index.php?search=%22Smith%20%26%20Jones%22&', $page);
        $this->assertStringContainsString('index.php?search=%22Mobile%20%231%22&', $page);
    }

    public function testSearchCoversEveryYearAndFindsWordBeginnings(): void
    {
        // Search only looked in the year on screen, so last year's suspect was not found, and a word only
        // found itself: "Zr...phon" did not find "Zr...phone".
        $admin = $this->admin();
        $stem = 'Zr' . strtolower(generate_token(6));
        $old = $this->createCase($admin, $this->uniqueName('Old year '), array('case_suspect' => $stem . 'phone Holder'));
        $this->server->pdo()->exec("UPDATE exam_requests SET case_added_date = '2019-05-06 07:08:09' WHERE parent_id = $old");
        $current = $this->createCase($admin, $this->uniqueName('This year '), array('case_suspect' => $stem . 'phone Keeper'));
        $device = $this->addDevice($admin, $old, $stem . 'phonemodel');

        $page = $admin->get('index.php?search=' . $stem . 'phon&year=' . date('Y'))->body;
        $this->assertStringContainsString('edit_request.php?case=' . $old . '"', $page, 'A request from another year is found.');
        $this->assertStringContainsString('edit_request.php?case=' . $current . '"', $page);
        $this->assertStringContainsString('device_memo.php?uid=' . $device . '"', $page, 'Devices are found by word beginnings too.');
        $this->assertMatchesRegularExpression('/<b>' . date('Y') . '<\/b> \(1\)/', $page);
        $this->assertMatchesRegularExpression('/<b>2019<\/b> \(2\)/', $page, 'The old request and its device.');
        $this->assertStringContainsString('id="search_hint"', $page);

        // Removing the search returns to the year the search was made from.
        $this->assertStringContainsString('href="index.php?year=2019"', $admin->get('index.php?search=' . $stem . 'phon&year=2019')->body);
        // An exact phrase stays exact, and a minus leaves a word out.
        $this->assertStringNotContainsString('edit_request.php?case=' . $old . '"', $admin->get('index.php?search=' . rawurlencode('"' . $stem . 'phon"'))->body);
        $this->assertStringNotContainsString('edit_request.php?case=' . $old . '"', $admin->get('index.php?search=' . rawurlencode($stem . 'phon -holder'))->body);
    }

    public function testCaseReportWithoutACaseGoesToTheFrontPage(): void
    {
        $this->assertSame('index.php', $this->admin()->get('case_report.php')->location());
    }

    public function testCasePageShowsStatusAndOffersTheNextStep(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Overview '));
        $this->addDevice($admin, $caseId, $this->uniqueName('Model'));

        $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        $this->assertStringContainsString('chip chip-new', $page);
        $this->assertStringContainsString('chip chip-urgent', $page);
        $this->assertMatchesRegularExpression('#action="submit.php\?type=update_request_status".*value="2" name="case_status"#s', $page, 'A new case can be started.');
        $this->assertMatchesRegularExpression('#<select[^>]*name="device_action"#', $admin->get('edit_request.php?case=' . $caseId . '&tab=devices')->body);

        // A finished case is no longer flagged urgent, as on the front page.
        $this->server->pdo()->prepare("UPDATE exam_requests SET case_status = '3' WHERE id = :id")->execute(array(':id' => $caseId));
        $page = $admin->get('edit_request.php?case=' . $caseId)->body;
        $this->assertStringContainsString('chip chip-ready', $page);
        $this->assertStringNotContainsString('chip-urgent', $page);
    }

    public function testAddMediaDialogStartsFromTheHostDevice(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Media dialog '));
        $hostId = $this->addDevice($admin, $caseId, $this->uniqueName('Host'), array('device_item_number' => '7', 'device_owner' => 'Roe Richard'));

        // The item number input had two value attributes; the empty first one won, so the host's number was never filled in.
        $page = $admin->get('edit_request.php?case=' . $caseId . '&tab=devices')->body;
        $this->assertMatchesRegularExpression('#id="media_' . $hostId . '_item"[^>]*value="7"#', $page);
        $this->assertMatchesRegularExpression('#id="media_' . $hostId . '_owner"[^>]*value="Roe Richard"#', $page);

        // A refused device form keeps its input for the next try, but not its owner in the host's media dialog.
        $admin->post('submit.php?type=device', array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
            'parent_id' => (string) $caseId, 'device_host_id' => '0', 'device_type' => '', 'device_owner' => 'Someone Else',
            'device_manuf' => 'Kept Manufacturer', 'device_action' => '1', 'device_location' => 'Locker', 'is_removed' => '0'));
        $page = $admin->get('edit_request.php?case=' . $caseId . '&tab=devices')->body;
        $this->assertStringContainsString('value="Kept Manufacturer"', $page);
        $this->assertMatchesRegularExpression('#id="media_' . $hostId . '_owner"[^>]*value="Roe Richard"#', $page);
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

    public function testFrontPageMarksAndFiltersStalledCases(): void
    {
        $admin = $this->admin();
        $suspect = 'Zs' . generate_token(6);
        $stalled = $this->createCase($admin, $this->uniqueName('Stalled '), array('case_suspect' => $suspect));
        $fresh = $this->createCase($admin, $this->uniqueName('Fresh '), array('case_suspect' => $suspect));
        // Both open; one without an update for longer than stalled_case (120 days by default).
        $this->server->pdo()->prepare('UPDATE exam_requests SET case_status = "2" WHERE id IN (:a, :b)')->execute(array(':a' => $stalled, ':b' => $fresh));
        $this->server->pdo()->prepare('UPDATE exam_requests SET last_updated = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE id = :id')->execute(array(':id' => $stalled));

        $page = $admin->get('index.php?search=' . $suspect)->body;
        $this->assertSame(1, substr_count($page, 'class="stalled-pill"'));
        $this->assertMatchesRegularExpression('/href="index\.php\?[^"]*&s=5"[^>]*>.*?<span class="status-filter-count">1<\/span>/s', $page, 'The stalled filter counts the case.');

        $filtered = $admin->get('index.php?search=' . $suspect . '&s=5')->body;
        $this->assertStringContainsString('edit_request.php?case=' . $stalled . '"', $filtered);
        $this->assertStringNotContainsString('edit_request.php?case=' . $fresh . '"', $filtered);
        $this->assertStringContainsString('edit_request.php?case=' . $fresh . '"', $admin->get('index.php?search=' . $suspect . '&s=2')->body, 'Open includes cases that are not stalled.');
        $this->assertSame(200, $admin->get('index.php?s[]=5')->status, 'A malformed filter shows all cases.');
    }

    public function testSearchFoundDevicesFollowTheirCaseThroughTheStatusFilters(): void
    {
        // A device row has its case's status but not its examiners or last update, so "assigned to me" and
        // "stalled" left out the devices a search found in the user's own or a stalled case.
        $admin = $this->admin();
        $pdo = $this->server->pdo();
        $name = $pdo->query("SELECT name FROM users WHERE username = 'admin'")->fetchColumn();
        $caseId = $this->createCase($admin, $this->uniqueName('Own case '));
        $model = 'Zd' . generate_token(6);
        $deviceId = $this->addDevice($admin, $caseId, $model);
        $pdo->prepare('UPDATE exam_requests SET case_status = "2" WHERE parent_id = :id')->execute(array(':id' => $caseId));
        $pdo->prepare('UPDATE exam_requests SET forensic_investigator = :name, last_updated = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE id = :id')
            ->execute(array(':name' => $name, ':id' => $caseId));

        foreach (array('4', '5') as $filter) {
            $page = $admin->get('index.php?search=' . $model . '&s=' . $filter)->body;
            $this->assertStringContainsString('device_memo.php?uid=' . $deviceId . '"', $page, 'Filter ' . $filter);
        }
        $this->assertStringNotContainsString('device_memo.php?uid=' . $deviceId . '"', $admin->get('index.php?search=' . $model . '&s=1')->body);
    }

    public function testSearchesWithFullTextOperatorsDoNotFail(): void
    {
        $admin = $this->admin();
        $user = 'zq' . generate_token(6);
        $caseId = $this->createCase($admin, 'Mailbox ' . $user, array('case_suspect' => $user . '@example.com'));
        // InnoDB's full text parser rejects these as syntax errors; kirjuri_fulltext_query() rewrites them.
        $response = $admin->get('index.php?search=' . urlencode($user . '@example.com'));
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('edit_request.php?case=' . $caseId, $response->body);
        foreach (array('+', '-', '*', '~', '<>', '@') as $term) {
            $this->assertSame(200, $admin->get('index.php?search=' . urlencode($term))->status, $term);
        }
        // Valid boolean syntax keeps its meaning.
        $this->assertStringContainsString('edit_request.php?case=' . $caseId, $admin->get('index.php?search=' . urlencode('+' . $user))->body);
        $this->assertStringNotContainsString('edit_request.php?case=' . $caseId, $admin->get('index.php?search=' . urlencode($user . ' -mailbox'))->body);
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
