// End-to-end: the Forms module. Build a form (several field types and a condition), insert it into a page from
// the editor, submit it on the site (with validation errors first), then find the entry in the inbox and export CSV.
// Needs a running Slate site with the slate-forms module active: SLATE_URL, SLATE_USER, SLATE_PASS.
// Skipped when the module is not installed (the CI site from bin/ci-install has no modules).
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

test('forms: build, insert in a page, submit with validation, inbox and CSV', async ({ page, browser }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  const probe = await page.request.get(`${BASE}/admin/m/forms`);
  test.skip(probe.status() === 404, 'The Forms module (slate-forms) is not active on this site.');

  const stamp = Date.now().toString(36);
  const formName = `E2E form ${stamp}`;
  const person = `Ada ${stamp}`;

  // ---- 1. New form from the blank template, then build it.
  await page.goto(`${BASE}/admin/m/form-edit?new=1`);
  await page.fill('.sf-new input[name=title]', formName);
  await page.locator('.sf-tpl', { hasText: 'Blank form' }).click();
  await page.click('.sf-new button.btn-primary');
  await expect(page.locator('[data-sfb] .sfb-canvas')).toBeVisible();
  await expect(page.locator('.sfb-empty')).toBeVisible();

  const add = async (type, label) => {
    await page.click(`.sfb-palette [data-add="${type}"]`);
    await page.locator('[data-inspector] [data-k="label"]').fill(label);
  };
  await add('text', 'Full name');
  await page.locator('[data-inspector] [data-k="required"]').check();
  await add('email', 'Email');
  await page.locator('[data-inspector] [data-k="required"]').check();
  await add('radio', 'Contact me by');
  const choices = page.locator('[data-inspector] .sfb-choice [data-c="l"]');
  await choices.nth(0).fill('Email');
  await choices.nth(1).fill('Phone');
  await page.locator('[data-inspector] [data-c-del="2"]').click();
  await expect(choices).toHaveCount(2);
  await add('tel', 'Phone number');
  await page.locator('[data-inspector] [data-k="required"]').check();
  // Show "Phone number" only when "Contact me by" is "Phone".
  await page.locator('[data-inspector] .sfb-logic > summary').click();
  await page.locator('[data-inspector] [data-cond-on]').check();
  await page.locator('[data-inspector] [data-cond="field"]').selectOption('contact_me_by');
  await page.locator('[data-inspector] [data-cond="value"]').selectOption('Phone');
  await expect(page.locator('.sfb-item.is-sel .sfb-cond')).toContainText('Contact me by');
  await add('textarea', 'Message');
  await expect(page.locator('.sfb-canvas .sfb-item')).toHaveCount(5);

  // Drag a heading from the palette to the top, then a field by its handle; remove the heading again.
  const items = page.locator('.sfb-canvas .sfb-item');
  await page.locator('.sfb-palette [data-add="heading"]').dragTo(items.nth(0), { targetPosition: { x: 20, y: 5 } });
  await expect(items).toHaveCount(6);
  await expect(items.nth(0)).toHaveClass(/sfb-t-heading/);
  await items.nth(2).locator('.sfb-grip').dragTo(items.nth(0), { targetPosition: { x: 20, y: 5 } });
  await expect(items.nth(0)).toContainText('Email');
  await items.nth(1).locator('[data-act="del"]').click();
  await expect(items).toHaveCount(5);
  await items.nth(0).click();
  await page.locator('[data-inspector] [data-move="1"]').click();
  await expect(items.nth(0)).toContainText('Full name');
  await expect(items.nth(1)).toContainText('Email');

  // The same with the move buttons (touch and keyboard friendly).
  await items.nth(4).click();
  await page.locator('[data-inspector] [data-move="-1"]').click();
  await expect(page.locator('.sfb-canvas .sfb-item').nth(3)).toContainText('Message');
  await page.locator('[data-inspector] [data-move="1"]').click();
  await expect(page.locator('.sfb-canvas .sfb-item').nth(4)).toContainText('Message');

  await page.click('[data-save]');
  await expect(page.locator('[data-save-state]')).toContainText('Saved');

  // ---- 2. Insert it into a new page from the content editor.
  await page.goto(`${BASE}/admin/content/page/new`);
  await page.fill('[data-title]', `Forms test page ${stamp}`);
  await page.click('[data-cmd="form"]');
  await page.fill('[data-fp-q]', stamp);
  await expect(page.locator('.ed-fp-item')).toHaveCount(1);
  await page.locator('.ed-fp-item').click();
  await expect(page.locator('.ed-fp-preview iframe')).toBeVisible();
  await page.click('[data-fp-insert]');
  await expect(page.locator('.ed-area [data-slate-form-widget]')).toContainText(formName);
  await page.click('[data-save="publish"]');
  await expect(page.locator('[data-save-state]')).toContainText('Saved');
  await expect(page).toHaveURL(/\/content\/page\/\d+/);
  await page.reload();
  const permalink = await page.locator('[data-view-link]').getAttribute('href');

  // ---- 3. Submit on the site as a visitor.
  const ctx = await browser.newContext();
  const site = await ctx.newPage();
  site.on('pageerror', (e) => errors.push(e.message));
  await site.goto(permalink.startsWith('http') ? permalink : BASE + permalink);
  const form = site.locator('form[data-slate-form]');
  await expect(form).toBeVisible();
  const field = (key) => form.locator(`.slate-form__field[data-sf-field="${key}"]`);
  await expect(field('phone_number')).toBeHidden();

  // Empty submit: inline messages, nothing sent.
  await form.locator('.slate-form__submit').click();
  await expect(field('full_name').locator('.slate-form__error-msg')).toHaveText('This field is required.');
  await expect(field('email').locator('.slate-form__error-msg')).toHaveText('This field is required.');
  await field('full_name').locator('input').fill(person);
  await field('email').locator('input').fill('not-an-email');
  await field('email').locator('input').blur();
  await expect(field('email').locator('.slate-form__error-msg')).toHaveText('Enter a valid email address.');
  await field('email').locator('input').fill('ada@example.com');

  // The condition: choosing "Phone" shows a required phone field.
  await field('contact_me_by').locator('input[value="Phone"]').check();
  await expect(field('phone_number')).toBeVisible();
  await form.locator('.slate-form__submit').click();
  await expect(field('phone_number').locator('.slate-form__error-msg')).toHaveText('This field is required.');
  await field('phone_number').locator('input').fill('+44 20 7946 0000');
  await field('message').locator('textarea').fill('Hello from the e2e test.');

  // The server validates too (JavaScript can be off or bypassed).
  const formId = await form.getAttribute('data-slate-form');
  const direct = await ctx.request.post(`${BASE}/_slate/form`, { headers: { Accept: 'application/json' }, form: { _form: formId, _js: '1', 'fields[full_name]': '', 'fields[email]': 'nope' } });
  expect(direct.status()).toBe(422);
  const body = await direct.json();
  expect(body.errors.full_name).toBe('This field is required.');
  expect(body.errors.email).toBe('Enter a valid email address.');
  expect(body.errors.phone_number).toBeUndefined(); // hidden by its condition, so not required

  await site.waitForTimeout(3200); // the spam time trap wants a few seconds on the page
  await form.locator('.slate-form__submit').click();
  await expect(form.locator('.slate-form__success')).toBeVisible();
  await ctx.close();

  // ---- 4. The entry in the inbox, its detail view and the CSV export.
  await page.goto(`${BASE}/admin/m/submissions?q=${encodeURIComponent(stamp)}`);
  const row = page.locator('.lt-table tbody tr', { hasText: person });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText(formName);
  await row.locator('.row-title').click();
  await expect(page.locator('.sub-detail')).toContainText('+44 20 7946 0000');
  await expect(page.locator('.sub-detail')).toContainText('Phone');

  const csv = await page.request.get(`${BASE}/admin/m/submissions?export=csv&q=${encodeURIComponent(stamp)}`);
  expect(csv.status()).toBe(200);
  expect(csv.headers()['content-type']).toContain('text/csv');
  const text = await csv.text();
  expect(text).toContain('Phone number');
  expect(text).toContain(person);

  // Per-form counts on the forms list.
  await page.goto(`${BASE}/admin/m/forms?q=${encodeURIComponent(stamp)}`);
  await expect(page.locator('.lt-table tbody tr', { hasText: formName })).toContainText('1');

  expect(errors).toEqual([]);
});
