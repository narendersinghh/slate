// End-to-end: Goraiya in the dashboard editor (no separate AI box): the header guide, the sparrow next to selected
// text and next to the current block (revamp, delete with undo), and one scrollbar (the sidebar scrolls with the page).
// Needs a running Slate site with the fake AI provider (bin/mock-ai + bin/ci-ai) and SLATE_MOCK_AI=1.
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';

test.setTimeout(90_000);
test.skip(!process.env.SLATE_MOCK_AI, 'needs the fake AI provider (SLATE_MOCK_AI=1)');

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

test('Goraiya in the editor: guide, selected text, block revamp and delete with undo', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  await page.goto(`${BASE}/admin/content/post/new`);
  await expect(page.locator('.ai-card')).toHaveCount(0); // no separate AI box any more
  expect(await page.locator('.editor-side').evaluate((el) => getComputedStyle(el).overflowY)).toBe('visible');

  // The guide.
  await page.click('[data-gor-guide]');
  await expect(page.locator('.modal')).toContainText('Select text');
  await page.locator('.modal [data-close]').first().click();

  const area = page.locator('[data-main-editor] .ed-area');
  await area.click();
  await page.keyboard.type('First paragraph that Goraiya will rewrite.');
  await page.keyboard.press('Enter');
  await page.keyboard.type('Second paragraph to delete.');

  // Selected text: the sparrow appears next to it.
  await area.locator('p').first().selectText();
  const fab = page.locator('.gor-fab');
  await expect(fab).toBeVisible();
  await expect(fab).not.toHaveClass(/is-block/);
  await fab.click();
  await expect(page.locator('.gor-pop')).toContainText('Selected text');
  await page.click('.gor-pop [data-gor="improve"]');
  await expect(page.locator('.modal .ai-preview')).toContainText('Grow your business');
  await expect(page.locator('.modal .ai-preview script')).toHaveCount(0);
  await page.click('.modal [data-mode="replace"]');
  await expect(area).toContainText('Grow your business');
  await expect(area).not.toContainText('First paragraph');

  // A block: caret in the second paragraph, the sparrow on the left; delete, then undo.
  await area.locator('p', { hasText: 'Second paragraph' }).click();
  await expect(fab).toHaveClass(/is-block/);
  await fab.click();
  await expect(page.locator('.gor-pop')).toContainText('This paragraph');
  await page.click('.gor-pop [data-gor="delete"]');
  await expect(area).not.toContainText('Second paragraph');
  await page.locator('.toast-action', { hasText: 'Undo' }).click();
  await expect(area).toContainText('Second paragraph');

  // Revamp a block with an instruction.
  await area.locator('p', { hasText: 'Second paragraph' }).click();
  await fab.click();
  await page.fill('.gor-pop input', 'make it friendlier');
  await page.click('.gor-pop button[type=submit]');
  await expect(page.locator('.modal .ai-preview')).toBeVisible();
  await page.click('.modal [data-mode="replace"]');
  await expect(area).not.toContainText('Second paragraph to delete.');
  expect(errors).toEqual([]);
});
