// End-to-end: Import pages (core/Slate/SiteImport.php): a page picked file by file (HTML + separate CSS), a page
// imported by its address with every file it uses, and the designs ("Keep the original design", "Use my site's styles
// for..." chosen parts such as typography only, "Use my site's styles completely"), switched later in the page settings.
// Needs a running Slate site (SLATE_URL, SLATE_USER, SLATE_PASS). The link import needs tests/fixtures served where the
// site can reach it and private addresses allowed for the test (bin/ci-import):
//   php -S 127.0.0.1:8191 -t tests/fixtures &   php bin/ci-import
// IMPORT_FIXTURES is that address as the Slate server sees it (default http://127.0.0.1:8191).
import { test, expect, request as pwRequest } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const SHOTS = process.env.SLATE_SHOTS || 'test-results/screens';
const FIXTURES = (process.env.IMPORT_FIXTURES || 'http://127.0.0.1:8191').replace(/\/$/, '');
const here = path.dirname(fileURLToPath(import.meta.url));
const single = (f) => path.join(here, 'fixtures/single-page', f);

test.describe.configure({ mode: 'serial' });
test.setTimeout(180_000);

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

// The page as a signed-out visitor gets it.
async function visitor(url) {
  const ctx = await pwRequest.newContext();
  const res = await ctx.get(new URL(url, BASE).toString());
  expect(res.status()).toBe(200);
  const html = await res.text();
  await ctx.dispose();
  return html;
}

// Review step: pick the design, keep the current homepage, publish, import. Returns the report rows.
async function finishImport(page, design, parts = []) {
  await page.check(`input[name=design][value=${design}]`);
  if (design === 'partial') {
    for (const box of await page.locator('[data-import-part]').all()) {
      const want = parts.includes(await box.getAttribute('value'));
      if ((await box.isChecked()) !== want) await box.click();
    }
  }
  await page.check('input[name=home][value=""]');
  await page.selectOption('select[name=status]', 'publish');
  await page.click('button[type=submit]:has-text("Import pages")');
  await expect(page.getByText(/Imported \d+ page/)).toBeVisible();
  return page.locator('[data-import-result]');
}

const ownCss = /<link rel="stylesheet" href="\/content\/uploads\/sites\/[0-9a-f-]+\//;

