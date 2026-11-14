// Browser smoke tests. They drive a running Kirjuri installation: KIRJURI_URL (default
// http://localhost:8080, the Docker setup) with the admin password in KIRJURI_ADMIN_PASSWORD.
const { defineConfig } = require('@playwright/test');

// KIRJURI_CHROMIUM: a Chromium to use instead of the one Playwright downloads, for machines that
// come with a browser of another version.
const launchOptions = process.env.KIRJURI_CHROMIUM ? { executablePath: process.env.KIRJURI_CHROMIUM } : {};

module.exports = defineConfig({
  testDir: './specs',
  // The tests share one installation and its login throttle, so they run one at a time.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 30000,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.KIRJURI_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions,
  },
  projects: [
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
    { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
