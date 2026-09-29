# Kirjuri

A web app for tracking digital forensic examination requests (cases), their devices and media.
PHP 8.1+, Twig 3 templates, Bootstrap 3.4, jQuery 3.7, Font Awesome 4.6, TinyMCE 7, MariaDB or MySQL.
Labs often run it on networks without internet access, so the app loads nothing from outside:
no web fonts, no CDNs.

## Layout

- `*.php` at the root are the pages; `submit.php` dispatches form posts to `actions/*.php`.
- `lib/` holds all shared functions and every SQL query. `lib/migrations.php` has the versioned
  schema changes, applied automatically. `include_functions.php` bootstraps each page.
- `views/*.twig` are the templates. `views/base.twig` has the layout, the sidebar and the shared
  scripts, including the `data-validate` required-field check. `views/device_fields.twig` holds the
  fields shared by the add device and add media dialogs.
- `views/css/kirjuri.css` is the theme, loaded after Bootstrap. Colours, radii and shadows are CSS
  variables on `:root`.
- `conf/lang_*.JSON` are the five languages (EN, DE, ES, FI, PT_BR). `conf/settings.conf` has the
  default settings.
- `bin/kirjuri` is the command line tool (doctor, migrate, user management, logs, cache).
- `tests/Unit` and `tests/Integration` hold the PHPUnit tests. `tests/browser` holds the
  Playwright tests.

## Checks

Run all of these before every push. CI runs them on PHP 8.1 and 8.4 against MariaDB 10.11 and
MySQL 8.0, plus the Docker image and the browser tests.

    cd tests
    composer install
    KIRJURI_TEST_DB_HOST=127.0.0.1 KIRJURI_TEST_DB_USER=root KIRJURI_TEST_DB_PASSWORD=... vendor/bin/phpunit
    vendor/bin/phpstan analyse --memory-limit=1G     # level 5, must be clean

The integration tests install a throwaway copy of the app into a new database and serve it with
`php -S`. The database user needs to be able to create and drop databases.

For UI changes, also run the browser tests against a test installation. They add cases and
devices, so never point them at a real one. See `tests/browser/README.md`:

    docker compose up -d
    cd tests/browser && npm ci && npx playwright test

## Conventions

The tests in `tests/Unit/SourceTest.php` enforce most of these.

- SQL only in `lib/`.
- CSRF and case tokens go in POST bodies, never in URLs.
- `|raw` only on trusted values. User-written HTML goes through `|purify`. Values inside inline
  `<script>` need `|e('js')`.
- Redirect back to a page with `kirjuri_redirect_back()`.
- Every `lang.*` key a template or PHP file uses must exist in all five language files, with keys
  kept in sorted order. Each file keeps its own encoding: DE uses CRLF line endings and `\u`
  escapes, ES and FI use `\u` escapes, EN and PT_BR are raw UTF-8. Custom language files get
  missing strings from English at load time; the language editor sees the raw file.
- Every `<label for>` points at an `id` in the same template. When a partial is included more than
  once, prefix its ids so they stay unique.
- Passwords need at least 8 characters.
- Every bug fix gets a test that fails on the old code, and every change gets a line in `CHANGELOG.md`.

## Design

- Use the CSS variables in `kirjuri.css` (`--k-primary`, `--k-danger`, `--k-border` and so on)
  instead of hard-coded colours or inline `style=` colours.
- Dark mode follows the operating system. Every colour variable gets a dark value in the
  `prefers-color-scheme: dark` block. The accents (`--k-primary` and so on) are fills with white text
  on them; coloured text uses the `-text` variants (`--k-primary-text`), which dark mode lightens.
  Printouts and the case report (`<html class="paper">`) stay light.
- Pages must not scroll sideways at 390px wide. Wide tables scroll inside their own box. The
  browser tests check this.
- Forms: labels go above the fields in a `.field-grid` of `.field` elements. Required fields get
  `required` and a `<span class="req">*</span>`, and the form gets `data-validate novalidate`.
- Keep text in the language files, not in templates, including `aria-label` and `confirm()` text.

## Git and pull requests

- Before merging a PR, wait for every CI check and read every review comment. Codex reviews pull
  requests in this repository. Fix real findings (with a test), reply on each thread, then merge
  with a merge commit.
- After a PR merges, start new work from a fresh `origin/master`.
