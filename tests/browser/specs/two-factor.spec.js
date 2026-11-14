// Two-factor sign-in in the browser: setting it up from the menu, then signing in with a code from the app and
// with a recovery code. The test computes the codes an authenticator app would show from the key on the page.
const crypto = require('crypto');
const { test, expect, login, ADMIN_PASSWORD, expectNoSidewaysScroll, findDuplicateIds, findReadabilityProblems, openMenuIfFolded } = require('./fixtures');

/** The bytes of a key in base32, as the page shows it for typing into an app. */
function base32Decode(text) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of text.replace(/[\s=]/g, '').toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

/** The six-digit code (RFC 6238) an app with this secret shows now, or `steps` periods of 30 seconds later. */
function totp(secret, steps = 0) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + steps));
  const hash = crypto.createHmac('sha1', secret).update(counter).digest();
  const value = (hash.readUInt32BE(hash[19] & 0x0f) & 0x7fffffff) % 1000000;
  return String(value).padStart(6, '0');
}

async function createUser(page, username, password) {
  await page.goto('users.php');
  await page.click('[data-target="#add_user_modal"]');
  await page.fill('#new_username', username);
  await page.fill('#new_name', username);
  await page.fill('#new_password', password);
  await page.fill('#new_current_password', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/users\.php/), page.locator('#add_user_modal [type=submit]').click()]);
}

/**
 * Sign out by dropping the session cookie. The signed-in page is left first: its unread counter, polled without
 * a session, sends the page to login.php, which would cut short the next navigation.
 */
async function signOut(page) {
  await page.goto('about:blank');
  await page.context().clearCookies();
}

/** The password step of signing in, which now leads to the code step. */
async function enterPassword(page, username, password) {
  await signOut(page);
  await page.goto('login.php');
  await page.fill('#username', username);
  await page.fill('#password', password);
  await Promise.all([page.waitForURL(/login\.php/), page.click('#request_save_button')]);
  await expect(page.locator('#two_factor_code')).toBeVisible();
}

test('two-factor sign-in is set up from the menu, then asks for a code or a recovery code', async ({ page }, testInfo) => {
  const username = 'twofactor' + Date.now() + testInfo.project.name;
  const password = 'browser-test-password';
  await login(page, 'admin', ADMIN_PASSWORD);
  await createUser(page, username, password);
  await signOut(page);
  await login(page, username, password);

  await openMenuIfFolded(page);
  await page.click('#sidebar-nav a[href="two_factor.php"]');
  await expect(page.locator('.two-factor-status')).toContainText('Off');
  await page.click('a[href="two_factor.php?setup=1"]');
  await expect(page.locator('.two-factor-qr svg')).toBeVisible();
  await expectNoSidewaysScroll(page);
  expect(await findDuplicateIds(page)).toEqual([]);

  // The key behind "Can't scan the code?" is the one in the QR code.
  await page.click('.two-factor-key-box summary');
  await expect(page.locator('#two_factor_key')).toBeVisible();
  const secret = base32Decode(await page.locator('#two_factor_key').textContent());
  expect(secret).toHaveLength(20);

  // Without a code the form says so instead of sending.
  const submit = page.locator('form[action$="two_factor_setup"] [type=submit]');
  await page.fill('#current_password', password);
  await submit.click();
  await expect(page).toHaveURL(/setup=1/);
  await expect(page.locator('#two_factor_code')).toBeFocused();
  await expect(page.locator('.field.has-error')).toHaveCount(1);

  await page.fill('#two_factor_code', totp(secret));
  await Promise.all([page.waitForURL(/two_factor\.php$/), submit.click()]);
  const codes = await page.locator('#recovery_codes code').allTextContents();
  expect(codes).toHaveLength(10);
  await expectNoSidewaysScroll(page);
  await page.click('.recovery-codes-card a[href="two_factor.php"]');
  await expect(page.locator('.two-factor-status')).toContainText('On');
  await expect(page.locator('#recovery_codes')).toHaveCount(0);

  // The password alone no longer signs in: the code step follows.
  await enterPassword(page, username, password);
  await expect(page.locator('#two_factor_code')).toBeFocused();
  await expect(page.locator('#username')).toHaveCount(0);
  await expectNoSidewaysScroll(page);
  expect(await findDuplicateIds(page)).toEqual([]);
  // The setup used this period's code, and each code works once, so the app's next one.
  await page.fill('#two_factor_code', totp(secret, 1));
  await Promise.all([page.waitForURL(/index\.php/), page.click('.two-factor-form [type=submit]')]);

  // A lost phone: a recovery code instead.
  await enterPassword(page, username, password);
  await page.click('.two-factor-recovery summary');
  await page.fill('#recovery_code', codes[0]);
  await Promise.all([page.waitForURL(/two_factor\.php/), page.click('.two-factor-recovery [type=submit]')]);
  await expect(page.locator('.flash-error')).toContainText('9 left');
});

for (const colorScheme of ['light', 'dark']) {
  test.describe(`${colorScheme} mode`, () => {
    test.use({ colorScheme });

    test('the code step of signing in and the recovery codes are readable', async ({ page }, testInfo) => {
      // The test installation's admin has no two-factor sign-in, so a user of its own.
      const username = 'tfread' + Date.now() + testInfo.project.name + colorScheme;
      const password = 'browser-test-password';
      await login(page, 'admin', ADMIN_PASSWORD);
      await createUser(page, username, password);
      await signOut(page);
      await login(page, username, password);
      await page.goto('two_factor.php?setup=1');
      const secret = base32Decode(await page.locator('#two_factor_key').textContent());
      await page.fill('#two_factor_code', totp(secret));
      await page.fill('#current_password', password);
      await Promise.all([page.waitForURL(/two_factor\.php$/), page.click('form[action$="two_factor_setup"] [type=submit]')]);
      await expect(page.locator('#recovery_codes')).toBeVisible();
      expect(await findReadabilityProblems(page)).toEqual([]);
      await enterPassword(page, username, password);
      await page.click('.two-factor-recovery summary');
      expect(await findReadabilityProblems(page)).toEqual([]);
    });
  });
}
