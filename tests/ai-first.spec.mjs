// End-to-end: import HTML pages keeping their design, and Goraiya creating a page.
// Needs a running Slate site: SLATE_URL, SLATE_USER, SLATE_PASS. Goraiya needs the fake AI provider
// (php -S 127.0.0.1:8097 bin/mock-ai; php bin/ci-ai http://127.0.0.1:8097/v1) and SLATE_MOCK_AI=1.
import { test, expect } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const SHOTS = process.env.SLATE_SHOTS || 'test-results/screens';
const here = path.dirname(fileURLToPath(import.meta.url));

test.setTimeout(120_000);

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

test('import a site: pages keep their design, links and assets work, parts become editable', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/import`);
  await page.setInputFiles('[data-import-files]', path.join(here, 'fixtures/sample-site.zip'));
  await page.click('[data-import-go]');
  await page.waitForURL(/\/admin\/import\/[0-9a-f-]+$/);
  await expect(page.locator('input[name="paths[]"]')).toHaveCount(3);
  await page.screenshot({ path: `${SHOTS}/import-review.png` });
  await page.selectOption('select[name=status]', 'publish');
  await page.click('button[type=submit]:has-text("Import pages")');
  await expect(page.getByText('Imported 3 pages')).toBeVisible();

  // Visitor view of the imported home page (set as homepage)
  const res = await page.request.get(`${BASE}/`, { headers: { Cookie: '' } });
  const html = await res.text();
  expect(html).toContain('<body class="home"');
  expect(html).toMatch(/href="\/content\/uploads\/sites\/[0-9a-f-]+\/css\/style\.css"/);
  expect(html).toMatch(/src="\/content\/uploads\/sites\/[0-9a-f-]+\/img\/logo\.svg"/);
  expect(html).toMatch(/url\('\/content\/uploads\/sites\/[0-9a-f-]+\/img\/hero\.svg'\)/);
  expect(html).toMatch(/href="\/about(-\d+)?\/#team"/);             // link to another imported page
  expect(html).toMatch(/<h1[^>]*data-se="e\d+"/);            // editable heading
  expect(html).toMatch(/data-se-loop="l\d+"[^>]*>|class="features"[^>]*data-se-loop/); // repeating group found
  expect(html).toMatch(/swiper-wrapper"[^>]*data-se-loop|data-se-loop="l\d+"[^>]*class="swiper-wrapper/);
  expect(html).toContain('<title>Acme Studio');
  const css = await page.request.get(new URL(html.match(/href="(\/content\/uploads\/sites\/[^"]+\/css\/style\.css)"/)[1], BASE).toString());
  expect(css.status()).toBe(200);
  const svg = await (await page.request.get(new URL(html.match(/src="(\/content\/uploads\/sites\/[^"]+\/img\/logo\.svg)"/)[1], BASE).toString())).text();
  expect(svg).toContain('<rect');

  // The page renders with its own stylesheet and runs its own script
  await page.goto(`${BASE}/`);
  await expect(page.locator('h1')).toHaveCSS('font-family', /^Georgia/);
  expect(html).not.toContain('--font-heading');                 // Slate's design-system styles stay out
  await expect(page.locator('html')).toHaveAttribute('data-app-loaded', '1');
  await page.screenshot({ path: `${SHOTS}/import-home.png`, fullPage: true });

  // Nested page with ../ links
  const blogUrl = html.match(/href="(\/blog(-\d+)?\/)"/)[1];
  const blog = await (await page.request.get(`${BASE}${blogUrl}`)).text();
  expect(blog).toMatch(/href="\/about(-\d+)?\/"/);
});

test('Goraiya creates a draft page from a description', async ({ page }) => {
  test.skip(!process.env.SLATE_MOCK_AI, 'set SLATE_MOCK_AI=1 when the fake AI provider runs');
  await login(page);
  await page.click('[data-goraiya-open]');
  await expect(page.locator('#goraiya')).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/goraiya-open.png` });
  await page.fill('[data-goraiya-text]', 'Create a services page for my agency');
  await page.keyboard.press('Enter');
  const card = page.locator('.goraiya-result').first();
  await expect(card).toContainText('Services', { timeout: 30_000 });
  await page.screenshot({ path: `${SHOTS}/goraiya-done.png` });
  const preview = await card.locator('a.btn-primary').getAttribute('href');
  const html = await (await page.request.get(new URL(preview, BASE).toString())).text();
  expect(html).toContain('Services that grow your business');
  expect(html).not.toContain('window.bad');                  // scripts from AI pages are removed
  expect(html).toMatch(/class="cards"[^>]*data-se-loop|data-se-loop="l\d+"[^>]*class="cards"/);
  expect(html).toContain('<meta name="description" content="What we offer."');
});
