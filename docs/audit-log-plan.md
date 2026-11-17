# Tamper-evident audit log: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seal and chain every audit log entry so that an edited, removed, reordered or inserted entry shows, refuse
changes that can not be recorded, and put checkpoints on case reports and in backups.

**Architecture:** Pure functions in a new `lib/audit.php` build, open and check entries. `kirjuri_audit_write()` adds
an entry under a row lock on `audit_head`, inside a database transaction. `lib/logging.php` wraps it for pages:
`kirjuri_audited()` runs a change and its entry in one transaction and writes the event log lines after the commit.
`bin/kirjuri audit:verify`, `doctor`, the audit viewer, the case report and the backup read the chain through the
same functions.

**Tech Stack:** PHP 8.1+ (OpenSSL AES-256-GCM, `hash_hkdf`, `fsync`), MariaDB 10.11 and MySQL 8.0 (InnoDB,
`SELECT ... FOR UPDATE`), Twig 3, PHPUnit, PHPStan level 5, Playwright.

**Spec:** `docs/audit-log-design.md`, approved 17 November 2026. Read it first. "Design part N" below means its
section "N." under Design.

## Global Constraints

- Entry file: `logs/audit/<first six digits of the epoch>/<epoch>_<four random hex, upper case>.log`, created with
  `fopen(..., 'x')`, one line: `KIRJURI-AUDIT 2 <entry number> <previous fingerprint> <sealed payload>` and `"\n"`.
- Seal: `kirjuri_seal(gzencode($json), kirjuri_audit_entry_key($key_string), "kirjuri audit entry <number> <previous fingerprint> <file name>")`.
- Key: `hash_hkdf('sha256', $key_string, 32, 'kirjuri audit log entries')`. `$key_string` is read from
  `conf/audit_credentials.php` as text and never `include`d.
- Fingerprint: lowercase hex SHA-256 of the whole entry file. Entry 1's previous fingerprint is the SHA-256 of the
  list of old entries (`<file name> <fingerprint>` lines sorted by file name, each ending in `"\n"`).
- Payload keys: `entry`, `time` (`date('c')`), `case` (int or null), `event` (`level`, `description`), `user` (as
  `audit_log_write()` builds it today), `request_contents`. JSON with `JSON_INVALID_UTF8_SUBSTITUTE`. Request
  fields whose name contains `password`, in any case and at any depth, are removed.
- Checkpoint text: `entry <n>, <d.m.Y H:i:s>, <first 20 hex characters in groups of four>`. `--since` accepts at
  least 16 hex characters, with or without spaces.
- Old entries are never rewritten, re-encrypted or moved.
- SQL only in `lib/` (`tests/Unit/SourceTest.php` enforces it). New tables InnoDB, utf8mb4.
- New `lang.*` strings in all five files with `php tests/tools/add_lang_strings.php`, and on the translation review
  page. Event log lines and `bin/kirjuri` output stay English.
- Every bug fix gets a test that fails on the old code, and every change a line in `CHANGELOG.md`.
- Pages do not scroll sideways at 390px; colours only from `kirjuri.css` variables; the case report stays light.
- Before every push: `cd tests && vendor/bin/phpunit` (with `KIRJURI_TEST_DB_*` set) and
  `vendor/bin/phpstan analyse --memory-limit=1G`, both clean. Browser tests for Tasks 8 and 9.
- Integration tests share one test installation. A test that damages the audit log restores every byte it changed
  in a `finally` block, so `audit:verify` stays clean for the tests after it.
- Commit messages are plain sentences in the repository's style and end with this session's attribution lines.

## Review Focus

1. Request text with bytes that are not UTF-8, pasted from another program: the change is saved with the bad bytes
   replaced. Because changes fail closed, an entry that could not be encoded would block that change for good.
   Test in Task 1.
2. An installation with thousands of old entry files: starting the chain inside a web request finishes however long
   it takes. Test in Task 3.
3. The server clock set back by a time sync, so a newer entry's file name holds an earlier epoch: the log is ordered
   by entry number and nothing is reported. Test in Task 4.
4. Two requests at the same moment right after the upgrade, while there is no chain yet: one chain start, not two.
   Test in Task 3.
5. A key file edited by hand on Windows (CRLF, a UTF-8 BOM, single quotes, a closing `?>`): read as the same key.
   Test in Task 1.

---

# Pull request 1: the chain (design parts 1 to 9)

### Task 1: Entry format and key

**Files:**
- Create: `lib/audit.php`
- Modify: `lib/helpers.php` (receives `kirjuri_seal()` and `kirjuri_unseal()`, unchanged, from
  `lib/two_factor.php:155-182`), `lib/two_factor.php` (loses them), `include_functions.php:27`, `bin/kirjuri:19`
  and `tests/bootstrap.php` (each requires `lib/audit.php` after `lib/helpers.php`)
- Test: `tests/Unit/AuditEntryTest.php`

