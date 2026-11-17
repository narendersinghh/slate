// End-to-end: SEO and Appearance have their own pages (old Settings addresses redirect), and the styles editor
// (Appearance › Styles) adds colours, variables and classes that reach the website.
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

test('SEO and Appearance live outside Settings; old addresses redirect; sections save', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/settings/seo/sitemap`);
  await expect(page).toHaveURL(/\/admin\/seo\/sitemap$/);
  await expect(page.locator('.mod-tabs a.active')).toHaveText('XML sitemap');
  await expect(page.locator('.settings-nav')).toHaveCount(0);
  await page.goto(`${BASE}/admin/settings/appearance/design`);
  await expect(page).toHaveURL(/\/admin\/design\/styles$/);
  // Settings no longer lists them.
  await page.goto(`${BASE}/admin/settings`);
  await expect(page.locator('.settings-nav-group summary', { hasText: 'SEO' })).toHaveCount(0);
  await expect(page.locator('.settings-nav-group summary', { hasText: 'Appearance' })).toHaveCount(0);
  // A section saves and comes back to its own address.
  await page.goto(`${BASE}/admin/seo/sitemap`);
  const per = page.locator('input[name="s[seo_sitemap_per_page]"]');
  const before = await per.inputValue();
  await per.fill('1234');
  await page.click('.save-bar button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/seo\/sitemap$/);
  await expect(per).toHaveValue('1234');
  await per.fill(before);
  await page.click('.save-bar button[type=submit]');
  await expect(per).toHaveValue(before);
});

test('styles editor: colour, variable and class with hover reach the website', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  await page.goto(`${BASE}/admin/design/styles`);
  await expect(page.locator('.dse-tabs')).toBeVisible();
  await expect(page.locator('[data-dirty]')).toBeHidden();

  await page.click('[data-add-color]');
  await page.keyboard.type('E2E Mint');
  await page.locator('.dse-color').last().locator('[data-c="value"]').fill('#3eb489');
  await expect(page.locator('.dse-color').last().locator('.dse-chip')).toContainText('--color-e2e-mint');

  await page.click('[data-tab="variables"]');
  await page.click('[data-preset="5"]');
  await page.locator('.dse-var').last().locator('[data-v="name"]').fill('e2e-gap');
  await page.locator('.dse-var').last().locator('[data-v="value"]').fill('18px');

  await page.click('[data-tab="classes"]');
  await page.click('[data-new-cls]');
  await page.keyboard.type('e2e card');
  await expect(page.locator('[data-k="name"]')).toHaveValue('e2e-card');
  await page.locator('textarea[data-k="css"]').click();
  await page.click('[data-snip="1"]');
  await page.locator('textarea[data-k="hover"]').fill('opacity: .9;');
  // The live preview applies the class; the dashboard's own buttons keep their look.
  await expect(page.locator('[data-stage] .e2e-card')).toHaveCSS('border-top-left-radius', /px/);
  await expect(page.locator('[data-dirty]')).toBeVisible();

  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('.dse-tabs')).toBeVisible();
  const html = await (await page.request.get(`${BASE}/no-such-page-e2e/`)).text();
  expect(html).toContain('--color-e2e-mint:#3eb489');
  expect(html).toContain('--e2e-gap:18px');
  expect(html).toContain('.e2e-card:hover{opacity: .9;}');

  // Clean up.
  await page.click('[data-tab="classes"]');
  await page.locator('[data-cls]', { hasText: 'e2e-card' }).click();
  page.once('dialog', (d) => d.accept());
  await page.click('[data-del-cls]');
  await page.click('[data-tab="variables"]');
  await page.locator('.dse-var', { has: page.locator('[value="e2e-gap"]') }).locator('[data-v="del"]').click();
  await page.click('[data-tab="colours"]');
  page.once('dialog', (d) => d.accept());
  await page.locator('.dse-color', { has: page.locator('[value="E2E Mint"]') }).locator('[data-c="del"]').click();
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('.dse-tabs')).toBeVisible();
  const after = await (await page.request.get(`${BASE}/no-such-page-e2e/`)).text();
  expect(after).not.toContain('--color-e2e-mint');
  expect(after).not.toContain('.e2e-card');
  expect(errors).toEqual([]);
});
