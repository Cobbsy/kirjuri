<?php

namespace Kirjuri\Tests\Integration;

final class ApiTest extends IntegrationTestCase
{
    private function apiKey(string $username): string
    {
        $query = $this->server->pdo()->prepare('SELECT * FROM users WHERE username = :username');
        $query->execute(array(':username' => $username));
        return api_key_for($query->fetch(\PDO::FETCH_ASSOC));
    }

    private function apiUser(): string
    {
        $username = $this->uniqueName('api');
        $this->createUser($username, 'password1', 1, 'A');
        return $username;
    }

    public function testKeyRequiresTheApiFlag(): void
    {
        $username = $this->uniqueName('noapi');
        $this->createUser($username, 'password1', 1);
        $this->assertSame(403, $this->client()->get('api.php?operation=info&key=' . $this->apiKey($username))->status);
    }

    public function testGetReturnsTheCase(): void
    {
        $name = $this->uniqueName('Api get ');
        $caseId = $this->createCase($this->admin(), $name);
        $response = $this->client()->get('api.php?operation=get&id=' . $caseId . '&key=' . $this->apiKey($this->apiUser()));
        $this->assertSame(200, $response->status);
        $this->assertStringStartsWith('application/json', $response->header('Content-Type'));
        $this->assertSame($name, json_decode($response->body, true)[0]['case_name']);
    }

    public function testFindSearchesCasesAndDevices(): void
    {
        $admin = $this->admin();
        $word = 'Needle' . generate_token(6);
        $caseId = $this->createCase($admin, 'Api find ' . $word);
        $deviceId = $this->addDevice($admin, $caseId, 'Model ' . $word);
        $this->addDevice($admin, $caseId, 'Other model');

        $response = $this->client()->post('api.php?operation=find&key=' . $this->apiKey($this->apiUser()), array('find' => $word));
        $found = json_decode($response->body, true);
        $this->assertSame(array((string) $caseId), array_column($found['cases'], 'id'));
        $this->assertSame(array((string) $deviceId), array_column($found['devices'], 'id'));
    }

    public function testFindAcceptsTermsWithFullTextOperators(): void
    {
        $user = 'zq' . generate_token(6);
        $caseId = $this->createCase($this->admin(), $this->uniqueName('Api mail '), array('case_suspect' => $user . '@example.com'));
        $response = $this->client()->post('api.php?operation=find&key=' . $this->apiKey($this->apiUser()), array('find' => $user . '@example.com'));
        $this->assertSame(200, $response->status);
        $this->assertContains((string) $caseId, array_column(json_decode($response->body, true)['cases'], 'id'));
    }

    public function testAddCreatesACaseInTheCurrentYear(): void
    {
        $name = $this->uniqueName('Api add ');
        $response = $this->client()->post('api.php?operation=add&key=' . $this->apiKey($this->apiUser()), array('case_name' => $name, 'case_suspect' => 'X'));
        $this->assertSame(200, $response->status);

        $row = $this->server->pdo()->query("SELECT * FROM exam_requests WHERE case_name = '$name'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame($row['id'], $row['parent_id']);
        $this->assertSame(date('Y'), substr($row['case_added_date'], 0, 4));
        $this->assertGreaterThan(0, (int) $row['case_id']);
    }

    public function testUpdateAppendsNotesAndCannotMoveItemsOrChangeAccess(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Api update '));
        $otherCase = $this->createCase($admin, $this->uniqueName('Api other '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('M'));

        $response = $this->client()->post('api.php?operation=update&id=' . $deviceId . '&key=' . $this->apiKey($this->apiUser()), array(
                'device_model' => 'Updated by API',
                'report_notes' => 'first',
                'parent_id' => (string) $otherCase,
                'case_owner' => 'nobody',
            ));
        $this->assertSame(200, $response->status);
        $this->client()->post('api.php?operation=update&id=' . $deviceId . '&key=' . $this->apiKey($this->apiUser()), array('report_notes' => 'second'));

        $row = $this->row($deviceId);
        $this->assertSame('Updated by API', $row['device_model']);
        $this->assertSame('<p>first</p><p>second</p>', $row['report_notes']);
        $this->assertSame((string) $caseId, $row['parent_id']);
        $this->assertNull($row['case_owner']);
    }

    public function testNotesFromTheApiCannotRunScripts(): void
    {
        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Api notes '));
        $this->client()->post('api.php?operation=update&id=' . $caseId . '&key=' . $this->apiKey($this->apiUser()), array(
                'report_notes' => 'Safe text<script>alert("report")</script><img src="x.png" onerror="alert(1)">',
                'examiners_notes' => '<a href="javascript:alert(2)">Link</a><script>alert("examiner")</script>',
            ));
        foreach (array('edit_request.php?case=' . $caseId, 'case_report.php?case=' . $caseId) as $page) {
            $body = $admin->get($page)->body;
            $this->assertStringContainsString('Safe text', $body, $page);
            $this->assertDoesNotMatchRegularExpression('/<script>alert|<img[^>]*onerror|href="javascript:/i', $body, $page);
        }
    }

    public function testUnknownColumnReturnsServerError(): void
    {
        $this->expectLoggedError('API update failed');
        $caseId = $this->createCase($this->admin(), $this->uniqueName('Api bad column '));
        $response = $this->client()->post('api.php?operation=update&id=' . $caseId . '&key=' . $this->apiKey($this->apiUser()), array('no_such_column' => 'x'));
        $this->assertSame(500, $response->status);
        $this->assertStringNotContainsString('SQLSTATE', $response->body, 'Database errors must not be shown to API clients.');
    }

    public function testAccessGroupsApplyToTheApi(): void
    {
        $admin = $this->admin();
        $restricted = $this->createCase($admin, $this->uniqueName('Api restricted '));
        $admin->post('submit.php?type=case_access&id=' . $restricted, array(
                'token' => $this->token($admin),
                'ct' => $this->caseToken($admin, $restricted),
                'access' => array('admin_only' => 'admin_only'),
            ));
        $key = $this->apiKey($this->apiUser());

        $this->assertSame(403, $this->client()->get("api.php?operation=get&id=$restricted&key=$key")->status);
        $this->assertSame(403, $this->client()->post("api.php?operation=update&id=$restricted&key=$key", array('case_name' => 'x'))->status);

        $info = json_decode($this->client()->get("api.php?operation=info&key=$key")->body, true);
        $this->assertNotContains((string) $restricted, array_column($info['cases'], 'id'));

        $adminInfo = json_decode($this->client()->get('api.php?operation=info&key=' . $this->apiKeyForAdmin())->body, true);
        $this->assertContains((string) $restricted, array_column($adminInfo['cases'], 'id'));
    }

    private function apiKeyForAdmin(): string
    {
        $this->server->pdo()->exec("UPDATE users SET flags = CONCAT(IFNULL(flags, ''), 'A') WHERE username = 'admin' AND (flags IS NULL OR flags NOT LIKE '%A%')");
        return $this->apiKey('admin');
    }
}
