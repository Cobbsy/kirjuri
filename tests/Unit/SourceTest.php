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
            // rest_api.php is entirely commented out; see its header.
            return strpos($file, 'lib/') !== 0 && $file !== 'rest_api.php';
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
}