**Interfaces:**
- Produces, in `lib/audit.php`:
  - `const KIRJURI_AUDIT_DIR = 'logs/audit'`, `const KIRJURI_AUDIT_KEY_FILE = 'conf/audit_credentials.php'`
  - `class KirjuriAuditException extends RuntimeException {}`
  - `kirjuri_audit_key_string(string $key_file = KIRJURI_AUDIT_KEY_FILE): ?string`: null when missing or unreadable
  - `kirjuri_audit_create_key(string $key_file = KIRJURI_AUDIT_KEY_FILE): bool`: true when this call wrote it
  - `kirjuri_audit_entry_key(string $key_string): string`: 32 raw bytes
  - `kirjuri_audit_seal_entry(int $number, string $previous, string $file, array $payload, string $key_string): string`: the file's contents
  - `kirjuri_audit_open_entry(string $contents, string $file, ?string $key_string): array`: keys `format`
    (`chain`, `legacy` or `plain`), `number` (?int), `previous` (?string), `payload` (?array, null when it does not open)
  - `kirjuri_audit_fingerprint(string $contents): string`; `kirjuri_audit_short_fingerprint(string $fingerprint): string`
  - `kirjuri_audit_parse_checkpoint(string $text): ?array`: keys `entry` (int), `prefix` (lowercase hex)
  - `kirjuri_audit_clean_request(array $request): array`
  - `kirjuri_audit_entry_path(string $file, string $dir = KIRJURI_AUDIT_DIR): ?string`: null unless `$file` matches
    `/^\d{10}_[A-Za-z0-9]+\.log$/`
  - `kirjuri_audit_entry_files(string $dir = KIRJURI_AUDIT_DIR): array`: file name => path of every entry-named
    file in a six-digit folder; anything else is ignored

- [ ] **Step 1: Write the failing tests** in `tests/Unit/AuditEntryTest.php` (temporary folders under
  `sys_get_temp_dir()`, removed in `tearDown`):

```php
testSealedEntryOpensWithItsKeyAndPlace()
    // seal entry 7, previous str_repeat('a', 64), file '1790201586_AE66.log', payload with entry 7 and an event
    $this->assertStringStartsWith('KIRJURI-AUDIT 2 7 ' . str_repeat('a', 64) . ' v1:', $contents);
    $this->assertStringEndsWith("\n", $contents);
    // open with the same key and name: format 'chain', number 7, previous 'aaa…', payload equal to the one sealed
testSealedEntryOpensNowhereElse()
    // payload null when opened with another key, under another file name, and with the number or the previous
    // fingerprint changed in the text
testKeyUsesAllOfTheKeyString()
    // two 64-hex keys equal in their first 24 characters (the old encrypt() used no more of them)
    $this->assertNotSame(kirjuri_audit_entry_key($a), kirjuri_audit_entry_key($b));
testKeyFileIsReadAsText()                       // Review Focus 5
    // '<?php return "K" ?>', "<?php return 'K';\r\n" and "\xEF\xBB\xBF<?php return \"K\";" all give 'K'
    // missing file, and '<?php echo "x";', give null
    // '<?php function kirjuri_test_ran() {} return "K";' gives 'K' and function_exists('kirjuri_test_ran') stays false
testKeyIsCreatedOnce()
    // true the first time, false the second; contents match '/^<\?php return "[0-9a-f]{64}";\n$/'
testOldEntriesStillOpen()
    // encrypt($json, $key) from lib/helpers.php opens as format 'legacy' with its payload; '{"user":{}}' as 'plain'
testPayloadWithInvalidUtf8SealsAndOpens()      // Review Focus 1
    // request_contents ['note' => "caf\xe9"] seals; opened, its note is "caf\u{FFFD}"
testPasswordsAreRemovedFromRequests()
    $this->assertSame(array('access' => array('a' => 'b'), 'case_name' => 'c'), kirjuri_audit_clean_request(array(
        'password' => 'x', 'new_password' => 'y', 'Current_Password' => 'z',
        'access' => array('password2' => 'w', 'a' => 'b'), 'case_name' => 'c')));
testCheckpointText()
    $this->assertSame('9f3c 1a2b 77de 0c41 5e9a', kirjuri_audit_short_fingerprint('9f3c1a2b77de0c415e9a' . str_repeat('0', 44)));
    $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c415e9a'), kirjuri_audit_parse_checkpoint('48213:9f3c 1a2b 77de 0c41 5e9a'));
    $this->assertSame(array('entry' => 48213, 'prefix' => '9f3c1a2b77de0c41'), kirjuri_audit_parse_checkpoint('entry 48213, 17.11.2026 10:22:31, 9F3C 1A2B 77DE 0C41'));
    // null for '48213:9f3c1a2b' (too short), 'abc' and ''
testOnlyEntryNamesAreEntryFiles()
    // counted: 179020/1790201586_AE66.log; ignored: .gitignore, 179020/notes.txt, 179020/1790201586_AE66.log~,
    // other/1790201586_AE66.log
    $this->assertNull(kirjuri_audit_entry_path('../../conf/mysql_credentials.php', $dir));
```

- [ ] **Step 2: Run them and see them fail**

Run: `cd tests && vendor/bin/phpunit --filter AuditEntryTest`
Expected: FAIL, `Call to undefined function kirjuri_audit_...`

- [ ] **Step 3: Implement Task 1's functions in `lib/audit.php` and move the seal functions**

The key string comes from `/return\s*(["'])([^"']+)\1/` after stripping a BOM. `kirjuri_audit_create_key()` locks
and writes like `kirjuri_two_factor_key(true)`, with the content `<?php return "<bin2hex(random_bytes(32))>";` and a
newline. `kirjuri_audit_open_entry()` splits a `KIRJURI-AUDIT 2` line at its first four spaces; other contents go
through `decrypt()` (`legacy`), or are JSON when they start with `{` (`plain`).

- [ ] **Step 4: Run the tests and see them pass**

Run: `cd tests && vendor/bin/phpunit --filter 'AuditEntryTest|TwoFactorTest'`
Expected: PASS (the two-factor tests use the moved seal functions).

- [ ] **Step 5: Commit** ("Audit log entries: a sealed, chained format and a key read as text")

### Task 2: Migration 010

