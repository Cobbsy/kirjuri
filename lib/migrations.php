<?php
/* Database schema migrations.
**
** Each migration has an ID that sorts in the order it must run. Applied IDs are recorded
** in the schema_migrations table, so every migration runs once per database. Migrations
** must be idempotent and additive: an existing installation may already have some of the
** changes, because before migrations existed the installer and page loads changed the
** schema directly. Use the *_exists() helpers rather than assuming the current state.
**
** To change the schema, append a migration to kirjuri_migrations(). Never edit or reorder
** one that has shipped. Pending migrations are applied automatically on the next page load
** (see kirjuri_ensure_schema()), by the installer, and by `php bin/kirjuri migrate`.
*/

function kirjuri_migrations() {
    return array(
        '001_baseline' => array(
            'description' => 'Create the tables of Kirjuri 0.9.2',
            'up' => 'kirjuri_migration_001_baseline',
        ),
        '002_exam_request_columns' => array(
            'description' => 'Add access group, password and criminal act date columns to exam_requests',
            'up' => 'kirjuri_migration_002_exam_request_columns',
        ),
        '003_indexes' => array(
            'description' => 'Index the columns used to look up cases, devices, attachments and messages',
            'up' => 'kirjuri_migration_003_indexes',
        ),
        '004_recount_devices' => array(
            'description' => 'Recalculate device counts, which the front page no longer corrects on every view',
            'up' => 'kirjuri_migration_004_recount_devices',
        ),
        '005_utf8mb4' => array(
            'description' => 'Store text as utf8mb4, so that emoji and other characters outside the Basic Multilingual Plane can be saved',
            'up' => 'kirjuri_migration_005_utf8mb4',
        ),
    );
}


