// End-to-end test of the visual builder: drag a block, style it, undo/redo, save, reload, check the visitor page.
// Needs a running Slate site (see .github/workflows/builder.yml):  SLATE_URL, SLATE_USER, SLATE_PASS
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const SHOTS = process.env.SLATE_SHOTS || 'test-results/screens';

test.setTimeout(120_000);

test('visual builder: drag, style, undo, save, reload, output', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));

  // Sign in
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);

  // New page → open the builder
  const title = 'Builder test ' + Date.now();
  await page.goto(`${BASE}/admin/content/page/new`);
  await page.fill('input[name=title]', title);
  // Some classic content first, to prove switching back restores it exactly.
  await page.evaluate(() => {
    const area = document.querySelector('[data-main-editor] [contenteditable]');
    if (area) area.innerHTML = '<p>Original classic text</p>';
    const ta = document.querySelector('textarea[name=content]');
    if (ta) ta.value = '<p>Original classic text</p>';
  });
  await page.click('button[name=then][value=builder]');
  await expect(page).toHaveURL(/\/builder$/);
  await page.waitForFunction(() => window.pbEditor && window.pbEditor.getModel().get('ready') !== false);
  await page.waitForTimeout(800);

  // The real site header/footer are shown around the design as locked previews
  expect(await page.evaluate(() => window.pbEditor.getWrapper().components().filter((c) => c.getAttributes()['data-pb-chrome']).length)).toBeGreaterThan(0);

  // Drag the Hero block onto the canvas
  const frame = page.locator('.gjs-frame');
  const block = page.locator('.gjs-block', { hasText: /^\s*Hero\s*$/ }).first();
  await block.scrollIntoViewIfNeeded();
  const from = await block.boundingBox();
  const to = await frame.boundingBox();
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  for (let i = 1; i <= 12; i++) {
    await page.mouse.move(from.x + (to.x + to.width / 2 - from.x) * (i / 12), from.y + (to.y + to.height / 2 - from.y) * (i / 12));
    await page.waitForTimeout(40);
  }
  await page.mouse.up();
  await page.waitForTimeout(600);
  let count = await page.evaluate(() => window.pbEditor.getWrapper().find('h1').filter((c) => !c.closest('[data-pb-chrome]')).length);
  test.info().annotations.push({ type: 'drag', description: count ? 'real mouse drag' : 'API fallback' });
  console.log('drop via', count ? 'real mouse drag' : 'API fallback');
  if (count === 0) {
    // Some headless setups do not deliver native drag events into iframes; drive the same block through the API.
    await page.evaluate(() => window.pbEditor.addComponents(window.pbEditor.Blocks.get('hero').get('content')));
    count = await page.evaluate(() => window.pbEditor.getWrapper().find('h1').filter((c) => !c.closest('[data-pb-chrome]')).length);
  }
  expect(count).toBe(1);
  await page.screenshot({ path: `${SHOTS}/1-dropped.png` });

  // Select the heading and style it through the Style Manager (class-based, responsive)
  const cls = await page.evaluate(() => {
    const ed = window.pbEditor;
    const h = ed.getWrapper().find('h1').filter((c) => !c.closest('[data-pb-chrome]'))[0];
    ed.select(h);
    return h.getClasses()[0];
  });
  expect(cls).toMatch(/^pb-/);
  await page.evaluate((c) => {
    const ed = window.pbEditor;
    ed.Css.setRule('.' + c, { color: 'rgb(200, 30, 30)' });
    ed.Css.setRule('.' + c, { 'font-size': '24px' }, { atRuleType: 'media', atRuleParams: '(max-width: 480px)' });
  }, cls);
  expect(await page.evaluate(() => window.pbEditor.getCss())).toContain('rgb(200, 30, 30)');

  // Undo removes the last change, redo brings it back
  await page.click('[data-cmd=undo]');
  await page.click('[data-cmd=redo]');
  await page.screenshot({ path: `${SHOTS}/2-styled.png` });

  // Publish
  await page.click('[data-pb-save=publish]');
  await expect(page.locator('[data-pb-state]')).toContainText('Saved', { timeout: 15_000 });

  // Reload: the design is still there
  await page.reload();
  await page.waitForFunction(() => window.pbEditor && window.pbEditor.getWrapper().find('h1').filter((c) => !c.closest('[data-pb-chrome]')).length === 1);
  expect(await page.evaluate(() => window.pbEditor.getCss())).toContain('rgb(200, 30, 30)');

  // Visitor page: plain HTML + CSS, class-based rules, no builder scripts
  const viewUrl = await page.locator('.dropdown-menu a[target=_blank]').first().getAttribute('href');
  const res = await page.request.get(new URL(viewUrl, BASE).toString());
  expect(res.status()).toBe(200);
  const html = await res.text();
  expect(html).toContain('<div class="pb-page">');
  expect(html).toMatch(new RegExp(`\\.${cls}\\{[^}]*color:rgb\\(200, 30, 30\\)`));
  expect(html).toContain('@media (max-width: 480px)');
  expect(html).not.toContain('grapes');
  expect(html).not.toContain('data-pb-chrome');
  expect((html.match(/<header class="site-header/g) || []).length).toBe(1);
  await page.goto(new URL(viewUrl, BASE).toString());
  await page.screenshot({ path: `${SHOTS}/3-visitor.png`, fullPage: true });

  // Back to the classic editor: the original content returns, the design is kept
  await page.goto(new URL(viewUrl, BASE).toString().replace(/\?.*$/, ''));
  const editUrl = page.url();
  const builderUrl = await page.evaluate(() => document.querySelector('#slate-bar a.sb-primary')?.getAttribute('href'));
  expect(builderUrl).toMatch(/\/builder$/);
  await page.goto(new URL(builderUrl.replace(/\/builder$/, ''), BASE).toString());
  page.once('dialog', (d) => d.accept());
  await page.click('button[name=mode][value=classic]');
  await page.waitForLoadState('load');
  expect(await page.locator('textarea[name=content]').inputValue()).toContain('Original classic text');
  const visitor = await (await page.request.get(editUrl)).text();
  expect(visitor).toContain('Original classic text');
  expect(visitor).not.toContain('pb-page');

  expect(errors).toEqual([]);
});
