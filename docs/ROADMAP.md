# Roadmap

Where Kirjuri's development stands, what is still open, and the plans agreed for the testing stage
and for installing Kirjuri without Docker. Written at the end of the development stage (October
2026) so later sessions can pick up from here. Update it when an item moves.

## Development stage: done

Pull requests #6 to #14 were merged with merge commits after green CI; #15 was open, with green CI, when this was written:

- #6 Clean-up and dark mode: one theme in `views/css/kirjuri.css`, dark mode that follows the
  operating system, printouts and the case report kept light.
- #7 Front page: status filters with counts (all, new, open, assigned to me, stalled, ready), a
  sticky table header.
- #8 Admin pages: settings, users, tools and messages in the card and field layout. Fixed
  FullCalendar's self-closing tags under jQuery 3.5.
- #9 Statistics: labelled bars instead of pie charts, a validated chart palette (`--k-chart-1` to
  `--k-chart-8`), the chart colour setting removed.
- #10 Language editor: search, the English text beside each field, missing strings in their own
  card.
- #11 FullCalendar 3.10.5 and moment.js 2.30.1; the local patch is gone.
- #12 Calendar short month names; `|e('js')` for text inside inline scripts, with a source test.
- #13 Statistics months that ended on the 31st and counted days twice; Save after Clear in the add
  user dialog.
- #14 Review leftovers: search results and the "assigned to me"/stalled filters, the settings
  language list and Yes/No choices, Copy to new keeping the recipient.
- #15 The session start hook (`.claude/hooks/session-start.sh`) and this roadmap.

## Open items

- **Translations.** Eleven strings added during the redesign (back_up_database, find_a_string,
  last_activity, missing_strings, no, no_tools, offline, online, reserve, stalled, yes) have German,
  Spanish, Finnish and Brazilian Portuguese versions written without a native speaker. The review
  page is https://claude.ai/artifact/BZc61pGDCQJBw8iPM6TEzP; translators need Contributor access to
  save. Their answers are in the page's `reviews` collection, one document per `key~LANG`, read with
  the `ArtifactData` tool. Apply the agreed wording to `conf/lang_*.JSON`, keeping each file's
  encoding (see CLAUDE.md).
- **Outside code review.** Codex was at its usage limit for every PR from #6 to #15, so none of
  them has had a second reviewer. When the limit resets, ask it to review the changes since #5
  (`git diff d467c3e origin/master`), fix real findings with tests, and reply on each thread.

## Testing stage plan

1. **Automated checks stay green**: PHPUnit and PHPStan as in CLAUDE.md, and the Playwright browser
   tests against a test installation (never a real one).
2. **Acceptance testing by lab users** on a test installation with realistic but made-up cases:
   adding requests and devices, the front page filters, statistics for a full year, the tools
   calendar, messages, user management, the language editor, the case report printout, light and
   dark mode, and a phone-width browser.
3. **Translation review**, from the open item above.
4. **Native installs on Windows 11 and macOS**, following the plan below: first in CI, then one
   real run-through on each kind of machine.
