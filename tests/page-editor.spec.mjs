// End-to-end: the on-page editor for imported / AI-written pages (core/Slate/PageEditor.php, admin/assets/page-editor.js).
// Needs a running Slate site (SLATE_URL, SLATE_USER, SLATE_PASS) and a way to create the test page:
//   SLATE_PE_SETUP  command that runs bin/ci-page-editor on the site and prints its JSON
//                   (default: "php bin/ci-page-editor"; with Docker: "docker exec <container> php bin/ci-page-editor")
// AI checks run when the site's AI points at bin/mock-ai (php bin/ci-ai http://127.0.0.1:8097/v1); otherwise they are skipped.
import { test, expect, request as pwRequest } from '@playwright/test';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const SHOTS = process.env.SLATE_SHOTS || 'test-results/screens';
const SETUP = process.env.SLATE_PE_SETUP || 'php bin/ci-page-editor';

test.describe.configure({ mode: 'serial' });
test.setTimeout(120_000);

let PAGE; // { id, url, body }
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABgAAAAQCAIAAACDRijCAAAAJklEQVR4nGP8z0AdwEQlc0YNGjVo1KBRg0YNGjVo1KBRg0YNAgB0VgEf9q3JIQAAAABJRU5ErkJggg==', 'base64');

async function login(page, user = USER, pass = PASS) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', user);
  await page.fill('input[name=password]', pass);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

// The page as a signed-out visitor gets it (separate cookie jar).
async function visitorHtml() {
  const ctx = await pwRequest.newContext();
  const res = await ctx.get(`${BASE}${PAGE.url}`);
  expect(res.status()).toBe(200);
  const html = await res.text();
  await ctx.dispose();
  return html;
}

async function openEditor(page) {
  await page.goto(`${BASE}${PAGE.url}`);
  await page.click('#se-toggle');
  await expect(page.locator('.se-bar')).toBeVisible();
}

async function saveAndWait(page) {
  const resp = page.waitForResponse((r) => r.url().includes('/page-editor/') && r.url().endsWith('/save'));
  await page.keyboard.press('Control+s');
  const r = await resp;
  const j = await r.json();
  expect(j.ok, j.error).toBe(true);
  return j;
}

test.beforeAll(() => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const out = execSync(SETUP, { encoding: 'utf8' }).trim().split('\n').pop();
  PAGE = JSON.parse(out);
});

