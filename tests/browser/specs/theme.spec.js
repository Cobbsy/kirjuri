const { test, expect, createCase, findReadabilityProblems } = require('./fixtures');

const pages = ['index.php', 'add_case.php', 'statistics.php', 'tools.php', 'users.php', 'messages.php', 'settings.php', 'help.php'];

for (const colorScheme of ['light', 'dark']) {
  test.describe(`${colorScheme} mode`, () => {
    test.use({ colorScheme });

    test('the login page is readable', async ({ page }) => {
      await page.goto('login.php');
      expect(await findReadabilityProblems(page)).toEqual([]);
    });

    for (const url of pages) {
      test(`${url} is readable`, async ({ admin: page }) => {
        await page.goto(url);
        expect(await findReadabilityProblems(page)).toEqual([]);
      });
    }

    test('a case, its devices and its log are readable', async ({ admin: page }) => {
      const caseId = await createCase(page, 'Theme test ' + Date.now());
      await page.goto('edit_request.php?case=' + caseId);
      await page.keyboard.press('Escape');
      expect(await findReadabilityProblems(page)).toEqual([]);
      await page.goto('timeline.php?case=' + caseId);
      expect(await findReadabilityProblems(page)).toEqual([]);
    });

    test('the case report stays light, like the paper it is printed on', async ({ admin: page }) => {
      const caseId = await createCase(page, 'Report test ' + Date.now());
      await page.goto('case_report.php?case=' + caseId);
      await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
      expect(await findReadabilityProblems(page)).toEqual([]);
    });
  });
}
