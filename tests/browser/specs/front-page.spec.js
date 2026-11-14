const { test, expect, createCase } = require('./fixtures');

test('the case list header has a cell for every column', async ({ admin: page }) => {
  await createCase(page, 'Columns test ' + Date.now());
  await page.goto('index.php');
  const headerCells = await page.locator('.case-table thead th').count();
  const rowCells = await page.locator('.case-table tbody tr[class] >> nth=0').locator('td').count();
  expect(rowCells).toBe(headerCells);
});

test('a status chip filters the list and shows how many cases it holds', async ({ admin: page }) => {
  await createCase(page, 'Filter test ' + Date.now());
  await page.goto('index.php');
  const chip = page.locator('.status-filter-new');
  const count = Number(await chip.locator('.status-filter-count').textContent());
  expect(count).toBeGreaterThan(0);

  await chip.click();
  await expect(page).toHaveURL(/[?&]s=1\b/);
  await expect(page.locator('.status-filter-new')).toHaveAttribute('aria-current', 'true');
  const rows = page.locator('.case-table tbody tr:not(.case-table-footer)');
  await expect(rows).toHaveCount(count);
  for (const row of await rows.all()) {
    await expect(row).toHaveClass(/\bdanger\b/);
  }

  await page.locator('.status-filter-all').click();
  await expect(page.locator('.status-filter-all')).toHaveAttribute('aria-current', 'true');
});

test('on a wide screen the case list header stays in view while the page scrolls', async ({ admin: page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'narrow screens scroll the table sideways instead');
  for (let i = 0; i < 3; i++) await createCase(page, 'Sticky test ' + Date.now());
  await page.setViewportSize({ width: 1440, height: 400 });
  await page.goto('index.php');
  const header = page.locator('.case-table thead th').first();
  const start = (await header.boundingBox()).y;
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(start);
  expect(Math.round((await header.boundingBox()).y)).toBe(0);
});
