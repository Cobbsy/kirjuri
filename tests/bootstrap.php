<?php
// Test bootstrap. Loads the application's dependencies and side effect free helpers.
// include_functions.php is never loaded here: it starts a session and connects to the
// database. The integration tests exercise it through a real web server instead.

define('KIRJURI_ROOT', dirname(__DIR__));

require __DIR__ . '/vendor/autoload.php';
require KIRJURI_ROOT . '/vendor/autoload.php';
require KIRJURI_ROOT . '/lib/helpers.php';
require KIRJURI_ROOT . '/lib/errors.php';
require KIRJURI_ROOT . '/lib/migrations.php';
require KIRJURI_ROOT . '/lib/cases.php';
require KIRJURI_ROOT . '/lib/statistics.php';
require KIRJURI_ROOT . '/lib/install.php';

spl_autoload_register(function ($class) {
    $prefix = 'Kirjuri\\Tests\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
