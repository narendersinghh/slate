// End-to-end: Templates list and editor (live preview of unsaved code, tag palette, Ctrl/Cmd S, in use, delete).
// Needs a running Slate site: SLATE_URL, SLATE_USER, SLATE_PASS.
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';

test.setTimeout(90_000);

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

test('templates: create, live preview before saving, save with the keyboard, use, delete', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await login(page);
  await page.goto(`${BASE}/admin/templates`);
  await expect(page.locator('[data-tpl-card]').first()).toBeVisible();
  await page.fill('[data-tpl-find]', '404');
  await expect(page.locator('[data-tpl-card]:visible')).toHaveCount(1);
  await page.fill('[data-tpl-find]', '');

  // A new template for single posts, starting from the starter.
  await page.goto(`${BASE}/admin/templates/new?kind=single&target=post`);
  await expect(page.locator('[data-settings]')).toBeVisible();
  await page.fill('.tpe-name', 'E2E post template');
  const html = page.locator('textarea[name="html"]');
  await html.fill('<article class="e2e-tpl">\n</article>');
  await html.press('Control+End');
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.fill('[data-tag-find]', 'title');
  await page.locator('[data-insert="{{title}}"]').click();
  await expect(html).toHaveValue(/<article class="e2e-tpl">\n\s*\{\{title\}\}/);

  // The preview shows the unsaved code with a real post.
  const frame = page.frameLocator('[data-frame]');
  await expect(frame.locator('.e2e-tpl')).toBeVisible({ timeout: 15_000 });
  await expect(frame.locator('.e2e-tpl')).not.toHaveText('');
  await expect(page.locator('[data-save-state]')).toHaveText('Unsaved changes');

  // Save with the keyboard; the address becomes the template's own.
  await page.keyboard.press('Control+s');
  await expect(page.locator('[data-save-state]')).toHaveText(/Saved/);
  await expect(page).toHaveURL(/\/admin\/templates\/t[0-9a-f]+$/);
  const editUrl = page.url();

  // Not in use yet: visitors still get the theme. Put it in use and check a post page.
  await page.locator('.tpe-live input').check();
  await page.keyboard.press('Control+s');
  await expect(page.locator('[data-save-state]')).toHaveText(/Saved/);
  const src = await page.locator('[data-frame]').getAttribute('src');
  const postUrl = src.split('?')[0];
  const visitor = await (await page.context().browser().newContext()).newPage();
  await visitor.goto(BASE + postUrl);
  await expect(visitor.locator('.e2e-tpl')).toBeVisible();
  await visitor.close();

  // The list shows it in use; then delete it.
  await page.goto(`${BASE}/admin/templates`);
  await expect(page.locator('.tpl-version', { hasText: 'E2E post template' })).toContainText('In use');
  await page.goto(editUrl);
  await page.click('[data-toggle-settings]');
  await page.click('.tpe-settings button[formaction$="/delete"]');
  await expect(page).toHaveURL(/\/admin\/templates$/);
  await expect(page.locator('.tpl-version', { hasText: 'E2E post template' })).toHaveCount(0);
  expect(errors).toEqual([]);
});
