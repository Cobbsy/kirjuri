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

- **Translations.** 34 strings added since the redesign have German, Spanish, Finnish and Brazilian
  Portuguese versions written without a native speaker: eleven from the redesign, six hints and
  messages (`search_hint`, `search_years`, `search_limited`, `assign_examiner_hint`, `not_saved`,
  `reservation_in_past`) and the seventeen strings of the help and licences pages (`help_*` texts and
  titles, `licenses_intro`). All of them are on the review page,
  https://claude.ai/artifact/BZc61pGDCQJBw8iPM6TEzP, grouped by where they appear, with the current
  text of each language file as of November 2026. Translators need Contributor access to save. Their
  answers are in the page's `reviews` collection, one document per `key~LANG`, read with the
  `ArtifactData` tool. Apply the agreed wording to `conf/lang_*.JSON`, keeping each file's encoding
  (see CLAUDE.md). `tests/tools/translation_review.php` builds the page from the language files; add
  new strings to its groups as they are written and publish the page again to the same URL.
  Status (14 November 2026): German is confirmed for the eleven redesign strings (all "reads right",
  16 October), so the files need no change yet. Nothing else has an answer. The help texts quote
  button and tab names, and each quoted name was checked against the same language file and matches.
  The page also asks about three device statuses that look wrong, each shown only to its language:
  German "unterbrochen" for Broken (suggested "defekt"), Spanish "Reanalizar" for Examination over
  (suggested "Análisis terminado") and Brazilian Portuguese "Live investigation", still in English
  (suggested "Exame ao vivo"). Device statuses are stored with their text ("9|Done"), so a corrected
  label shows on a device only once its status is changed; only the number drives progress.
  Finnish numbers its statuses differently from the others, so its list is not compared key by key.
  The 58 strings of the settings page (a label and a help line per setting, November 2026) are on the
  page too, in their own group, including the two for `two_factor_required`.
  The fifty-eight strings of two-factor sign-in (the `two_factor_*` strings, `help_two_factor_title` and
  `help_two_factor_text`), also written without a native speaker, are on the page in their own group,
  each with the page it shows on. The eight strings of the audit log (`audit_*`: the message when a change
  is refused, the viewer's six statuses and the case report's checkpoint line, November 2026) are on the
  page in their own group, each with what it means. In all, 161 strings wait for translators, and the page
  is not shared with any yet: share it from its Share menu, with Contributor access for each translator.
- **Outside code review.** Codex was at its usage limit for every PR from #6 to #15 and left nothing
  on #16 to #20, so none of them has had a second reviewer. #21 is a review-only pull request (never
  to be merged) from the working branch into `codex-review-base`, a branch at #5's merge (`d467c3e`),
  so Codex sees everything since #5 in one diff. Codex was asked on it on 13 and 14 November 2026 and was
  at its limit both times. When the limit resets, comment `@codex review` on #21 once, fix real findings with
  tests in a pull request against master, reply on each thread, then close #21 and delete
  `codex-review-base`. #21 covers everything up to #27. Two-factor sign-in came after it, from another
  branch, so Codex needs to be asked on that pull request separately, and so does the audit log's.
  On 17 November 2026 Codex was asked on #35 (the audit log) and #36 (the case log's links) and was at its limit
  both times; both were merged without it, as #28 to #34 were. When the limit resets, open a second review-only
  pull request like #21, from master into a branch at #27's merge (`188be27`), so Codex sees #28 to #36 (two-factor
  sign-in and the audit log among them) in one diff, and ask once there. The audit log matters most: a flaw in
  its sealing or chaining could let a changed entry pass as untouched.
- **Findings of our own review (November 2026)** of the same diff, with code-review at high effort.
  Done (November 2026): search no longer lists cases, or their devices, that the user may not open,
  and the counts leave them out (a match told an outsider what a case's notes or suspect held, prefix
  by prefix); the year list shows such cases without their name, suspect, file number, investigator,
  urgency justification or links, and sorting by a hidden column puts them last; devices of a removed
  request are no longer found, on the front page or through the API. Also done (November 2026): work on a
  device keeps its case from being marked stalled, detaching a device checks the case token, search shows
  at most 500 rows with a note, the event log is read from its end, statistics count devices by the month
  they were added (so the months add up) and give inline scripts only numbered ids, and the users page
  loads the status of usernames with dots. One finding was wrong: "assigned to me" does follow a rename,
  as renaming a user renames them in the cases (`kirjuri_rename_examiner`); a test now covers it.