test('text: edit, bold, tag, colour, size, reset style; save, reload, visitor HTML, revisions', async ({ page, browser }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await login(page);
  await page.goto(`${BASE}${PAGE.url}`);
  // The page's own design is intact and the editor is offered.
  await expect(page.locator('body.pe-test')).toBeVisible();
  await expect(page.locator('#slate-bar #se-toggle')).toBeVisible();
  await page.click('#se-toggle');
  await expect(page.locator('#se-toggle')).toHaveAttribute('aria-pressed', 'true');

  // Heading: type, bold a word, change to H2, colour.
  const h1 = page.locator('.hero h1');
  await h1.click();
  await expect(page.locator('.se-toolbar')).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/pe-toolbar.png` });
  await page.evaluate(() => { const t = document.querySelector('.hero h1').firstChild; const r = document.createRange(); r.setStart(t, 0); r.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(r); });
  await page.keyboard.type('Hi! ');
  await page.evaluate(() => {
    const t = document.querySelector('.hero h1').firstChild;
    const r = document.createRange(); r.setStart(t, 0); r.setEnd(t, 3);
    const s = getSelection(); s.removeAllRanges(); s.addRange(r);
  });
  await page.click('.se-toolbar [data-cmd="italic"]'); // headings are bold already: Bold would turn it off
  await page.selectOption('#se-tag', 'h2');
  await expect(page.locator('.hero h2')).toHaveCount(1);
  await page.locator('[data-color]').fill('#cc0000');
  await expect(page.locator('.se-count')).toHaveText(/unsaved change/);

  // Lead paragraph: size, then reset style removes it again; the button gets a size that stays.
  await page.click('.hero p.lead');
  await page.evaluate(() => {
    const t = document.querySelector('.hero p.lead').firstChild; const i = t.textContent.indexOf('friendly');
    const r = document.createRange(); r.setStart(t, i); r.setEnd(t, i + 8);
    const s = getSelection(); s.removeAllRanges(); s.addRange(r);
  });
  await page.click('.se-toolbar [data-cmd="bold"]');
  await page.click('.se-toolbar [data-pop="size"]');
  await page.click('.se-toolbar [data-size="32"]');
  expect(await page.evaluate(() => document.querySelector('.hero p.lead').style.fontSize)).toBe('32px');
  await page.click('.se-toolbar [data-act="reset"]');
  expect(await page.evaluate(() => document.querySelector('.hero p.lead').style.fontSize)).toBe('');
  await page.click('.hero a.button');
  await page.click('.se-toolbar [data-pop="size"]');
  await page.fill('#se-size', '22');
  await page.click('.se-toolbar [data-act="size-apply"]');
  await page.keyboard.press('Escape');
  await expect(page.locator('.se-toolbar')).toBeHidden();

  // Leaving with unsaved changes asks first.
  let dialog = '';
  page.once('dialog', async (d) => { dialog = d.type(); await d.dismiss(); });
  await page.evaluate(() => { location.href = '/'; }).catch(() => {});
  await page.waitForTimeout(500);
  expect(dialog).toBe('beforeunload');

  const res = await saveAndWait(page);
  expect(res.revisions).toBeGreaterThanOrEqual(1);
  await expect(page.locator('.se-count')).toHaveText('No changes');

  const html = await visitorHtml();
  expect(html).toContain('<h2 data-se="e3" style="color: #cc0000"><i>Hi!</i> Welcome to <em data-se="e4">Acme</em></h2>');
  expect(html).toContain('<p class="lead" data-se="e5">We build <b>friendly</b> websites for small teams.</p>');
  expect(html).toMatch(/<a class="button" href="\/contact" data-se="e6" style="font-size: 22px">Get in touch<\/a>/);
  expect(html).not.toContain('contenteditable');
  expect(html).not.toContain('se-config'); // visitors get no editor

  // A second save in the same session (no false "changed elsewhere" conflict).
  await page.click('.hero a.button');
  await page.click('.se-toolbar [data-pop="align"]');
  await page.click('.se-toolbar [data-align="right"]');
  await saveAndWait(page);
  expect(await visitorHtml()).toContain('style="font-size: 22px; text-align: right"');

  // Reload: the change is there for the editor too.
  await page.reload();
  await expect(page.locator('.hero h2 i')).toHaveText('Hi!');
  expect(errors).toEqual([]);
});

test('image: upload through the panel, alt, aspect ratio, fit, focus', async ({ page }) => {
  await login(page);
  await openEditor(page);
  await page.click('img.photo');
  await expect(page.locator('.se-panel')).toBeVisible();
  await expect(page.locator('#se-panel-title')).toHaveText('Image');
  const file = path.join(SHOTS, 'pe-upload.png');
  fs.writeFileSync(file, PNG);
  const up = page.waitForResponse((r) => r.url().includes('/api/media') && r.request().method() === 'POST');
  await page.setInputFiles('[data-upload]', file);
  expect((await up).status()).toBe(200);
  await expect(page.locator('#se-isize')).toBeVisible(); // library image: sizes offered
  await page.fill('#se-alt', 'A tiny test image');
  await page.click('[data-aspect="16/9"]');
  await page.click('[data-fit="contain"]');
  await page.click('[data-pos="left top"]');
  await page.screenshot({ path: `${SHOTS}/pe-image-panel.png` });
  await saveAndWait(page);
  const html = await visitorHtml();
  const img = html.match(/<img class="photo"[^>]*>/)[0];
  expect(img).toMatch(/src="\/content\/uploads\/[^"]+\.(png|webp)"/);
  expect(img).not.toContain('pe-photo.png');
  expect(img).not.toContain('srcset');
  expect(img).toContain('alt="A tiny test image"');
  expect(img).toContain('aspect-ratio: 16 / 9');
  expect(img).toContain('object-fit: contain');
  expect(img).toContain('object-position: left top');
  expect(img).toMatch(/data-media-id="\d+"/);
});

test('AI rewrite and alt suggestion (mock provider)', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}${PAGE.url}`);
  const cfg = await page.evaluate(() => JSON.parse(document.getElementById('se-config').textContent));
  test.skip(!cfg.ai, 'AI is not configured on this site');
  await page.click('#se-toggle');
  await page.click('.hero p.lead');
  await page.click('.se-toolbar [data-pop="ai"]');
  await page.screenshot({ path: `${SHOTS}/pe-ai-menu.png` });
  await page.click('.se-toolbar [data-ai="shorter"]');
  await expect(page.locator('.hero p.lead')).toContainText('sweet');
  const lead = await page.locator('.hero p.lead').innerHTML();
  expect(lead).not.toContain('\u2014');
  expect(lead).not.toContain('script');
  expect(lead).toContain('<strong>sweet</strong>');
  await page.keyboard.press('Escape');
  await page.click('img.photo');
  await page.click('[data-act="alt-ai"]');
  await expect(page.locator('#se-alt')).toHaveValue('A small orange test picture');
  await saveAndWait(page);
  const html = await visitorHtml();
  expect(html).toContain('<p class="lead" data-se="e5">Short, and <strong>sweet</strong></p>');
});

