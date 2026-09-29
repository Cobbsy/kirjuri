const { test, expect } = require('./fixtures');

test('a wrong password is refused with a message on the login card', async ({ page }) => {
  await page.goto('login.php');
  // An unknown username, so repeated runs never lock the admin account.
  await page.fill('#username', 'nobody-' + Date.now());
  await page.fill('#password', 'not-the-password');
  await page.click('#request_save_button');
  await expect(page).toHaveURL(/login\.php/);
  await expect(page.locator('.login-card .alert-danger')).toBeVisible();
});

test('the admin lands on the front page with the case list', async ({ admin: page }) => {
  await expect(page).toHaveURL(/index\.php/);
  await expect(page.locator('.index-toolbar h1')).toBeVisible();
  await expect(page.locator('#sidebar-nav a[href^="add_case.php"]')).toBeAttached();
});
