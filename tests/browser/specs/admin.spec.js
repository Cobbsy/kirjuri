const { test, expect } = require('./fixtures');

test('with a user open for editing, the add user dialog has its own fields and password check', async ({ admin: page }) => {
  await page.goto('users.php?populate=1');
  await expect(page.locator('#u')).toBeVisible();
  await page.click('[data-target="#add_user_modal"]');
  const dialog = page.locator('#add_user_modal');
  await expect(dialog).toBeVisible();

  // Its labels lead to its own fields, not to the edit form's.
  await dialog.locator('label[for="new_username"]').click();
  await expect(page.locator('#new_username')).toBeFocused();

  // Save waits for the admin's password in the dialog, whatever the edit form holds.
  const save = dialog.locator('[type=submit]');
  await expect(save).toBeDisabled();
  await page.fill('#edit_current_password', 'something');
  await expect(save).toBeDisabled();
  await page.fill('#new_current_password', 'something');
  await expect(save).toBeEnabled();
});

test('a tool can be added and reserved', async ({ admin: page }) => {
  const name = 'Write blocker ' + Date.now();
  await page.goto('tools.php');
  await page.click('[data-target="#add_tool_modal"]');
  await page.fill('#product_name', name);
  await page.fill('#serialno', 'SN-' + Date.now());
  await page.fill('#hw_version', '2.1');
  await page.locator('#add_tool_modal [type=submit]').click();

  // Let the reservation calendar finish drawing: leaving mid-render makes it throw.
  await expect(page.locator('.fc-view')).toBeVisible();
  await page.getByRole('link', { name }).click();
  await expect(page.locator('h1')).toContainText(name);
  await page.fill('#reserve_comment', 'Imaging a laptop');
  await page.locator('.reserve-form [type=submit]').click();
  await expect(page.locator('.card table').last()).toContainText('Imaging a laptop');
});

test('a message can be sent, opened and answered', async ({ admin: page }) => {
  const subject = 'Note to self ' + Date.now();
  await page.goto('messages.php?show=compose');
  await page.selectOption('#msgto', { label: await page.locator('#msgto option').last().textContent() });
  await page.fill('#subject', subject);
  await page.waitForFunction(() => window.tinymce && tinymce.activeEditor && tinymce.activeEditor.initialized);
  await page.evaluate(() => tinymce.activeEditor.setContent('<p>Remember the write blocker.</p>'));
  await page.locator('form[action*="send_message"] [type=submit]').click();

  await page.goto('messages.php?show=inbox');
  await page.getByRole('link', { name: subject }).click();
  await expect(page.locator('.message-body .rich-text')).toContainText('Remember the write blocker.');
  await page.locator('label[for="reply_subject"]').click();
  await expect(page.locator('#reply_subject')).toBeFocused();
  await expect(page.locator('#reply_subject')).toHaveValue(new RegExp(subject));
});
