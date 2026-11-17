<?php

namespace Kirjuri\Tests\Integration;

use PDO;

/** Empty databases of a test's own, dropped when it ends, for running lib/ code directly against a database. */
trait ScratchDatabase
{
    private array $scratch = array();

    protected function tearDown(): void
    {
        foreach ($this->scratch as $name) {
            $this->rootPdo()->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        }
    }

    private function rootPdo(): PDO
    {
        $pdo = new PDO('mysql:host=' . getenv('KIRJURI_TEST_DB_HOST'), getenv('KIRJURI_TEST_DB_USER') ?: 'root', getenv('KIRJURI_TEST_DB_PASSWORD') ?: '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function scratchDatabase(): PDO
    {
        $name = 'kirjuritestmig' . generate_token(8);
        $this->rootPdo()->exec('CREATE DATABASE `' . $name . '`');
        $this->scratch[] = $name;
        $pdo = new PDO($this->scratchDsn($name), getenv('KIRJURI_TEST_DB_USER') ?: 'root', getenv('KIRJURI_TEST_DB_PASSWORD') ?: '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        return $pdo;
    }

    private function scratchDsn(string $name): string
    {
        return 'mysql:host=' . getenv('KIRJURI_TEST_DB_HOST') . ';dbname=' . $name . ';charset=utf8';
    }
}
