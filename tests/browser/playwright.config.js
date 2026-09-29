// Browser smoke tests. They drive a running Kirjuri installation: KIRJURI_URL (default
// http://localhost:8080, the Docker setup) with the admin password in KIRJURI_ADMIN_PASSWORD.
const { defineConfig } = require('@playwright/test');

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
  },
  projects: [
    { name: 'desktop', use: { browserName: 'chromium', viewport: { width: 1440, height: 900 } } },
    { name: 'phone', use: { browserName: 'chromium', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
});
