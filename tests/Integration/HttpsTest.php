<?php

namespace Kirjuri\Tests\Integration;

/** Moving plain HTTP to the HTTPS address, HSTS, the session cookie over HTTPS, and saving those settings. */
final class HttpsTest extends IntegrationTestCase
{
    /** Run $test with these settings in settings.local, then put the file back as it was. */
    private function withSettings(array $changes, callable $test): void
    {
        $file = $this->server->dir . '/conf/settings.local';
        $saved = file_exists($file) ? file_get_contents($file) : null;
        $text = file_get_contents(KIRJURI_ROOT . '/conf/settings.conf');
        foreach ($changes as $key => $value) {
            $line = $key . ' = "' . $value . '"';
            $text = preg_replace('/^' . preg_quote($key, '/') . ' = .*$/m', $line, $text, 1, $count);
            if ($count === 0) {
                $text = str_replace("[settings]\n", "[settings]\n" . $line . "\n", $text);
            }
        }
        file_put_contents($file, $text);
        try {
            $test();
        } finally {
            $saved === null ? @unlink($file) : file_put_contents($file, $saved);
        }
    }

    public function testPlainHttpMovesToTheHttpsAddressBeforeASessionStarts(): void
    {
        $this->withSettings(array('https_address' => 'https://kirjuri.example.org'), function () {
            $client = $this->client();
            $page = $client->get('edit_request.php?case=5&tab=devices');
            $this->assertSame(302, $page->status);
            $this->assertSame('https://kirjuri.example.org/edit_request.php?case=5&tab=devices', $page->header('Location'));
            $this->assertNull($page->header('Set-Cookie'), 'No session cookie goes out over plain HTTP.');
            $this->assertSame(307, $client->post('submit.php?type=login', array('username' => 'admin'))->status, 'A form keeps its method.');

            $client->setHeader('X-Forwarded-Proto', 'https');
            $this->assertSame(200, $client->get('login.php')->status, 'A proxy that ends HTTPS in front of Kirjuri says so, and the page is served.');
        });
        $this->assertSame(200, $this->client()->get('login.php')->status, 'Off by default.');
    }

    public function testHstsGoesOutOverHttpsOnlyAndOnlyWhenTurnedOn(): void
    {
        $this->withSettings(array('hsts_max_age' => '300'), function () {
            $client = $this->client();
            $this->assertNull($client->get('login.php')->header('Strict-Transport-Security'), 'Browsers ignore it over plain HTTP.');
            $client->setHeader('X-Forwarded-Proto', 'https');
            $this->assertSame('max-age=300', $client->get('login.php')->header('Strict-Transport-Security'));
        });
        $client = $this->client();
        $client->setHeader('X-Forwarded-Proto', 'https');
        $this->assertNull($client->get('login.php')->header('Strict-Transport-Security'), 'Off by default.');
    }

    public function testTheSessionCookieIsSecureBehindAProxyThatEndsHttps(): void
    {
        // The cookie's Secure flag followed $_SERVER['HTTPS'] alone, which a proxy that ends HTTPS in front of Kirjuri
        // does not set, so the session cookie could be sent back over plain HTTP.
        $this->assertStringNotContainsStringIgnoringCase('; secure', (string) $this->client()->get('login.php')->header('Set-Cookie'));
        $client = $this->client();
        $client->setHeader('X-Forwarded-Proto', 'https');
        $this->assertStringContainsStringIgnoringCase('; secure', (string) $client->get('login.php')->header('Set-Cookie'));
    }

