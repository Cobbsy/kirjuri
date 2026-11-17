# Tamper-evident audit log: design

Roadmap, Security, item 2. Agreed with the owner on 17 November 2026. It ships as two pull requests: the
chain (parts 1 to 9 below), then the changes that write no entry today (part 10).

## Why

Kirjuri's audit log is meant to show who changed what on a case and when, so that a lab can account for
its evidence (chain of custody). Today it can not prove any of that:

- Entries are encrypted with AES-256-CBC and nothing authenticates them, so a changed entry still
  decrypts and nothing reports it.
- `encrypt()` base64-encodes the key and OpenSSL cuts the result to 32 characters, so only 24 of the
  key's 64 hex characters are used: 96 bits instead of 256. The IV is 16 hex characters, 64 bits.
- Nothing links entries, so deleting one, or the newest few, leaves no trace. The SHA-256 in the event
  log line sits in a plain text file that anyone who can edit the entry can edit too.
- If an entry can not be written, the change is saved anyway, stamped "Audit file failure.".
- If the key file is missing, a new key is made silently and the older entries can no longer be read.
- Several changes write no entry: API updates and cases added through the API, KRF imports, removing an
  attachment (it reuses the upload's entry), attaching and detaching devices (not in the event log
  either), and creating, changing or deleting users.

## Goals

- An edited, deleted, reordered or inserted entry shows when the log is checked, for anyone who can
  change files but does not hold the key in `conf/`.
- A rewrite of the whole log by someone who controls the server shows against a checkpoint kept outside
  it: a printed case report or a database backup.
- Every change to a case, a device, access to a case, a user account or a setting has a sealed entry,
  or it is not saved.
- The sealed entries alone are the chain-of-custody record: who, when, what happened in words, which
  case, and the request as it was sent.
- Upgrades keep every existing entry readable, and existing links to entries keep working.

## Non-goals

- Stopping a server administrator from rewriting the log. The design only makes it detectable, through
  checkpoints held elsewhere.
- Proving anything about old entries before the upgrade. They are fingerprinted when the chain starts;
  what happened to them earlier can not be known.
- Redesigning the audit viewer page (its own roadmap item), a verified mark on each case log line, and
  sending checkpoints off the machine automatically (syslog or a remote service).
- Signatures with a key the server does not hold.
- Auditing messages, tool reservations and ordinary sign-ins. They stay in the event log only.

## Anti-goals

The work has failed, even if it runs, if any of these happen:

- Old entries are re-encrypted or rewritten. They are original records.
- A sound log is reported as tampered with, for example after two requests raced, a change was refused
  or a key was lost. Every finding of `audit:verify` says plainly what it means.
- A change is saved without an entry.
- Existing links to entries break (case log, event log, attachments).
- A password, two-factor code, recovery code or secret ends up in an entry.

## Approaches considered

1. **Chosen: sealed, chained entry files with an index in the database.** Entries stay one file each, as
   today, sealed with AES-256-GCM and linked by the SHA-256 of the previous file. A database table
   records each entry. Files and database are two places to tamper with, and existing links keep working.
2. Chaining the plain event log lines. Simpler and readable, but the event log holds no request data, can
   not be encrypted because the case log displays it, and exists twice (per case and global).
3. Moving the entries into the database. One place to tamper with, every mysqldump backup grows with
   request bodies, and every existing link to an entry file breaks.

Checkpoints outside the server: the owner chose both a line on every printed case report and a checkpoint
in every database backup (17 November 2026).

## Design

All new code with SQL goes in a new `lib/audit.php` (SQL only in `lib/`).

### 1. Entry files

Same folder and name as today: `logs/audit/<first six digits of the epoch>/<epoch>_<four random hex>.log`,
created with `fopen(..., 'x')` (a new random part on a collision) and flushed with `fsync()` before its
index row is written.

A new entry is one line:

    KIRJURI-AUDIT 2 <entry number> <previous fingerprint> <sealed payload>

- The entry number counts from 1.
- The previous fingerprint is the SHA-256, in lowercase hex, of the previous entry's whole file. Entry 1
  takes the fingerprint of the list of old entries (part 5).
