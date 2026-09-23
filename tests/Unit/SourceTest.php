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
}