5. **Upgrade testing**: an existing installation's database upgraded with `php bin/kirjuri
   migrate`, and a backup taken and restored.
6. **Security review**, before Kirjuri holds real case data: a full code audit, then a penetration
   test of a test installation set up as for go-live. See Security below.

## Security

Kirjuri holds case data that may become evidence. The application protects itself reasonably well,
but much of the data's safety depends on how it is installed and run. This was assessed in October
2026 by reading the security-relevant code, not by a full audit.

Already in place: passwords hashed with `password_hash()`; sign-in lockout after 10 failures per
username or 50 per IP address within 15 minutes; a new session ID at sign-in, HttpOnly and SameSite
session cookies, an idle timeout; CSRF and case tokens in POST bodies; prepared statements for all
SQL (enforced by a source test); HTMLPurifier on user-written HTML; X-Frame-Options, `frame-ancestors`
and `nosniff` headers; access levels, per-case access groups and per-user IP allow and block lists;
an event log and an AES-256 encrypted audit log; `.htaccess` rules keeping `conf/`, `logs/`, `cache/`
and the code folders off the web; an installer that will not run twice.

Minimum for go-live (deployment, not code):

- HTTPS. Kirjuri does not force it, and the session cookie is only marked secure over HTTPS.
- Not reachable from the internet: lab network or the machine only, behind a firewall, with the
  database listening on 127.0.0.1.
- Full-disk encryption (BitLocker, FileVault). The database, attachments included, is not encrypted
  at rest.
- `show_errors` off (it ships on; `bin/kirjuri doctor` warns), and `session_idle_timeout` lowered
  from 12 hours.
- Access groups on sensitive cases: a case without one is visible to every signed-in user. Few
  admin accounts.
- A dedicated database user with rights on Kirjuri's database only; a password on the database's
  root account.
- Encrypted backups kept off the machine and restored now and then, including `conf/`, which holds
  the audit log key.
- Operating system updates, antivirus, physical security, and limited administrator rights on the
  machine.

Code improvements, in suggested order:

1. Two-factor sign-in (TOTP), or LDAP sign-in against a directory that already has it.
2. A tamper-evident audit log for chain of custody: authenticated encryption (AES-256-GCM) and a
   hash chain over entries, so an edited or deleted entry shows. The current IV is 16 hex characters
   (64 bits) rather than 16 random bytes.
3. A stronger password rule than 8 characters, or a check against known breached passwords.
4. Settings to redirect HTTP to HTTPS and send `Strict-Transport-Security`.
5. A full Content-Security-Policy, after moving the inline scripts into files.
6. The defaults above changed in `conf/settings.conf`: `show_errors` off and a shorter idle
   timeout, so a new install starts safe.

## Installing without Docker

Kirjuri needs PHP 8.1 or newer (8.3 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `zlib`,
`json` and `session` (`ldap` for LDAP sign-in), MariaDB 10.11 or MySQL 8.0, a web server, and
mysqldump for backups. `vendor/` is committed, so Composer and Node are not needed to run it. Apache
is preferred because it honours the `.htaccess` files that keep `conf/`, `logs/`, `cache/` and the
code folders off the web; other servers need those rules in their own configuration. PHP's built-in
server is not for real use: it would serve the audit logs.

Recommended stacks: Homebrew (`php`, `mariadb`, `httpd`) on macOS; the native Apache Lounge, PHP
(thread-safe build from windows.php.net) and MariaDB installers as Windows services on Windows 11.
XAMPP or MAMP are fine for evaluation. Install steps: PHP settings (`upload_max_filesize = 16M`,
`post_max_size = 20M`, `expose_php = Off`), a database and user, the code under the web root with
`AllowOverride All`, write access to `conf/`, `logs/` and `cache/` for the web server, then
`php bin/kirjuri install` (on Windows `php bin\kirjuri install`) and `php bin/kirjuri doctor`. Set the
time zone and `mysqldump_location` in the settings. Upgrades replace the code and run `php
bin/kirjuri migrate`.

Known risks to check first:

- `backup.php` starts mysqldump with an environment holding only `MYSQL_PWD`. On Windows that drops
  `SystemRoot`, which usually stops mysqldump from connecting. Keep the existing environment and
  add the password, with a test.
- `bin/kirjuri` has a Unix `#!` line; on Windows it runs as `php bin\kirjuri`, and its prompts need
  trying in PowerShell.
- Git's `autocrlf` on Windows: pin line endings in `.gitattributes`.
- CI only runs on Linux. Add Windows and macOS jobs (PHP and MariaDB installed in the job) before
  calling native installs supported.

## Installers for Windows and macOS

The plan for a one-file install: a signed `.exe`/`.msi` (Inno Setup or WiX) and a signed, notarized
`.pkg` (`pkgbuild`/`productbuild`) that each bundle private copies of PHP 8.3, MySQL 8.4 LTS (it has
official builds for both systems, MariaDB has none for macOS) and Caddy, with Kirjuri's access rules
in the Caddy configuration. The installer generates database passwords, binds the database to
127.0.0.1, installs Kirjuri, registers services that start at boot, runs `doctor`, and opens the
first-run page. Upgrades replace the program and run `migrate`; uninstalling keeps the data unless
asked. Nightly backups go to a folder the user picks.

Kirjuri needs one change for this: a setting for the data folder, so `conf/`, `logs/` and `cache/`
(about 50 relative paths today) can live in `C:\ProgramData\Kirjuri` or
`/Library/Application Support/Kirjuri`, defaulting to today's layout. Installers are built and
smoke-tested by GitHub Actions on Windows and macOS runners from pinned, checksummed downloads.
Costs: an Apple Developer ID ($99 a year) and a Windows code signing certificate, and someone
watching PHP, MySQL and Caddy releases for security fixes. Kirjuri is MIT licensed; MySQL is GPL and
ships as a separate program with its licence and a pointer to its source.

Phases: (1) Windows and macOS CI, the backup fix and the data folder setting; (2) a runnable zip
bundle per platform; (3) the installers; (4) signing, automatic backups, an optional tray or
menu-bar helper, and illustrated install guides. Phase 3 needs a real Windows 11 PC and a Mac for a
test install; phase 4 needs the signing accounts.

## Working notes

- The session start hook sets up MariaDB and the test packages. If the container restarts and
  MariaDB is down, run the hook again. `service mariadb stop` can fail here; use
  `mysqladmin -uroot -p... shutdown`.
- A browser test copy is a copy of the code served with `setsid nohup php -S 127.0.0.1:8099`.
  After copying changed templates into it, delete its compiled templates (`cache/??/`): Twig's
  `auto_reload` compares modification times to the second and can keep serving an old template.
- Stop a test server with `kill $(pgrep -f '^php -S 127.0.0.1:8099')`, not `pkill -f`, which
  matches the shell running it.