test('repeating group: duplicate, move, delete (slider keeps working after reload)', async ({ page }) => {
  await login(page);
  await openEditor(page);
  await page.click('.swiper-slide:nth-child(2) h4');
  await page.click('.se-toolbar [data-act="loop"]');
  await expect(page.locator('#se-panel-title')).toHaveText('Repeating group');
  await expect(page.locator('.se-items li')).toHaveCount(3);
  await expect(page.locator('.se-items li.se-cur')).toContainText('Slide B');
  await page.screenshot({ path: `${SHOTS}/pe-loop-panel.png` });

  // Duplicate "Slide B": saves and reloads straight back into edit mode.
  await Promise.all([page.waitForEvent('load'), page.click('[data-li="1"][data-la="duplicate"]')]);
  await expect(page.locator('.se-bar')).toBeVisible();
  await expect(page.locator('.swiper-slide h4')).toHaveText(['Slide A', 'Slide B', 'Slide B', 'Slide C']);
  expect(await page.evaluate(() => document.querySelector('.swiper-wrapper').dataset.slides)).toBe('4'); // page script ran on the new markup
  const ids = await page.locator('.swiper-slide h4').evaluateAll((els) => els.map((e) => e.getAttribute('data-se')));
  expect(new Set(ids).size).toBe(4); // fresh ids for the copy

  // Move "Slide A" down, then delete the copy.
  await page.click('.swiper-slide:nth-child(1) p');
  await page.click('.se-toolbar [data-act="loop"]');
  await Promise.all([page.waitForEvent('load'), page.click('[data-li="0"][data-la="down"]')]);
  await expect(page.locator('.swiper-slide h4')).toHaveText(['Slide B', 'Slide A', 'Slide B', 'Slide C']);
  await page.click('.swiper-slide:nth-child(3) p');
  await page.click('.se-toolbar [data-act="loop"]');
  page.once('dialog', (d) => d.accept());
  await Promise.all([page.waitForEvent('load'), page.click('[data-li="2"][data-la="remove"]')]);
  await expect(page.locator('.swiper-slide h4')).toHaveText(['Slide B', 'Slide A', 'Slide C']);
  const html = await visitorHtml();
  expect(html.match(/class="swiper-slide"/g).length).toBe(3);
});

