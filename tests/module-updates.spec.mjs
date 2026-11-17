// End-to-end: automatic module updates on the Modules screen and the dashboard.
// Needs the fake registry (php -S 127.0.0.1:8199 bin/mock-registry) reachable from the site AND from the test
// runner: MOCK_REGISTRY (default http://127.0.0.1:8199). Uses the "upd-ui" test module it serves.
// Covers: the "installs automatically" notice, the per-module and site-wide auto-update switches, a manual update,
// a failed update with its notice, error report, dashboard banner, "Try again" and "Skip this version", and the update log.
// The pipeline cases themselves (rollback of files and data, hangs, stuck updates) are in bin/ci-module-updates.
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const REG = (process.env.MOCK_REGISTRY || 'http://127.0.0.1:8199').replace(/\/$/, '');

test.setTimeout(240_000);

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

test('module updates: automatic notice, switches, failure report, try again, skip, log', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  const probe = await page.request.get(`${REG}/scenario?slug=upd-ui`).catch(() => null);
  test.skip(!probe || !probe.ok(), `The fake registry is not running at ${REG} (php -S 127.0.0.1:8199 bin/mock-registry).`);
  const scenario = async (s) => expect((await page.request.get(`${REG}/scenario?slug=upd-ui&set=${s}`)).ok()).toBeTruthy();
  const refresh = async () => { await page.goto(`${BASE}/admin/modules/available?refresh=1`); };
  const card = () => page.locator('#module-upd-ui');
  const toast = () => page.locator('.toast').first();

  await login(page);

  // ---- Registry under Sources.
  await page.goto(`${BASE}/admin/modules/sources`);
  const reg = page.locator('textarea[name=registries]');
  const current = await reg.inputValue();
  if (!current.includes(`${REG}/registry.json`)) {
    await reg.fill(`${current.trim()}\n${REG}/registry.json`.trim());
    await page.locator('form', { has: reg }).locator('button.btn-primary').click();
    await expect(toast()).toContainText('saved');
  }

  const autoForm = page.locator('[data-auto-settings]');
  if (!(await autoForm.locator('input[name=enabled]').isChecked())) {
    await autoForm.locator('input[name=enabled]').check();
    await autoForm.getByRole('button', { name: 'Save' }).click();
    await expect(toast()).toContainText('Automatic update settings saved');
  }

  // ---- A clean install of upd-ui 1.0.0 (remove a copy left by an earlier run).
  await scenario('v1');
  if ((await page.request.get(`${BASE}/admin/modules/upd-ui/check`)).status() === 200) {
    await page.goto(`${BASE}/admin/modules/upd-ui/check`);
    const deactivate = page.getByRole('button', { name: 'Deactivate', exact: true });
    if (await deactivate.count()) {
      await deactivate.click();
      await expect(page.getByRole('button', { name: 'Activate', exact: true })).toBeVisible();
    }
    await page.getByRole('button', { name: 'Delete module' }).click();
    await expect(toast()).toContainText('deleted');
  }
  await refresh();
  await page.locator('article', { hasText: 'Update UI demo' }).getByRole('button', { name: 'Install' }).click();
  await expect(page).toHaveURL(/modules\/upd-ui\/check/);
  await page.getByRole('button', { name: 'Activate', exact: true }).click();
  await expect(toast()).toContainText('activated');

  // ---- 2.0.0 is out: it will install automatically, so no Update button.
  await scenario('good');
  await refresh();
  await page.goto(`${BASE}/admin/modules`);
  await expect(card().locator('[data-update-pending]')).toContainText('Update to 2.0.0 available, it will install automatically');
  await expect(card().locator('[data-update-pending]')).toContainText('twice a day');
  await expect(card().getByRole('button', { name: 'Update', exact: true })).toHaveCount(0);
  await expect(card().locator('[data-auto-toggle]')).toHaveAttribute('aria-checked', 'true');

  // ---- Switch this module to manual updates: the Update button appears and runs the safe pipeline.
  await card().locator('[data-auto-toggle]').click();
  await expect(toast()).toContainText('no longer update automatically');
  await expect(card().locator('[data-auto-toggle]')).toHaveAttribute('aria-checked', 'false');
  await card().locator('[data-update-manual]').getByRole('button', { name: 'Update', exact: true }).click();
  await expect(toast()).toContainText('updated to 2.0.0');
  await expect(card()).toContainText('v2.0.0');
  await expect(card().locator('[data-update-done]')).toContainText('Updated to 2.0.0');
  await expect(card().locator('[data-update-done] a', { hasText: 'Update log' })).toBeVisible();

  // ---- 3.0.1 fails while loading: undone, notice with the report on the card.
  await scenario('fatal');
  await refresh();
  await page.goto(`${BASE}/admin/modules`);
  await card().locator('[data-update-manual]').getByRole('button', { name: 'Update', exact: true }).click();
  await expect(toast()).toContainText('failed');
  const failed = card().locator('[data-update-failed]');
  await expect(failed).toContainText('Updating to 3.0.1 failed and was undone.');
  await expect(failed).toContainText('Your site is unchanged.');
  await expect(card()).toContainText('v2.0.0');
  await failed.locator('summary', { hasText: 'Error report' }).click();
  await expect(failed.locator('[data-update-report]')).toContainText('Call to undefined function');
  await expect(failed.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(failed.getByRole('button', { name: 'Skip this version' })).toBeVisible();
  expect((await page.request.get(`${BASE}/`)).status()).toBe(200);
  expect((await page.request.get(`${BASE}/admin/m/upd-ui`)).status()).toBe(200);

  // ---- Dashboard banner: Try again (fails again, still undone), then Skip this version.
  await page.goto(`${BASE}/admin/`);
  const banner = page.locator('[data-module-update-failed="upd-ui"]');
  await expect(banner).toContainText('Updating Update UI demo to 3.0.1 failed and was undone. Your site is unchanged.');
  await banner.locator('summary', { hasText: 'Error report' }).click();
  await expect(banner).toContainText('Call to undefined function');
  await banner.getByRole('button', { name: 'Try again' }).click();
  await expect(page).toHaveURL(/\/admin\/?$/);
  await expect(toast()).toContainText('failed');
  await expect(banner).toBeVisible();
  await banner.getByRole('button', { name: 'Skip this version' }).click();
  await expect(toast()).toContainText('will not be installed');
  await expect(page.locator('[data-module-update-failed="upd-ui"]')).toHaveCount(0);
  await page.goto(`${BASE}/admin/modules`);
  await expect(card().locator('[data-update-failed]')).toHaveCount(0);
  await expect(card().locator('[data-update-manual]')).toContainText('you skipped it');

  // ---- Site-wide and per-module switches under Sources.
  await page.goto(`${BASE}/admin/modules/sources`);
  const auto = page.locator('[data-auto-settings]');
  await expect(auto).toContainText('twice a day (every 12 hours)');
  await auto.locator('input[name="auto[]"][value="upd-ui"]').check();
  await auto.locator('input[name=enabled]').uncheck();
  await auto.getByRole('button', { name: 'Save' }).click();
  await expect(toast()).toContainText('Automatic update settings saved');
  await page.goto(`${BASE}/admin/modules`);
  await expect(card().locator('[data-auto-toggle]')).toHaveCount(0);
  await page.goto(`${BASE}/admin/modules/sources`);
  await page.locator('[data-auto-settings] input[name=enabled]').check();
  await page.locator('[data-auto-settings]').getByRole('button', { name: 'Save' }).click();
  await page.goto(`${BASE}/admin/modules`);
  await expect(card().locator('[data-auto-toggle]')).toHaveAttribute('aria-checked', 'true');

  // ---- A newer good version after the skipped one: pending automatically again; install it by hand from Available.
  await scenario('good3');
  await refresh();
  await page.goto(`${BASE}/admin/modules`);
  await expect(card().locator('[data-update-pending]')).toContainText('3.1.0');
  await page.goto(`${BASE}/admin/modules/available`);
  await page.locator('article', { hasText: 'Update UI demo' }).getByRole('button', { name: /Update to 3\.1\.0/ }).click();
  await expect(toast()).toContainText('updated to 3.1.0');

  // ---- Update log: list and one report.
  await page.goto(`${BASE}/admin/modules/log`);
  await expect(page.locator('main')).toContainText('Update UI demo');
  await page.locator('main a', { hasText: '2.0.0 to 3.0.1' }).first().click();
  const log = page.locator('[data-update-log]');
  await expect(log).toContainText('Failed at “load”');
  await expect(log).toContainText('Rollback');
  await expect(log).toContainText('Files restored');

  expect(errors).toEqual([]);
});
