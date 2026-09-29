// Shared helpers: a logged-in page that fails the test on any JavaScript error.
const base = require('@playwright/test');

const ADMIN_PASSWORD = process.env.KIRJURI_ADMIN_PASSWORD || 'admin-password';

const test = base.test.extend({
  page: async ({ page }, use) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await use(page);
    base.expect(errors, 'JavaScript errors on the page').toEqual([]);
  },
  admin: async ({ page }, use) => {
    await login(page, 'admin', ADMIN_PASSWORD);
    await use(page);
  },
});

async function login(page, username, password) {
  await page.goto('login.php');
  await page.fill('#username', username);
  await page.fill('#password', password);
  await Promise.all([page.waitForURL(/index\.php/), page.click('#request_save_button')]);
}

/** Fail when the page is wider than the window, which makes phones scroll sideways. */
async function expectNoSidewaysScroll(page) {
  const widths = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
  base.expect(widths[0], 'page width vs window width').toBeLessThanOrEqual(widths[1] + 1);
}

/**
 * Text the page shows that is hard to read: each element with its own text (or a form field's value) whose
 * colour has less than minContrast against the background behind it. In dark mode it also lists light boxes,
 * which are usually a light colour the theme left in place. Backgrounds are composited up the element's
 * ancestors; an element over a background image or gradient is skipped.
 */
async function findReadabilityProblems(page, minContrast = 3) {
  return page.evaluate((minContrast) => {
    const parse = (value) => {
      const m = /rgba?\(([^)]+)\)/.exec(value);
      if (!m) return null;
      const p = m[1].split(/[\s,/]+/).filter(Boolean).map(Number);
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const over = (top, bottom) => ({
      r: top.r * top.a + bottom.r * (1 - top.a),
      g: top.g * top.a + bottom.g * (1 - top.a),
      b: top.b * top.a + bottom.b * (1 - top.a),
      a: 1,
    });
    const luminance = ({ r, g, b }) => {
      const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const contrast = (a, b) => {
      const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
      return (hi + 0.05) / (lo + 0.05);
    };
    const dark = matchMedia('(prefers-color-scheme: dark)').matches;
    const canvas = dark ? { r: 18, g: 18, b: 18, a: 1 } : { r: 255, g: 255, b: 255, a: 1 };
    const backgroundOf = (el) => {
      const chain = [];
      for (let e = el; e; e = e.parentElement) chain.unshift(e);
      let colour = canvas;
      for (const e of chain) {
        const style = getComputedStyle(e);
        if (style.backgroundImage !== 'none') return null;
        const c = parse(style.backgroundColor);
        if (c && c.a > 0) colour = over(c, colour);
      }
      return colour;
    };
    const describe = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
      (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '');
    const visible = (el) => {
      const rect = el.getBoundingClientRect();
      const style = getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && el.checkVisibility({ opacityProperty: true });
    };

    const problems = [];
    for (const el of document.body.querySelectorAll('*')) {
      if (['SCRIPT', 'STYLE', 'OPTION', 'IMG', 'CANVAS', 'IFRAME', 'svg'].includes(el.tagName) || !visible(el)) continue;
      const field = el.matches('input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden]), select, textarea');
      const ownText = [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
      if (field ? el.value.trim() : ownText) {
        const bg = backgroundOf(el);
        if (bg) {
          const ratio = contrast(over(parse(getComputedStyle(el).color), bg), bg);
          if (ratio < minContrast) problems.push(`${describe(el)} "${(field ? el.value : el.textContent).trim().slice(0, 30)}": contrast ${ratio.toFixed(2)}`);
        }
      }
      if (dark) {
        const c = parse(getComputedStyle(el).backgroundColor);
        const rect = el.getBoundingClientRect();
        // An inline background is data, such as a tool's colour on the calendar; its text is still checked above.
        if (c && c.a > 0.5 && luminance(c) > 0.4 && rect.width * rect.height > 400 && !el.style.backgroundColor) problems.push(`${describe(el)}: light background ${getComputedStyle(el).backgroundColor}`);
      }
    }
    return [...new Set(problems)];
  }, minContrast);
}

/** On phones the navigation is folded behind the Menu button. */
async function openMenuIfFolded(page) {
  const toggle = page.locator('.sidebar-toggle');
  if (await toggle.isVisible()) {
    await toggle.click();
    await base.expect(page.locator('#sidebar-nav')).toBeVisible();
  }
}

/** Create a request through the form and return its UID. */
async function createCase(page, name) {
  await page.goto('add_case.php');
  await page.fill('#examination_request', name);
  await page.fill('#case_file_number', '5500/R/' + Date.now());
  await page.fill('#case_crime', 'Fraud');
  await page.selectOption('#classification', { index: 1 });
  await page.fill('#case_suspect', 'Doe John');
  await page.fill('#case_investigation_lead', 'Lead Investigator');
  await page.fill('#case_investigator', 'Case Investigator');
  await page.fill('#case_investigator_tel', '555-0100');
  await page.selectOption('#case_investigator_unit', { index: 1 });
  await page.fill('#case_confiscation_date', '2026-01-15');
  await page.fill('#case_request_description', 'One phone');
  await page.fill('#case_requested_action', 'Full examination');
  await page.click('#request_save_button');
  const link = page.locator('.main h1 a[href^="edit_request.php?case="]');
  await base.expect(link).toBeVisible();
  return Number((await link.getAttribute('href')).split('=')[1]);
}

/**
 * Open a new case and assign an examiner in the dialog that pops up, which starts it. A fresh
 * installation may have no users flagged as examiners, so "mobile examination only" is chosen.
 */
async function startCase(page, caseId) {
  await page.goto('edit_request.php?case=' + caseId);
  const dialog = page.locator('#add_forensic_examiner_modal');
  await base.expect(dialog).toBeVisible();
  await dialog.locator('select[name="forensic_investigator"]').selectOption('-');
  await dialog.locator('button[type="submit"]').click();
  await base.expect(page.locator('.chip-open')).toBeVisible();
}

module.exports = { test, expect: base.expect, login, expectNoSidewaysScroll, findReadabilityProblems, openMenuIfFolded, createCase, startCase, ADMIN_PASSWORD };
