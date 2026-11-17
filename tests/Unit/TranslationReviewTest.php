<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once KIRJURI_ROOT . '/tests/tools/translation_review.php';

/** tests/tools/translation_review.php, which builds the translation review page from the language files. */
final class TranslationReviewTest extends TestCase
{
    /** @return array<string, array<string, mixed>> key => the page's entry for it */
    private function entries(): array
    {
        $entries = array();
        foreach (kirjuri_translation_review_data(KIRJURI_ROOT) as $group) {
            foreach ($group['strings'] as $entry) {
                $entries[$entry['key']] = $entry;
            }
        }
        return $entries;
    }

    public function testThePageBuildsWithEveryStringInEveryLanguage(): void
    {
        // kirjuri_translation_review_data() throws when a language file lacks a string on the page.
        $entries = $this->entries();
        $this->assertNotEmpty($entries);
        foreach ($entries as $key => $entry) {
            foreach (KIRJURI_REVIEW_LANGUAGES as $code) {
                $this->assertIsString($entry[$code], "$key in $code");
            }
        }
    }

    public function testEverySettingAndTwoFactorStringIsOnThePage(): void
    {
        // Both groups are collected from the files, so strings added later reach translators without editing the tool.
        $entries = $this->entries();
        $english = json_decode(file_get_contents(KIRJURI_ROOT . '/conf/lang_EN.JSON'), true);
        $expected = array();
        foreach (array_keys(parse_ini_file(KIRJURI_ROOT . '/conf/settings.conf', true)['settings']) as $setting) {
            $expected[] = 'setting_' . $setting;
            $expected[] = 'setting_' . $setting . '_help';
        }
        $expected = array_merge($expected, array_filter(array_keys($english), fn ($key) => strpos($key, 'two_factor') === 0 || strpos($key, 'help_two_factor') === 0));
        $this->assertSame(array(), array_values(array_diff($expected, array_keys($entries))));
        $this->assertNotSame('Two-factor sign-in', $entries['two_factor_enter_code']['where'], 'A string used on one page says which.');
    }

    public function testAuditGroupHasEveryAuditString(): void
    {
        $english = json_decode(file_get_contents(KIRJURI_ROOT . '/conf/lang_EN.JSON'), true);
        $expected = array_values(array_filter(array_keys($english), fn ($key) => strpos($key, 'audit_') === 0));
        $this->assertNotEmpty($expected);
        $groups = array_column(kirjuri_translation_review_data(KIRJURI_ROOT), null, 'id');
        $this->assertArrayHasKey('audit', $groups);
        $keys = array_column($groups['audit']['strings'], 'key');
        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys);
        foreach ($groups['audit']['strings'] as $entry) {
            $this->assertNotSame('', $entry['context'], $entry['key'] . ' says where it shows and what it means.');
        }
    }

    public function testPlaceholdersAreNamedForTranslators(): void
    {
        $this->assertSame('%d is replaced by a number or a date. Keep %d exactly as it is.', kirjuri_translation_review_placeholders('Recovery codes left: %d.'));
        $this->assertSame('', kirjuri_translation_review_placeholders('No placeholder here.'));
        $this->assertStringContainsString('%d', $this->entries()['two_factor_codes_left']['context']);
    }

    public function testDeviceStatusesAreShownWithoutTheirStepAndOnlyToTheirLanguage(): void
    {
        $entry = $this->entries()['device_action12'];
        $this->assertSame(array('DE'), $entry['only']);
        $this->assertStringNotContainsString('|', $entry['DE']);
    }

    public function testNoStringCanEndThePageScript(): void
    {
        // The groups go inside an inline <script>; a "</script>" in a translation would end it.
        $page = kirjuri_translation_review_page(KIRJURI_ROOT, "<script>\nconst GROUPS = /*GROUPS*/;\n</script>");
        $json = substr($page, strpos($page, '= ') + 2, strrpos($page, ';') - strpos($page, '= ') - 2);
        $this->assertStringNotContainsString('<', $json);
        $this->assertIsArray(json_decode($json, true));
        $this->assertSame(1, substr_count($page, '</script>'));
    }

    public function testTheTemplateNeedsOneMarker(): void
    {
        $this->expectException(RuntimeException::class);
        kirjuri_translation_review_page(KIRJURI_ROOT, '<script>const GROUPS = [];</script>');
    }
}
