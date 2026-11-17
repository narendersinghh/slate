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

// ---------------------------------------------------------------------------------------------------------------
// Actions (webhook with chosen fields, header and signature; a Slack message with a condition) and the simple
// question spam check. The webhook receiver is bin/mock-forms-hook:
//   php -S 127.0.0.1:8193 bin/mock-forms-hook
// FORMS_HOOK is the address the site posts to, FORMS_HOOK_READ the one this test reads from (they differ when the
// site runs in a container). The site must allow private addresses (php bin/ci-import, as in CI).
const HOOK = process.env.FORMS_HOOK || 'http://127.0.0.1:8193';
const HOOK_READ = process.env.FORMS_HOOK_READ || HOOK;

async function newForm(page, name, template = 'contact') {
  await page.goto(`${BASE}/admin/m/form-edit?new=1`);
  await page.fill('.sf-new input[name=title]', name);
  await page.locator(`.sf-tpl:has(input[value="${template}"])`).click();
  await page.click('.sf-new button.btn-primary');
  await expect(page.locator('[data-sfb] .sfb-canvas')).toBeVisible();
  return Number(new URL(page.url()).searchParams.get('id'));
}

async function saveForm(page) {
  await page.click('[data-save]');
  await expect(page.locator('[data-save-state]')).toContainText('Saved');
}

test('forms: webhook and chat actions, simple question spam check', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  const probe = await page.request.get(`${BASE}/admin/m/forms`);
  test.skip(probe.status() === 404, 'The Forms module (slate-forms) is not active on this site.');
  const receiver = await page.request.get(`${HOOK_READ}/log`).catch(() => null);
  test.skip(!receiver || !receiver.ok(), `No webhook receiver at ${HOOK_READ} (php -S 127.0.0.1:8193 bin/mock-forms-hook).`);
  await page.request.post(`${HOOK_READ}/reset`);

  const stamp = Date.now().toString(36);
  const id = await newForm(page, `Actions ${stamp}`);
  await page.click('[data-tab="actions"]');

  // Webhook: JSON, two chosen fields under own keys, a header and a signing secret.
  await page.click('[data-aadd="webhook"]');
  const hook = page.locator('.sfb-action').nth(0);
  await hook.locator('[data-ak="url"]').fill(`${HOOK}/hook`);
  await hook.locator('label:has([data-ak="fields"][value="chosen"])').click();
  await hook.locator('[data-map="name"]').check();
  await hook.locator('[data-mapkey="name"]').fill('full_name');
  await hook.locator('[data-map="email"]').check();
  await hook.locator('details.sfb-adv > summary').click();
  await hook.locator('[data-hadd]').click();
  await hook.locator('[data-hn="0"]').fill('X-Test');
  await hook.locator('[data-hv="0"]').fill('abc');
  await hook.locator('[data-ak="secret"]').fill('shh-secret');

  // Slack message, only when the subject is "Sales".
  await page.click('[data-aadd="slack"]');
  const slack = page.locator('.sfb-action').nth(1);
  await slack.locator('[data-ak="url"]').fill(`${HOOK}/slack`);
  await slack.locator('[data-ak="cond_on"]').check();
  await slack.locator('[data-ac="field"]').selectOption('subject');
  await slack.locator('[data-ac="value"]').selectOption('Sales');

  // Spam check: a question of our own.
  await page.locator('[data-s="captcha"]').selectOption('question');
  await page.locator('[data-s="captcha_question"]').fill('What colour is the sky?');
  await page.locator('[data-s="captcha_answer"]').fill('blue, light blue');
  await saveForm(page);

  // Secrets never come back to the browser.
  await expect(page.locator('.sfb-action').nth(0).locator('[data-ak="secret"]')).toHaveValue('');
  await expect(page.locator('.sfb-action').nth(0).locator('[data-ak="secret"]')).toHaveAttribute('placeholder', /Saved/);

  // "Send test" delivers right away.
  await page.locator('.sfb-action').nth(0).locator('[data-atest]').click();
  await expect(page.locator('[data-deliveries] .pill-success').first()).toBeVisible();
  let log = await (await page.request.get(`${HOOK_READ}/log`)).json();
  expect(log.at(-1).headers['x-slate-event']).toBe('form.test');
  await page.request.post(`${HOOK_READ}/reset`);

  // A visitor sends the form: wrong answer first.
  const ctx = await page.context().browser().newContext();
  const visitor = await ctx.newPage();
  visitor.on('pageerror', (e) => errors.push(e.message));
  // The dashboard preview renders the real form; a visitor reaches it through a page. Use a published page.
  await page.goto(`${BASE}/admin/content/page/new`);
  await page.fill('[data-title]', `Actions page ${stamp}`);
  await page.click('[data-cmd="form"]');
  await page.fill('[data-fp-q]', stamp);
  await page.locator('.ed-fp-item').first().click();
  await page.click('[data-fp-insert]');
  await page.click('[data-save="publish"]');
  await expect(page.locator('[data-save-state]')).toContainText('Saved');
  await page.reload();
  const href = await page.locator('[data-view-link]').getAttribute('href');
  await visitor.goto(href.startsWith('http') ? href : BASE + href);
  const form = visitor.locator('form[data-slate-form]');
  await form.locator('[name="fields[name]"]').fill(`Grace ${stamp}`);
  await form.locator('[name="fields[email]"]').fill('grace@example.com');
  await form.locator('[name="fields[subject]"]').selectOption('Sales');
  await form.locator('[name="fields[message]"]').fill('Please call me.');
  await expect(form.locator('label[for$="-captcha"]')).toHaveText(/What colour is the sky\?/);
  await form.locator('[name="_captcha"]').fill('green');
  await visitor.waitForTimeout(3200); // time trap
  await form.locator('.slate-form__submit').click();
  await expect(form.locator('[data-sf-field="_captcha"] .slate-form__error-msg, p[data-sf-field="_captcha"]').first()).toHaveText('That answer is not right. Please try again.');
  await expect(form.locator('.slate-form__success')).toBeHidden();
  await form.locator('[name="_captcha"]').fill('Blue');
  await form.locator('.slate-form__submit').click();
  await expect(form.locator('.slate-form__success')).toBeVisible();
  await ctx.close();

  // Both actions arrive (sent right after the response).
  await expect.poll(async () => (await (await page.request.get(`${HOOK_READ}/log`)).json()).length, { timeout: 15_000 }).toBe(2);
  log = await (await page.request.get(`${HOOK_READ}/log`)).json();
  const wh = log.find((r) => r.path === '/hook');
  const body = JSON.parse(wh.body);
  expect(body.event).toBe('form.submitted');
  expect(body.form.id).toBe(id);
  expect(body.fields).toEqual({ full_name: `Grace ${stamp}`, email: 'grace@example.com' });
  expect(wh.headers['x-test']).toBe('abc');
  const { createHmac } = await import('node:crypto');
  const expected = 'sha256=' + createHmac('sha256', 'shh-secret').update(wh.headers['x-slate-timestamp'] + '.' + wh.body).digest('hex');
  expect(wh.headers['x-slate-signature']).toBe(expected);
  const sl = log.find((r) => r.path === '/slack');
  expect(JSON.parse(sl.body).text).toContain(`*New entry: Actions ${stamp}*`);

  // The delivery log in the builder.
  await page.goto(`${BASE}/admin/m/form-edit?id=${id}`);
  await page.click('[data-tab="actions"]');
  await expect(page.locator('[data-deliveries] .pill-success')).toHaveCount(3);

  expect(errors).toEqual([]);
});