test('make dynamic (posts) and static again', async ({ page }) => {
  await login(page);
  const before = (await visitorHtml()).match(/<section class="cards"[\s\S]*?<\/section>/)[0];
  await openEditor(page);
  await page.click('.cards article:nth-child(2) h3');
  await page.click('.se-toolbar [data-act="loop"]');
  await page.click('.se-dyn summary');
  await page.selectOption('#se-dsrc', 'posts:post');
  await page.fill('#se-dlim', '2');
  await page.selectOption('#se-dord', 'title:asc');
  // Auto-guessed mapping: heading -> title, image -> featured image, first link -> item link, paragraph -> excerpt.
  await expect(page.locator('.se-map select[data-key="e10"]')).toHaveValue('title');
  await expect(page.locator('.se-map select[data-key="e9"]')).toHaveValue('image');
  await expect(page.locator('.se-map select[data-key="link:0"]')).toHaveValue('url');
  await expect(page.locator('.se-map select[data-key="e11"]')).toHaveValue('excerpt');
  await page.screenshot({ path: `${SHOTS}/pe-dynamic-form.png` });
  await Promise.all([page.waitForEvent('load'), page.click('[data-act="dynamic"]')]);

  let html = await visitorHtml();
  let cards = html.match(/<section class="cards"[\s\S]*?<\/section>/)[0];
  expect(cards).not.toContain('{{');
  expect(cards).toContain('<h3 data-se="e10">Dyn post four</h3>');
  expect(cards).toContain('<h3 data-se="e10">Dyn post one</h3>');
  expect(cards).toContain('<p data-se="e11">Excerpt of Dyn post four</p>');
  expect(cards).toMatch(/<a href="\/[^"]*dyn-post-four\/"><img/);
  expect(cards.match(/<article/g).length).toBe(2 + 3); // 2 live items + the 3 originals kept in <template>
  expect(cards).toContain('<template data-se-static');
  // Live items render in the browser; originals stay inert.
  await expect(page.locator('.cards > article')).toHaveCount(2);
  await page.screenshot({ path: `${SHOTS}/pe-dynamic.png`, fullPage: true });

  // Dynamic items are not edited one by one: clicking selects the whole list.
  await page.click('.cards article:nth-child(1) h3');
  await expect(page.locator('#se-panel-title')).toHaveText('Dynamic list');
  await Promise.all([page.waitForEvent('load'), page.click('[data-act="static"]')]);
  html = await visitorHtml();
  cards = html.match(/<section class="cards"[\s\S]*?<\/section>/)[0];
  expect(cards).toBe(before);
});

