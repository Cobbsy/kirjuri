<?php

namespace Kirjuri\Tests\Integration;

use PDO;

/** Runs lib/migrations.php directly against scratch databases, plus one upgrade through the web server. */
final class MigrationsTest extends IntegrationTestCase
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
        $pdo = new PDO('mysql:host=' . getenv('KIRJURI_TEST_DB_HOST') . ';dbname=' . $name . ';charset=utf8',
            getenv('KIRJURI_TEST_DB_USER') ?: 'root', getenv('KIRJURI_TEST_DB_PASSWORD') ?: '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        return $pdo;
    }

    /** The exam_requests table as the 0.9.2 installer created it on a first run, before any upgrades. */
    private function createLegacySchema(PDO $db): void
    {
        $db->exec('CREATE TABLE users (id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT, username varchar(256) NOT NULL,
            password varchar(256) NOT NULL, name varchar(256), access int(1) DEFAULT 3, flags varchar(16),
            attr_1 mediumtext, attr_2 mediumtext, attr_3 mediumtext, attr_4 mediumtext, attr_5 mediumtext, attr_6 mediumtext,
            attr_7 mediumtext, attr_8 mediumtext) ENGINE=InnoDB DEFAULT CHARSET=utf8');
        $db->exec('CREATE TABLE exam_requests (id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT, parent_id int(16), case_id int(16),
            case_name text, case_suspect text, case_file_number text, case_added_date datetime, device_host_id int(16),
            case_investigator text, forensic_investigator text, phone_investigator text, case_investigation_lead text,
            case_investigator_unit text, case_crime text, case_requested_action text, case_request_description text,
            report_notes mediumtext, device_manuf text, device_model text, device_identifier text, device_owner text,
            FULLTEXT KEY tapaus (case_name, case_suspect, case_file_number, case_investigator, forensic_investigator,
            phone_investigator, case_investigation_lead, case_investigator_unit, case_crime, case_requested_action,
            case_request_description, report_notes, device_manuf, device_model, device_identifier, device_owner)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8');
        $db->exec("INSERT INTO users (id, username, password, access) VALUES (2, 'admin', 'hash', 0)");
        $db->exec("INSERT INTO exam_requests (id, parent_id, case_id, case_name, case_suspect, case_added_date)
            VALUES (1, 1, 1, 'Legacy case', 'Legacy suspect', '2017-05-01 10:00:00')");
    }

    public function testFreshDatabaseGetsEveryMigrationOnce(): void
    {
        $db = $this->scratchDatabase();
        $applied = kirjuri_migrate($db);
        $this->assertSame(array_keys(kirjuri_migrations()), $applied);
        $this->assertSame(array(), kirjuri_migrate($db), 'A second run applies nothing.');
        $this->assertSame(array(), kirjuri_pending_migrations($db));

        foreach (array('users', 'tools', 'messages', 'exam_requests', 'attachments', 'schema_migrations') as $table) {
            $this->assertTrue(kirjuri_table_exists($db, $table), $table);
        }
        $this->assertTrue(kirjuri_column_exists($db, 'exam_requests', 'case_owner'));
        $this->assertTrue(kirjuri_index_exists($db, 'exam_requests', 'idx_parent_id'));
        $this->assertTrue(kirjuri_index_exists($db, 'exam_requests', 'tapaus'));
    }

    public function testLegacyDatabaseIsUpgradedWithoutLosingData(): void
    {
        $db = $this->scratchDatabase();
        $this->createLegacySchema($db);

        kirjuri_migrate($db);

        $this->assertTrue(kirjuri_column_exists($db, 'exam_requests', 'case_owner'));
        $this->assertTrue(kirjuri_table_exists($db, 'attachments'));
        $this->assertTrue(kirjuri_index_exists($db, 'exam_requests', 'idx_case_added_date'));
        $row = $db->query('SELECT case_name, case_suspect, case_owner FROM exam_requests WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(array('case_name' => 'Legacy case', 'case_suspect' => 'Legacy suspect', 'case_owner' => null), $row);
        $this->assertSame('1', $db->query("SELECT COUNT(*) FROM exam_requests WHERE MATCH (case_name, case_suspect, case_file_number,
            case_investigator, forensic_investigator, phone_investigator, case_investigation_lead, case_investigator_unit, case_crime,
            case_requested_action, case_request_description, report_notes, device_manuf, device_model, device_identifier, device_owner)
            AGAINST ('Legacy' IN BOOLEAN MODE)")->fetchColumn(), 'The search index still works.');
    }

    public function testDatabaseThatAlreadyHasSomeChangesIsUpgraded(): void
    {
        // Installations where install.php was rerun already have some of the added columns.
        $db = $this->scratchDatabase();
        $this->createLegacySchema($db);
        $db->exec('ALTER TABLE exam_requests ADD case_owner MEDIUMTEXT');
        $db->exec('CREATE INDEX idx_parent_id ON exam_requests (parent_id)');

        kirjuri_migrate($db);
        $this->assertTrue(kirjuri_column_exists($db, 'exam_requests', 'is_protected'));
        $this->assertSame(array(), kirjuri_pending_migrations($db));
    }

    public function testMigrateCanStopAfterAGivenMigration(): void
    {
        $db = $this->scratchDatabase();
        $this->assertSame(array('001_baseline'), kirjuri_migrate($db, '001_baseline'));
        $this->assertFalse(kirjuri_column_exists($db, 'exam_requests', 'case_owner'));
        $this->assertCount(count(kirjuri_migrations()) - 1, kirjuri_pending_migrations($db));
    }

    public function testInstalledSiteAppliesPendingMigrationsOnTheNextPageLoad(): void
    {
        $db = $this->server->pdo();
        $this->assertSame(array(), kirjuri_pending_migrations($db), 'install.php applies every migration.');

        // Pretend the index migration is new, as after upgrading Kirjuri.
        $db->exec('DROP INDEX idx_msgto ON messages');
        $db->exec("DELETE FROM schema_migrations WHERE id = '003_indexes'");
        unlink($this->server->dir . '/cache/schema_version');

        $this->assertSame(200, $this->client()->get('login.php')->status);
        $this->assertSame(array(), kirjuri_pending_migrations($db));
        $this->assertTrue(kirjuri_index_exists($db, 'messages', 'idx_msgto'));
        $this->assertNotEmpty(array_filter($this->server->eventLog(), fn ($line) => strpos($line, 'Applied database migration 003_indexes') !== false));
    }
}
