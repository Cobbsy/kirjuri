<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** lib/https.php: moving plain HTTP to the HTTPS address, HSTS, and the checks on saving those settings. */
final class HttpsTest extends TestCase
{
    public function testARequestIsHttpsDirectlyOrThroughAProxyThatSaysSo(): void
    {
        $this->assertTrue(kirjuri_request_is_https(array('HTTPS' => 'on')));
        $this->assertTrue(kirjuri_request_is_https(array('HTTPS' => '1')));
        $this->assertFalse(kirjuri_request_is_https(array('HTTPS' => 'off')), 'IIS sets HTTPS to off for plain HTTP.');
        $this->assertFalse(kirjuri_request_is_https(array('HTTPS' => '')));
        $this->assertFalse(kirjuri_request_is_https(array()));
        // A proxy that ends TLS in front of Kirjuri passes the scheme on; the first value is the client's.
        $this->assertTrue(kirjuri_request_is_https(array('HTTP_X_FORWARDED_PROTO' => 'https')));
        $this->assertTrue(kirjuri_request_is_https(array('HTTP_X_FORWARDED_PROTO' => 'HTTPS, http')));
        $this->assertFalse(kirjuri_request_is_https(array('HTTP_X_FORWARDED_PROTO' => 'http, https')));
    }

    public function testOnlyAnHttpsOriginIsAValidAddress(): void
    {
        $this->assertSame('https://kirjuri.example.org', kirjuri_valid_https_address(' https://Kirjuri.Example.org/ '));
        $this->assertSame('https://kirjuri.lab:8443', kirjuri_valid_https_address('https://kirjuri.lab:8443'));
        $this->assertSame('https://10.0.0.5', kirjuri_valid_https_address('https://10.0.0.5'));
        $this->assertSame('https://[fd00::5]:8443', kirjuri_valid_https_address('https://[fd00::5]:8443'));
        foreach (array('', 'http://kirjuri.example.org', 'kirjuri.example.org', 'https://kirjuri.example.org/kirjuri',
                'https://kirjuri.example.org?x=1', 'https://user@kirjuri.example.org', 'https://-bad.example.org',
                "https://kirjuri.example.org\r\nSet-Cookie: x=1", 'https://kirjuri.example.org:99999', 'https://') as $bad) {
            $this->assertNull(kirjuri_valid_https_address($bad), $bad);
        }
    }

    public function testPlainHttpMovesToTheAddressWithItsPathAndQuery(): void
    {
        $settings = array('https_address' => 'https://kirjuri.example.org');
        $this->assertSame('https://kirjuri.example.org/kirjuri/edit_request.php?case=5&tab=devices',
            kirjuri_https_redirect($settings, array('REQUEST_URI' => '/kirjuri/edit_request.php?case=5&tab=devices')));
        $this->assertSame('https://kirjuri.example.org/', kirjuri_https_redirect($settings, array('REQUEST_URI' => '')));
        $this->assertSame('https://kirjuri.example.org/', kirjuri_https_redirect($settings, array('REQUEST_URI' => 'http://elsewhere/')),
            'Only a path is kept: the address decides where the request goes.');
        $this->assertNull(kirjuri_https_redirect($settings, array('REQUEST_URI' => '/', 'HTTPS' => 'on')), 'Already on HTTPS.');
        $this->assertNull(kirjuri_https_redirect($settings, array('REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https')));
        $this->assertNull(kirjuri_https_redirect(array('https_address' => ''), array('REQUEST_URI' => '/')), 'Off by default.');
        $this->assertNull(kirjuri_https_redirect(array(), array('REQUEST_URI' => '/')), 'An older settings file without the setting.');
        $this->assertNull(kirjuri_https_redirect(array('https_address' => 'kirjuri.example.org'), array('REQUEST_URI' => '/')),
            'A hand-edited address that is not valid moves nobody; doctor reports it.');
    }

