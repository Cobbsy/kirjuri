<?php

namespace Kirjuri\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected KirjuriServer $server;
    private int $eventLogStart;
    private int $serverLogStart;
    private int $errorLogStart;
    private array $expectedErrors = array();

    protected function setUp(): void
    {
        if (!KirjuriServer::isConfigured()) {
            $this->markTestSkipped('Set KIRJURI_TEST_DB_HOST (and KIRJURI_TEST_DB_USER / KIRJURI_TEST_DB_PASSWORD) to run the integration tests.');
        }
        $this->server = KirjuriServer::get();
        $this->eventLogStart = count($this->server->eventLog());
        $this->serverLogStart = count($this->server->serverLog());
        $this->errorLogStart = count($this->server->errorLog());
    }

    /**
     * Every PHP warning, notice and error Kirjuri handles is written to its event log with the
     * level "Error" and to logs/error.log, and fatal errors go to the server's stderr. A test fails if either gains an
     * entry it did not declare with expectLoggedError().
     */
    protected function assertPostConditions(): void
    {
        $unexpected = array();
        foreach (array_slice($this->server->eventLog(), $this->eventLogStart) as $line) {
            if (strpos($line, ';Error;') !== false && !$this->isExpected($line)) {
                $unexpected[] = $line;
            }
        }
        foreach (array_slice($this->server->errorLog(), $this->errorLogStart) as $line) {
            if (!$this->isExpected($line)) {
                $unexpected[] = $line;
            }
        }
        foreach (array_slice($this->server->serverLog(), $this->serverLogStart) as $line) {
            if (preg_match('/PHP (Fatal|Parse|Warning|Notice|Deprecated)/', $line) && !$this->isExpected($line)) {
                $unexpected[] = $line;
            }
        }
        $this->assertSame(array(), $unexpected, 'Unexpected errors were logged.');
    }

    protected function expectLoggedError(string $substring): void
    {
        $this->expectedErrors[] = $substring;
    }

    private function isExpected(string $line): bool
    {
        foreach ($this->expectedErrors as $substring) {
            if (strpos($line, $substring) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function client(): HttpClient
    {
        return new HttpClient($this->server->baseUrl);
    }

    protected function login(string $username, string $password): HttpClient
    {
        $client = $this->client();
        $response = $client->post('submit.php?type=login', array('username' => $username, 'password' => $password, 'auth_type' => 'local'));
        $this->assertSame('index.php', $response->location(), "Login as $username failed.");
        return $client;
    }

    protected function admin(): HttpClient
    {
        return $this->login('admin', KirjuriServer::ADMIN_PASSWORD);
    }

    /** The session CSRF token, read from a page every access level can open. */
    protected function token(HttpClient $client): string
    {
        $token = $client->get('add_case.php')->inputValue('token');
        $this->assertNotEmpty($token, 'No CSRF token found.');
        return $token;
    }

    /** The per-case access token, handed out when the case page is opened. */
    protected function caseToken(HttpClient $client, int $caseId): string
    {
        $token = $client->get('edit_request.php?case=' . $caseId)->inputValue('ct');
        $this->assertNotEmpty($token, 'No case token found.');
        return $token;
    }

    /**
     * Create a user as admin. Access levels: 0 admin, 1 user, 2 view only, 3 add only.
     * Flag "A" enables API access.
     */
    protected function createUser(string $username, string $password, int $access, string $flags = ''): void
    {
        $admin = $this->admin();
        $response = $admin->post('submit.php?type=create_user', array(
                'token' => $this->token($admin),
                'username' => $username,
                'name' => $username,
                'access' => (string) $access,
                'password' => $password,
                'current_password' => KirjuriServer::ADMIN_PASSWORD,
                'flag1' => $flags,
                'flag2' => '',
                'ip_whitelist' => '',
                'ip_blacklist' => '',
                'user_id' => '',
            ));
        $this->assertSame('users.php', $response->location());
    }

    protected function uniqueName(string $prefix): string
    {
        return $prefix . generate_token(6);
    }

    /** Create an examination request and return its UID. */
    protected function createCase(HttpClient $client, string $name, array $fields = array()): int
    {
        $response = $client->post('submit.php?type=examination_request', $fields + array(
                'token' => $this->token($client),
                'case_name' => $name,
                'case_file_number' => '5500/R/' . generate_token(4),
                'case_investigator' => 'Investigator',
                'case_investigator_unit' => 'Unit 1',
                'case_investigator_tel' => '555-1',
                'case_investigation_lead' => 'Lead',
                'case_confiscation_date' => '2026-01-15',
                'case_crime' => 'Fraud',
                'case_suspect' => 'Doe John',
                'case_request_description' => 'Examine the devices',
                'case_urgency' => '1',
                'case_urg_justification' => '',
                'case_requested_action' => 'Full examination',
                'case_contains_mob_dev' => '1',
                'classification' => 'Public',
                'examiners_notes' => '',
            ));
        $this->assertSame(200, $response->status, 'Creating a case failed: ' . $response->location());
        $query = $this->server->pdo()->prepare('SELECT id FROM exam_requests WHERE case_name = :name AND id = parent_id');
        $query->execute(array(':name' => $name));
        $id = $query->fetchColumn();
        $this->assertNotFalse($id, 'The case was not stored.');
        return (int) $id;
    }

    /** Add a device to a case and return its UID. */
    protected function addDevice(HttpClient $client, int $caseId, string $model, array $fields = array()): int
    {
        $response = $client->post('submit.php?type=device', $fields + array(
                'token' => $this->token($client),
                'ct' => $this->caseToken($client, $caseId),
                'parent_id' => (string) $caseId,
                'device_host_id' => '0',
                'device_type' => 'Smartphone',
                'device_manuf' => 'Acme',
                'device_model' => $model,
                'device_identifier' => 'SN-' . $model,
                'device_location' => 'Locker',
                'device_item_number' => '1',
                'device_document' => 'D1',
                'device_time_deviation' => '',
                'device_os' => 'Android',
                'device_size_in_gb' => '64',
                'device_owner' => 'Doe',
                'case_request_description' => '',
                'device_action' => '1',
                'is_removed' => '0',
            ));
        $this->assertSame('edit_request.php?case=' . $caseId . '&tab=devices', $response->location());
        $query = $this->server->pdo()->prepare('SELECT id FROM exam_requests WHERE parent_id = :case AND device_model = :model');
        $query->execute(array(':case' => $caseId, ':model' => $model));
        return (int) $query->fetchColumn();
    }

    protected function row(int $id): array
    {
        $query = $this->server->pdo()->prepare('SELECT * FROM exam_requests WHERE id = :id');
        $query->execute(array(':id' => $id));
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, "Row $id does not exist.");
        return $row;
    }
}
