const { test, expect, createCase, startCase, openMenuIfFolded, expectNoSidewaysScroll } = require('./fixtures');

const pages = ['index.php', 'add_case.php', 'statistics.php', 'tools.php', 'users.php', 'messages.php', 'settings.php', 'lang_editor.php', 'help.php'];

for (const url of pages) {
  test(`${url} renders without errors or sideways scrolling`, async ({ admin: page }) => {
    const response = await page.goto(url);
    expect(response.status()).toBe(200);
    await expect(page.locator('.main')).toBeVisible();
    await expectNoSidewaysScroll(page);
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

test('the case tabs switch in the sidebar, and on phones the menu folds away after a choice', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Tabs test ' + Date.now());
  await startCase(page, caseId);
  await page.goto('edit_request.php?case=' + caseId);
  await expect(page.locator('#tab-exam-request')).toBeVisible();
  await expectNoSidewaysScroll(page);

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