**Files:**
- Modify: `lib/migrations.php` (`'010_audit_chain'` after `'009_two_factor'`, and
  `kirjuri_migration_010_audit_chain(PDO $db)`)
- Test: `tests/Integration/MigrationsTest.php`

**Interfaces:**
- Produces: the tables of design part 3, with `audit_chain.username VARCHAR(256)` like `users.username`, and the row
  `audit_head (id 1, entry 0, fingerprint NULL, key_replaced_at NULL)`, inserted with `INSERT IGNORE` so the
  migration can run twice.

- [ ] **Step 1: Write the failing test** `testAuditTablesStartEmpty()`: a migrated scratch database has the three
  tables, no rows in `audit_chain` or `audit_legacy`, and `audit_head` is exactly one row (`1`, `0`, null, null).
  Update any test that lists the migrations by ID.
- [ ] **Step 2: Run it and see it fail:** `vendor/bin/phpunit --filter MigrationsTest`, FAIL, table doesn't exist.
- [ ] **Step 3: Write the migration.**
- [ ] **Step 4: Run `MigrationsTest` and see it pass.**
- [ ] **Step 5: Commit** ("Migration 010: tables for the audit log chain")

### Task 3: Writing entries

**Files:**
- Modify: `lib/audit.php`
- Create: `tests/Integration/ScratchDatabase.php` (a trait with `rootPdo()`, `scratchDatabase()` and the
  `tearDown` that drops them, moved out of `MigrationsTest`, which then uses it), `tests/Integration/AuditChainTest.php`,
  `tests/Integration/fixtures/audit_writer.php`

