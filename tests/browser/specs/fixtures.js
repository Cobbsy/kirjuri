// Shared helpers: a logged-in page that fails the test on any JavaScript error.
const base = require('@playwright/test');

const ADMIN_PASSWORD = process.env.KIRJURI_ADMIN_PASSWORD || 'admin-password';

const test = base.test.extend({
  page: async ({ page }, use) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await use(page);
    base.expect(errors, 'JavaScript errors on the page').toEqual([]);
  },
  admin: async ({ page }, use) => {
    await login(page, 'admin', ADMIN_PASSWORD);
    await use(page);
  },
});

async function login(page, username, password) {
  await page.goto('login.php');
  await page.fill('#username', username);
  await page.fill('#password', password);
  await Promise.all([page.waitForURL(/index\.php/), page.click('#request_save_button')]);
}

/** Fail when the page is wider than the window, which makes phones scroll sideways. */
async function expectNoSidewaysScroll(page) {
  const widths = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
  base.expect(widths[0], 'page width vs window width').toBeLessThanOrEqual(widths[1] + 1);
}

/** On phones the navigation is folded behind the Menu button. */
async function openMenuIfFolded(page) {
  const toggle = page.locator('.sidebar-toggle');
  if (await toggle.isVisible()) {
    await toggle.click();
    await base.expect(page.locator('#sidebar-nav')).toBeVisible();
  }
}

/** Create a request through the form and return its UID. */
async function createCase(page, name) {
  await page.goto('add_case.php');
  await page.fill('#examination_request', name);
  await page.fill('#case_file_number', '5500/R/' + Date.now());
  await page.fill('#case_crime', 'Fraud');
  await page.selectOption('#classification', { index: 1 });
  await page.fill('#case_suspect', 'Doe John');
  await page.fill('#case_investigation_lead', 'Lead Investigator');
  await page.fill('#case_investigator', 'Case Investigator');
  await page.fill('#case_investigator_tel', '555-0100');
  await page.selectOption('#case_investigator_unit', { index: 1 });
  await page.fill('#case_confiscation_date', '2026-01-15');
  await page.fill('#case_request_description', 'One phone');
  await page.fill('#case_requested_action', 'Full examination');
  await page.click('#request_save_button');
  const link = page.locator('.main h1 a[href^="edit_request.php?case="]');
  await base.expect(link).toBeVisible();
  return Number((await link.getAttribute('href')).split('=')[1]);
}

/**
 * Open a new case and assign an examiner in the dialog that pops up, which starts it. A fresh
 * installation may have no users flagged as examiners, so "mobile examination only" is chosen.
 */
async function startCase(page, caseId) {
  await page.goto('edit_request.php?case=' + caseId);
  const dialog = page.locator('#add_forensic_examiner_modal');
  await base.expect(dialog).toBeVisible();
  await dialog.locator('select[name="forensic_investigator"]').selectOption('-');
  await dialog.locator('button[type="submit"]').click();
  await base.expect(page.locator('.chip-open')).toBeVisible();
}

module.exports = { test, expect: base.expect, login, expectNoSidewaysScroll, openMenuIfFolded, createCase, startCase, ADMIN_PASSWORD };
