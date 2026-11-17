// End-to-end: Content model screens. Post type and taxonomy editors, the field group builder
// (palette, repeater, reorder, save), fields in the post editor, stable field names, clean up.
// Needs a running Slate site: SLATE_URL, SLATE_USER, SLATE_PASS.
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';

test.setTimeout(120_000);

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

const fields = (page) => page.locator('[data-fields] > .fb-field');
const openPanel = (page) => page.locator('.fb-field.is-open .fb-panel');
const savedGroup = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-fb-config]').textContent).group);

test('content model: post type, taxonomy, field group, editor, stable names', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await login(page);

  // ---- post type: names, key and address are filled in from the singular name ----
  await page.goto(`${BASE}/admin/model/types/new`);
  await page.fill('[data-cm-singular]', 'E2e leader');
  await expect(page.locator('[data-cm-plural]')).toHaveValue('E2e leaders');
  await expect(page.locator('[data-cm-rewrite]')).toHaveValue('e2e-leaders');
  await expect(page.locator('[data-cm-key]')).toHaveValue('e2e_leader');
  await expect(page.locator('[data-cm-title]')).toHaveText('E2e leaders');
  await expect(page.locator('[data-cm-urls]')).toContainText('/e2e-leaders/');
  await page.click('[data-cm-icon-toggle]');
  await page.fill('[data-cm-icon-search]', 'star');
  await page.locator('.cm-icon-grid label[data-name="star"]').click();
  await expect(page.locator('[data-cm-icon-name]')).toHaveText('star');
  await page.click('.cm-save-bar button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/types$/);
  await expect(page.locator('.cm-row', { hasText: 'E2e leaders' })).toBeVisible();

  // ---- taxonomy attached to it ----
  await page.goto(`${BASE}/admin/model/taxonomies/new`);
  await page.fill('[data-cm-singular]', 'E2e region');
  await expect(page.locator('[data-cm-plural]')).toHaveValue('E2e regions');
  await page.locator('.cm-feature', { has: page.locator('input[value="e2e_leader"]') }).click();
  await expect(page.locator('[data-cm-tax-mock]')).toContainText('E2e leaders');
  await page.click('.cm-save-bar button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/taxonomies$/);
  await page.goto(`${BASE}/admin/model/types`);
  await expect(page.locator('.cm-row', { hasText: 'E2e leaders' }).locator('.cm-chip')).toContainText(['E2e regions']);

  // ---- field group builder ----
  await page.goto(`${BASE}/admin/model/fields/new`);
  await page.fill('[data-g="title"]', 'E2E profile');
  await page.locator('.cm-toggle-chip', { has: page.locator('input[value="e2e_leader"]') }).click();
  await expect(page.locator('[data-fb-where]')).toContainText('E2e leaders');

  await page.click('[data-add-type="text"]');
  await openPanel(page).locator('[data-k="label"]').fill('Role');
  await expect(openPanel(page).locator('[data-k="name"]')).toHaveValue('role');

  await page.click('[data-add-type="number"]');
  await openPanel(page).locator('[data-k="label"]').fill('Years in office');
  await openPanel(page).locator('[data-k="required"]').check();

  await page.click('[data-add-type="select"]');
  await openPanel(page).locator('[data-k="label"]').fill('Party');
  await openPanel(page).locator('[data-k="options"]').fill('Green\nred : Red');

  await page.click('[data-add-type="repeater"]');
  await openPanel(page).locator('[data-k="label"]').fill('Milestones');
  await openPanel(page).locator('[data-act="sub-add"]').click();
  await openPanel(page).locator('[data-sk="label"]').last().fill('Year');
  await openPanel(page).locator('[data-sub-type]').selectOption('image');
  await openPanel(page).locator('[data-act="sub-add"]').click();
  await openPanel(page).locator('[data-sk="label"]').last().fill('Photo');
  await expect.poll(() => openPanel(page).locator('[data-sk="name"]').evaluateAll((els) => els.map((x) => x.value))).toEqual(['year', 'photo']);

  // Duplicate, then delete the copy.
  await fields(page).nth(0).locator('[data-act="dup"]').click();
  await expect(fields(page)).toHaveCount(5);
  await expect(fields(page).nth(1)).toContainText('role_copy');
  await fields(page).nth(1).locator('[data-act="del"]').click();
  await expect(fields(page)).toHaveCount(4);

  // Reorder: buttons, then drag "Milestones" to the top.
  await fields(page).nth(2).locator('[data-act="up"]').click();
  await expect(fields(page).nth(1)).toContainText('Party');
  await fields(page).nth(3).locator('.fb-row').dragTo(fields(page).nth(0).locator('.fb-row'), { targetPosition: { x: 60, y: 4 } });
  await expect(fields(page).nth(0)).toContainText('Milestones');

  // The preview mirrors the box in the editor.
  await expect(page.locator('[data-fb-preview]')).toContainText('Years in office');

  await page.click('.cm-save-bar button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/fields\/g[a-f0-9]+$/);
  const groupUrl = page.url();
  let g = await savedGroup(page);
  expect(g.fields.map((f) => f.name)).toEqual(['milestones', 'role', 'party', 'years_in_office']);
  expect(g.fields[0].sub_fields.map((f) => [f.name, f.type])).toEqual([['year', 'text'], ['photo', 'image']]);
  expect(g.fields[2].options).toEqual(['Green', 'red : Red']);
  expect(g.fields[3].required).toBe(true);
  expect(g.location.post_types).toEqual(['e2e_leader']);

  // ---- the post editor shows the fields ----
  await page.goto(`${BASE}/admin/content/e2e_leader/new`);
  const box = page.locator('section.card', { hasText: 'E2E profile' });
  await expect(box).toBeVisible();
  await expect(box.locator('label[for="f_fields_role_"]')).toHaveText('Role');
  await expect(box.locator('[data-repeater]')).toBeVisible();
  await expect(box.locator('select[name="fields[party]"] option')).toHaveText(['Select…', 'Green', 'Red']);
  await expect(page.getByText('E2e regions').first()).toBeVisible();

  // ---- renaming a saved field keeps its name ----
  await page.goto(groupUrl);
  await fields(page).filter({ hasText: 'Role' }).locator('[data-toggle]').click();
  await expect(openPanel(page).locator('.fb-name-locked code')).toHaveText('role');
  await openPanel(page).locator('[data-k="label"]').fill('Job title');
  await expect(fields(page).nth(1).locator('.fb-title strong')).toHaveText('Job title');
  await page.click('.cm-save-bar button[type=submit]');
  await expect(page).toHaveURL(groupUrl);
  g = await savedGroup(page);
  expect(g.fields[1]).toMatchObject({ label: 'Job title', name: 'role' });

  // ---- clean up ----
  await page.click('.cm-danger button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/fields$/);
  await expect(page.locator('.cm-row', { hasText: 'E2E profile' })).toHaveCount(0);
  await page.goto(`${BASE}/admin/model/taxonomies/e2e_region`);
  await page.click('.cm-danger button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/taxonomies$/);
  await page.goto(`${BASE}/admin/model/types/e2e_leader`);
  await page.click('.cm-danger button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/model\/types$/);
  await expect(page.locator('.cm-row', { hasText: 'E2e leaders' })).toHaveCount(0);
  expect(errors).toEqual([]);
});