    public function testHstsIsSentOnlyOverHttpsAndOnlyWhenTurnedOn(): void
    {
        $https = array('HTTPS' => 'on');
        $this->assertSame('max-age=300', kirjuri_hsts_header(array('hsts_max_age' => '300'), $https));
        $this->assertSame('max-age=31536000', kirjuri_hsts_header(array('hsts_max_age' => '31536000'), $https));
        $this->assertNull(kirjuri_hsts_header(array('hsts_max_age' => '300'), array()), 'Browsers ignore it over plain HTTP.');
        $this->assertNull(kirjuri_hsts_header(array('hsts_max_age' => '0'), $https));
        $this->assertNull(kirjuri_hsts_header(array(), $https));
        $this->assertNull(kirjuri_hsts_header(array('hsts_max_age' => 'a year'), $https));
        $this->assertNull(kirjuri_hsts_header(array('hsts_max_age' => '-5'), $https));
    }

    public function testTheAddressMustBeTheOneThePageIsOpenAt(): void
    {
        $this->assertTrue(kirjuri_https_address_is_current('https://kirjuri.example.org', array('HTTP_HOST' => 'Kirjuri.example.org')));
        $this->assertTrue(kirjuri_https_address_is_current('https://kirjuri.example.org', array('HTTP_HOST' => 'kirjuri.example.org:443')));
        $this->assertTrue(kirjuri_https_address_is_current('https://kirjuri.lab:8443', array('HTTP_HOST' => 'kirjuri.lab:8443')));
        $this->assertFalse(kirjuri_https_address_is_current('https://kirjuri.lab:8443', array('HTTP_HOST' => 'kirjuri.lab')));
        $this->assertFalse(kirjuri_https_address_is_current('https://kirjuri.example.org', array('HTTP_HOST' => 'kirjuri.example.org.evil')));
        $this->assertFalse(kirjuri_https_address_is_current('https://kirjuri.example.org', array()));
    }

    public function testSavingKeepsTheCurrentValueOfARefusedHttpsSetting(): void
    {
        $current = array('https_address' => '', 'hsts_max_age' => '0', 'title_text' => 'Kirjuri');
        $https = array('HTTPS' => 'on', 'HTTP_HOST' => 'kirjuri.example.org');
        $plain = array('HTTP_HOST' => 'kirjuri.example.org');
        $posted = array('https_address' => 'https://kirjuri.example.org/', 'hsts_max_age' => '300', 'title_text' => 'Lab');

        // Over HTTPS, at the address being saved: both are taken, the address tidied.
        $this->assertSame(array(array('https_address' => 'https://kirjuri.example.org', 'hsts_max_age' => '300', 'title_text' => 'Lab'), null),
            kirjuri_check_https_settings($posted, $current, $https));

        // Over plain HTTP neither can be turned on, and the other settings are still saved.
        $this->assertSame(array(array('https_address' => '', 'hsts_max_age' => '0', 'title_text' => 'Lab'), 'https_needs_https'),
            kirjuri_check_https_settings($posted, $current, $plain));

        // An address that is not this page's, or not an address at all, is refused; HSTS is still taken.
        $this->assertSame(array(array('https_address' => '', 'hsts_max_age' => '300', 'title_text' => 'Lab'), 'https_address_other_host'),
            kirjuri_check_https_settings(array('https_address' => 'https://kirjuri.other.org') + $posted, $current, $https));
        $this->assertSame(array(array('https_address' => '', 'hsts_max_age' => '300', 'title_text' => 'Lab'), 'https_address_invalid'),
            kirjuri_check_https_settings(array('https_address' => 'kirjuri.example.org') + $posted, $current, $https));

        // HSTS takes only the durations the page offers.
        $this->assertSame(array(array('https_address' => '', 'hsts_max_age' => '0', 'title_text' => 'Lab'), null),
            kirjuri_check_https_settings(array('https_address' => '', 'hsts_max_age' => '999999999') + $posted, $current, $https));

        // Turning both off, or saving them unchanged, works from anywhere: that never sends anyone to a broken address.
        $on = array('https_address' => 'https://kirjuri.example.org', 'hsts_max_age' => '31536000', 'title_text' => 'Kirjuri');
        $this->assertSame(array(array('https_address' => '', 'hsts_max_age' => '0', 'title_text' => 'Kirjuri'), null),
            kirjuri_check_https_settings(array('https_address' => ' ', 'hsts_max_age' => '0', 'title_text' => 'Kirjuri'), $on, $plain));
        $this->assertSame(array($on, null), kirjuri_check_https_settings($on, $on, $plain));
        // A setting the form did not send is left out, as before.
        $this->assertSame(array(array('title_text' => 'Lab'), null), kirjuri_check_https_settings(array('title_text' => 'Lab'), $current, $plain));
    }
}