// Cloudflare Turnstile with Cloudflare's published test keys (needs internet on the site and in the browser).
// Off unless FORMS_TURNSTILE=1.
test('forms: Cloudflare Turnstile test keys pass and fail', async ({ page }) => {
  test.skip(process.env.FORMS_TURNSTILE !== '1', 'Set FORMS_TURNSTILE=1 to run against Cloudflare (internet needed).');
  await login(page);
  const probe = await page.request.get(`${BASE}/admin/m/forms`);
  test.skip(probe.status() === 404, 'The Forms module (slate-forms) is not active on this site.');
  const setKeys = async (secret) => {
    await page.goto(`${BASE}/admin/settings/modules/slate-forms`);
    await page.fill('[name="s[forms_turnstile_site]"]', '1x00000000000000000000AA');
    await page.fill('[name="s[forms_turnstile_secret]"]', secret);
    await page.locator('form:has([name="s[forms_turnstile_site]"]) button[type=submit]').last().click();
    await expect(page.locator('.toast, .alert-success').first()).toBeVisible();
  };
  await setKeys('1x0000000000000000000000000000000AA'); // always passes
  const stamp = Date.now().toString(36);
  const id = await newForm(page, `Turnstile ${stamp}`, 'newsletter');
  await page.click('[data-tab="actions"]');
  await page.locator('[data-s="captcha"]').selectOption('turnstile');
  await saveForm(page);

  const send = async () => {
    await page.goto(`${BASE}/admin/m/forms/preview?id=${id}`);
    const form = page.locator('form[data-slate-form]');
    await form.locator('[name="fields[email]"]').fill('t@example.com');
    await form.locator('[name="fields[consent]"]').check();
    await expect(form.locator('[name="cf-turnstile-response"]')).not.toHaveValue('', { timeout: 20_000 });
    await page.waitForTimeout(3200);
    await form.locator('.slate-form__submit').click();
    return form;
  };
  let form = await send();
  await expect(form.locator('.slate-form__success')).toBeVisible({ timeout: 15_000 });

  await setKeys('2x0000000000000000000000000000000AA'); // always fails
  form = await send();
  await expect(form.locator('.slate-form__error-msg[data-sf-field="_captcha"], [data-sf-field="_captcha"] .slate-form__error-msg').first()).toHaveText('The spam check failed. Please try again.', { timeout: 15_000 });

  // Leave the site without Turnstile keys.
  await page.goto(`${BASE}/admin/settings/modules/slate-forms`);
  await page.fill('[name="s[forms_turnstile_site]"]', '');
  await page.locator('form:has([name="s[forms_turnstile_site]"]) button[type=submit]').last().click();
});