    public function testHttpsIsTurnedOnOnlyFromTheSettingsPageOpenedOverHttpsAtThatAddress(): void
    {
        $file = $this->server->dir . '/conf/settings.local';
        $saved = file_exists($file) ? file_get_contents($file) : null;
        try {
            $admin = $this->admin();
            $page = $admin->get('settings.php')->body;
            $this->assertStringContainsString('name="settings[https_address]" value="" id="setting_https_address"', $page);
            $this->assertMatchesRegularExpression('/<label for="setting_hsts_max_age">Remember HTTPS \(HSTS\)<\/label>\s*<select class="form-control" id="setting_hsts_max_age" name="settings\[hsts_max_age\]"/', $page);
            $this->assertMatchesRegularExpression('/<option value="0" selected>Off<\/option>\s*<option value="300">5 minutes, to try it out<\/option>\s*<option value="31536000">1 year<\/option>/', $page);

            $here = 'https://' . parse_url($this->server->baseUrl, PHP_URL_HOST) . ':' . parse_url($this->server->baseUrl, PHP_URL_PORT);
            $save = function (array $changes) use ($admin) {
                $settings = parse_ini_file(KIRJURI_ROOT . '/conf/settings.conf', true)['settings'];
                $admin->post('submit.php?type=save_settings', array('token' => $this->token($admin), 'settings' => $changes + $settings, 'inv_units' => 'Unit 1, Unit 2, Unit 3'));
                $now = parse_ini_file($this->server->dir . '/conf/settings.local', true)['settings'];
                return array($now['https_address'], $now['hsts_max_age'], $now['title_text']);
            };

            // Over plain HTTP neither can be turned on; the rest of the form is saved.
            $this->assertSame(array('', '0', 'Saved over HTTP'), $save(array('https_address' => $here, 'hsts_max_age' => '300', 'title_text' => 'Saved over HTTP')));
            $this->assertStringContainsString('Saved, except the HTTPS settings', $admin->get('settings.php')->body);

            // Over HTTPS, an address other than the one the page is open at would send everyone elsewhere.
            $admin->setHeader('X-Forwarded-Proto', 'https');
            $this->assertSame(array('', '300', 'Kirjuri'), $save(array('https_address' => 'https://kirjuri.example.org', 'hsts_max_age' => '300')));
            $this->assertStringContainsString('Saved, except the HTTPS address: it must be the address this page is open at.', $admin->get('settings.php')->body);

            $this->assertSame(array($here, '300', 'Kirjuri'), $save(array('https_address' => $here . '/', 'hsts_max_age' => '300')));
            $this->assertSame('max-age=300', $admin->get('settings.php')->header('Strict-Transport-Security'));

            // From now on plain HTTP moves there; turning it off works from anywhere.
            $admin->setHeader('X-Forwarded-Proto', null);
            $moved = $admin->get('index.php');
            $this->assertSame(302, $moved->status);
            $this->assertSame($here . '/index.php', $moved->header('Location'));
            $admin->setHeader('X-Forwarded-Proto', 'https');
            $this->assertSame(array('', '0', 'Kirjuri'), $save(array('https_address' => '', 'hsts_max_age' => '0')));
        } finally {
            $saved === null ? @unlink($file) : file_put_contents($file, $saved);
        }
    }

    public function testDoctorSaysWhetherPlainHttpMovesToHttps(): void
    {
        [, $out] = $this->server->cli(array('doctor'));
        $this->assertStringContainsString('[WARN] Pages opened over plain HTTP stay on HTTP', $out);
        $this->assertStringNotContainsString('HSTS', $out);

        $this->withSettings(array('https_address' => 'https://kirjuri.example.org', 'hsts_max_age' => '300'), function () {
            [$status, $out] = $this->server->cli(array('doctor'));
            $this->assertStringContainsString('[ OK ] Pages opened over plain HTTP move to https://kirjuri.example.org', $out);
            $this->assertStringContainsString('[ OK ] Browsers are told to use only HTTPS for Kirjuri for 300 seconds (HSTS)', $out);
        });
        $this->withSettings(array('https_address' => 'kirjuri.example.org', 'hsts_max_age' => 'a year'), function () {
            [, $out] = $this->server->cli(array('doctor'));
            $this->assertStringContainsString('[WARN] https_address "kirjuri.example.org" is not an address like https://kirjuri.example.org', $out);
            $this->assertStringContainsString('[WARN] hsts_max_age "a year" is not a number of seconds', $out);
        });
    }
}
