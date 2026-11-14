<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once KIRJURI_ROOT . '/tests/tools/add_lang_strings.php';

/** tests/tools/add_lang_strings.php, the tool that adds strings to the five language files. */
final class AddLangStringsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kirjuri_lang_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/conf', 0777, true);
        foreach (glob(KIRJURI_ROOT . '/conf/lang_*.JSON') as $file) {
            copy($file, $this->root . '/conf/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/conf/*'));
        rmdir($this->root . '/conf');
        rmdir($this->root);
    }

    private function file(string $code): string
    {
        return file_get_contents($this->root . '/conf/lang_' . $code . '.JSON');
    }

    /** @return string[] the top-level string keys in file order */
    private function keys(string $code): array
    {
        preg_match_all('/^    "((?:[^"\\\\]|\\\\.)*)": "/m', $this->file($code), $matches);
        return $matches[1];
    }

    public function testAddsEachStringAtItsSortedPlaceInEveryFilesOwnEncoding(): void
    {
        $strings = array('zz_test_late' => 'Später – “quoted” a/b', 'aa_test_early' => 'Früh', 'help_zz_test' => 'Mitte');
        foreach (array('EN', 'DE', 'ES', 'FI', 'PT_BR') as $code) {
            $before = json_decode($this->file($code), true);
            $this->assertSame(3, kirjuri_add_lang_strings($this->root, $code, $strings));
            $after = json_decode($this->file($code), true);
            $this->assertIsArray($after, $code);
            $this->assertSame('Später – “quoted” a/b', $after['zz_test_late'], $code);
            $this->assertSame(array_keys($before), array_values(array_diff(array_keys($after), array_keys($strings))), $code);
            $keys = $this->keys($code);
            $sorted = $keys;
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $keys, "$code is no longer in sorted order.");
        }

        // German keeps CRLF line endings and \u escapes; English stays raw UTF-8. Both escape slashes.
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $this->file('DE')));
        $escaped = json_encode('Später – “quoted” a/b'); // \u escapes and \/ for the slash.
        $this->assertStringContainsString('\\u00e4', $escaped);
        $this->assertStringContainsString('"zz_test_late": ' . $escaped . ',' . "\r\n", $this->file('DE'));
        $this->assertStringContainsString('"zz_test_late": "Später – “quoted” a\/b",' . "\n", $this->file('EN'));
        $this->assertStringNotContainsString("\r", $this->file('EN'));
    }

    public function testRefusesAKeyTheFileHasAndWritesNothing(): void
    {
        $before = $this->file('FI');
        try {
            kirjuri_add_lang_strings($this->root, 'FI', array('aa_test_new' => 'Uusi', 'help' => 'Apu'));
            $this->fail('An existing key was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('"help"', $e->getMessage());
        }
        $this->assertSame($before, $this->file('FI'));
    }

    public function testAddsAfterTheLastEntryOfAFileWithoutNestedGroups(): void
    {
        file_put_contents($this->root . '/conf/lang_XX.JSON', "{\n    \"a\": \"1\",\n    \"b\": \"2\"\n}");
        kirjuri_add_lang_strings($this->root, 'XX', array('c' => '3'));
        $this->assertSame("{\n    \"a\": \"1\",\n    \"b\": \"2\",\n    \"c\": \"3\"\n}", $this->file('XX'));
    }
}
