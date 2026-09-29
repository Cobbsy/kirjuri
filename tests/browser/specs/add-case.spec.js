const { test, expect, createCase, expectNoSidewaysScroll } = require('./fixtures');

test('an incomplete request is not sent and the missing fields are marked', async ({ admin: page }) => {
  await page.goto('add_case.php');
  await page.fill('#case_crime', 'Fraud');
  await page.click('#request_save_button');

  await expect(page).toHaveURL(/add_case\.php/);
  await expect(page.locator('#case_file_number')).toBeFocused(); // Only the prefix is filled in.
  await expect(page.locator('.field.has-error')).not.toHaveCount(0);
  await expect(page.locator('#case_crime')).not.toHaveAttribute('aria-invalid', 'true');

  // Filling a marked field clears its mark straight away.
  await page.fill('#case_suspect', 'Doe John');
  await expect(page.locator('#case_suspect')).toHaveAttribute('aria-invalid', 'false');

  // Clearing the form clears the marks too.
  await page.click('input[type="reset"]');
  await expect(page.locator('.field.has-error')).toHaveCount(0);
  await expectNoSidewaysScroll(page);
});

test('a complete request is saved and listed on the front page', async ({ admin: page }) => {
  const name = 'Browser test ' + Date.now();
  const caseId = await createCase(page, name);
  await page.goto('index.php');
  await expect(page.locator(`.case-table a[href="edit_request.php?case=${caseId}"]`).first()).toBeVisible();
  await expect(page.locator('.case-table')).toContainText(name);
  await expectNoSidewaysScroll(page);
});
