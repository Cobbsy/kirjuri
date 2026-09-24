<?php

namespace Kirjuri\Tests\Integration;

final class AccessControlTest extends IntegrationTestCase
{
    public function testAddOnlyUserCanOnlyAddRequests(): void
    {
        $username = $this->uniqueName('addonly');
        $this->createUser($username, 'password1', 3);
        $caseId = $this->createCase($this->admin(), $this->uniqueName('Admin case '));

        $user = $this->login($username, 'password1');
        // Access levels are compared as strings; PHP 8.1 returning ints broke this redirect.
        $this->assertSame('add_case.php', $user->get('index.php')->location());
        $this->assertSame(200, $user->get('add_case.php')->status);
        foreach (array('edit_request.php?case=' . $caseId, 'users.php', 'settings.php', 'statistics.php', 'print_sticker.php?type=examination_request&uid=' . $caseId) as $page) {
            $this->assertSame('index.php', $user->get($page)->location(), $page);
        }

        $ownCase = $this->createCase($user, $this->uniqueName('Add only case '));
        $this->assertSame('1', $this->row($ownCase)['case_status']);
    }

    public function testCasesRestrictedToAnAccessGroupAreHiddenFromOthers(): void
    {
        $outsider = $this->uniqueName('outsider');
        $member = $this->uniqueName('member');
        $this->createUser($outsider, 'password1', 1);
        $this->createUser($member, 'password1', 1);

        $admin = $this->admin();
        $caseId = $this->createCase($admin, $this->uniqueName('Restricted '));
        $deviceId = $this->addDevice($admin, $caseId, $this->uniqueName('M'));
        $response = $admin->post('submit.php?type=case_access&id=' . $caseId, array(
                'token' => $this->token($admin),
                'ct' => $this->caseToken($admin, $caseId),
                'access' => array($member => $member),
            ));
        $this->assertSame($member, $this->row($caseId)['case_owner']);

        $this->expectLoggedError('Denied, user not in access group');
        $this->expectLoggedError('out-of-bounds POST request');
        $outside = $this->login($outsider, 'password1');
        foreach (array('edit_request.php?case=' . $caseId, 'device_memo.php?uid=' . $deviceId, 'case_report.php?case=' . $caseId,
                'download_csv.php?case=' . $caseId, 'download_krf.php?case=' . $caseId, 'timeline.php?case=' . $caseId,
                'print_sticker.php?type=device&uid=' . $deviceId) as $page) {
            $response = $outside->get($page);
            $this->assertSame('index.php', $response->location(), $page);
            $this->assertStringNotContainsString('Doe John', $response->body, $page);
        }

        $inside = $this->login($member, 'password1');
        $this->assertSame(200, $inside->get('edit_request.php?case=' . $caseId)->status);
        // Admins always have access.
        $this->assertSame(200, $admin->get('edit_request.php?case=' . $caseId)->status);
    }

    public function testFrontPageHidesRestrictedCasesFromUsersWithSimilarNames(): void
    {
        $bob = $this->uniqueName('bob');
        $this->createUser($bob, 'password1', 1);
        $admin = $this->admin();
        $suspect = $this->uniqueName('S'); // Short: the front page truncates suspects to 20 characters.
        $caseId = $this->createCase($admin, $this->uniqueName('Similar names '), array('case_suspect' => $suspect));
        // A group containing a longer name that starts with bob's username.
        $admin->post('submit.php?type=case_access&id=' . $caseId, array('token' => $this->token($admin), 'ct' => $this->caseToken($admin, $caseId),
            'access' => array($bob . 'by' => $bob . 'by')));

        $page = $this->login($bob, 'password1')->get('index.php')->body;
        $this->assertStringNotContainsString($suspect, $page, 'The old check matched usernames as substrings.');
        $this->assertStringContainsString($suspect, $admin->get('index.php')->body, 'Admins see every case.');
    }

    public function testRegularUserCannotOpenAdminPages(): void
    {
        $username = $this->uniqueName('regular');
        $this->createUser($username, 'password1', 1);
        $user = $this->login($username, 'password1');
        foreach (array('users.php', 'lang_editor.php', 'log.php', 'backup.php', 'auditor.php') as $page) {
            $this->assertSame('index.php', $user->get($page)->location(), $page);
        }
        $this->assertSame(200, $user->get('settings.php')->status, 'Users may change their own password.');
    }

    public function testBuiltInAccountsCannotBeDeleted(): void
    {
        $admin = $this->admin();
        foreach (array(1 => 'anonymous', 2 => 'admin') as $id => $username) {
            $response = $admin->post('submit.php?type=create_user', array(
                    'token' => $this->token($admin),
                    'username' => $username,
                    'name' => $username,
                    'access' => 'A', // The form sends A for admin; submit.php turns it into 0.
                    'current_password' => KirjuriServer::ADMIN_PASSWORD,
                    'delete_user' => 'delete',
                    'user_id' => (string) $id,
                    'ip_whitelist' => '',
                    'ip_blacklist' => '',
                ));
            $this->assertSame('users.php?populate=' . $id, $response->location());
        }
        $this->assertSame('2', $this->server->pdo()->query('SELECT COUNT(*) FROM users WHERE id IN (1, 2)')->fetchColumn());
    }