test('mobile: panels are bottom sheets', async ({ browser }) => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  await login(page);
  await openEditor(page);
  await page.click('img.photo');
  const box = await page.locator('.se-panel').boundingBox();
  expect(Math.round(box.x)).toBe(0);
  expect(Math.round(box.width)).toBe(390);
  expect(Math.round(box.y + box.height)).toBe(844);
  await page.screenshot({ path: `${SHOTS}/pe-mobile-sheet.png` });
  await page.keyboard.press('Escape');
  await page.click('.hero p.lead');
  await expect(page.locator('.se-toolbar')).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/pe-mobile-toolbar.png` });
  await ctx.close();
});

test('server rejects bad operations; people without edit rights get no editor', async ({ page, browser }) => {
  await login(page);
  await page.goto(`${BASE}${PAGE.url}`);
  const results = await page.evaluate(async () => {
    const C = JSON.parse(document.getElementById('se-config').textContent);
    const send = (ops) => fetch(C.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': C.csrf }, body: JSON.stringify({ ops }) })
      .then(async (r) => ({ status: r.status, json: await r.json() }));
    return {
      badId: await send([{ op: 'text', id: 'e9999', html: 'x' }]),
      badProp: await send([{ op: 'style', id: 'e5', style: { position: 'fixed' } }]),
      badValue: await send([{ op: 'style', id: 'e5', style: { color: 'red;background:url(x)' } }]),
      badAttr: await send([{ op: 'attr', id: 'e6', name: 'onclick', value: 'alert(1)' }]),
      badUrl: await send([{ op: 'attr', id: 'e6', name: 'href', value: 'javascript:alert(1)' }]),
      badTag: await send([{ op: 'tag', id: 'e5', tag: 'script' }]),
      badLoop: await send([{ op: 'loop', id: 'l2', action: 'remove', index: 99 }]),
      // A valid change followed by an invalid one: nothing is saved.
      mixed: await send([{ op: 'text', id: 'e5', html: 'SHOULD NOT SAVE' }, { op: 'remove', id: 'e424242' }]),
      noCsrf: await fetch(C.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{"ops":[]}' }).then((r) => r.status),
    };
  });
  for (const k of ['badId', 'badProp', 'badValue', 'badAttr', 'badUrl', 'badTag', 'badLoop', 'mixed']) {
    expect(results[k].status, k).toBe(422);
    expect(results[k].json.ok, k).toBe(false);
  }
  expect(results.badId.json.error).toContain('element not found');
  expect(results.badProp.json.error).toContain('position');
  expect(results.noCsrf).toBe(419);
  expect(await visitorHtml()).not.toContain('SHOULD NOT SAVE');

  // An author who did not write this page: no editor on the page, and the save endpoint refuses.
  const ctx = await browser.newContext();
  const p2 = await ctx.newPage();
  await login(p2, 'peauthor', 'author-password-123');
  await p2.goto(`${BASE}${PAGE.url}`);
  await expect(p2.locator('body.pe-test')).toBeVisible();
  expect(await p2.locator('#se-config').count()).toBe(0);
  expect(await p2.locator('#se-toggle').count()).toBe(0);
  const csrf = (await (await p2.request.get(`${BASE}/admin/`)).text()).match(/"csrf":"([a-f0-9]+)"/)[1];
  const r = await p2.request.post(`${BASE}/admin/page-editor/${PAGE.id}/save`, {
    headers: { 'X-CSRF-Token': csrf, Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { ops: [{ op: 'text', id: 'e5', html: 'Hacked' }] },
  });
  expect(r.status()).toBe(403);
  await ctx.close();
  // Signed-out visitors: no editor either.
  expect(await visitorHtml()).not.toContain('page-editor.js');
});

// ---------------------------------------------------------------- pages that are not designed pages
// Stored content (WordPress-like HTML), the item's title and fields shown by a Template or the theme, and term pages.

const run = (args) => JSON.parse(execSync(SETUP + ' ' + args, { encoding: 'utf8' }).trim().split('\n').pop());
async function visitorAt(url) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.get(`${BASE}${url}`);
  expect(res.status()).toBe(200);
  const html = await res.text();
  await ctx.dispose();
  return html;
}
// What the editor's marks add; without them a team member gets the same markup as visitors.
const unmark = (h) => h.replace(/ data-se(-bind|-loop|-item)?="[^"]*"/g, '').replace(/<!--\/?se:[a-z]+(:[a-z0-9]+)?-->/g, '');
const noMarks = (h) => !/data-se(-bind|-loop|-item)?="/.test(h) && !h.includes('<!--se:') && !h.includes('se-config');
const caretAtEnd = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
  const g = getSelection(); g.removeAllRanges(); g.addRange(r);
}, sel);
const selectAll = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s); const r = document.createRange(); r.selectNodeContents(el);
  const g = getSelection(); g.removeAllRanges(); g.addRange(r);
}, sel);
const nbsp = (s) => s.replace(/ /g, '&nbsp;');

test('page shown by a Template: title, field and stored content edited in place; only the edited elements change', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const T = PAGE.tpl;
  await login(page);
  const visitorBefore = await visitorAt(T.url);
  expect(noMarks(visitorBefore)).toBe(true);
  // Signed in, before editing: same markup as visitors apart from the editor's marks.
  const editorRaw = await (await page.request.get(`${BASE}${T.url}`)).text();
  const region = (h) => h.match(/<section class="pe-t">[\s\S]*?<\/section>/)[0];
  expect(region(unmark(editorRaw))).toBe(region(visitorBefore));
  expect(editorRaw).toContain('<h1 class="t-title" data-se-bind="post:' + T.id + ':title">');
  expect(editorRaw).toContain('alt="Template page"'); // values inside attributes are never marked
  expect(editorRaw).not.toContain('data-slate-edit');

  await page.goto(`${BASE}${T.url}`);
  await expect(page.locator('#sb-edit')).toHaveCount(0); // the older region editor is retired here
  await page.click('#se-toggle');
  // Title: edited as stored (not the curly-quoted output).
  await page.click('h1.t-title');
  await expect(page.locator('.se-toolbar .se-chip-label')).toHaveText('Title');
  await selectAll(page, 'h1.t-title');
  await page.keyboard.type('Edited title');
  await page.click('p.t-sub');
  await selectAll(page, 'p.t-sub');
  await page.keyboard.type('New subtitle');
  // Stored content: a paragraph and an image.
  await page.click('.t-body p.intro');
  await caretAtEnd(page, '.t-body p.intro');
  await page.keyboard.type(', edited');
  await page.click('.t-body img');
  await page.fill('#se-alt', 'New alt');
  // Featured image ({{image}}): replaced through the image panel, saved as the item's featured image.
  await page.click('figure.t-fig img');
  await expect(page.locator('#se-panel-title')).toHaveText('Featured image');
  const file = path.join(SHOTS, 'pe-feature-new.png');
  fs.writeFileSync(file, PNG);
  const up = page.waitForResponse((r) => r.url().includes('/api/media') && r.request().method() === 'POST');
  await page.setInputFiles('[data-upload]', file);
  const newId = (await (await up).json()).items[0].id;
  await page.keyboard.press('Escape');
  // Content that is not stored text (shortcodes, {{tags}}) is not editable.
  expect(await page.locator('.t-body p:has-text("[gallery")').getAttribute('data-se')).toBeNull();
  // Lists from the template explain where they come from.
  await page.hover('.t-item >> nth=0');
  await expect(page.locator('.se-hover .se-tagname')).toHaveText(/^Comes from “Dyn post/);
  await page.screenshot({ path: `${SHOTS}/pe-template-page.png` });
  const resp = page.waitForResponse((r) => r.url().endsWith(`/page-editor/${T.id}/save`));
  await Promise.all([page.waitForEvent('load'), page.keyboard.press('Control+s')]);
  expect((await resp).status()).toBe(200);
  await expect(page.locator('.se-bar')).toBeVisible(); // back in edit mode after the reload

  const html = await visitorAt(T.url);
  expect(noMarks(html)).toBe(true);
  expect(html).toContain('<h1 class="t-title">Edited title</h1>');
  expect(html).toContain('<p class="t-sub">New subtitle</p>');
  expect(html).toContain('<img class="t-alt" alt="Edited title"');
  // Stored content: every byte outside the two edited elements is unchanged (comments, quotes, entities, SVG case, shortcodes).
  const stored = run('content ' + T.id);
  expect(stored.title).toBe('Edited title');
  expect(stored.fields.subtitle).toBe('New subtitle');
  expect(stored.featured).toBe(newId);
  expect(nbsp(stored.content)).toBe(PAGE.stored
    .replace('Intro text &amp; more&nbsp;here</p>', 'Intro text &amp; more&nbsp;here, edited</p>')
    .replace('<img src="/content/uploads/pe-photo.png" alt="Pic" width="24" height="16"/>', '<img src="/content/uploads/pe-photo.png" alt="New alt" width="24" height="16" />'));
  expect(errors).toEqual([]);
});

test('page shown by the theme: title and content edited in place', async ({ page }) => {
  const T = PAGE.theme;
  await login(page);
  const visitorBefore = await visitorAt(T.url);
  const editorRaw = await (await page.request.get(`${BASE}${T.url}`)).text();
  const region = (h) => h.match(/<article class="wrap narrow entry">[\s\S]*?<\/article>/)[0];
  expect(region(unmark(editorRaw))).toBe(region(visitorBefore)); // no extra wrappers for team members
  await page.goto(`${BASE}${T.url}`);
  await page.click('#se-toggle');
  await page.click('h1.entry-title');
  await selectAll(page, 'h1.entry-title');
  await page.keyboard.type('Theme page edited');
  await page.click('.entry-content h2#sec');
  await caretAtEnd(page, '.entry-content h2#sec');
  await page.keyboard.type(' two');
  await page.selectOption('#se-tag', 'h3');
  await page.screenshot({ path: `${SHOTS}/pe-theme-page.png` });
  await Promise.all([page.waitForEvent('load'), page.keyboard.press('Control+s')]);
  const html = await visitorAt(T.url);
  expect(noMarks(html)).toBe(true);
  expect(html).toContain('<h1 class="entry-title">Theme page edited</h1>');
  expect(html).toContain('<h3 id="sec">Section heading two</h3>');
  const stored = run('content ' + T.id).content;
  expect(stored).toBe(PAGE.stored.replace('<h2 id="sec">Section heading</h2>', '<h3 id="sec">Section heading two</h3>'));
});

test('term page: name and a term field edited in place', async ({ page }) => {
  const T = PAGE.term;
  await login(page);
  await page.goto(`${BASE}${T.url}`);
  await page.click('#se-toggle');
  await page.click('p.tl');
  await expect(page.locator('p.tl')).toHaveText("Topic lead's text");
  await caretAtEnd(page, 'p.tl');
  await page.keyboard.type(' More.');
  await page.click('h1.tt');
  await selectAll(page, 'h1.tt');
  await page.keyboard.type('PE Topic renamed');
  await page.screenshot({ path: `${SHOTS}/pe-term-page.png` });
  await Promise.all([page.waitForEvent('load'), page.keyboard.press('Control+s')]);
  const html = await visitorAt(T.url);
  expect(noMarks(html)).toBe(true);
  expect(html).toContain('<h1 class="tt">PE Topic renamed</h1>');
  expect(html).toMatch(/<p class="tl">Topic lead(&#039;|')s text More\.<\/p>/);
  const t = run('term ' + T.id);
  expect(t.name).toBe('PE Topic renamed');
  expect(t.fields.lead).toBe("Topic lead's text More.");
  // Without manage_terms the term save is refused.
  const ctx = await page.context().browser().newContext();
  const p2 = await ctx.newPage();
  await login(p2, 'peauthor', 'author-password-123');
  const csrf = (await (await p2.request.get(`${BASE}/admin/`)).text()).match(/"csrf":"([a-f0-9]+)"/)[1];
  const r = await p2.request.post(`${BASE}/admin/page-editor/term/${T.id}/save`, {
    headers: { 'X-CSRF-Token': csrf, Accept: 'application/json', 'Content-Type': 'application/json' },
    data: { ops: [{ op: 'field', bind: `term:${T.id}:name`, value: 'Hacked' }] },
  });
  expect(r.status()).toBe(403);
  await ctx.close();
});

// ---------------------------------------------------------------- the page's template: its static parts edited in place

test('template text edited in place: this page\'s own design, byte-minimal change, mixed parts read-only, undo', async ({ page }) => {
  const T = PAGE.tpl;
  const before = run('template pepagetpl');
  await login(page);
  await page.goto(`${BASE}${T.url}`);
  await page.click('#se-toggle');
  // Static template parts carry template ids; parts with values ({{author}}) and list items do not.
  await expect(page.locator('p.t-static')).toHaveAttribute('data-se', /^te\d+$/);
  await expect(page.locator('a.t-btn')).toHaveAttribute('data-se', /^te\d+$/);
  expect(await page.locator('p.t-mixed').getAttribute('data-se')).toBeNull();
  expect(await page.locator('.t-item >> nth=0').getAttribute('data-se')).toBeNull();
  await page.hover('.t-item >> nth=0');
  await expect(page.locator('.se-hover .se-tagname')).toHaveText(/^Comes from “Dyn post/);
  await page.click('p.t-static');
  await expect(page.locator('.se-toolbar .se-chip-label')).toHaveText('Page design');
  await caretAtEnd(page, 'p.t-static');
  await page.keyboard.type(' edited');
  await page.click('a.t-btn');
  await selectAll(page, 'a.t-btn');
  await page.keyboard.type('New label');
  await page.screenshot({ path: `${SHOTS}/pe-template-parts.png` });
  const resp = page.waitForResponse((r) => r.url().endsWith('/page-editor/template/pepagetpl/save'));
  await page.keyboard.press('Control+s');
  expect((await resp).status()).toBe(200);
  await expect(page.locator('[data-act="undo-tpl"]')).toBeVisible();
  const after = run('template pepagetpl');
  expect(after.html).toBe(before.html.replace('<p class="t-static">Static <em>template</em> text</p>', '<p class="t-static">Static <em>template</em> text edited</p>')
    .replace('<a class="t-btn" href="/somewhere">Button label</a>', '<a class="t-btn" href="/somewhere">New label</a>'));
  expect(after.versions).toBe(before.versions + 1);
  const html = await visitorAt(T.url);
  expect(noMarks(html)).toBe(true);
  expect(html).toContain('<p class="t-static">Static <em>template</em> text edited</p>');
  expect(html).toContain('<a class="t-btn" href="/somewhere">New label</a>');
  // Survives a reload; then undo puts the template back exactly.
  await page.reload();
  await expect(page.locator('a.t-btn')).toHaveText('New label');
  await page.click('#se-toggle');
  page.once('dialog', (d) => d.accept());
  await Promise.all([page.waitForEvent('load'), page.click('[data-act="undo-tpl"]')]);
  const undone = run('template pepagetpl');
  expect(undone.html).toBe(before.html);
  expect(undone.versions).toBe(before.versions);
  expect(await visitorAt(T.url)).toContain('<a class="t-btn" href="/somewhere">Button label</a>');
  // Template ids that are not static parts are refused by the server.
  const bad = await page.evaluate(async () => {
    const C = JSON.parse(document.getElementById('se-config').textContent);
    const r = await fetch(C.template.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': C.csrf },
      body: JSON.stringify({ ops: [{ op: 'text', id: 'te9999', html: 'x' }] }) });
    return { status: r.status, json: await r.json() };
  });
  expect(bad.status).toBe(422);
});

test('shared template: notice with the number of pages, change shows on every page, undo', async ({ page }) => {
  const S = PAGE.shared;
  const before = run('template ' + S.template);
  await login(page);
  await page.goto(`${BASE}${S.url}`);
  await page.click('#se-toggle');
  await page.click('p.pp-shared');
  await expect(page.locator('.se-toolbar .se-chip-label')).toHaveText('Template: PE post');
  await expect(page.locator('.se-toolbar .se-chip-label')).toHaveAttribute('title', `This is part of the template “PE post”. Changes appear on all ${S.count} pages that use it.`);
  await caretAtEnd(page, 'p.pp-shared');
  await page.keyboard.type(' for everyone');
  let notice = '';
  page.once('dialog', async (d) => { notice = d.message(); await d.accept(); });
  const resp = page.waitForResponse((r) => r.url().endsWith(`/page-editor/template/${S.template}/save`));
  await page.keyboard.press('Control+s');
  expect((await resp).status()).toBe(200);
  expect(notice).toContain(`This is part of the template “PE post”. Changes appear on all ${S.count} pages that use it.`);
  await expect(page.locator('.se-toast', { hasText: `Saved to the template “PE post” (${S.count} pages)` })).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/pe-template-shared.png` });
  expect(await visitorAt(S.url2)).toContain('<p class="pp-shared">Shared template line for everyone</p>');
  expect(run('template ' + S.template).html).toBe(before.html.replace('Shared template line</p>', 'Shared template line for everyone</p>'));
  // Undo from the toast.
  page.once('dialog', (d) => d.accept());
  await Promise.all([page.waitForEvent('load'), page.locator('.se-toast-act').first().click()]);
  expect(run('template ' + S.template).html).toBe(before.html);
  expect(await visitorAt(S.url2)).toContain('<p class="pp-shared">Shared template line</p>');
  // People who may not change templates get no template parts.
  const ctx = await page.context().browser().newContext();
  const p2 = await ctx.newPage();
  await login(p2, 'peauthor', 'author-password-123');
  const raw = await (await p2.request.get(`${BASE}${S.url}`)).text();
  expect(raw).not.toMatch(/data-se="te\d+"/);
  await ctx.close();
});
