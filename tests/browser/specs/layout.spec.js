const { test, expect, createCase, startCase, openMenuIfFolded, expectNoSidewaysScroll, findDuplicateIds } = require('./fixtures');

// statistics.php?year=1990 is a year without requests: the empty state.
const pages = ['index.php', 'add_case.php', 'statistics.php', 'statistics.php?year=1990', 'tools.php', 'users.php', 'messages.php', 'settings.php', 'two_factor.php', 'two_factor.php?setup=1', 'lang_editor.php', 'log.php', 'help.php', 'licenses.php'];

for (const url of pages) {
  test(`${url} renders without errors or sideways scrolling`, async ({ admin: page }) => {
    const response = await page.goto(url);
    expect(response.status()).toBe(200);
    await expect(page.locator('.main')).toBeVisible();
    await expectNoSidewaysScroll(page);
    expect(await findDuplicateIds(page)).toEqual([]);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  });
}

test('on a wide screen the sidebar stays in place while the page scrolls', async ({ admin: page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'the phone menu scrolls with the page');
  await page.goto('help.php');
  const sidebar = page.locator('.sidebar');
  await expect(sidebar).toHaveCSS('position', 'fixed');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
  expect((await sidebar.boundingBox()).y).toBe(0);
});

test('the audit viewer, opened from the case log, says the entry is sealed and fits a phone', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Audit viewer test ' + Date.now());
  await page.goto('timeline.php?case=' + caseId);
  // The case log's own layout covers its links (a separate problem), so the link's address is opened directly.
  await page.goto(await page.locator('a[href*="auditor.php?view="]').first().getAttribute('href'));
  await expect(page.locator('.alert-success')).toContainText('sealed, in sequence and unchanged');
  await expectNoSidewaysScroll(page);
});

test('the case tabs switch in the sidebar, and on phones the menu folds away after a choice', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Tabs test ' + Date.now());
  await startCase(page, caseId);
  await page.goto('edit_request.php?case=' + caseId);
  await expect(page.locator('#tab-exam-request')).toBeVisible();
  await expectNoSidewaysScroll(page);
  expect(await findDuplicateIds(page)).toEqual([]);

  await openMenuIfFolded(page);
  await page.click('#sidebar-nav a[href="#tab-devices"]');
  await expect(page.locator('#tab-devices')).toBeVisible();
  await expect(page.locator('#tab-exam-request')).toBeHidden();

  const toggle = page.locator('.sidebar-toggle');
  if (await toggle.isVisible()) {
    await expect(page.locator('#sidebar-nav')).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  }
});