- The sealed payload is `kirjuri_seal(gzencode($json), $key, $context)`: AES-256-GCM with a random 12-byte
  IV, the function two-factor sign-in already uses (move it out of `lib/two_factor.php` so both share it).
  `$context` is `kirjuri audit entry <number> <previous fingerprint> <file name>`, so the number, the link
  and the name can not be changed without the key.
- An entry's fingerprint is the SHA-256 of its whole file.

The payload keeps today's `user` and `request_contents` keys, so the viewer keeps working, and adds the
entry number, the time, the case and the event:

    {
        "entry": 48213,
        "time": "2026-11-17T10:22:31+02:00",
        "case": 1234,
        "event": {"level": "Update", "description": "Changed device UID12 location to Evidence locker 3."},
        "user": {"username": "...", "ip_address": "...", "user_agent": "...", "referer": "...",
                 "request_uri": "...", "request_method": "..."},
        "request_contents": {...}
    }

`case` is null for changes that are not about a case (settings, users). For a device it is the device's
case. Fields whose name contains `password` are removed from `request_contents` before sealing.

Readers tell the formats apart by the first characters: `KIRJURI-AUDIT 2` is a new entry, `{` an
unencrypted old entry (written when OpenSSL was missing), anything else an old CBC entry.

The viewer's "push back to database" form (`REVERT_FROM_AUDIT`) works as before, and its new entry records
which entry it came from, as today.

### 2. Key

`conf/audit_credentials.php` stays the only key file, so backups need nothing new. It is read as text,
not run with `include`: the string in `return "..."` or `return '...'`. New entries use
`hash_hkdf('sha256', <that string>, 32, 'kirjuri audit log entries')`, which uses all of its 256 bits.
Old entries are decrypted as today (`decrypt()`), with the same string.

A new key file is written as now (64 hex characters from 32 random bytes) but with `fopen('x')` and a lock,
as `kirjuri_two_factor_key()` does, so two requests can not each write one. A key file that exists but can
not be read stops writing: changes are refused (part 6) and `doctor` names the file.

### 3. Database (migration `010_audit_chain`)

    audit_chain   entry INT UNSIGNED PRIMARY KEY, file VARCHAR(64) UNIQUE, fingerprint CHAR(64),
                  previous CHAR(64), case_id INT NULL (indexed), username VARCHAR(255), created DATETIME
    audit_head    id TINYINT PRIMARY KEY (one row, id 1), entry INT UNSIGNED (0 while there is no chain),
                  fingerprint CHAR(64) NULL, key_replaced_at INT UNSIGNED NULL
    audit_legacy  file VARCHAR(64) PRIMARY KEY, fingerprint CHAR(64), size INT UNSIGNED

InnoDB, utf8mb4. The migration inserts the `audit_head` row. `audit_chain` goes into every database backup,
so each backup holds every fingerprint up to the moment it was taken.

### 4. Writing an entry

One function writes entries, for example `kirjuri_audit(PDO $db, $case_id, $level, $description, array
$request)`. It returns the entry's file name, the "stamp" that the event log, the case log and attachments
link to, and replaces `audit_log_write()`.

1. Begin a transaction unless the caller has one open.
2. `SELECT ... FROM audit_head WHERE id = 1 FOR UPDATE`. This row is the lock: one entry is written at a
   time, so two requests can not take the same number or the same previous fingerprint. The lock is held
   until the commit.
3. If there is no chain yet, start it (part 5).
4. If the key file is missing and the chain has entries, write a new key and a key-replaced entry first
   (part 7).
5. Seal and write the file.
6. Insert the `audit_chain` row and update `audit_head`.
7. Commit, if this function began the transaction.

On any failure it rolls back if it began the transaction, deletes the file if it wrote one, and throws an
exception of its own class (for example `KirjuriAuditException`). A caller with its own transaction rolls
that back.

After the commit, the caller writes the event log lines as today, with the stamp. The line in the global
log becomes "Audit entry 48213: 1790201586_AE66.log, fingerprint <sha256>".

