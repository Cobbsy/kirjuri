<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The parts of two-factor sign-in that need no database: the code maths, encryption, recovery codes, the QR code. */
final class TwoFactorTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890'; // The shared secret of the RFC 4226 and RFC 6238 test vectors.

    public function testHotpMatchesTheRfc4226TestValues(): void
    {
        $expected = array('755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489');
        foreach ($expected as $counter => $code) {
            $this->assertSame($code, kirjuri_hotp(self::RFC_SECRET, $counter), "Counter $counter");
        }
    }

    public static function rfc6238Times(): array
    {
        return array(
            '59' => array(59, '94287082'),
            '1111111109' => array(1111111109, '07081804'),
            '1111111111' => array(1111111111, '14050471'),
            '1234567890' => array(1234567890, '89005924'),
            '2000000000' => array(2000000000, '69279037'),
            '20000000000' => array(20000000000, '65353130'), // After 2038.
        );
    }

    #[DataProvider('rfc6238Times')]
    public function testTotpMatchesTheRfc6238TestValues(int $time, string $code): void
    {
        $this->assertSame($code, kirjuri_hotp(self::RFC_SECRET, kirjuri_totp_step($time), 8));
        // The six digits an app shows are the last six of the eight.
        $this->assertSame(substr($code, -6), kirjuri_hotp(self::RFC_SECRET, kirjuri_totp_step($time)));
    }

    public function testBase32MatchesTheRfc4648TestValues(): void
    {
        $vectors = array('' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI');
        foreach ($vectors as $bytes => $text) {
            $this->assertSame($text, kirjuri_base32_encode((string) $bytes));
            $this->assertSame((string) $bytes, kirjuri_base32_decode($text));
        }
        // As people type keys and apps print them: lower case, grouped, padded.
        $this->assertSame('foobar', kirjuri_base32_decode('mzxw 6ytb oi======'));
        $this->assertNull(kirjuri_base32_decode('MZXW1'), '1 is not in the base32 alphabet.');
        $secret = kirjuri_two_factor_new_secret();
        $this->assertSame(20, strlen($secret));
        $this->assertSame($secret, kirjuri_base32_decode(kirjuri_two_factor_readable_key($secret)));
        $this->assertMatchesRegularExpression('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', kirjuri_two_factor_readable_key($secret));
    }

    public function testCodesAroundNowAreAcceptedOnce(): void
    {
        $secret = kirjuri_two_factor_new_secret();
        $time = 1800000015;
        $now = kirjuri_totp_step($time);
        foreach (array(-1, 0, 1) as $drift) {
            $this->assertSame($now + $drift, kirjuri_totp_match($secret, kirjuri_hotp($secret, $now + $drift), $time, 0), "A clock $drift step(s) off");
        }
        $this->assertNull(kirjuri_totp_match($secret, kirjuri_hotp($secret, $now - 2), $time, 0), 'Too old');
        $this->assertNull(kirjuri_totp_match($secret, kirjuri_hotp($secret, $now + 2), $time, 0), 'Too far ahead');
        // Once a code is used, it and older ones are refused; a newer one still works.
        $this->assertNull(kirjuri_totp_match($secret, kirjuri_hotp($secret, $now), $time, $now));
        $this->assertNull(kirjuri_totp_match($secret, kirjuri_hotp($secret, $now - 1), $time, $now));
        $this->assertSame($now + 1, kirjuri_totp_match($secret, kirjuri_hotp($secret, $now + 1), $time, $now));
        // Typed with a space or dash in the middle; not with letters, or too short.
        $code = kirjuri_hotp($secret, $now);
        $this->assertSame($now, kirjuri_totp_match($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $time, 0));
        $this->assertSame($now, kirjuri_totp_match($secret, substr($code, 0, 3) . '-' . substr($code, 3), $time, 0));
        $this->assertNull(kirjuri_totp_match($secret, substr($code, 0, 5), $time, 0));
        $this->assertNull(kirjuri_totp_match($secret, 'abcdef', $time, 0));
        $this->assertNull(kirjuri_totp_match('another secret', $code, $time, 0));
    }

    public function testRecoveryCodesAreReadAsTyped(): void
    {
        $codes = array();
        for ($i = 0; $i < 50; $i++) {
            $code = kirjuri_recovery_code_generate();
            $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{5}-[0-9a-hjkmnp-tv-z]{5}$/', $code);
            $this->assertSame(str_replace('-', '', $code), kirjuri_recovery_code_normalise($code));
            $codes[] = $code;
        }
        $this->assertCount(50, array_unique($codes));
        // Upper case, spaces, no dash, and letters that look like the digits they stand for.
        $this->assertSame('k7m2qx9p41', kirjuri_recovery_code_normalise(' K7M2Q X9P4I '));
        $this->assertSame('k7m2qx9p41', kirjuri_recovery_code_normalise('k7m2q-x9p4l'));
        $this->assertSame('k7m20x9p4r', kirjuri_recovery_code_normalise('k7m2O-x9p4r'));
        $this->assertNull(kirjuri_recovery_code_normalise('k7m2q-x9p4'), 'Too short');
        $this->assertNull(kirjuri_recovery_code_normalise('k7m2q-x9p4uu'), 'Too long, and u is not in the alphabet');
        $this->assertNull(kirjuri_recovery_code_normalise('123456'), 'A code from the app');
    }

    public function testSecretsOnlyDecryptWithTheSameKeyAndAccount(): void
    {
        $key = random_bytes(32);
        $secret = kirjuri_two_factor_new_secret();
        $sealed = kirjuri_seal($secret, $key, kirjuri_two_factor_context(7));
        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString(kirjuri_base32_encode($secret), $sealed);
        $this->assertSame($secret, kirjuri_unseal($sealed, $key, kirjuri_two_factor_context(7)));
        $this->assertNotSame($sealed, kirjuri_seal($secret, $key, kirjuri_two_factor_context(7)), 'A new IV every time');

        $this->assertNull(kirjuri_unseal($sealed, random_bytes(32), kirjuri_two_factor_context(7)), 'Another key');
        $this->assertNull(kirjuri_unseal($sealed, $key, kirjuri_two_factor_context(8)), 'Copied to another account');
        $raw = base64_decode(substr($sealed, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertNull(kirjuri_unseal('v1:' . base64_encode($raw), $key, kirjuri_two_factor_context(7)), 'Changed');
        $this->assertNull(kirjuri_unseal('v1:not base64!', $key, kirjuri_two_factor_context(7)));
        $this->assertNull(kirjuri_unseal(kirjuri_base32_encode($secret), $key, kirjuri_two_factor_context(7)), 'Not sealed');
        $this->assertNull(kirjuri_unseal($sealed, 'short key', kirjuri_two_factor_context(7)));
    }

    public function testTheAppLinkNamesTheAccountAndIssuer(): void
    {
        $secret = kirjuri_two_factor_new_secret();
        $this->assertSame('otpauth://totp/Lab%20One:j.smith?secret=' . kirjuri_base32_encode($secret) . '&issuer=Lab%20One&algorithm=SHA1&digits=6&period=30',
            kirjuri_two_factor_uri($secret, 'j.smith', 'Lab One'));
        // The label is "issuer:account", so a colon in the title would split it in the wrong place.
        $this->assertStringStartsWith('otpauth://totp/Lab%201:', kirjuri_two_factor_uri($secret, 'x', 'Lab: 1'));
        $this->assertStringStartsWith('otpauth://totp/Kirjuri:', kirjuri_two_factor_uri($secret, 'x', ' '));
    }

    public function testTheQrCodeIsDrawnHereAndTakesTheThemeColours(): void
    {
        $svg = kirjuri_two_factor_qr_svg(kirjuri_two_factor_uri(kirjuri_two_factor_new_secret(), 'admin', 'Kirjuri'));
        $this->assertStringStartsWith('<svg ', $svg, 'Inline SVG, without an XML declaration');
        $this->assertStringContainsString('aria-hidden="true"', $svg, 'Its box is the image for screen readers');
        $this->assertStringContainsString('class="qr-dark"', $svg);
        $this->assertStringContainsString('class="qr-light"', $svg);
        $this->assertStringNotContainsString('fill="#', $svg);
        $this->assertDoesNotMatchRegularExpression('/^<svg[^>]* width=/', $svg, 'Sized by its box');
        // Nothing is loaded from elsewhere: the only URL is the SVG namespace.
        preg_match_all('#\b(?:https?:)?//[^\s"\']+#', $svg, $urls);
        $this->assertSame(array('http://www.w3.org/2000/svg'), array_values(array_unique($urls[0])));
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function testTheRequirementSetting(): void
    {
        $admin = array('access' => '0');
        $user = array('access' => '1');
        $this->assertSame('0', kirjuri_two_factor_requirement(array()));
        $this->assertSame('admins', kirjuri_two_factor_requirement(array('two_factor_required' => ' Admins ')));
        $this->assertSame('0', kirjuri_two_factor_requirement(array('two_factor_required' => '1')), 'Not a known value');
        $this->assertFalse(kirjuri_two_factor_required_for(array('two_factor_required' => '0'), $admin));
        $this->assertTrue(kirjuri_two_factor_required_for(array('two_factor_required' => 'admins'), $admin));
        $this->assertFalse(kirjuri_two_factor_required_for(array('two_factor_required' => 'admins'), $user));
        $this->assertTrue(kirjuri_two_factor_required_for(array('two_factor_required' => 'everyone'), $user));
    }
}