test('separate HTML and CSS files make one page; unlinked CSS can be attached; both designs', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/import`);
  // Pick the files one by one: the page, its stylesheet (asked for as assets/page.css), another stylesheet nobody uses, a stray file.
  await page.setInputFiles('[data-import-files]', [single('page.html'), single('page.css')]);
  await page.setInputFiles('[data-import-more]', [single('extra.css'), single('notes.txt')]);
  const list = page.locator('[data-import-list]');
  await expect(list.locator('[data-import-group="page"] li')).toHaveCount(1);
  await expect(list.locator('[data-import-group="css"] li')).toHaveCount(2);
  await expect(list.locator('[data-import-item="page.css"]')).toContainText('Linked to page.html');
  await expect(list.locator('[data-import-item="extra.css"]')).toContainText('Not linked yet');
  await expect(list).toContainText('One page with its files');
  await page.screenshot({ path: `${SHOTS}/import-files.png`, fullPage: true });
  await list.locator('[data-import-item="notes.txt"] [data-import-remove]').click();
  await expect(list.locator('[data-import-item="notes.txt"]')).toHaveCount(0);
  await expect(page.locator('[data-import-picked]')).toContainText('3 files ready');
  await page.click('[data-import-go]');
  await page.waitForURL(/\/admin\/import\/[0-9a-f-]+$/);
  const reviewUrl = page.url();

  // Review: one page, the unused stylesheet offered for attaching, two designs with previews.
  await expect(page.locator('input[name="paths[]"]')).toHaveCount(1);
  await expect(page.locator('input[name="attach[]"][value="extra.css"]')).toBeChecked();
  await expect(page.locator('[data-import-thumb="keep"]')).toHaveCount(1);
  await expect(page.locator('[data-import-thumb="site"]')).toHaveCount(1);
  const before = await page.locator('[data-import-thumb="keep"]').getAttribute('src');
  const after = await page.locator('[data-import-thumb="site"]').getAttribute('src');
  const pBefore = await (await page.request.get(new URL(before, BASE).toString())).text();
  const pAfter = await (await page.request.get(new URL(after, BASE).toString())).text();
  expect(pBefore).toMatch(/sites\/[0-9a-f-]+\/assets\/page\.css/);   // asked for in a folder, uploaded without: linked by name
  expect(pBefore).not.toContain('id="ds-vars"');
  expect(pAfter).toContain('id="ds-vars"');
  expect(pAfter).toContain('id="slate-site-base"');
  expect(pAfter).not.toMatch(/page\.css|extra\.css/);
  await page.locator('.import-designs').scrollIntoViewIfNeeded();
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${SHOTS}/import-design-choice.png`, fullPage: true });

  // Keep the original design: the page's own CSS (linked and attached) applies, the design system stays out.
  let rows = await finishImport(page, 'keep');
  await expect(rows).toHaveCount(1);
  await expect(rows.first()).toContainText('Keep the original design');
  await expect(rows.first()).toContainText('attached to the page');
  await expect(rows.first()).toContainText('linked by name');
  const keepUrl = await rows.first().locator('a:has-text("Open and edit")').getAttribute('href');
  const keepHtml = await visitor(keepUrl.replace(/\?preview=\d+$/, ''));
  expect(keepHtml).toMatch(/href="\/content\/uploads\/sites\/[0-9a-f-]+\/assets\/page\.css"/);
  expect(keepHtml).toMatch(/href="\/content\/uploads\/sites\/[0-9a-f-]+\/extra\.css"/);
  expect(keepHtml).not.toContain('id="ds-vars"');
  expect(keepHtml).toMatch(/<h1[^>]*data-se="e\d+"/);
  await page.goto(new URL(keepUrl.replace(/\?preview=\d+$/, ''), BASE).toString());
  await expect(page.locator('h1')).toHaveCSS('color', 'rgb(10, 20, 30)');       // page.css
  await expect(page.locator('section.intro p')).toHaveCSS('color', 'rgb(40, 50, 60)'); // extra.css, attached

  // Same files, "Use my site's styles": own CSS gone, design system in, structure and editable parts kept.
  await page.goto(reviewUrl);
  rows = await finishImport(page, 'site');
  await expect(rows.first()).toContainText("Use my site's styles");
  const siteUrl = (await rows.first().locator('a:has-text("Open and edit")').getAttribute('href')).replace(/\?preview=\d+$/, '');
  const siteHtml = await visitor(siteUrl);
  expect(siteHtml).not.toMatch(ownCss);
  expect(siteHtml).toContain('id="ds-vars"');
  expect(siteHtml).toContain('--font-heading');
  expect(siteHtml).toContain('class="slate-site-styles"');
  expect(siteHtml).toMatch(/<h1[^>]*data-se="e\d+"/);
  expect(siteHtml).toMatch(/class="button btn"/);                            // button-like link gets the global class
  expect(siteHtml).toMatch(/class="cards sl-grid sl-cols-3"/);                // grid hint from the removed CSS
  expect(siteHtml).not.toContain('background:#123');                          // inline colours go
  await page.goto(new URL(siteUrl, BASE).toString());
  await expect(page.locator('h1')).not.toHaveCSS('color', 'rgb(10, 20, 30)');
  await expect(page.locator('ul.cards')).toHaveCSS('display', 'grid');
  await page.screenshot({ path: `${SHOTS}/import-site-styles.png`, fullPage: true });
});

