# Browser tests

Playwright tests that drive Kirjuri in a real browser, at desktop and phone widths. They cover
what the PHP tests cannot see: the page scripts (form checks, dialogs, the phone menu, case
tabs, device status changes) and the layout (no page scrolls sideways on a phone). Every test
also fails on any JavaScript error on the page.

They run against a running installation and change its data (they add cases and devices), so
use a test installation, never a real one. The easiest is the Docker setup in the repository
root:

    docker compose up -d
    cd tests/browser
    npm ci
    npx playwright install chromium
    npx playwright test

Settings, as environment variables:

- `KIRJURI_URL`: the installation to test, default `http://localhost:8080/`
- `KIRJURI_ADMIN_PASSWORD`: the admin password, default `admin-password` (the Docker setup's)

`npx playwright test --project=phone` runs only the phone width, `--headed` shows the browser,
and `npx playwright show-report` opens the report of the last run. In CI the report and the
failing tests' screenshots and traces are kept as the `playwright-report` artifact.
