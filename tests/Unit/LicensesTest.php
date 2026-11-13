<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicensesTest extends TestCase
{
    public function testEveryComponentHasItsLicenceText(): void
    {
        foreach (kirjuri_licensed_components(KIRJURI_ROOT) as $component) {
            $this->assertNotSame('', $component['license'], $component['name']);
            $this->assertNotSame('', trim($component['text']), $component['name'] . ' has no licence text in ' . $component['file']);
        }
    }

    public function testListsKirjuriAndEveryComposerPackage(): void
    {
        $components = kirjuri_licensed_components(KIRJURI_ROOT);
        $names = array_column($components, 'name');
        $this->assertSame('Kirjuri', $names[0]);
        $this->assertSame(trim(file_get_contents(KIRJURI_ROOT . '/conf/RELEASE')), $components[0]['version']);
        $installed = json_decode(file_get_contents(KIRJURI_ROOT . '/vendor/composer/installed.json'), true);
        foreach ($installed['packages'] as $package) {
            $this->assertContains($package['name'], $names);
        }
        $this->assertContains('twig/twig', $names);
    }

    /** A library added to views/ or vendor/ and loaded by a template needs an entry in kirjuri_licensed_components(). */
    public function testEveryThirdPartyFileTheTemplatesLoadIsListed(): void
    {
        $files = array_column(kirjuri_licensed_components(KIRJURI_ROOT), 'file');
        $assets = array();
        foreach (glob(KIRJURI_ROOT . '/views/*.twig') as $template) {
            preg_match_all('#(?:src|href)=["\']((?:views|vendor)/[^"\'{]+)#', file_get_contents($template), $matches);
            $assets = array_merge($assets, $matches[1]);
        }
        $this->assertContains('vendor/tinymce/tinymce/tinymce.min.js', $assets);
        foreach (array_unique($assets) as $asset) {
            if (preg_match('#^views/(css/|img/favicon\.svg$|js/kirjuri\.js$)#', $asset)) {
                continue; // Kirjuri's own.
            }
            $parts = explode('/', $asset);
            // vendor/owner/package, views/js/folder or views/js/name for a single file, views/folder.
            $package = $parts[0] === 'vendor' ? implode('/', array_slice($parts, 0, 3))
                : ($parts[1] === 'js' || $parts[1] === 'img' ? implode('/', array_slice($parts, 0, 3)) : implode('/', array_slice($parts, 0, 2)));
            if (count($parts) === 3 && $parts[1] === 'js') {
                $package = preg_replace('/(\.min)?\.js$/', '', $package);
            }
            $listed = array_filter($files, function ($file) use ($package) {
                return strpos($file, $package) === 0;
            });
            $this->assertNotEmpty($listed, "$asset is loaded by a template but its licence is not listed.");
        }
    }
}
