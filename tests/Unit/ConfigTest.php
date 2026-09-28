<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;
    private string $cwd;

    protected function setUp(): void
    {
        require_once KIRJURI_ROOT . '/lib/config.php';
        $this->cwd = getcwd();
        $this->dir = sys_get_temp_dir() . '/kirjuri_config_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/conf', 0777, true);
        file_put_contents($this->dir . '/conf/RELEASE', '1.0');
        file_put_contents($this->dir . '/conf/settings.conf', "[settings]\ntitle_text = \"Kirjuri\"\nnew_setting = \"default\"\n\n[inv_units]\nUnit1 = \"Unit 1\"\n");
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        array_map('unlink', glob($this->dir . '/conf/*'));
        rmdir($this->dir . '/conf');
        rmdir($this->dir);
    }

    public function testSavedSettingsGetDefaultsForSettingsAddedLater(): void
    {
        file_put_contents('conf/settings.local', "[settings]\ntitle_text = \"Lab\"\n\n[inv_units]\n2 = \"Our unit\"\n");
        $prefs = kirjuri_load_settings(kirjuri_settings_file());
        $this->assertSame('Lab', $prefs['settings']['title_text']);
        $this->assertSame('default', $prefs['settings']['new_setting']);
        $this->assertSame(array('2' => 'Our unit'), $prefs['inv_units'], 'Only [settings] takes defaults; units are the saved list.');
        $this->assertSame('1.0', $prefs['settings']['release']);
    }

    public function testDefaultsAloneBeforeSettingsAreSaved(): void
    {
        $prefs = kirjuri_load_settings(kirjuri_settings_file());
        $this->assertSame(array('title_text' => 'Kirjuri', 'new_setting' => 'default', 'release' => '1.0'), $prefs['settings']);
    }
}