test("site's styles for typography only: the page keeps its sections and layout; colours and buttons added later", async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/import`);
  await page.setInputFiles('[data-import-files]', [single('page.html'), single('page.css'), single('extra.css')]);
  await page.click('[data-import-go]');
  await page.waitForURL(/\/admin\/import\/[0-9a-f-]+$/);
  // The partial preview follows the ticked parts.
  await page.check('input[name=design][value=partial]');
  for (const box of await page.locator('[data-import-part]').all()) {
    if ((await box.getAttribute('value')) !== 'typography' && await box.isChecked()) await box.click();
  }
  if (!(await page.locator('[data-import-part][value=typography]').isChecked())) await page.locator('[data-import-part][value=typography]').click();
  await expect(page.locator('[data-import-thumb="partial"]')).toHaveAttribute('src', /design=partial&parts=typography$/);
  const thumb = await page.locator('[data-import-thumb="partial"]').getAttribute('src');
  const prev = await (await page.request.get(new URL(thumb, BASE).toString())).text();
  expect(prev).toContain('id="slate-site-parts"');
  expect(prev).toMatch(/assets\/page\.css/);                                  // the page's own CSS stays
  await page.locator('.import-designs').scrollIntoViewIfNeeded();
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${SHOTS}/import-partial-choice.png`, fullPage: true });

  const rows = await finishImport(page, 'partial', ['typography']);
  await expect(rows.first()).toContainText("Site's styles for: Typography");
  const url = (await rows.first().locator('a:has-text("Open and edit")').getAttribute('href')).replace(/\?preview=\d+$/, '');
  const edit = await rows.first().locator('a:has-text("Settings")').getAttribute('href');
  const html = await visitor(url);
  expect(html).toMatch(ownCss);                                               // own stylesheet still loaded
  expect(html).toContain('style="background:#123;padding:40px"');             // section background kept
  expect(html).toContain('id="slate-site-parts"');
  expect(html).toContain('--font-heading');                                   // design-system variables
  expect(html).not.toContain('id="ds-vars"');                                 // but not its element rules and classes
  expect(html).toContain('class="slate-parts"');
  expect(html).toMatch(/<h1[^>]*data-se="e\d+"/);
  expect(html).toMatch(/data-se-loop="l\d+"/);
  expect(html).not.toMatch(/class="button btn"/);                             // buttons not chosen
  // Page CSS order: the overrides come after the page's own stylesheet.
  expect(html.indexOf('id="slate-site-parts"')).toBeGreaterThan(html.search(ownCss));

  await page.goto(new URL(url, BASE).toString());
  await expect(page.locator('section.intro')).toHaveCSS('background-color', 'rgb(17, 34, 51)'); // section background
  await expect(page.locator('ul.cards')).toHaveCSS('display', 'grid');        // layout from page.css
  await expect(page.locator('h1')).toHaveCSS('font-family', /Instrument Serif/); // design-system heading font
  await expect(page.locator('ul.cards p').first()).toHaveCSS('font-family', /Inter/); // design-system body font
  await expect(page.locator('h1')).toHaveCSS('color', 'rgb(10, 20, 30)');     // colours not chosen: the page's own
  await expect(page.locator('ul.cards p').first()).toHaveCSS('color', 'rgb(40, 50, 60)');
  await page.screenshot({ path: `${SHOTS}/import-partial-typography.png`, fullPage: true });

  // The choice is remembered for the next import.
  await page.goto(`${BASE}/admin/import`);
  await page.setInputFiles('[data-import-files]', [single('page.html')]);
  await page.click('[data-import-go]');
  await page.waitForURL(/\/admin\/import\/[0-9a-f-]+$/);
  await expect(page.locator('input[name=design][value=partial]')).toBeChecked();
  await expect(page.locator('[data-import-part][value=typography]')).toBeChecked();
  await expect(page.locator('[data-import-part][value=colors]')).not.toBeChecked();

  // Later, in the page settings: add colours and buttons.
  await page.goto(new URL(edit, BASE).toString());
  const card = page.locator('[data-page-design]');
  await expect(card.locator('input[name=html_design][value=partial]')).toBeChecked();
  await expect(card.locator('input[name="html_parts[]"][value=typography]')).toBeChecked();
  await card.locator('input[name="html_parts[]"][value=colors]').check();
  await card.locator('input[name="html_parts[]"][value=buttons]').check();
  await card.screenshot({ path: `${SHOTS}/import-partial-setting.png` });
  await page.click('[data-save=publish]');
  await expect(page.locator('[data-save-state]')).toHaveText(/^Saved /, { timeout: 15_000 });
  const html2 = await visitor(url);
  expect(html2).toMatch(/class="button btn"/);
  expect(html2).toContain('intro sl-own-bg');
  expect(html2).toMatch(ownCss);
  await page.goto(new URL(url, BASE).toString());
  await expect(page.locator('a.button')).toHaveCSS('background-color', 'rgb(242, 107, 45)'); // global btn look
  await expect(page.locator('ul.cards p').first()).toHaveCSS('color', 'rgb(31, 31, 31)');   // palette text colour
  await expect(page.locator('section.intro p')).toHaveCSS('color', 'rgb(40, 50, 60)');      // own-background section keeps its colours
  await expect(page.locator('section.intro')).toHaveCSS('background-color', 'rgb(17, 34, 51)');
  await expect(page.locator('h1')).toHaveCSS('font-family', /Instrument Serif/);
  await page.screenshot({ path: `${SHOTS}/import-partial-colours-buttons.png`, fullPage: true });
});