    public function testOtherUsersCanBeDeleted(): void
    {
        $username = $this->uniqueName('deleteme');
        $this->createUser($username, 'password1', 1);
        $id = $this->server->pdo()->query("SELECT id FROM users WHERE username = '$username'")->fetchColumn();

        $admin = $this->admin();
        $admin->post('submit.php?type=create_user', array(
                'token' => $this->token($admin),
                'username' => $username,
                'name' => $username,
                'access' => '1',
                'current_password' => KirjuriServer::ADMIN_PASSWORD,
                'delete_user' => 'delete',
                'user_id' => $id,
                'ip_whitelist' => '',
                'ip_blacklist' => '',
            ));
        $this->assertSame('0', $this->server->pdo()->query("SELECT COUNT(*) FROM users WHERE username = '$username'")->fetchColumn());
    }

    public function testIpWhitelistBlocksLoginFromOtherAddresses(): void
    {
        $username = $this->uniqueName('ipuser');
        $this->createUser($username, 'password1', 1);
        $admin = $this->admin();
        $admin->post('submit.php?type=create_user', array(
                'token' => $this->token($admin),
                'username' => $username,
                'name' => $username,
                'access' => '1',
                'password' => '',
                'current_password' => KirjuriServer::ADMIN_PASSWORD,
                'flag1' => '',
                'flag2' => '',
                'ip_whitelist' => '10.99.0.0/16',
                'ip_blacklist' => '',
                'user_id' => '',
            ));
        $response = $this->client()->post('submit.php?type=login', array('username' => $username, 'password' => 'password1', 'auth_type' => 'local'));
        $this->assertSame('login.php', $response->location());
    }

    public function testSessionsDoNotContainPasswordHashes(): void
    {
        $username = $this->uniqueName('hashcheck');
        $this->createUser($username, 'password1', 1);
        $hashes = $this->server->pdo()->query("SELECT password FROM users WHERE username IN ('$username', 'admin')")->fetchAll(\PDO::FETCH_COLUMN);

        $admin = $this->admin();
        $admin->get('users.php');
        $this->login($username, 'password1')->get('settings.php');
        $this->assertNotEmpty($this->server->sessionFiles());
        foreach ($this->server->sessionFiles() as $session) {
            foreach ($hashes as $hash) {
                $this->assertStringNotContainsString($hash, $session);
            }
            $this->assertDoesNotMatchRegularExpression('/\$2y\$\d\d\$/', $session);
        }
    }

    public function testSessionsHoldOnlyTheirOwnState(): void
    {
        $other = $this->uniqueName('otheruser');
        $this->createUser($other, 'password1', 1);
        $client = $this->login($other, 'password1');
        $this->assertSame(200, $client->get('index.php')->status);

        $admin = $this->admin();
        $admin->get('index.php');
        foreach ($this->server->sessionFiles() as $session) {
            // Language strings, the user and tool lists and the unread count are reloaded on every
            // request; storing them made each session file about 26 KB and put every user in it.
            $this->assertLessThan(4096, strlen($session));
            $this->assertStringNotContainsString('all_users', $session);
            $this->assertStringNotContainsString('lang|', $session); // PHP's session format writes top-level keys as key|value.
        }
        $this->assertSame(200, $admin->get('messages.php')->status, 'Pages still get the language strings.');
    }

    public function testPasswordChangeStillChecksTheCurrentPassword(): void
    {
        $username = $this->uniqueName('changer');
        $this->createUser($username, 'password1', 1);
        $user = $this->login($username, 'password1');
        $user->post('submit.php?type=update_password', array('token' => $this->token($user), 'current_password' => 'wrong', 'new_password' => 'password2'));
        $this->login($username, 'password1');

        $user = $this->login($username, 'password1');
        $this->assertSame('login.php', $user->post('submit.php?type=update_password', array('token' => $this->token($user), 'current_password' => 'password1', 'new_password' => 'password2'))->location());
        $this->login($username, 'password2');
    }

    public function testUserStatusStaysInsideTheSessionFolders(): void
    {
        $admin = $this->admin(); // Creates cache/user_admin, the start of the path below.
        $decoy = $this->server->dir . '/cache/' . $this->uniqueName('decoy');
        mkdir($decoy);
        touch($decoy . '/old.txt', time() - 5 * 86400);
        // It used to purge every file older than three days in any folder it was pointed at, conf/ included.
        $response = $admin->get('user_status.php?user=' . rawurlencode('admin/../' . basename($decoy)));
        $this->assertFileExists($decoy . '/old.txt');
        $this->assertStringContainsString('fa-circle-o', $response->body);

        $this->assertStringContainsString('title="online"', $admin->get('user_status.php?user=admin')->body);
    }
}
