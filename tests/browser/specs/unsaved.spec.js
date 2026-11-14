const { test, expect, createCase, startCase } = require('./fixtures');

// Leaving a page with text that was not saved asks first (data-warn-unsaved in kirjuri.js). The browser shows its
// own question; the tests record it and stay on the page.
function watchLeaving(page) {
  const asked = [];
  page.on('dialog', async (dialog) => {
    asked.push(dialog.type());
    await dialog.dismiss();
  });
  return asked;
}

async function addDevice(page, caseId) {
  await page.goto(`edit_request.php?case=${caseId}&tab=devices`);
  await page.click('button[data-target="#add_device_media_modal"]');
  const dialog = page.locator('#add_device_media_modal');
  await dialog.locator('#device_type').selectOption({ index: 1 });
  await dialog.locator('#device_action').selectOption({ index: 1 });
  await dialog.locator('#device_location').selectOption({ index: 1 });
  await dialog.locator('#device_model').fill('Unsaved ' + Date.now());
  await dialog.locator('button[type="submit"]').click();
  await page.locator('table.device-card .device-title a[href^="device_memo.php"]').first().click();
  await expect(page.locator('#device_manuf')).toBeVisible();
}

test('the device page asks before leaving with unsaved changes, and not after saving', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Unsaved device ' + Date.now());
  await startCase(page, caseId);
  await addDevice(page, caseId);
  const devicePage = page.url();
  const asked = watchLeaving(page);

  // A status change saves itself, so leaving after one needs no question.
  await page.locator('select[name="device_action"]').selectOption({ index: 4 });
  await expect(page.locator('.autosave-status')).toHaveText('Changes saved.');

  await page.fill('#device_manuf', 'Typed but not saved');
  await page.locator('.breadcrumb a').first().click();
  await expect.poll(() => asked).toEqual(['beforeunload']);
  expect(page.url()).toBe(devicePage);

  await page.locator('#save_button').click();
  await expect(page.locator('#device_manuf')).toHaveValue('Typed but not saved');
  await page.locator('.breadcrumb a').first().click();
  await expect(page).toHaveURL(/index\.php/);
  expect(asked).toEqual(['beforeunload']);
});

test('the notes editors ask before leaving with unsaved text', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Unsaved notes ' + Date.now());
  await startCase(page, caseId);
  await page.goto(`edit_request.php?case=${caseId}&tab=examiners_notes`);
  const asked = watchLeaving(page);
  const editor = page.frameLocator('#tab-examiners_notes iframe').locator('body');
  await editor.click();
  await page.keyboard.type('Password of the phone: 1234');
  await page.goto('index.php').catch(() => {}); // Refused while the question is open.
  await expect.poll(() => asked).toEqual(['beforeunload']);
});

test('a cleared add request form leaves without asking', async ({ admin: page }) => {
  await page.goto('add_case.php');
  const asked = watchLeaving(page);
  await page.fill('#case_crime', 'Fraud');
  await page.locator('input[type="reset"]').click();
  await page.locator('.sidebar a[href^="index.php"]').first().click();
  await expect(page).toHaveURL(/index\.php/);
  expect(asked).toEqual([]);
});
