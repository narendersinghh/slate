// End-to-end: the menu builder (Settings › Appearance › Menus): search to add, custom links, nesting, order, save.
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

const rootItems = (page) => page.locator('.mb-root > .mb-item');

test('menu builder: search and add, custom link, nest, reorder, save', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  await page.goto(`${BASE}/admin/settings/appearance/menus`);
  await expect(page.locator('.mb-head')).toBeVisible();

  // A fresh menu, so the test does not depend on what the site already has.
  await page.click('[data-new-menu]');
  await page.fill('[data-menu-name]', 'E2E menu');
  await expect(page.locator('.mb-empty')).toBeVisible();

  // Search content by title and add it.
  await expect(page.locator('[data-results="post"] .mb-result').first()).toBeVisible();
  const first = (await page.locator('[data-results="post"] .mb-result strong').first().textContent()).trim();
  await page.fill('[data-q="post"]', first.slice(0, 6));
  await expect(page.locator('[data-results="post"] .mb-result strong').first()).toContainText(first.slice(0, 6), { ignoreCase: true });
  await page.locator('[data-results="post"] .mb-result input').first().check();
  await expect(page.locator('[data-sel-count="post"]')).toHaveText('1 selected');
  await page.click('[data-add-selected="post"]');
  await expect(rootItems(page)).toHaveCount(1);

  // Custom link.
  await page.click('[data-tab="custom"]');
  await page.fill('[data-custom-label]', 'Docs');
  await page.fill('[data-custom-url]', 'https://example.com/docs');
  await page.click('[data-add-custom]');
  await expect(rootItems(page)).toHaveCount(2);
  await expect(rootItems(page).nth(1)).toContainText('Docs');

  // Make "Docs" a sub-item, then back, then move it to the top.
  await rootItems(page).nth(1).locator('[data-act="indent"]').click();
  await expect(rootItems(page)).toHaveCount(1);
  await expect(page.locator('.mb-root .mb-list .mb-item')).toContainText('Docs');
  await page.locator('.mb-root .mb-list .mb-item [data-act="outdent"]').click();
  await expect(rootItems(page)).toHaveCount(2);
  await rootItems(page).nth(1).locator('[data-act="up"]').click();
  await expect(rootItems(page).nth(0)).toContainText('Docs');

  // Details: rename and open in a new tab.
  await rootItems(page).nth(0).locator('[data-toggle]').click();
  await page.locator('.mb-item.is-open [data-k="label"]').fill('Documentation');
  await page.locator('.mb-item.is-open [data-k="target"]').check();
  await expect(rootItems(page).nth(0).locator('.mb-title strong')).toHaveText('Documentation');

  // Drag the content item into "Documentation" (drop on its middle).
  await rootItems(page).nth(1).locator('.mb-row').dragTo(rootItems(page).nth(0).locator('> .mb-row'));
  await expect(rootItems(page)).toHaveCount(1);

  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('.mb-head')).toBeVisible();
  const saved = await page.evaluate(() => JSON.parse(document.querySelector('[data-menu-config]').textContent).menus.find((m) => m.name === 'E2E menu'));
  expect(saved.items).toHaveLength(1);
  expect(saved.items[0]).toMatchObject({ label: 'Documentation', type: 'custom', target: '_blank' });
  expect(saved.items[0].children).toHaveLength(1);
  expect(saved.items[0].children[0].type).toBe('post');

  // Delete the test menu again.
  const idx = await page.evaluate(() => JSON.parse(document.querySelector('[data-menu-config]').textContent).menus.findIndex((m) => m.name === 'E2E menu'));
  await page.selectOption('[data-menu-switch]', String(idx));
  page.once('dialog', (d) => d.accept());
  await page.click('[data-del-menu]');
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('.mb-head')).toBeVisible();
  expect(await page.evaluate(() => JSON.parse(document.querySelector('[data-menu-config]').textContent).menus.some((m) => m.name === 'E2E menu'))).toBe(false);
  expect(errors).toEqual([]);
});