A helper that runs a change and its entry in one transaction keeps the call sites short, for example
`kirjuri_audited($db, function () { ...the change...; return array($case_id, $level, $description); },
$request)`. Its exact shape is the builder's call.

### 5. Starting the chain, and the old entries

While `audit_head.entry` is 0, the first write starts the chain. So does `bin/kirjuri migrate` right after
migration 010, so that an upgrade from the command line starts it at once. Starting it:

1. fingerprints every file under `logs/audit/*/` into `audit_legacy` (at this point they are all old
   entries);
2. builds the list `<file name> <fingerprint>` (one per line, sorted by file name) and takes its SHA-256;
3. writes entry 1, level `Audit`, "Audit log chain started. <n> earlier entries fingerprinted.", whose
   payload holds the count and the list's SHA-256, and whose previous fingerprint is the list's SHA-256.

From then on an old file that is edited, deleted or added shows, and so does an edited `audit_legacy`
table, because entry 1 seals the list. Old entries are never rewritten.

### 6. Failing closed

A change and its entry are saved together, or neither is.

- Changes in the database: the entry is written inside the change's transaction, after the change (so its
  description can name a new device's UID) and before the commit.
- Changes outside the database (the settings file): the entry is written first. If the change then fails,
  the entry records an attempt, and the event log says it failed.
- Two-factor events record something that has already happened (a code accepted or refused, a setup).
  They keep their place, but if the entry can not be written the sign-in or the setup stops: the session
  is completed only after the entry is written.