- **Bug: a case can be closed before its devices are done.** Found in the UI review of 17 November 2026
  (first item there). Fix it next, before the rest of that review: it lets a case be closed early, it is not
  polish. The second pass of that review found two more that are best fixed with it, in the same pull request:
  dialogs that do not work from the keyboard (item 11) and a new request's devices tab that points to a button
  it does not have (item 12).

- **TinyMCE's licence.** TinyMCE 7 no longer gets public security fixes, and TinyMCE 8 adds Tiny's
  own terms beside the GPL. Decide before go-live whether to move to 8 or to another editor; see
  the TinyMCE advisory under Security.

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
an event log and a tamper-evident audit log (November 2026, see below); two-factor sign-in with an
authenticator app (November 2026, see below); `.htaccess` rules keeping `conf/`, `logs/`, `cache/`
and the code folders off the web; an installer that will not run twice.

Minimum for go-live (deployment, not code):

- HTTPS. Kirjuri does not force it, and the session cookie is only marked secure over HTTPS.
- Not reachable from the internet: lab network or the machine only, behind a firewall, with the
  database listening on 127.0.0.1.
- Full-disk encryption (BitLocker, FileVault). The database, attachments included, is not encrypted
  at rest.
- `show_errors` off and `session_idle_timeout` at an hour or less. Both are the defaults since November
  2026, but an install that saved its settings earlier keeps its old values (`show_errors` on, 12 hours);
  `bin/kirjuri doctor` warns about `show_errors`.
- Access groups on sensitive cases: a case without one is visible to every signed-in user. Few
  admin accounts.
- Two-factor sign-in required at least for administrators (setting `two_factor_required`), and
  `conf/two_factor_key.php` in the backups: without it the authenticator apps stop working.
- A dedicated database user with rights on Kirjuri's database only; a password on the database's
  root account.
- Encrypted backups kept off the machine and restored now and then, including `conf/`, which holds
  the audit log key. After restoring one, run `php bin/kirjuri audit:verify`. Each database backup starts with an
  audit log checkpoint and each case report ends with one: kept off the machine, they are what shows a log rewritten
  on the server (`audit:verify --since <checkpoint>`).
- Operating system updates, antivirus, physical security, and limited administrator rights on the
  machine.

Code improvements, in suggested order:

1. Done (November 2026): two-factor sign-in (TOTP). Users set it up with an authenticator app from a QR code
   drawn on the server; after the password, signing in asks for the app's code (each works once, 30 seconds
   of clock drift either side). Ten recovery codes, hashed, for a lost phone. Wrong codes count in the login
   throttle, and 30 in a row block codes from the app until an admin resets it or runs `user:unlock`;
   recovery codes still work, and the next sign-in reports the wrong codes. The `two_factor_required` setting
   requires it for admins or everyone, who set it up at their next sign-in; setting up at sign-in is never
   offered to an account that has it, so a password alone can not swap the phone. LDAP accounts get the code
   step too (not tested end to end: CI has no directory server). The anonymous sign-in and API keys are
   not covered. Secrets are encrypted with AES-256-GCM under `conf/two_factor_key.php`; admins reset it on the
   users page or with `bin/kirjuri user:two-factor-reset`. Not done: remembering a browser, passkeys or
   security keys, codes by e-mail or SMS.
2. Done (November 2026): a tamper-evident audit log for chain of custody, designed in `docs/audit-log-design.md` and
   built from `docs/audit-log-plan.md`. Entries are sealed with AES-256-GCM (the old encryption used 96 bits of its key
   and authenticated nothing), chained by the previous entry's SHA-256 and indexed in the database, so an edited,
   removed, reordered or added entry shows in `bin/kirjuri audit:verify`. A change whose entry can not be written is
   refused. Case reports and database backups carry checkpoints that the log can be checked against later. Old entries
   are kept and fingerprinted, not re-encrypted. Still to do, in the next pull request (part 10 of the design): entries
   for the API, KRF imports, removing an attachment, attaching and detaching devices, and user accounts, which write
   none today.