test('import from a link: page, linked page and every file they use; switch the design later', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/import?tab=url`);
  await page.fill('[data-import-urls]', `${FIXTURES}/link-site/`);
  await page.check('[data-import-follow]');
  await page.screenshot({ path: `${SHOTS}/import-url.png` });
  await page.click('[data-import-fetch]');
  await page.waitForURL(/\/admin\/import\/[0-9a-f-]+$/, { timeout: 120_000 });
  await expect(page.locator('input[name="paths[]"]')).toHaveCount(2);         // the page and the page it links to
  await expect(page.locator('.import-src').first()).toContainText(`${FIXTURES}/link-site/`);
  await expect(page.locator('.alert').first()).toContainText('could not be fetched');
  await page.screenshot({ path: `${SHOTS}/import-url-review.png`, fullPage: true });

  const rows = await finishImport(page, 'keep');
  await expect(rows).toHaveCount(2);
  const home = rows.filter({ hasText: 'Linkhaus' }).filter({ hasNotText: 'Team' });
  await expect(home).toContainText(`${FIXTURES}/link-site/`);
  await expect(home).toContainText('files fetched');
  await expect(home).toContainText('could not be fetched');
  await expect(home).toContainText('gone.png');
  await expect(page.locator('.import-summary')).toContainText('Files fetched');
  await page.screenshot({ path: `${SHOTS}/import-url-report.png`, fullPage: true });
  const url = (await home.locator('a:has-text("Open and edit")').getAttribute('href')).replace(/\?preview=\d+$/, '');
  const edit = await home.locator('a:has-text("Settings")').getAttribute('href');

  const html = await visitor(url);
  const site = html.match(/\/content\/uploads\/sites\/([0-9a-f-]+)\//)[1];
  const at = (p) => `/content/uploads/sites/${site}/${p}`;
  expect(html).toContain(`href="${at('css/main-')}`);                         // stylesheet with ?v=2 stored under its own name
  expect(html).toContain(`href="${at('img/favicon.svg')}"`);
  expect(html).toContain(`src="${at('img/logo.svg')}"`);
  expect(html).toContain(`srcset="${at('img/photo.svg')} 1x, ${at('img/photo-2x.svg')} 2x"`);
  expect(html).toContain(`poster="${at('img/poster.svg')}"`);
  expect(html).toContain(`src="${at('js/site.js')}"`);
  expect(html).toMatch(/href="\/[a-z0-9-]*team[a-z0-9-]*\/#people"/);         // link to the other imported page
  expect(html).toContain('href="https://example.org/elsewhere"');              // other sites stay as they are
  expect(html).toMatch(/<h1[^>]*data-se="e\d+"/);
  expect(html).not.toContain('id="ds-vars"');
  const cssPath = html.match(/href="(\/content\/uploads\/sites\/[^"]+\/css\/main-[0-9a-f]+\.css)"/)[1];
  const css = await (await page.request.get(new URL(cssPath, BASE).toString())).text();
  expect(css).toContain('@import "base.css"');
  expect(css).toContain('url("../fonts/link.woff2")');
  for (const f of ['css/base.css', 'fonts/link.woff2', 'img/bg.svg']) {
    expect((await page.request.get(new URL(at(f), BASE).toString())).status()).toBe(200);
  }
  await page.goto(new URL(url, BASE).toString());
  await expect(page.locator('h1')).toHaveCSS('color', 'rgb(200, 10, 10)');
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(250, 240, 230)'); // from the @import
  await expect(page.locator('html')).toHaveAttribute('data-link-site', '1');
  await page.screenshot({ path: `${SHOTS}/import-url-page.png`, fullPage: true });

  // Switch to the site's styles in the page settings.
  await page.goto(new URL(edit, BASE).toString());
  await expect(page.locator('[data-page-design]')).toContainText(`${FIXTURES}/link-site/`);
  await page.locator('[data-page-design]').screenshot({ path: `${SHOTS}/import-design-setting.png` });
  await page.check('input[name=html_design][value=site]');
  await page.click('[data-save=publish]');
  await expect(page.locator('[data-save-state]')).toHaveText(/^Saved /, { timeout: 15_000 });
  const styled = await visitor(url);
  expect(styled).not.toMatch(/main-[0-9a-f]+\.css/);
  expect(styled).not.toContain('.note{color');
  expect(styled).toContain('id="ds-vars"');
  expect(styled).toMatch(/class="btn-primary btn"/);
  expect(styled).toMatch(/class="grid sl-grid sl-cols-3"/);
  expect(styled).toMatch(/class="top sl-row"/);
  expect(styled).toContain('style="text-align:center"');                     // layout kept, colour dropped
  expect(styled).toMatch(/<h1[^>]*data-se="e\d+"/);
  expect(styled).toContain(`src="${at('js/site.js')}"`);                      // scripts stay
  await page.goto(new URL(url, BASE).toString());
  await expect(page.locator('h1')).not.toHaveCSS('color', 'rgb(200, 10, 10)');
  await page.screenshot({ path: `${SHOTS}/import-url-site-styles.png`, fullPage: true });

  // And back: exactly the original design again.
  await page.goto(new URL(edit, BASE).toString());
  await page.check('input[name=html_design][value=keep]');
  await page.click('[data-save=publish]');
  await expect(page.locator('[data-save-state]')).toHaveText(/^Saved /, { timeout: 15_000 });
  const back = await visitor(url);
  expect(back).toMatch(/main-[0-9a-f]+\.css/);
  expect(back).not.toContain('id="ds-vars"');
});

test('import from a link refuses addresses that are not http(s)', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/import?tab=url`);
  await page.fill('[data-import-urls]', 'file:///etc/passwd');
  await page.click('[data-import-fetch]');
  await expect(page.locator('.toast').filter({ hasText: 'Only http:// and https://' })).toBeVisible();
});