`submit.php`, `upload.php`, `api.php` and `import_krf.php` catch `KirjuriAuditException`. A page shows the
new message `audit_failed` ("Not saved: the change could not be recorded in the audit log. Tell an
administrator.") and goes back with `kirjuri_redirect_back()`. The live device status and location menus
show their existing "Not saved" text, the API answers 500, and `bin/kirjuri` prints the error and exits 1.
The event log gets an `Error` line naming the cause.

### 7. A lost key

If the key file is missing while the chain has entries, a new key is written and a key-replaced entry goes
in before the change's own entry: level `Audit`, "The audit log key was missing, and a new one was made.
Entries 1 to <n> can no longer be read with it." `audit_head.key_replaced_at` records its number.

The links do not need the key, so `audit:verify` still checks the order and completeness of the earlier
entries. It reports that they can not be read, and why, instead of calling them changed. `doctor` fails
while the key file is missing or unreadable and the chain has entries, and warns once a replacement is
recorded.

### 8. Checking

`bin/kirjuri audit:verify [--since <entry>:<fingerprint>]` runs a check in `lib/audit.php` that the
command, `doctor` and the viewer share. It walks `audit_chain` in order and reports, in plain words:

- an entry file that is missing, or whose fingerprint differs from its row (changed or replaced);
- an entry whose number, previous fingerprint or name does not match its place, or whose seal does not
  open (changed, or sealed with another key);
- a link that does not match the previous entry's fingerprint (an entry removed, inserted or reordered);
- an entry file with no row. With a number past the newest row: "entries past the end of the index: the
  index may have been cut". With a number another file holds in the index: "an entry that was never saved;
  its change was refused or failed";
- entries before a key replacement, which can not be read with the current key;
- an old file that is missing, changed or added since the chain started, or an `audit_legacy` table that
  no longer matches entry 1;
- an `audit_head` that does not match the newest row.

It ends with the checkpoint of the newest entry:

    Checkpoint: entry 48213, 17.11.2026 10:22:31, 9f3c 1a2b 77de 0c41 5e9a

A checkpoint shows the first 20 hex characters (80 bits) of the fingerprint in groups of four: enough that
no one can build a chain that matches it, and short enough to read out. `--since` takes a checkpoint with
or without spaces (at least 16 hex characters) and confirms that the entry still has that fingerprint and
that the chain continues from it. The exit code is 0 when nothing was found and 1 otherwise.

`bin/kirjuri doctor` checks the key file, that `logs/audit/` is writable (changes are refused otherwise),
that `audit_head` matches the newest row, and the newest 50 entries, and points to `audit:verify` for the
rest. `audit:show` and the audit viewer (`auditor.php`) check the one entry they show and say so above it:
sealed, in sequence and unchanged; an old entry unchanged since the chain started; an old entry that was
not there when the chain started; or an entry that does not match its record.

### 9. Checkpoints outside the server

The case report (`case_report.php`) ends its last page with one line from `audit_head`, for example "Audit
log when this report was made: entry 48213, 17.11.2026 10:22, fingerprint 9f3c 1a2b 77de 0c41 5e9a". There
is no line while there is no chain. The line has no setting; one can be added if a lab objects. Opening a
report writes no entry. No entry about the report's case can be newer than the head, so the line also shows
that the report matches the log up to that entry.

`backup.php` starts the dump with a comment line holding the same checkpoint, for whoever keeps the backup.
The `audit_chain` table in the dump holds every fingerprint as well.

The README explains how to check a printed report or a backup against the log:
`bin/kirjuri audit:verify --since 48213:9f3c1a2b77de0c415e9a`.

### 10. Changes that write no entry today (second pull request)

With `kirjuri_audit()` and the rules of part 6: API updates and cases added through the API; KRF imports;
removing an attachment (an entry of its own, besides the upload's); attaching and detaching devices (with
new event log lines too); creating, changing and deleting users, and password changes (that it happened,
never the password).

## Text and languages

The new strings (`audit_failed`, the viewer's status lines and the report line) go into all five language
files with `tests/tools/add_lang_strings.php`, and onto the translation review page in a group of their
own, published to the same URL. The viewer's other text stays as it is until the viewer is redesigned.

## Tests

Each test fails on the old code where that applies.

Unit tests, with a temporary folder and no database:

- sealing and reading an entry;
- each finding of part 8: a changed byte, a deleted entry in the middle, the newest entry deleted with its
  row, two entries swapped, an entry sealed with another key, an entry that was never saved, an old file
  edited, deleted and added;
- the checkpoint format and reading `--since`;
- the key read as text with either kind of quotes, and used in full: two keys that differ only after the
  24th character seal differently;
- old CBC entries and unencrypted entries still read;
- passwords removed from requests;
- a source test that nothing calls the old `audit_log_write()` or `include`s the key file.

Integration tests:

- migration 010 on a database with old entries starts the chain and fingerprints them;
- changes through the pages write chained entries, and `audit:verify` passes;
- a change is refused and the database is unchanged when an entry can not be written (replace `logs/audit`
  with a plain file: the tests may run as root, where taking away write permission does not stop writes);
- a lost key gives a key-replaced entry and the findings of part 7;
- several processes writing at once get consecutive numbers and a clean chain (`php -S` serves one request
  at a time, so this test runs processes that write entries directly);
- the case report and the backup carry the checkpoint;
- the output and exit codes of `audit:verify`, `audit:show` and `doctor`;
- in the second pull request, each newly covered change writes an entry.

Then PHPStan at level 5, and the browser tests for the case report and the viewer (the new lines must not
make either page scroll sideways at 390px).

## Documents

CHANGELOG; the README (an audit log section: what is recorded, `audit:verify`, checkpoints, a lost key);
the ROADMAP (item 2 done, and the go-live list adds checking the log with `audit:verify` after restoring a
backup); CLAUDE.md if a convention changes, such as how actions write entries.

## Left to the builder

Function and class names, the order of the JSON fields, whether a helper wraps changes in transactions,
the English wording of findings and messages (the owner reads them in review), and how many entries
`doctor` checks.

## Calls made without the owner

These were not discussed, and any of them can be changed:

1. The new key is derived from the existing key file instead of a new file, so backups need nothing new.
2. Entry 1 fingerprints the old entries; they are not re-encrypted.
3. Changes outside the database (settings) write their entry first. Two-factor events keep their place but
   stop the sign-in or setup when their entry can not be written.
4. After a lost key, `doctor` fails only while the key file is missing or unreadable, and warns afterwards,
   because the key change stays in the log for good. (The plan said only that `doctor` fails.)
5. The report line has no setting, shows 20 characters of the fingerprint, and opening a report writes no
   entry.
6. Database backups start with a checkpoint comment line.
7. Password fields are removed from every entry.
