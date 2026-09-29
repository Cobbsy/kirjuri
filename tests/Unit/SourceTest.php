<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SourceTest extends TestCase
{
    public static function phpFiles(): array
    {
        $files = array();
        foreach (array_merge(glob(KIRJURI_ROOT . '/*.php'), glob(KIRJURI_ROOT . '/lib/*.php'), glob(KIRJURI_ROOT . '/actions/*.php'), glob(KIRJURI_ROOT . '/extra/*.php'), array(KIRJURI_ROOT . '/bin/kirjuri')) as $file) {
            $files[substr($file, strlen(KIRJURI_ROOT) + 1)] = array($file);
        }
        return $files;
    }

    #[DataProvider('phpFiles')]
    public function testPhpFileHasNoSyntaxErrors(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    public static function templates(): array
    {
        $templates = array();
        foreach (glob(KIRJURI_ROOT . '/views/*.twig') as $file) {
            $templates[basename($file)] = array(basename($file));
        }
        return $templates;
    }

    #[DataProvider('templates')]
    public function testTemplateCompilesWithoutDeprecations(string $template): void
    {
        $twig = new Environment(new FilesystemLoader(KIRJURI_ROOT . '/views'), array('cache' => false));
        require_once KIRJURI_ROOT . '/lib/output.php';
        kirjuri_add_twig_filters($twig);
        $deprecations = array();
        set_error_handler(function ($errno, $errstr) use (&$deprecations) {
            $deprecations[] = $errstr;
            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);
        try {
            $twig->load($template);
        } finally {
            restore_error_handler();
        }
        $this->assertSame(array(), $deprecations);
    }

    public static function filesOutsideDataLayer(): array
    {
        return array_filter(self::phpFiles(), function ($file) {
            return strpos($file, 'lib/') !== 0;
        }, ARRAY_FILTER_USE_KEY);
    }

    /** SQL lives in lib/, so every query can be found, reviewed and tested in one place. */
    #[DataProvider('filesOutsideDataLayer')]
    public function testNoDatabaseAccessOutsideLib(string $file): void
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize(file_get_contents($file)), function ($token) {
            return !$token->isIgnorable();
        }));
        $found = array();
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            $after = $tokens[$i + 2] ?? null;
            if ($token->is(array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR)) && $next !== null && $after !== null
                && in_array(strtolower($next->text), array('prepare', 'query', 'exec', 'quote'), true) && $after->text === '(') {
                $found[] = 'line ' . $token->line . ': ->' . $next->text . '()';
            }
            if ($token->is(T_NEW) && $next !== null && in_array(strtolower(ltrim($next->text, '\\')), array('pdo', 'mysqli'), true)) {
                $found[] = 'line ' . $token->line . ': new ' . $next->text;
            }
        }
        $this->assertSame(array(), $found, 'Move database access into a lib/ function.');
    }

    /** Rich text goes through |purify. |raw is only for values that are not user input. */
    #[DataProvider('templates')]
    public function testTemplatePrintsRawOnlyForTrustedValues(string $template): void
    {
        preg_match_all('/\{\{\s*([\w.]+)\s*\|\s*raw\b/', file_get_contents(KIRJURI_ROOT . '/views/' . $template), $matches);
        $trusted = array('confCrimes'); // conf/crimes_autofill.conf, edited by the administrator.
        $this->assertSame(array(), array_values(array_diff($matches[1], $trusted)), 'Use |purify for rich text.');
    }

    public static function languageFiles(): array
    {
        $files = array();
        foreach (glob(KIRJURI_ROOT . '/conf/lang_*.JSON') as $file) {
            $files[basename($file)] = array($file);
        }
        return $files;
    }

    /** Every language string the code and templates ask for exists, so none prints as blank or logs a warning. */
    #[DataProvider('languageFiles')]
    public function testLanguageFileHasEveryStringInUse(string $file): void
    {
        $used = array();
        foreach (self::filesOutsideDataLayer() + array_filter(self::phpFiles(), fn ($f) => strpos($f, 'lib/') === 0, ARRAY_FILTER_USE_KEY) as $php) {
            preg_match_all("/\\['lang'\\]\\['([A-Za-z0-9_]+)'\\]/", file_get_contents($php[0]), $matches);
            $used = array_merge($used, $matches[1]);
        }
        foreach (glob(KIRJURI_ROOT . '/views/*.twig') as $template) {
            preg_match_all('/\\blang\\.([A-Za-z0-9_]+)/', file_get_contents($template), $matches);
            $used = array_merge($used, $matches[1]);
        }
        $strings = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(300, count(array_unique($used)));
        $this->assertSame(array(), array_values(array_diff(array_unique($used), array_keys($strings))));
    }

    /** Inline event handlers only call functions the same page defines. */
    #[DataProvider('templates')]
    public function testTemplateHandlersCallDefinedFunctions(string $template): void
    {
        $source = file_get_contents(KIRJURI_ROOT . '/views/' . $template);
        preg_match_all('/\\bon[a-z]+="([A-Za-z_]\\w*)\\(/', $source, $matches);
        $defined = array();
        foreach (glob(KIRJURI_ROOT . '/views/js/*.js') as $script) {
            preg_match_all('/function\\s+(\\w+)\\s*\\(/', file_get_contents($script), $found);
            $defined = array_merge($defined, $found[1]);
        }
        foreach (array($source, file_get_contents(KIRJURI_ROOT . '/views/base.twig')) as $page) {
            preg_match_all('/function\\s+(\\w+)\\s*\\(/', $page, $found);
            $defined = array_merge($defined, $found[1]);
        }
        $builtins = array('confirm', 'alert', 'return', 'history', 'window', 'document', 'this', 'location', 'event');
        $this->assertSame(array(), array_values(array_diff(array_unique($matches[1]), $defined, $builtins)));
    }

    /** A label names the field it belongs to: its "for" points at an id on the same page. */
    #[DataProvider('templates')]
    public function testLabelsPointAtFieldsOnTheSamePage(string $template): void
    {
        $source = file_get_contents(KIRJURI_ROOT . '/views/' . $template);
        preg_match_all('/\\bid="([^"{}]+)"/', $source, $ids);
        preg_match_all('/<label[^>]*\\bfor="([^"{}]+)"/', $source, $labels);
        $this->assertSame(array(), array_values(array_diff(array_unique($labels[1]), $ids[1])));
    }

    /** CSRF and case tokens are sent in POST bodies only: URLs end up in logs, history and Referer headers. */
    #[DataProvider('templates')]
    public function testTemplateLinksCarryNoTokens(string $template): void
    {
        $this->assertDoesNotMatchRegularExpression('/[?&](token|ct)=/', file_get_contents(KIRJURI_ROOT . '/views/' . $template));
    }

    /**
     * Colours live in kirjuri.css as classes on its variables, so a theme change reaches every page. Colours that are
     * data, such as an administrator's chart colour printed with {{ }}, may still go in a style attribute.
     */
    #[DataProvider('templates')]
    public function testTemplateSetsNoColoursOfItsOwn(string $template): void
    {
        $source = preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', file_get_contents(KIRJURI_ROOT . '/views/' . $template));
        $colour = '#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|var)\(|\b(?:red|darkred|green|blue|orange|yellow|black|white|grey|gray|lightgrey)\b';
        $found = array();
        preg_match_all('/\sstyle="([^"]*)"/', $source, $styles);
        foreach ($styles[1] as $style) {
            if (preg_match('/(?:^|;)\s*(?:color|background|border)[\w-]*\s*:[^;]*(?:' . $colour . ')/i', $style)) {
                $found[] = 'style="' . $style . '"';
            }
        }
        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/si', $source, $blocks);
        foreach ($blocks[1] as $block) {
            if (preg_match('/(?:color|background|border)[\w-]*\s*:[^;}]*(?:' . $colour . ')/i', $block, $match)) {
                $found[] = '<style> ' . trim($match[0]);
            }
        }
        preg_match_all('/\.css\(\s*[\'"](?:color|background[\w-]*|border[\w-]*)[\'"][^)]*\)/i', $source, $scripts);
        $found = array_merge($found, $scripts[0]);
        $this->assertSame(array(), $found, 'Use a class from views/css/kirjuri.css.');
    }

    /** Every colour variable in kirjuri.css has a dark mode value, so a new colour cannot stay light in dark mode. */
    public function testEveryThemeColourHasADarkModeValue(): void
    {
        $css = file_get_contents(KIRJURI_ROOT . '/views/css/kirjuri.css');
        $this->assertSame(1, preg_match('/^:root \{(.*?)^\}/ms', $css, $light));
        $this->assertSame(1, preg_match('/prefers-color-scheme: dark\) \{\s*:root:not\(\.paper\) \{(.*?)^  \}/ms', $css, $dark));
        preg_match_all('/(--k-[\w-]+):\s*(?:#|rgba?\()/', $light[1], $colours);
        preg_match_all('/(--k-[\w-]+):/', $dark[1], $darkValues);
        // The sidebar is dark in both modes, and calendar events have pale colours in both.
        $sameInBoth = array('--k-sidebar-text', '--k-sidebar-muted', '--k-event-text');
        $this->assertGreaterThan(40, count($colours[1]));
        $this->assertSame(array(), array_values(array_diff($colours[1], $darkValues[1], $sameInBoth)));
    }

    /** The same for HTML that PHP pages print themselves. install.php runs before the theme exists. */
    #[DataProvider('phpFiles')]
    public function testPhpPrintsNoColoursOfItsOwn(string $file): void
    {
        if (basename($file) === 'install.php') {
            $this->assertTrue(true);
            return;
        }
        $this->assertDoesNotMatchRegularExpression('/style=\\\\?["\'][^"\']*(?:color|background)\s*:/i', file_get_contents($file), 'Use a class from views/css/kirjuri.css.');
    }

    /** The statistics charts take their colours from the theme's --k-chart-* palette, which has light and dark values. */
    public function testStatisticsChartsUseTheThemePalette(): void
    {
        $source = file_get_contents(KIRJURI_ROOT . '/views/statistics.twig');
        $this->assertDoesNotMatchRegularExpression('/["\']#[0-9a-fA-F{]|rgba?\(\d|\brandom\(/', $source, 'Use themeColour("--k-chart-N").');
        $this->assertStringContainsString('--k-chart-', $source);
    }

    /** Every stylesheet, script and image a template loads from the application exists. */
    #[DataProvider('templates')]
    public function testTemplateLoadsOnlyFilesThatExist(string $template): void
    {
        preg_match_all('/\b(?:href|src)="((?:views|vendor)\/[^"{}?#]+)"/', file_get_contents(KIRJURI_ROOT . '/views/' . $template), $matches);
        $missing = array_values(array_filter(array_unique($matches[1]), fn ($path) => !file_exists(KIRJURI_ROOT . '/' . $path)));
        $this->assertSame(array(), $missing);
    }
}