3. A stronger password rule than 8 characters, or a check against known breached passwords.
4. Settings to redirect HTTP to HTTPS and send `Strict-Transport-Security`.
5. A full Content-Security-Policy, after moving the inline scripts into files.
6. Done (November 2026): `conf/settings.conf` ships with `show_errors` off and a 60-minute
   `session_idle_timeout`, so a new install starts safe.

Advisories in bundled libraries. `composer audit`, run from the repository root, checks the locked
versions against published advisories. CI does not run it, so run it before a release.

**TinyMCE 7.9.3: GHSA-mf2p-h6hf-fcwm (CVE-2026-116675), rated high.** Checked in November 2026. It
does not reach Kirjuri as Kirjuri is set up, and no public 7.x release fixes it, so TinyMCE stays at
7.9.3 for now.

The flaw is in TinyMCE's media plugin, which shows embedded videos and pages as placeholders while
you edit. When the editor saves, the plugin turns each placeholder back into the real element on the
live page and copies attributes from the content onto it, so an `onerror` written into a note runs
as a script in the browser of whoever saves it. TinyMCE 8.9.3 builds the element in an inert
document instead.

Kirjuri is not affected because its editors never load that plugin. `tinymce.init()` in
`views/base.twig` has no `plugins` option, and TinyMCE loads no plugins unless told to; the plugin's
files are in `vendor/` but nothing asks for them. This was tried in Chromium with the bundled 7.9.3
and a crafted note. With Kirjuri's settings the note saved as harmless attributes and nothing ran.
With `plugins: "media"` added, its script ran on save. `testEditorsDoNotLoadTheMediaPlugin` in
`tests/Unit/SourceTest.php` fails if an editor's settings name the media plugin, so do not add it
while Kirjuri is on 7.9.3.

HTML Purifier covers only part of this. It removes the plugin's `data-mce-*` attributes from notes
saved through Kirjuri's forms and from everything printed with `|purify`. Notes added through the
API or a KRF import are stored as sent, though, and the case and device pages put stored notes into
the editor unpurified, as they must for editing. For those notes, the plugin not being loaded is
the only protection.

There is no update to apply. The advisory names 7.9.4 as the 7.x fix, but Tiny's release notes say
7.9.4 was released for TinyMCE Enterprise and Tiny Cloud, and it is not on Packagist, npm or the
public `tinymce-dist` repository, where 7.9.3 is the last 7.x. Going back to 7.9.2 would be worse,
because 7.9.3 also fixed two stored XSS flaws in TinyMCE's core (`mce:protected` comments, and
`data-mce-` prefixed `src`, `href` and `style` attributes) that do reach Kirjuri.

What it means for later: the 7.x line no longer gets public security fixes, so the next flaw in
TinyMCE's core may have no fix Kirjuri can use. The fixed releases are 8.9.3 and later, which still
run offline with `license_key: "gpl"`. Their licence changed, though. Since 8.3.0 TinyMCE's
`license.md` offers the GPL (version 2 or later) or Tiny's own terms, and adds "By use of this
Software you have agreed to these Tiny Technologies, Inc Software Terms & Conditions of Use"; 8.2.2
and earlier were under the GPL alone. (8.2.2 is outside this advisory's range but lacks the two core
fixes, which reached 8.x in 8.5.1.) Whether labs can accept that wording is a licensing decision to
make before go-live. If they can, move to TinyMCE 8 in its own pull request and check every editor:
case and device notes, messages and the report notes template in settings. If they cannot, the way
out is a different editor under a plain open source licence.

## Improvements found in the November 2026 review

A review of every page (desktop and phone, light and dark), the code behind saves, and an automated sweep
for structural problems. The bugs it found were fixed in the same pull request; these are the
improvements it suggests, most valuable first within each group. Items marked done have been merged.

Finding things:

- Done (November 2026): search covers every year, says which years the results come from, and a word
  finds longer words that start with it, with the syntax explained under the box in each language.
  Still open: a word inside another ("phone" in "iPhone"), which full text indexes cannot find;
  it would need a LIKE search over the device model and manufacturer.
- A filter for one examiner, unit or crime on the front page, and paging for years with hundreds of
  requests (the whole year is one table).

Working on a case:

- Done (November 2026): the device page is a summary card (status and location menus, attached devices,
  removal) and one form in cards with a save bar that stays in view, replacing a form split across a
  table and two sets of buttons. The request details dialog uses the add request form's field layout and
  required-field check, and its overdue confiscation warning works. On phones the save bar shows its
  other buttons as icons.
