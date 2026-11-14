const { test, expect, createCase, startCase, expectNoSidewaysScroll, findDuplicateIds } = require('./fixtures');

test('devices can be added, have their status changed and be edited', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Device test ' + Date.now());
  await startCase(page, caseId);
  await page.goto(`edit_request.php?case=${caseId}&tab=devices`);
  await expect(page.locator('#tab-devices')).toBeVisible();

  // The add device dialog refuses to send without type, status and location.
  await page.click('button[data-target="#add_device_media_modal"]');
  const dialog = page.locator('#add_device_media_modal');
  await expect(dialog).toBeVisible();
  await dialog.locator('button[type="submit"]').click();
  await expect(dialog.locator('.field.has-error')).toHaveCount(3);
  await expect(dialog.locator('#device_type')).toBeFocused();

  const model = 'Model ' + Date.now();
  await dialog.locator('#device_type').selectOption({ index: 1 });
  await dialog.locator('#device_action').selectOption({ index: 1 });
  await dialog.locator('#device_location').selectOption({ index: 1 });
  await dialog.locator('#device_manuf').fill('Acme');
  await dialog.locator('#device_model').fill(model);
  await dialog.locator('button[type="submit"]').click();

  await expect(page).toHaveURL(new RegExp(`edit_request\\.php\\?case=${caseId}&tab=devices`));
  const card = page.locator('table.device-card', { hasText: model });
  await expect(card).toBeVisible();
  await expectNoSidewaysScroll(page);

  // Changing the status saves in the background and updates the card's progress bar.
  const status = card.locator('select[name="device_action"]');
  const options = await status.locator('option:not([disabled])').evaluateAll((all) => all.map((o) => o.value));
  const next = options[options.length - 1];
  const closeHidden = () => page.locator('[data-close-request]').evaluate((button) => button.hidden);
  expect(await closeHidden()).toBe(true); // A device is still being worked on.
  const saved = page.waitForResponse((r) => r.url().includes('type=change_device_status') && r.request().method() === 'POST');
  await status.selectOption(next);
  await saved;
  // The menus say they saved, and the case can be closed at once: its only device needs no more work.
  await expect(card.locator('.autosave-status')).toHaveText('Changes saved.');
  expect(await closeHidden()).toBe(false);
  const location = card.locator('select[name="device_location"]');
  const located = page.waitForResponse((r) => r.url().includes('type=change_device_location') && r.request().method() === 'POST');
  await location.selectOption({ index: 3 });
  expect(await (await located).json()).toEqual({ saved: true });
  await expect(card.locator('.autosave-status')).toHaveText('Changes saved.');
  await page.reload();
  await expect(page.locator('table.device-card', { hasText: model }).locator('select[name="device_action"] option[selected]')).toHaveAttribute('value', next);

  // The device page saves its fields.
  await page.locator('table.device-card', { hasText: model }).locator('.device-title a[href^="device_memo.php"]').click();
  await expect(page.locator('#device_manuf')).toHaveValue('Acme');
  expect(await findDuplicateIds(page)).toEqual([]);
  await page.fill('#device_manuf', 'Acme Corporation');
  // One save bar, which stays in view at the bottom of the window while the page scrolls.
  await expect(page.locator('#save_button')).toHaveCount(1);
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(page.locator('#save_button')).toBeInViewport();
  await page.locator('#save_button').click();
  await expect(page.locator('#device_manuf')).toHaveValue('Acme Corporation');
  await expectNoSidewaysScroll(page);
});

test('the request details dialog checks its required fields before saving', async ({ admin: page }) => {
  const caseId = await createCase(page, 'Details test ' + Date.now());
  await startCase(page, caseId); // A new request opens with the examiner dialog.
  await page.goto(`edit_request.php?case=${caseId}`);
  await page.click('button[data-target="#perustiedot"]');
  const dialog = page.locator('#perustiedot');
  await expect(dialog).toBeVisible();
  expect(await findDuplicateIds(page)).toEqual([]);

  // An emptied required field is marked and focused instead of being saved.
  await dialog.locator('#case_crime').fill('');
  await dialog.locator('button[type="submit"]').click();
  await expect(dialog.locator('.field.has-error')).toHaveCount(1);
  await expect(dialog.locator('#case_crime')).toBeFocused();

  await dialog.locator('#case_crime').fill('Burglary');
  await dialog.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`edit_request\\.php\\?case=${caseId}$`));
  await page.click('button[data-target="#perustiedot"]');
  await expect(page.locator('#perustiedot #case_crime')).toHaveValue('Burglary');
  await expectNoSidewaysScroll(page);
});