function kirjuri_migration_001_baseline(PDO $db) {
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
        username varchar(256) NOT NULL,
        password varchar(256) NOT NULL,
        name varchar(256) DEFAULT NULL,
        access int(1) DEFAULT 3,
        flags varchar(16) DEFAULT NULL,
        attr_1 mediumtext DEFAULT NULL, attr_2 mediumtext DEFAULT NULL, attr_3 mediumtext DEFAULT NULL, attr_4 mediumtext DEFAULT NULL,
        attr_5 mediumtext DEFAULT NULL, attr_6 mediumtext DEFAULT NULL, attr_7 mediumtext DEFAULT NULL, attr_8 mediumtext DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8');

    $db->exec('CREATE TABLE IF NOT EXISTS tools (
        id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
        product_name varchar(256) NOT NULL,
        hw_version varchar(256) DEFAULT NULL,
        sw_version varchar(256) DEFAULT NULL,
        serialno varchar(256) DEFAULT NULL,
        flags varchar(16) DEFAULT NULL,
        attr_1 mediumtext DEFAULT NULL, attr_2 mediumtext DEFAULT NULL, attr_3 mediumtext DEFAULT NULL, attr_4 mediumtext DEFAULT NULL,
        attr_5 mediumtext DEFAULT NULL, attr_6 mediumtext DEFAULT NULL, attr_7 mediumtext DEFAULT NULL, attr_8 mediumtext DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8');

    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
        subject varchar(1024) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        msgfrom varchar(256) DEFAULT NULL,
        msgto varchar(256) DEFAULT NULL,
        received varchar(256) DEFAULT NULL,
        archived_from varchar(1) DEFAULT NULL,
        archived_to varchar(1) DEFAULT NULL,
        deleted_from varchar(1) DEFAULT NULL,
        deleted_to varchar(1) DEFAULT NULL,
        attr_1 mediumtext DEFAULT NULL, attr_2 mediumtext DEFAULT NULL, attr_3 mediumtext DEFAULT NULL, attr_4 mediumtext DEFAULT NULL,
        attr_5 mediumtext DEFAULT NULL, attr_6 mediumtext DEFAULT NULL, attr_7 mediumtext DEFAULT NULL, attr_8 mediumtext DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8');

    $db->exec('CREATE TABLE IF NOT EXISTS event_log (
        id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
        event_timestamp datetime DEFAULT NULL,
        event_descr text,
        event_level tinytext,
        ip varchar(16) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8');

    if (!kirjuri_table_exists($db, 'exam_requests')) {
        // MyISAM for the FULLTEXT search index, which older MySQL versions only support on MyISAM.
        $db->exec('CREATE TABLE exam_requests (
            id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
            parent_id int(16) DEFAULT NULL,
            case_id int(16) DEFAULT NULL,
            case_name text COLLATE utf8_unicode_ci,
            case_suspect text COLLATE utf8_unicode_ci,
            case_file_number text COLLATE utf8_unicode_ci,
            case_added_date datetime DEFAULT NULL,
            case_confiscation_date date DEFAULT NULL,
            case_start_date datetime DEFAULT NULL,
            case_ready_date datetime DEFAULT NULL,
            case_remove_date datetime DEFAULT NULL,
            case_devicecount int(16) DEFAULT NULL,
            case_investigator text COLLATE utf8_unicode_ci,
            forensic_investigator text COLLATE utf8_unicode_ci,
            phone_investigator text COLLATE utf8_unicode_ci,
            case_investigation_lead text COLLATE utf8_unicode_ci,
            case_investigator_tel text COLLATE utf8_unicode_ci,
            case_investigator_unit text COLLATE utf8_unicode_ci,
            case_crime text COLLATE utf8_unicode_ci,
            copy_location text COLLATE utf8_unicode_ci,
            is_removed int(1) DEFAULT NULL,
            case_status varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
            case_requested_action text COLLATE utf8_unicode_ci,
            device_action text COLLATE utf8_unicode_ci,
            case_contains_mob_dev int(1) DEFAULT NULL,
            case_urgency int(1) DEFAULT NULL,
            case_urg_justification text COLLATE utf8_unicode_ci,
            case_request_description text COLLATE utf8_unicode_ci,
            examiners_notes text COLLATE utf8_unicode_ci,
            device_type text COLLATE utf8_unicode_ci,
            device_manuf text COLLATE utf8_unicode_ci,
            device_model text COLLATE utf8_unicode_ci,
            device_os text COLLATE utf8_unicode_ci,
            device_identifier text COLLATE utf8_unicode_ci,
            device_location text COLLATE utf8_unicode_ci,
            device_item_number int(4) DEFAULT NULL,
            device_document text COLLATE utf8_unicode_ci,
            device_owner text COLLATE utf8_unicode_ci,
            device_is_host int(1) DEFAULT 0,
            device_host_id int(16) DEFAULT NULL,
            device_include_in_report int(1) DEFAULT NULL,
            device_time_deviation text COLLATE utf8_unicode_ci,
            device_size_in_gb int(16) DEFAULT NULL,
            device_contains_evidence int(1) DEFAULT 0,
            last_updated datetime DEFAULT NULL,
            classification text COLLATE utf8_unicode_ci,
            report_notes mediumtext COLLATE utf8_unicode_ci,
            FULLTEXT KEY tapaus (case_name, case_suspect, case_file_number, case_investigator, forensic_investigator,
                phone_investigator, case_investigation_lead, case_investigator_unit, case_crime, case_requested_action,
                case_request_description, report_notes, device_manuf, device_model, device_identifier, device_owner)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci');
    }

    $db->exec('CREATE TABLE IF NOT EXISTS attachments (
        id INT(10) AUTO_INCREMENT PRIMARY KEY,
        request_id INT(10), name VARCHAR(256), description TEXT, type VARCHAR(256), size INT NOT NULL, content MEDIUMBLOB NOT NULL,
        uploader VARCHAR(256), date_uploaded DATETIME, hash VARCHAR(256), attr_1 TEXT, attr_2 TEXT, attr_3 TEXT)');
}


function kirjuri_migration_002_exam_request_columns(PDO $db) {
    $columns = array(
        'criminal_act_date_start' => 'DATETIME',
        'criminal_act_date_end' => 'DATETIME',
        'case_password' => 'MEDIUMTEXT',
        'case_owner' => 'MEDIUMTEXT',
        'is_protected' => 'INT(1)',
    );
    foreach ($columns as $column => $type) {
        if (!kirjuri_column_exists($db, 'exam_requests', $column)) {
            $db->exec('ALTER TABLE exam_requests ADD ' . $column . ' ' . $type);
        }
    }
}


function kirjuri_migration_003_indexes(PDO $db) {
    $indexes = array(
        array('exam_requests', 'idx_parent_id', 'parent_id'),
        array('exam_requests', 'idx_case_added_date', 'case_added_date'),
        array('exam_requests', 'idx_device_host_id', 'device_host_id'),
        array('attachments', 'idx_request_id', 'request_id'),
        array('messages', 'idx_msgto', 'msgto(191)'),
        array('messages', 'idx_msgfrom', 'msgfrom(191)'),
    );
    foreach ($indexes as $index) {
        list($table, $name, $columns) = $index;
        if (!kirjuri_index_exists($db, $table, $name)) {
            $db->exec('CREATE INDEX ' . $name . ' ON ' . $table . ' (' . $columns . ')');
        }
    }
}


function kirjuri_migration_004_recount_devices(PDO $db) {
    $db->exec('UPDATE exam_requests SET case_devicecount = 0 WHERE id = parent_id');
    $db->exec('UPDATE exam_requests c
        JOIN (SELECT parent_id, COUNT(*) AS devices FROM exam_requests WHERE id != parent_id AND is_removed = 0 GROUP BY parent_id) d
        ON d.parent_id = c.id
        SET c.case_devicecount = d.devices
        WHERE c.id = c.parent_id');
}


function kirjuri_migration_005_utf8mb4(PDO $db) {
    // Indexed text columns are short enough for utf8mb4's four bytes a character: the message indexes
    // cover 191 characters (764 bytes) and schema_migrations.id is VARCHAR(191).
    $db->exec('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // One collation everywhere, whatever the server's default was when a table was created.
    foreach (array('users', 'tools', 'messages', 'event_log', 'exam_requests', 'attachments', 'schema_migrations') as $table) {
        if (kirjuri_table_exists($db, $table) && kirjuri_table_collation($db, $table) !== 'utf8mb4_unicode_ci') {
            $db->exec('ALTER TABLE ' . $table . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    }
}


function kirjuri_table_exists(PDO $db, $table) {
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $query->execute(array(':table' => $table));
    return (int) $query->fetchColumn() > 0;
}


function kirjuri_column_exists(PDO $db, $table, $column) {
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
    $query->execute(array(':table' => $table, ':column' => $column));
    return (int) $query->fetchColumn() > 0;
}


function kirjuri_table_collation(PDO $db, $table) {
    $query = $db->prepare('SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $query->execute(array(':table' => $table));
    return (string) $query->fetchColumn();
}


function kirjuri_index_exists(PDO $db, $table, $index) {
    $query = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index');
    $query->execute(array(':table' => $table, ':index' => $index));
    return (int) $query->fetchColumn() > 0;
}


function kirjuri_applied_migrations(PDO $db) {
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        id varchar(191) NOT NULL PRIMARY KEY,
        description text,
        applied_at datetime NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8');
    return $db->query('SELECT id FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}


function kirjuri_pending_migrations(PDO $db) {
    return array_diff_key(kirjuri_migrations(), array_flip(kirjuri_applied_migrations($db)));
}


/**
 * Apply pending migrations in order and return the IDs applied. $until stops after that
 * migration. $report is called with a line of text for each migration.
 */
function kirjuri_migrate(PDO $db, $until = null, $report = null) {
    // A named lock keeps two simultaneous requests from migrating at the same time.
    $locked = (int) $db->query("SELECT GET_LOCK('kirjuri_migrate', 30)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Could not get the migration lock. Is another migration running?');
    }
    $applied = array();
    try {
        foreach (kirjuri_pending_migrations($db) as $id => $migration) {
            call_user_func($migration['up'], $db);
            $query = $db->prepare('INSERT INTO schema_migrations (id, description, applied_at) VALUES (:id, :description, NOW())');
            $query->execute(array(':id' => $id, ':description' => $migration['description']));
            $applied[] = $id;
            if ($report !== null) {
                $report('Applied ' . $id . ': ' . $migration['description']);
            }
            if ($id === $until) {
                break;
            }
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('kirjuri_migrate')");
    }
    return $applied;
}


/**
 * Bring the schema up to date on page load. The latest applied migration ID is cached in
 * cache/schema_version, so an up to date installation skips the database check.
 */
function kirjuri_ensure_schema(PDO $db) {
    $ids = array_keys(kirjuri_migrations());
    $latest = end($ids);
    $marker = dirname(__DIR__) . '/cache/schema_version';
    if (file_exists($marker) && trim(file_get_contents($marker)) === $latest) {
        return array();
    }
    $applied = kirjuri_migrate($db);
    foreach ($applied as $id) {
        event_log_write('0', 'Admin', 'Applied database migration ' . $id . '.');
    }
    @file_put_contents($marker, $latest);
    return $applied;
}