- Done (November 2026): leaving the device page, the case's notes or a new request with changes that were not
  saved asks first (forms marked `data-warn-unsaved`, kirjuri.js; TinyMCE's own change tracking for the
  editors). Saving drafts automatically is still open, and would also keep text through a lost session.
- Done (November 2026): the device status and location menus save in the background and say so ("Changes
  saved." or, when refused, "Not saved"), and a status change updates the device's bars, the case's progress
  and its close button in place, so "Reload page" and the progress bar frames (`progress_bar_static.php`)
  are gone. The menus are one partial, `device_controls.twig`, on the case and device pages.
- Done (November 2026): a new request asks for its examiners in a card under its title instead of a dialog
  that opened every time, and the card saves only the examiners (`assign_examiners`), where the dialog wrote
  the whole request back from hidden copies of its fields. The old dialog's `pick_examiner` string is no
  longer used.
- Attachments: drag and drop, a progress bar, and the size limit shown before choosing a file.
- The case log opens in a new tab without Kirjuri's menu; make it a tab of the case. Its zoom and move
  buttons have no names for screen readers (needs new language strings).
- The audit log viewer (`auditor.php`) is a bare page: give it the layout, a list of the audit files and
  a labelled field. It says whether each entry is sealed and in sequence, and its table scrolls on a phone (November
  2026).
- Done (November 2026, #36): the case log's links to audit entries could not be clicked on wide screens. A grid
  column used as a spacer floated across the page and the event table's scroll box shrank to nothing beside it.
  The browser tests now click the first link at desktop and phone width.

Overview and reporting:

- Done (November 2026): statistics for a year without requests show the page with an empty state instead
  of sending the user to the front page with an error. The year arrows lead to the nearest years that have
  requests, and an arrow with nowhere to go is hidden.
- A dashboard on the front page for the signed-in examiner: my open requests, stalled ones, new ones
  waiting for an examiner, tool reservations this week.
- The case report as a PDF download as well as a print page, and a choice of what it includes.
- Export of the case list and the statistics as CSV.

Administration and help:

- Done (November 2026): the help page is a short user guide in the language files (adding a request,
  devices and media, statuses, the report, searching, access levels, messages) instead of the
  developer README. The licences have their own page, licenses.php, which reads each component's
  licence file, the Composer packages from vendor/composer/installed.json; a unit test fails when a
  template loads a library that is not listed. Worth knowing before distributing installers:
  TinyMCE 7 is GPL-2.0-or-later, HTML Purifier LGPL-2.1 and the barcode generator LGPL-3.0, while
  Kirjuri itself is MIT.
- Done (November 2026): each setting has a readable label and a line of help in the language files
  (`setting_<key>`, `setting_<key>_help`); a unit test fails when a shipped setting lacks either. Still
  open: grouping the settings by topic (case report, requests, sign-in, directory) instead of the order
  of `settings.conf`.
- Password rules, from the Security section. Two-factor sign-in is done (November 2026).
- E-mail or in-app notice when a request is assigned to someone or a message arrives; today the unread
  counter is the only sign.
- Done (November 2026): a tool reservation that has already ended is refused (one that has started is
  fine), and reservations store the username, so renaming a user keeps them. Migration 008 adds the username
  to existing reservations whose display name matches exactly one user; the others go on matching by name.

Look and feel:

- A manual light/dark switch beside the automatic one, and the logo as an image file an organization
  can replace.
- Empty states with a next step ("No requests yet. Add a request") on the front page, messages and
  tools.
- Keyboard: a shortcut to the search box. (Focus moved into dialogs is now item 11 of the UI review of
  17 November 2026, as a bug.)

Code health:

- `edit_request.twig` is 1,000 lines holding five tabs and six dialogs: split it into partials.
- Done (November 2026): `case_update` checks the case token like the other case actions, and device rows
  follow their case's status on every save, when added and when moved (`kirjuri_sync_device_status`).
  Migration 007 brought existing device rows in step.
- The browser tests run on `ubuntu-24.04` in CI because Playwright 1.56.1, pinned in
  `tests/browser/package.json`, cannot install Chromium on Ubuntu 26.04, which `ubuntu-latest` became in
  November 2026. Upgrade Playwright, check the tests still pass, and set the job back to `ubuntu-latest`.
- Chart.js 1 and vis 4.18 are old and unmaintained; Bootstrap 3 and Font Awesome 4 likewise. Plan an
  upgrade path rather than a rewrite.

## UI review of 17 November 2026: to do when finalizing

A review of the rendered pages on a throwaway installation with three cases and three devices: 13 pages at
390, 768 and 1440 pixels in light mode, the main ones in dark mode too, with a script measuring sideways
scroll, touch target sizes and controls without a name. A second pass the same day (items 11 to 17, below)
covered keyboard use, the empty and error states and the pages the first left out; loading states and the
largest text sizes have not been checked. Nothing was changed; these wait for the finalizing stage, except
items 1, 11 and 12, which are bugs to fix next (see Open items). Mark each done here when it is merged. Most
of them need a browser test.

Must fix:

1. **"Close ready request" shows while devices are still being examined.** `views/edit_request.twig`
   hides the button with the `hidden` attribute until every device is done, but `.btn` in the flex row
   `.case-actions-primary` overrides the browser's own `[hidden] { display: none }`, so it shows at 12%
   progress. `update_request_status` in `actions/cases.php` sets whatever status it is sent, so nothing
   stops it. The browser test in `devices.spec.js` checks the `hidden` property, not visibility. Fix:
   `[hidden] { display: none !important; }` in `kirjuri.css`, the server refuses to close a case whose
   devices are not done (`kirjuri_case_progress`), and the test uses `toBeHidden()`.
2. **Statistics and the devices tab break at 768 pixels.** On statistics the fixed sidebar covers the
   left of the page and its heading, and the monthly tables make the page 1,234 pixels wide. On the
   devices tab the device cards are 88 pixels too wide, cutting off the status menus. Fix: the phone
   layout (folded menu) below about 992 pixels instead of the fixed sidebar, `.table-responsive` around
   the statistics tables, the device card's right column below its text at medium widths, and a 768-pixel
   project in `tests/browser/playwright.config.js` so the sideways-scroll checks cover tablets.
3. **Controls without a name for screen readers.** The case log's move and zoom buttons are icons only;
   the front page's paperclip, phone, notes and lock icons in each row have no text or title and mean
   something only by colour; the case page's attachment input (`#fileToUpload`) has no label. Fix:
   `aria-label` on the buttons and `sr-only` text on the icons (as `index.twig` already does for one
   lock), a `<label for>` on the input, with the strings in all five language files and on the
   translation review page.

Should fix:

4. **The front page table on a phone** shows only No. and Case, cuts the case name mid-word and gives no
   sign that it scrolls; the summary line is clipped and the year arrows break over two lines. Fix: below
   768 pixels, each case as a stacked card (number, name, status stripe, investigator, date) in the
   device card's style, and the year arrows on the title's line.
5. **The radar chart of examined devices by type** has about 40 categories in labels of roughly 6
   pixels, and counts per category are meant to be labelled bars (CLAUDE.md). Fix: the labelled bar list
   used for classifications, with only the types that have a count.
6. **Empty statistics charts.** With no data the page draws four large empty charts. Fix: one line
   saying nothing was recorded this year. The data row's total also says "0 TB" in a row of gigabytes:
   give the total in the row's own unit.
7. **Small touch targets on phones:** status filters 30 pixels high, sort pills and the brand link 28,
   the Inbox, Outbox and Archived links 16 to 20. Fix: at least 44 pixels high under
   `@media (pointer: coarse)`.
8. **Red progress bars for cases that have just started.** `kirjuri_case_progress` makes the bar
   `danger` up to 20%, so every new case looks like an error. Fix: `--k-primary` while work is under way,
   `--k-success` when done, red only for real problems.
9. **The case log and the audit viewer have no menu**, so on a phone the browser's back button is the
   only way out. Making the case log a tab of the case (see Working on a case) fixes the first; until
   then both could extend `base.twig`.
10. **The browser's own "Choose files" control** sits beside styled buttons on the case and add request
    pages, and the devices tab's "Status", "Location" and "Update all" row has no label and does not say it
    changes every device. Fix: a `.btn`-styled file input with its own label, and a label such as "Change
    all devices" above the row, in the `.field` layout.

Nice to have:

- The case log's fit button, drawn as `›...‹`, as a labelled "Show all" button; and a minimum zoom of one
  day, as a case with one event zooms to milliseconds and shows axis labels like "995".
- The front page's red count beside a case name and the grey device count at the end of the row look
  alike: a tooltip or a different shape tells them apart.

Already good: dark mode on every page checked, the case page's facts and the add request form at both
widths, and the case log since #36.

Second pass, the same day: keyboard use, empty and error states, and the pages the first pass left out
(sign-in with a wrong password, the add request form sent empty, the add device dialog, a device's page, a
year with no requests, a request with no devices, an unknown case and device number, settings, tools,
messages and the case report), at 390 and 1440 pixels. Must fix:

11. **Dialogs do not work from the keyboard.** All five (request details, add device, add associated media,
    add tool, add user): when one opens, focus stays on the button behind it, Tab moves around the page under
    the dialog, and Escape does not close it. Bootstrap 3 moves focus into a dialog, keeps it there and closes
    it on Escape only when the `.modal` has `tabindex="-1"`, and none of them has it. Fix: add it to each,
    with a source test that every `.modal` in `views/` has it and a browser test that opens the add device
    dialog from the keyboard and closes it with Escape.
12. **A new request's devices tab points to a button it does not have.** Devices can be added only once the
    request is open with an examiner (`edit_request.twig`, the condition above the add device button), but
    the empty state always says `no_devices_add_device`, "No devices. Add a device from the button." Fix:
    until adding is allowed, a new string such as "Assign an examiner to start this request, then add its
    devices", linking to the assign card on the request tab, in the five language files.

Should fix:

13. **A wrong password also empties the username**, and focus goes to the username field. Fix: fill the
    username back in and focus the password field.
14. **An unknown case or device number goes back to the front page without a word** (`edit_request.php`,
    `device_memo.php`, the case report and the case log). The redirect is intended and tested
    (`testMissingCasePagesGoToTheFrontPage`). Fix: keep it, and add a message such as "Request 99999 was not
    found." with `message('info', ...)`, checked in that test.
15. **No skip link**: a keyboard user tabs through 11 sidebar links before reaching each page's content.
    Fix: a "Skip to content" link first in `base.twig`, shown only when focused, pointing at `.main`.
16. **A pre-filled template value counts as filled in.** On the add request form the criminal case number's
    template ("9999/R/") is flagged as missing, but the telephone's ("555-") passes. Fix: the `data-validate`
    check in `base.twig` treats every field still holding its template as empty, as it does for the case
    number.

Outside the interface:

17. **`bin/kirjuri install` changes the database name without saying so.** `lib/install.php` drops every
    character but letters, digits and hyphens, so `KIRJURI_DB_NAME=kirjuri_ui` installs into `kirjuriui`,
    and a script that later drops `kirjuri_ui` leaves the real database behind. Fix: refuse a name it
    would change, saying which characters are allowed (underscores are valid in MariaDB and MySQL names, so
    allowing them is better still).

Already good in the second pass: visible focus on every link, field and button tabbed to; the add request
form marks each missing field under its label and moves focus to the first; the sign-in error is clear; the
phone save bar's icon buttons keep their names for screen readers; the device menus are labelled; removing a
device asks first; and the device page, settings, messages and tools do not scroll sideways at 390 pixels.

## Installing without Docker

Kirjuri needs PHP 8.1 or newer (8.3 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `zlib`,
`json`, `session` and `iconv` (for the two-factor QR codes; `ldap` for LDAP sign-in), MariaDB 10.11 or MySQL 8.0, a web server, and
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
- New language strings: `php tests/tools/add_lang_strings.php strings.json` (see CLAUDE.md).
- A browser test copy is a copy of the code served with `setsid nohup php -S 127.0.0.1:8099`.
  After copying changed templates into it, delete its compiled templates (`cache/??/`): Twig's
  `auto_reload` compares modification times to the second and can keep serving an old template.
- Each two-factor code signs in once per account, so an integration test that signs the same user in twice
  within 30 seconds needs the app's next code, or resets `two_factor.last_step` (`forgetUsedCodes()` in
  `tests/Integration/TwoFactorTest.php`). The browser tests make users of their own for it: the admin
  account of a test installation must keep signing in with its password alone.
- Stop a test server with `kill $(pgrep -f '^php -S 127.0.0.1:8099')`, not `pkill -f`, which
  matches the shell running it.
