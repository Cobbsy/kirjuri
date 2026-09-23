<?php

namespace Kirjuri\Tests\Integration;

use PDO;
use RuntimeException;

/**
 * A disposable Kirjuri installation for the integration tests.
 *
 * The application is copied to a temporary folder (vendor/ is symlinked), installed
 * into a new database through install.php and served with PHP's built-in web server.
 * Everything is removed again when the test run ends. One instance is shared by all
 * integration tests.
 *
 * Configure the MySQL/MariaDB server with environment variables. The user needs
 * permission to create and drop databases.
 *   KIRJURI_TEST_DB_HOST      (required, e.g. 127.0.0.1)
 *   KIRJURI_TEST_DB_USER      (default root)
 *   KIRJURI_TEST_DB_PASSWORD  (default empty)
 */
final class KirjuriServer
{
    public const ADMIN_PASSWORD = 'admin-test-password';

    private static ?KirjuriServer $instance = null;

    public string $dir;
    public string $baseUrl;
    public string $database;
    private $process;
    private string $serverLog;

    public static function isConfigured(): bool
    {
        return getenv('KIRJURI_TEST_DB_HOST') !== false && getenv('KIRJURI_TEST_DB_HOST') !== '';
    }

    public static function get(): KirjuriServer
    {
        if (self::$instance === null) {
            self::$instance = new KirjuriServer();
            register_shutdown_function(array(self::$instance, 'destroy'));
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->database = 'kirjuritest' . generate_token(8); // install.php strips anything but letters, digits and dashes.
        $this->dir = sys_get_temp_dir() . '/kirjuri_it_' . generate_token(8);
        $this->copyApplication();
        $this->startServer();
        $this->install();
    }

    public function pdo(): PDO
    {
        $pdo = new PDO('mysql:host=' . getenv('KIRJURI_TEST_DB_HOST') . ';dbname=' . $this->database . ';charset=utf8',
            $this->dbUser(), $this->dbPassword());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        return $pdo;
    }

    /** Lines Kirjuri has written to logs/kirjuri.log so far. */
    public function eventLog(): array
    {
        $file = $this->dir . '/logs/kirjuri.log';
        return file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES) : array();
    }

    /** Lines the built-in server has written to stderr so far (fatal errors end up here). */
    public function serverLog(): array
    {
        return file_exists($this->serverLog) ? file($this->serverLog, FILE_IGNORE_NEW_LINES) : array();
    }

    /** Contents of all stored PHP session files. */
    public function sessionFiles(): array
    {
        return array_map('file_get_contents', glob($this->dir . '/sessions/sess_*'));
    }

    public function destroy(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        try {
            $pdo = new PDO('mysql:host=' . getenv('KIRJURI_TEST_DB_HOST'), $this->dbUser(), $this->dbPassword());
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->database . '`');
        } catch (\PDOException $e) {
            // Nothing more to clean up if the server went away.
        }
        if (is_link($this->dir . '/vendor')) {
            unlink($this->dir . '/vendor'); // Links to the real vendor/ folder, which must never be deleted.
        }
        if (getenv('KIRJURI_TEST_KEEP') === false) {
            delete_directory($this->dir);
        }
    }

    private function dbUser(): string
    {
        return getenv('KIRJURI_TEST_DB_USER') !== false ? getenv('KIRJURI_TEST_DB_USER') : 'root';
    }

    private function dbPassword(): string
    {
        return getenv('KIRJURI_TEST_DB_PASSWORD') !== false ? getenv('KIRJURI_TEST_DB_PASSWORD') : '';
    }

    private function copyApplication(): void
    {
        mkdir($this->dir);
        foreach (scandir(KIRJURI_ROOT) as $entry) {
            if (in_array($entry, array('.', '..', '.git', 'tests', 'vendor', 'cache', 'logs'), true)) {
                continue;
            }
            $this->copy(KIRJURI_ROOT . '/' . $entry, $this->dir . '/' . $entry);
        }
        symlink(KIRJURI_ROOT . '/vendor', $this->dir . '/vendor');
        mkdir($this->dir . '/cache');
        mkdir($this->dir . '/logs');
        mkdir($this->dir . '/logs/audit');
        // Never reuse credentials or settings from a developer's own installation.
        foreach (array('mysql_credentials.php', 'audit_credentials.php', 'settings.local', 'index_columns.local', 'report_notes.local') as $file) {
            if (file_exists($this->dir . '/conf/' . $file)) {
                unlink($this->dir . '/conf/' . $file);
            }
        }
    }

    private function copy(string $source, string $target): void
    {
        if (is_dir($source)) {
            mkdir($target);
            foreach (scandir($source) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->copy($source . '/' . $entry, $target . '/' . $entry);
                }
            }
        } else {
            copy($source, $target);
        }
    }

    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->baseUrl = 'http://127.0.0.1:' . $port;
        $this->serverLog = $this->dir . '/server.log';
        mkdir($this->dir . '/sessions');
        $command = array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'session.save_path=' . $this->dir . '/sessions',
            '-S', '127.0.0.1:' . $port, '-t', $this->dir);
        $this->process = proc_open($command, array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('file', $this->serverLog, 'a'),
                2 => array('file', $this->serverLog, 'a'),
            ), $pipes, $this->dir);

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        throw new RuntimeException('PHP built-in server did not start: ' . implode("\n", $this->serverLog()));
    }

    private function install(): void
    {
        $client = new HttpClient($this->baseUrl);
        $response = $client->post('install.php', array(
                's' => getenv('KIRJURI_TEST_DB_HOST'),
                'u' => $this->dbUser(),
                'p' => $this->dbPassword(),
                'd' => $this->database,
                'ap' => self::ADMIN_PASSWORD,
            ));
        if (strpos($response->body, 'Install script done') === false || !file_exists($this->dir . '/conf/mysql_credentials.php')) {
            throw new RuntimeException('Kirjuri install failed: ' . strip_tags($response->body) . implode("\n", $this->serverLog()));
        }
    }
}