**Interfaces:**
- Consumes: Task 1's functions; Task 2's tables.
- Produces:
  - `kirjuri_audit_write(PDO $db, ?int $case_id, string $level, string $description, array $request, array $user, string $dir = KIRJURI_AUDIT_DIR, string $key_file = KIRJURI_AUDIT_KEY_FILE): array`:
    keys `file`, `entry` (int), `fingerprint`, `started` (?int: old files fingerprinted, when this call started the
    chain) and `key_replaced` (?int: the number of the key-replaced entry, when this call wrote one). It needs an open
    transaction (`LogicException` otherwise) and follows design part 4, steps 2 to 6. On any failure it deletes the
    files it wrote and throws `KirjuriAuditException`; the caller rolls back.
  - `kirjuri_audit_start(PDO $db, array $user, string $dir = KIRJURI_AUDIT_DIR, string $key_file = KIRJURI_AUDIT_KEY_FILE): ?int`:
    in a transaction of its own, starts the chain if there is none; the number of old files, or null when a chain exists.
  - `kirjuri_audit_head(PDO $db): ?array`: keys `entry` (int), `fingerprint`, `created` (that entry's DATETIME) and
    `key_replaced_at` (?int); null while there is no chain.

The chain-start and key-replaced entries have level `Audit`, case null, an empty request, and the descriptions of
design parts 5 and 7 word for word. The chain-start payload adds `"old_entries": {"count": n, "list_sha256": "<hex>"}`.
`set_time_limit(0)` before fingerprinting old files.

- [ ] **Step 1: Write the failing tests** in `AuditChainTest` (scratch database migrated with `kirjuri_migrate()`, a
  temporary audit folder and key file, a user block like `array('username' => 'tester', ...)`):

```php
testFirstWriteStartsTheChainWithTheOldEntries()
    // two old files written with encrypt() in 179020/; one write gives entry 1, "Audit log chain started. 2 earlier
    // entries fingerprinted." (payload old_entries.count 2), and entry 2, the change;
    // audit_legacy holds both files with their SHA-256; entry 1's previous fingerprint is the SHA-256 of the list;
    // entry 2's previous is entry 1's fingerprint; audit_head is entry 2; the result's started is 2
testEntriesFollowEachOther()
    // three more writes: numbers 3, 4, 5, each previous = fingerprint of the one before; rows carry case_id,
    // username and created; every file opens with the key
testFailedWriteLeavesNothingBehind()
    // the audit folder replaced by a plain file: KirjuriAuditException; after rollBack(), no new audit_chain row,
    // audit_head unchanged. The key file replaced by a directory: the same, and no entry file written
testLostKeyIsReplacedAndRecorded()
    // two entries, then the key file deleted; the next write makes entry 3, "The audit log key was missing, and a new
    // one was made. Entries 1 to 2 can no longer be read with it.", and entry 4; key_replaced_at is 3; a new key file
testWritersAtTheSameTimeKeepOneChain()          // Review Focus 4
    // empty chain; four processes of fixtures/audit_writer.php, started together, write 25 entries each into the same
    // database, folder and key file; then entries 1 to 101 without gaps, one "Audit log chain started" entry (entry 1),
    // and every previous fingerprint equals the fingerprint of the entry before
testChainStartFingerprintsThousandsOfOldFiles()  // Review Focus 2
    // 3,000 small old files: 3,000 audit_legacy rows; entry 1's old_entries.count is 3000
testStartingTheChainHappensOnce()
    // kirjuri_audit_start() returns 0 on an empty folder, then null
```

`fixtures/audit_writer.php` takes `<dsn> <user> <password> <dir> <key file> <count>` and requires only
`lib/helpers.php` and `lib/audit.php`; each write is its own transaction.

- [ ] **Step 2: Run them and see them fail:** `vendor/bin/phpunit --filter AuditChainTest`, FAIL, undefined function.
- [ ] **Step 3: Implement `kirjuri_audit_write()`, `kirjuri_audit_start()` and `kirjuri_audit_head()`.**
- [ ] **Step 4: Run `AuditChainTest` and `MigrationsTest` and see them pass.**
- [ ] **Step 5: Commit** ("Write audit log entries in a chain, one at a time")

### Task 4: Checking the chain

**Files:**
- Modify: `lib/audit.php`
- Test: `tests/Unit/AuditCheckTest.php`

**Interfaces:**
- Consumes: Task 1's functions.
- Produces:
  - `kirjuri_audit_rows(PDO $db, ?int $newest = null): iterable`: `audit_chain` rows as arrays (`entry`, `file`,
    `fingerprint`, `previous`, `case_id`, `username`, `created`) in entry order; only the newest N when given
  - `kirjuri_audit_legacy_rows(PDO $db): array`: file name => fingerprint
  - `kirjuri_audit_check(iterable $rows, ?array $head, array $legacy, string $dir, ?string $key_string, ?array $since = null, bool $partial = false): array`:
    keys `findings` (list of arrays with `kind`, `entry` (?int), `file` (?string), `text`), `entries` (int checked),
    `old` (int checked), `checkpoint` (?string, of the newest row)
  - `kirjuri_audit_checkpoint_text(int $entry, string $created, string $fingerprint): string`

Each row gets the first of these checks that fails, and the next row's expectations come from this row:

1. `missing`: no file.
2. `changed`: the file's fingerprint differs from the row's.
3. `place`: the file's number or previous fingerprint differs from the row's.
4. `link`: the row's number is not the one before it plus one, or its previous fingerprint is not the one before's
   fingerprint. For the first row, the expectation is entry 1 with the old-entry list's SHA-256, unless `$partial`.
5. `seal` when a key is given and the seal does not open. Entries numbered below `key_replaced_at` are not reported
   one by one but as a single `unreadable_old_key` finding: "Entries 1 to <n> were sealed before the key was replaced
   at entry <m> and can not be read with it."

Then, unless `$partial`:

- Files without a row: `never_saved` when another row holds their number ("Entry <n> was never saved: its change was
  refused or failed."); `not_indexed` for exactly one file numbered one past the newest row ("Entry <n> is not in the
  index. Its change failed, or it was removed from the index."); `past_end` for any other ("Entries past the end of
  the index: the index may have been cut.").
- Old files: `old_missing`, `old_changed`, `old_added` (an old-format file not in `$legacy`); `old_list` when the
  SHA-256 of the list built from `$legacy` differs from entry 1's `list_sha256` (checked when entry 1 opens).
- `head` when `$head` is not the newest row's number and fingerprint.
- `since` when the checkpoint's entry is not among the rows, or its fingerprint does not start with the prefix.

`$partial` (used by `doctor` with the newest rows only) runs the per-row checks without the first row's link.

- [ ] **Step 1: Write the failing tests.** A helper builds a chain of N entries and two old files in a temporary
  folder with Task 1's functions and returns the rows, head and legacy arrays; no database.

```php
testSoundChainHasNoFindings()                // 5 entries, 2 old: findings [], entries 5, old 2, checkpoint 'entry 5, ...'
testChangedFileIsFound()                     // one byte changed in entry 3's file: [changed @3]
testEntryReplacedInFileAndIndexIsFound()     // entry 3 resealed with another key, row 3's fingerprint updated: [seal @3, link @4]
testRemovedEntryIsFound()                    // entry 3's file and row gone: [link @4]
testRemovedFileIsFound()                     // entry 3's file gone: [missing @3]
testSwappedEntriesAreFound()                 // files of entries 2 and 3 swapped: [changed @2, changed @3]
testHeadAheadOfTheIndexIsFound()             // rows 1-4, head 5: [head]
testCutLogIsFoundOnlyAgainstACheckpoint()    // entry 5's file and row gone, head moved to 4: [];
                                             // since entry 5: [since]; since entry 4 with its prefix: []
testUnindexedFilesAreNamedForWhatTheyMayBe() // a file numbered 6 (head 5): [not_indexed]; numbered 8: [past_end];
                                             // numbered 3 beside row 3's file: [never_saved]
testEntriesSealedBeforeALostKeyAreOneFinding() // entries 1-3 under key A, 4-5 under key B, key_replaced_at 4,
                                             // checked with key B: one [unreadable_old_key], no 'seal'
testOldFilesAreChecked()                     // edited: [old_changed]; deleted: [old_missing]; an old-format file
                                             // added: [old_added]; $legacy edited to match an edited file: [old_list]
testEntriesAreOrderedByNumberNotByFileName() // Review Focus 3: entry 2's name has an earlier epoch than entry 1's: []
testFilesThatAreNotEntriesAreIgnored()       // .gitignore, 179020/notes.txt, 179020/x.log~: []
testPartialCheckLooksOnlyAtTheNewest()       // rows 3-5 only, an old file deleted, $partial: []
testCheckpointTextIsReadable()
    $this->assertSame('entry 48213, 17.11.2026 10:22:31, 9f3c 1a2b 77de 0c41 5e9a',
        kirjuri_audit_checkpoint_text(48213, '2026-11-17 10:22:31', '9f3c1a2b77de0c415e9a' . str_repeat('0', 44)));
```

- [ ] **Step 2: Run them and see them fail:** `vendor/bin/phpunit --filter AuditCheckTest`, FAIL, undefined function.
- [ ] **Step 3: Implement the four functions.**
- [ ] **Step 4: Run `AuditCheckTest` and see it pass.**
- [ ] **Step 5: Commit** ("Check the audit log chain and say what each problem means")

### Task 5: Pages write chained entries and refuse changes that can not be recorded

**Files:**
- Modify: `lib/logging.php` (new functions below; `audit_log_write()` stays until Task 6), `actions/cases.php`
  (8 sites), `actions/devices.php` (6 sites), `actions/admin.php:54-74`, `upload.php:14-46`, `submit.php:111`,
  `conf/lang_*.JSON` (`audit_failed`)
- Test: `tests/Integration/AuditTest.php`

**Interfaces:**
- Consumes: `kirjuri_audit_write()`, `KirjuriAuditException`, `kirjuri_audit_open_entry()`, `kirjuri_audit_key_string()`.
- Produces, in `lib/logging.php`:
  - `kirjuri_audit_user(): array`: the `user` block exactly as `audit_log_write()` builds it today
  - `kirjuri_audited(PDO $db, callable $change, array $request, ?callable $then = null): ?string`: begins a
    transaction; `$change()` returns `array($case_id_or_null, $level, $description)`, or null when it changed nothing
    (roll back, write nothing, return null); writes the entry; calls `$then($file)` if given; commits; writes the
    event log lines; returns the entry's file name. Rolls back and rethrows on any exception.
  - `kirjuri_audit(PDO $db, $case_id, string $level, string $description, array $request): string`: the same for a
    change outside the database or an event that has already happened. `LogicException` while a transaction is open.
  - `kirjuri_audit_refused(KirjuriAuditException $e, bool $json): never`
  - Event log lines after the commit: `event_log_write($case_id ?? '0', $level, $description, $file)`;
    `event_log_write('0', 'Audit', 'Audit entry <n>: <file>, fingerprint <sha256>.')`; after a chain start,
    `event_log_write('0', 'Audit', 'Audit log chain started; <n> earlier entries fingerprinted.')`; after a key
    replacement, `event_log_write('0', 'Error', 'The audit log key conf/audit_credentials.php was missing, and a new one was made at entry <n>. Entries before it can no longer be read.')`.

At each call site the checks (session, tokens, case ownership, form fields) stay first. The database change moves into
the `$change` closure, which returns the case and the event log text it writes today. Every site records `$_POST`:
removing a case or a device recorded `$_GET` until now, which holds nothing but the action, so the form was lost
(the request URI in the `user` block still carries the query). Redirects and messages stay after the call.

- `examination_request`: the new case's UID is the case. The log folders are created after the call.
- `assign_examiners`: the closure returns null when `kirjuri_assign_examiners()` returns false.
- `devicememo`: the device-in-this-case check moves before the closure, so a refused memo writes no entry. With a
  move to another case, the case is the new one.
- `device`: the insert is inside the closure, as the description names the new UID.
- `upload.php`: per file, the insert is the change and `kirjuri_set_attachment_audit_stamp()` is `$then`. It is a
  page of its own, not dispatched by `submit.php`, so its loop catches `KirjuriAuditException` itself and calls
  `kirjuri_audit_refused($e, false)`.
- `save_settings`: `kirjuri_audit($kirjuri_database, null, 'Admin', 'Settings saved.', $_POST)` before writing the
  file; if `file_put_contents()` returns false, `event_log_write('0', 'Error', 'Settings could not be written to conf/settings.local.')`.
- `submit.php`: the `require` of the action file goes inside `try { } catch (KirjuriAuditException $e) { kirjuri_audit_refused($e, in_array($action, array('change_device_status', 'change_device_location'), true)); }`.
  `kirjuri_audit_refused()` writes `event_log_write('0', 'Error', 'Not saved: the audit log could not be written. ' . $e->getMessage())`,
  then sends `{"saved": false}` (the menus show their "Not saved" text) or sets the `audit_failed` message and calls
  `kirjuri_redirect_back('index.php')`, and stops.
- `audit_failed` in English: "Not saved: the change could not be recorded in the audit log. Tell an administrator."

- [ ] **Step 1: Write the failing tests** in `tests/Integration/AuditTest.php`. A helper opens an entry of the test
  installation with `kirjuri_audit_key_string($this->server->dir . '/conf/audit_credentials.php')`.

```php
testChangesWriteChainedEntries()
    // add a case, add a device, change its location: the newest three audit_chain rows have consecutive numbers and
    // the case's UID; each entry's event.description equals its line in the case log, e.g. "Changed device UID<n>
    // location to <location>."
testRemovingACaseRecordsItsForm()          // bug: $_GET was recorded
    // remove a case: its entry's request_contents.remove_exam_request is the case's UID
testChangeIsRefusedWhenItCanNotBeRecorded()
    // expectLoggedError('the audit log could not be written'); logs/audit moved aside and a plain file put in its
    // place (restored in finally): a case_update leaves the case's name as it was, adds no audit_chain row, and the
    // next page shows the audit_failed text; change_device_status answers {"saved": false} and the device's status
    // is unchanged
testRefusedMemoWritesNoEntry()
    // a device memo naming a device of another case: no new audit_chain row
```

- [ ] **Step 2: Run them and see them fail:** `vendor/bin/phpunit --filter AuditTest`, FAIL (no rows in `audit_chain`).
- [ ] **Step 3: Add the functions and convert the call sites.** Add `audit_failed` in five languages with the tool.
- [ ] **Step 4: Run the whole suite** (`vendor/bin/phpunit`) and see it pass; the existing case, device, attachment and
  settings tests show the conversions kept behaviour.
- [ ] **Step 5: Commit** ("Save each change together with its audit log entry, or not at all")

### Task 6: Two-factor events

**Files:**
- Modify: `lib/two_factor.php` (`kirjuri_two_factor_log()` takes `PDO $db` first and calls `kirjuri_audit()`;
  `kirjuri_two_factor_enable()` begins and commits only when `!$db->inTransaction()`), `actions/two_factor.php`,
  `actions/users.php:86`, `lib/cli.php:375,399`, `lib/logging.php` (delete `audit_log_write()`)
- Test: `tests/Integration/TwoFactorTest.php`, `tests/Unit/SourceTest.php`

**Interfaces:**
- Consumes: `kirjuri_audit()`, `kirjuri_audited()`.

Sign-in events (code accepted, recovery code used, wrong code, blocked) keep their place and call
`kirjuri_two_factor_log($db, ...)` before `kirjuri_two_factor_finish()`, so the session starts only after the entry
is written. Setting up (at sign-in and on the page), new recovery codes, turning off, the administrator's reset and the
command line's reset and unlock run their change inside `kirjuri_audited()`.

- [ ] **Step 1: Write the failing tests**

```php
// TwoFactorTest
testSignInStopsWhenItsEntryCanNotBeWritten()
    // a user with two-factor sign-in; logs/audit replaced by a plain file (restored in finally); right password and
    // right code: not signed in (index.php sends to login.php); expectLoggedError('the audit log could not be written')
testSetUpIsNotSavedWithoutItsEntry()
    // the same breakage; confirming setup on two_factor.php leaves no two_factor row for the user
// SourceTest
testNothingWritesTheOldAuditLog()
    // no .php file outside tests/ and vendor/ contains 'audit_log_write('
```

- [ ] **Step 2: Run them and see them fail:** `vendor/bin/phpunit --filter 'TwoFactorTest|SourceTest'`.
- [ ] **Step 3: Convert the two-factor call sites; delete `audit_log_write()`.**
- [ ] **Step 4: Run the whole suite and see it pass.** The existing test that entries hold no code or secret
  (`TwoFactorTest.php:160-183`) still passes.
- [ ] **Step 5: Commit** ("Two-factor sign-in events in the audit log chain, failing closed")

### Task 7: `audit:verify`, `doctor`, `migrate` and `audit:show`

**Files:**
- Modify: `lib/cli.php` (commands at 15-29, `cli_doctor()` at 211, `cli_migrate()` near 254, `cli_audit_show()` at 461-488)
- Test: `tests/Integration/CliTest.php`

**Interfaces:**
- Consumes: `kirjuri_audit_rows()`, `kirjuri_audit_legacy_rows()`, `kirjuri_audit_head()`, `kirjuri_audit_check()`,
  `kirjuri_audit_parse_checkpoint()`, `kirjuri_audit_key_string()`, `kirjuri_audit_start()`,
  `kirjuri_audit_open_entry()`, `kirjuri_audit_entry_path()`.

- `audit:verify [--since <checkpoint>]`: one line per finding, `Entry <n> (<file>): <text>` or just the text; then
  `Checked <n> entries and <m> old entries: no problems found.` or `...: <k> problem(s) found.`; then
  `Checkpoint: <checkpoint text>`. Exit 0 or 1. A `--since` that does not parse is a usage error (exit 1). With no
  chain: "There is no audit log chain yet. It starts with the first change." and exit 0.
- `doctor`, in place of line 211: the key file (`ok`; `fail` when missing or unreadable while the chain has entries;
  `warn` when missing before the first change, as now); `warn` "The audit log key was replaced at entry <n>; entries
  before it can not be read with it." when `key_replaced_at` is set; `fail` when `logs/audit/` is not writable; and,
  once connected with no pending migrations, `kirjuri_audit_check()` over `kirjuri_audit_rows($db, 50)` with
  `$partial`: `ok` "The newest 50 audit log entries are sealed and in sequence. Run php bin/kirjuri audit:verify to
  check them all." or `fail` with the first finding.
- `migrate`: after applying migrations, when `kirjuri_audit_head()` is null, `kirjuri_audit_start()` and print
  "Started the audit log chain; <n> earlier entries fingerprinted."
- `audit:show`: opens the entry with `kirjuri_audit_open_entry()` and prints the payload, then `fingerprint: <sha256 of the file>`.

- [ ] **Step 1: Write the failing tests** in `CliTest`:

```php
testAuditVerifyPassesAndPrintsACheckpoint()
    // after a change: exit 0; output contains 'no problems found' and matches
    // '/Checkpoint: entry \d+, \d\d\.\d\d\.\d{4} \d\d:\d\d:\d\d, ([0-9a-f]{4} ){4}[0-9a-f]{4}/'
    // --since with that checkpoint: exit 0; with the same entry and '0000000000000000': exit 1
testAuditVerifyFindsAChangedEntry()
    // the newest entry's file with one character of its sealed part changed (restored in finally): exit 1, its file
    // name in the output; after restoring, exit 0
testDoctorChecksTheAuditLog()
    // exit 0, output contains 'The newest 50 audit log entries are sealed and in sequence.'
testAuditShowReadsAnOldEntry()
    // an old CBC entry written with encrypt() and the installation's key string into a six-digit folder (deleted
    // in finally): audit:show prints its request_contents
```

- [ ] **Step 2: Run them and see them fail:** `vendor/bin/phpunit --filter CliTest`, FAIL (unknown command `audit:verify`).
- [ ] **Step 3: Implement the commands.**
- [ ] **Step 4: Run `CliTest` and see it pass.**
- [ ] **Step 5: Commit** ("bin/kirjuri audit:verify, and doctor checks the audit log")

### Task 8: The audit viewer says whether an entry is sound

**Files:**
- Modify: `lib/audit.php`, `auditor.php`, `views/auditor.twig`, `lib/cli.php` (`audit:show`'s first line),
  `conf/lang_*.JSON`, `tests/browser/specs/layout.spec.js`
- Test: `tests/Integration/AuditTest.php`, `tests/Unit/SourceTest.php`

**Interfaces:**
- Produces: `kirjuri_audit_entry_status(PDO $db, string $file, string $dir = KIRJURI_AUDIT_DIR, ?string $key_string = null): ?array`
  with keys `status` and `entry` (?int); null while there is no chain. `sealed` when the file has an `audit_chain`
  row, its fingerprint and place match the row, its seal opens and the row's previous fingerprint is the previous row's
  fingerprint; `mismatch` when it has a row and any of that fails, or an `audit_legacy` row with another fingerprint;
  `old_unchanged` for an `audit_legacy` row that matches; `not_indexed` for a new-format file without a row;
  `old_unrecorded` for an old-format file without an `audit_legacy` row.

Strings (English; the other four written without a native speaker):

- `audit_entry_sealed`: "Audit log entry %s: sealed, in sequence and unchanged."
- `audit_entry_old_unchanged`: "An entry from before the audit log was chained. It has not changed since the chain started."
- `audit_entry_old_unrecorded`: "An old entry that was not there when the audit log was chained. It may have been added since."
- `audit_entry_mismatch`: "This entry does not match the audit log's record of it: it was changed, replaced or sealed with another key."
- `audit_entry_not_indexed`: "This entry is not in the audit log's record. Its change may have failed, or the record was cut."

`auditor.php` reads entries with `kirjuri_audit_entry_path()` and `kirjuri_audit_open_entry()` and the key from
`kirjuri_audit_key_string()`, never `include`. The status shows above the data in the alert style other pages use
(success for `sealed` and `old_unchanged`, warning for `old_unrecorded`, danger for the rest). An entry that does not
open shows only its status, without PHP warnings. Top-level values (`entry`, `time`, `case`) show in their row.
The form that pushes an entry back to the database (`REVERT_FROM_AUDIT`) stays as it is.
`audit:show` prints the status's English text first.

- [ ] **Step 1: Write the failing tests**

```php
// AuditTest
testViewerSaysAnEntryIsSealed()      // the newest entry's page contains "Audit log entry <n>: sealed, in sequence and unchanged."
testViewerSaysAChangedEntryDoesNotMatch()
    // a byte of the newest entry changed (restored in finally): status 200, the mismatch text, no logged errors
testViewerSaysAnOldEntryWasNotRecorded()
    // an old CBC entry added after the chain started (deleted in finally): the old_unrecorded text
// SourceTest
testAuditKeyIsNeverRunAsPhp()
    // no .php file outside tests/ and vendor/ matches '/(include|require)(_once)?\s*\(?\s*[\'"]conf\/audit_credentials\.php/'
// layout.spec.js
// 'the audit viewer fits a phone': add a case, open auditor.php?view=<newest entry> at 390px, no sideways scroll
```

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement the status, the viewer change and the strings.**
- [ ] **Step 4: Run `vendor/bin/phpunit` and the browser tests against a test installation** (see
  `tests/browser/README.md`; never a real one) **and see them pass.**
- [ ] **Step 5: Commit** ("The audit viewer says whether an entry is sealed and in sequence")

### Task 9: Checkpoints on the case report and in backups

**Files:**
- Modify: `case_report.php`, `views/case_report.twig` (end of the last `.page`), `views/css/kirjuri.css`
  (`.audit-checkpoint`: small, `--k-text-muted`), `backup.php`, `conf/lang_*.JSON`, `tests/browser/specs/layout.spec.js`
- Test: `tests/Integration/AuditTest.php`, `tests/Integration/MiscellaneousTest.php:505-539`

**Interfaces:**
- Consumes: `kirjuri_audit_head()`, `kirjuri_audit_short_fingerprint()`, `kirjuri_audit_checkpoint_text()`.

`case_report.php` passes `audit_head` (`entry`, `created`, `short`) or null. The template prints
`lang.audit_report_checkpoint|format(audit_head.entry, audit_head.created|date("d.m.Y H:i"), audit_head.short)` when
there is one. `audit_report_checkpoint` in English: "Audit log when this report was made: entry %s, %s, fingerprint %s."
`backup.php`, when there is a chain, sends `-- Kirjuri audit log checkpoint: <checkpoint text>` and a newline before
the dump, and counts it in `Content-Length`.

- [ ] **Step 1: Write the failing tests**

```php
// AuditTest
testCaseReportCarriesTheCheckpoint()
    // after a change: the report contains "Audit log when this report was made: entry <head entry>," and the head's
    // short fingerprint; opening the report adds no audit_chain row
testBackupStartsWithTheCheckpoint()
    // with the fake mysqldump of testFailedBackupsAreReportedNotDownloaded: the body's first line is
    // '-- Kirjuri audit log checkpoint: entry <head entry>, ...' and the second '-- dump of ...'
// MiscellaneousTest::testFailedBackupsAreReportedNotDownloaded: its '-- dump of' check moves to the second line
// layout.spec.js: 'the case report fits a phone': case_report.php of a new case at 390px, no sideways scroll
```

- [ ] **Step 2: Run them and see them fail.**
- [ ] **Step 3: Implement the line and the backup header; add the string in five languages.**
- [ ] **Step 4: Run `vendor/bin/phpunit` and the browser tests and see them pass.**
- [ ] **Step 5: Commit** ("Checkpoints of the audit log on case reports and in backups")

### Task 10: Documents, translations and the first pull request

**Files:**
- Modify: `README.md` (an "Audit log" section: what is recorded, `audit:verify`, checking a printed report or a
  backup with `--since`, what to do after losing the key), `CHANGELOG.md`, `docs/ROADMAP.md` (Security item 2 done
  except part 10; go-live list: check the log with `audit:verify` after restoring a backup; the translation count),
  `CLAUDE.md` (Conventions: changes go through `kirjuri_audited()`, or `kirjuri_audit()` for changes outside the
  database), `tests/tools/translation_review.php` (a group "Audit log" collecting every `audit_*` string by name,
  each with where it shows), `tests/Unit/TranslationReviewTest.php`

- [ ] **Step 1: Write the failing test** `testAuditGroupHasEveryAuditString()` in `TranslationReviewTest`: the built
  page has a group with id `audit` holding every `audit_*` key of `conf/lang_EN.JSON`.
- [ ] **Step 2: Run it and see it fail;** add the group; run it and see it pass.
- [ ] **Step 3: Write the documents.**
- [ ] **Step 4: Rebuild the review page** (`php tests/tools/translation_review.php <scratchpad>/review.html`), read
  the live page (https://claude.ai/artifact/BZc61pGDCQJBw8iPM6TEzP) in full, and publish the new build to the same URL.
- [ ] **Step 5: Run every check:** `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --memory-limit=1G`, the browser
  tests. All pass.
- [ ] **Step 6: Commit, push, and open the pull request** against master, titled "Seal and chain the audit log", its
  description following the template if there is one and linking the design. Tell the owner, and wait for CI and
  their word on asking Codex. Do not merge without them.

---

# Pull request 2: changes that write no entry today (design part 10)

Start from a fresh `origin/master` once the first pull request is merged.

### Task 11: The API

**Files:** Modify `api.php:86-140`. Test `tests/Integration/ApiTest.php`.

**Interfaces:** Consumes `kirjuri_audited()`. The API signs in its key's user, so `kirjuri_audit_user()` names them.

`update`: the update and the device count inside `kirjuri_audited()`; level `API`, the description it logs today,
the case `kirjuri_case_of($kirjuri_database, $request_id)`. `add`: the creation inside; "Added examination request
<case id> through the API."; the case is the new UID. A refused entry falls into the existing `catch (Exception $e)`
and answers 500.

- [ ] **Step 1: Failing tests** `testApiUpdateWritesAnEntry()` and `testApiAddWritesAnEntry()`: a new
  `audit_chain` row with the case's UID and the API user's name.
- [ ] **Step 2: Run, see them fail. Step 3: Implement. Step 4: Run `ApiTest`, see it pass.**
- [ ] **Step 5: Commit** ("API changes write audit log entries")

### Task 12: KRF imports

**Files:** Modify `import_krf.php:60-113`. Test `tests/Integration/KrfImportTest.php`.

The inserts of the imported case, its devices and attachments run inside `kirjuri_audited()`; level `Add`, the
description it logs today; the request records the file's name, size and SHA-256, never its content. The page is not
dispatched by `submit.php`, so it catches `KirjuriAuditException` itself and calls `kirjuri_audit_refused($e, false)`.

- [ ] **Step 1: Failing test** `testImportWritesAnEntry()`: one new `audit_chain` row with the new case's UID; its
  `request_contents` holds the file's SHA-256 and no `content`.
- [ ] **Steps 2-4: Run, implement, run.**
- [ ] **Step 5: Commit** ("KRF imports write an audit log entry")

### Task 13: Attachments and attached devices

**Files:** Modify `actions/cases.php:194` (`remove_attachment`), `actions/devices.php:27-60` (`device_attach`,
`device_detach`). Test `tests/Integration/AttachmentTest.php`, `tests/Integration/DeviceAccessTest.php`.

Removing an attachment gets an entry of its own, with the event text it logs today; the event line links the new
entry instead of the upload's. Attaching and detaching get entries and case log lines: "Attached device UID<a> to
UID<b>." and "Detached device UID<a>."

- [ ] **Step 1: Failing tests** `testRemovingAnAttachmentWritesItsOwnEntry()` (the removal's case log line links a
  stamp other than the upload's), `testAttachingAndDetachingWriteEntries()` (two rows, two case log lines).
- [ ] **Steps 2-4: Run, implement, run.**
- [ ] **Step 5: Commit** ("Removing attachments and attaching devices write audit log entries")

### Task 14: User accounts

**Files:** Modify `actions/users.php` (create, modify, delete, the administrator's password change at 60, your own
at 139). Test `tests/Integration/AccessControlTest.php` or a new `tests/Integration/UserAuditTest.php`.

Each change runs in `kirjuri_audited()` with the event text it logs today; level as today; case null.

- [ ] **Step 1: Failing tests** `testUserChangesWriteEntries()` (create, change the access level, delete: three rows)
  and `testNoPasswordReachesTheAuditLog()` (create a user and change two passwords with distinctive values; no entry
  opened with the key contains any of them).
- [ ] **Steps 2-4: Run, implement, run.**
- [ ] **Step 5: Commit** ("User account changes write audit log entries")

### Task 15: The second pull request

- [ ] **Step 1:** CHANGELOG; ROADMAP (Security item 2 done); README's audit log section lists the new entries.
- [ ] **Step 2:** `vendor/bin/phpunit`, `vendor/bin/phpstan analyse --memory-limit=1G`, the browser tests; all pass.
- [ ] **Step 3:** Commit, push, open the pull request ("Audit log entries for the API, imports, attachments and
  users"), tell the owner, wait for CI and their word on Codex. Do not merge without them.
