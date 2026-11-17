// End-to-end: SEO › Schema by type (mapping reaches the JSON-LD), taxonomy archive item lists, and instant indexing
// (IndexNow key file, automatic submission on publish, nothing while noindexed, Request indexing in the editor).
// Needs a running Slate site (SLATE_URL, SLATE_USER, SLATE_PASS) whose config.php points the indexing endpoints at the
// mock (bin/mock-indexing, MOCK_URL), e.g. 'seo_endpoints' => ['indexnow' => 'http://127.0.0.1:8197/indexnow', …].
// The indexing tests refuse to run against real Google / IndexNow endpoints.
import { test, expect } from '@playwright/test';
import { generateKeyPairSync } from 'node:crypto';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';
const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:8197';

test.setTimeout(120_000);
test.describe.configure({ mode: 'serial' });

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

/** The JSON-LD graph of a public page (fetched without the admin session). */
async function graph(request, url) {
  const html = await (await request.get(url, { headers: { Cookie: '' } })).text();
  const m = html.match(/<script type="application\/ld\+json">(.*?)<\/script>/s);
  expect(m, `JSON-LD on ${url}`).toBeTruthy();
  return JSON.parse(m[1])['@graph'];
}

async function mockLog(request) {
  return (await request.get(`${MOCK}/log`)).json();
}

async function setToggle(page, section, key, on) {
  await page.goto(`${BASE}/admin/seo/${section}`);
  const box = page.locator(`input[type=checkbox][name="s[${key}]"]`);
  if ((await box.isChecked()) !== on) {
    await box.setChecked(on, { force: true });
  }
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator(`input[type=checkbox][name="s[${key}]"]`)).toBeChecked({ checked: on });
}

async function newPost(page, title) {
  await page.goto(`${BASE}/admin/content/post/new`);
  await page.fill('input[name=title]', title);
  await page.click('[data-save="publish"]');
  await expect(page).toHaveURL(/\/admin\/content\/post\/\d+/, { timeout: 20_000 });
  await page.goto(page.url()); // the editor saves in place; load the saved item's screen
}

test('schema by type: a mapped schema reaches the item page', async ({ page, request }) => {
  await login(page);
  await page.goto(`${BASE}/admin/seo/schema-types`);
  await expect(page.locator('[data-sd-row="post"]')).toBeVisible();
  await page.goto(`${BASE}/admin/seo/schema-types?type=post&as=Course`);
  await expect(page.locator('[data-sd-schema]')).toHaveValue('Course');
  await page.selectOption('select[name="sd[map][name]"]', '@title');
  await page.selectOption('select[name="sd[map][description]"]', '@excerpt');
  await page.selectOption('select[name="sd[map][provider.name]"]', '=');
  await expect(page.locator('input[name="sd[fixed][provider.name]"]')).toBeVisible();
  await page.fill('input[name="sd[fixed][provider.name]"]', 'E2E Academy');
  await page.selectOption('select[name="sd[map][courseCode]"]', '=');
  await page.fill('input[name="sd[fixed][courseCode]"]', 'E2E-101');
  await page.selectOption('select[name="sd[map][inLanguage]"]', '');
  await page.click('.save-bar button[type=submit]');
  await expect(page).toHaveURL(/schema-types\?type=post$/);
  await expect(page.locator('[data-sd-schema]')).toHaveValue('Course');
  await expect(page.locator('input[name="sd[fixed][provider.name]"]')).toHaveValue('E2E Academy');
  // The preview and the public page carry the mapped node; empty values are left out.
  await expect(page.locator('.seo-jsonld')).toContainText('"courseCode": "E2E-101"');
  const sample = await page.locator('a', { hasText: 'Test in Google Rich Results' }).getAttribute('href');
  const url = decodeURIComponent(sample.split('url=')[1]);
  const course = (await graph(request, url)).find((n) => n['@type'] === 'Course');
  expect(course).toBeTruthy();
  expect(course.provider).toEqual({ '@type': 'Organization', name: 'E2E Academy' });
  expect(course.courseCode).toBe('E2E-101');
  expect(course.name).toBeTruthy();
  expect(course.inLanguage).toBeUndefined();
  expect(course['@id']).toMatch(/^https?:\/\//);

  // Back to a blog post, with the default (unmapped) output.
  await page.goto(`${BASE}/admin/seo/schema-types?type=post&as=BlogPosting`);
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('[data-sd-schema]')).toHaveValue('BlogPosting');
  const blog = (await graph(request, url)).find((n) => n['@type'] === 'BlogPosting');
  expect(blog.headline).toBeTruthy();
  expect(blog.datePublished).toMatch(/^\d{4}-\d{2}-\d{2}T/);
});

test('taxonomy archives: collection page with an item list', async ({ page, request }) => {
  await login(page);
  const xml = await (await request.get(`${BASE}/sitemap-tax-category.xml`)).text();
  const termUrl = xml.match(/<loc>([^<]+)<\/loc>/)[1];
  await page.goto(`${BASE}/admin/seo/schema-types`);
  await page.selectOption('select[name="sd[tax][category]"]', 'itemlist');
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('select[name="sd[tax][category]"]')).toHaveValue('itemlist');
  const g = await graph(request, termUrl);
  const coll = g.find((n) => n['@type'] === 'CollectionPage');
  const list = g.find((n) => n['@type'] === 'ItemList');
  expect(coll.mainEntity['@id']).toBe(list['@id']);
  expect(list.itemListElement.length).toBeGreaterThan(0);
  expect(list.itemListElement[0]).toMatchObject({ '@type': 'ListItem', position: 1 });
  expect(list.itemListElement[0].url).toMatch(/^https?:\/\//);
  expect(g.find((n) => n['@type'] === 'BreadcrumbList')).toBeTruthy();
  // Back to the default.
  await page.selectOption('select[name="sd[tax][category]"]', 'collection');
  await page.click('.save-bar button[type=submit]');
  expect((await graph(request, termUrl)).find((n) => n['@type'] === 'ItemList')).toBeUndefined();
});

test('instant indexing: key file, automatic submission, noindex, request indexing', async ({ page, request }) => {
  await login(page);
  await page.goto(`${BASE}/admin/seo/instant`);
  await expect(page.locator('[data-seo-test-endpoints]'), 'config.php must point seo_endpoints at the mock').toBeVisible();
  await request.post(`${MOCK}/reset`);

  // Google: paste a service account key (a throwaway RSA key; the mock accepts any signed assertion).
  const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048, privateKeyEncoding: { type: 'pkcs8', format: 'pem' }, publicKeyEncoding: { type: 'spki', format: 'pem' } });
  await page.fill('textarea[name="seo_idx[google_key]"]', JSON.stringify({ type: 'service_account', project_id: 'e2e', private_key_id: 'k1',
    private_key: privateKey, client_email: 'e2e-indexer@e2e.iam.gserviceaccount.com', client_id: '1' }));
  for (const key of ['seo_indexnow_enabled', 'seo_google_indexing', 'seo_indexing_auto']) {
    const box = page.locator(`input[type=checkbox][name="s[${key}]"]`);
    if (!(await box.isChecked())) await box.setChecked(true, { force: true });
  }
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('code', { hasText: 'e2e-indexer@e2e.iam.gserviceaccount.com' })).toBeVisible();
  // The key is never shown again.
  expect(await page.content()).not.toContain('PRIVATE KEY');
  await page.click('button:has-text("Test connection")');
  await expect(page.locator('body')).toContainText('Connected as e2e-indexer@e2e.iam.gserviceaccount.com');

  // IndexNow key file is served at the site root.
  const keyUrl = await page.locator('a[href$=".txt"]').first().getAttribute('href');
  const key = keyUrl.match(/\/([a-zA-Z0-9-]+)\.txt$/)[1];
  const res = await request.get(keyUrl);
  expect(res.status()).toBe(200);
  expect((await res.text()).trim()).toBe(key);
  expect((await request.get(`${BASE}/0000000000000000aaaaaaaaaaaaaaaa.txt`)).status()).toBe(404);

  // Publishing queues and sends (after the response).
  await request.post(`${MOCK}/reset`);
  const title = `Indexing e2e ${Date.now()}`;
  await newPost(page, title);
  const slug = title.toLowerCase().replace(/\s+/g, '-');
  await expect.poll(async () => (await mockLog(request)).filter((r) => r.path === '/indexnow' && JSON.stringify(r.body).includes(slug)).length, { timeout: 15_000 }).toBe(1);
  await expect.poll(async () => (await mockLog(request)).filter((r) => r.path === '/publish' && r.body.url.includes(slug) && r.body.type === 'URL_UPDATED').length, { timeout: 15_000 }).toBe(1);
  const inBody = (await mockLog(request)).find((r) => r.path === '/indexnow').body;
  expect(inBody.key).toBe(key);
  expect(inBody.keyLocation).toBe(keyUrl);
  const tokenReq = (await mockLog(request)).find((r) => r.path === '/token');
  if (tokenReq) {
    expect(tokenReq.body.claims.iss).toBe('e2e-indexer@e2e.iam.gserviceaccount.com');
    expect(tokenReq.body.signed).toBe(true);
  }
  const editUrl = page.url();

  // Request indexing from the editor.
  await request.post(`${MOCK}/reset`);
  await page.goto(editUrl);
  const btn = page.locator('[data-seo-request]');
  await expect(btn).toBeEnabled();
  await btn.click();
  await expect(page.locator('[data-seo-request-out]')).toContainText('IndexNow: sent', { timeout: 15_000 });
  await expect(page.locator('[data-seo-request-out]')).toContainText('Google: sent');
  expect((await mockLog(request)).some((r) => r.path === '/indexnow' && JSON.stringify(r.body).includes(slug))).toBe(true);

  // The log lists the submissions.
  await page.goto(`${BASE}/admin/seo/instant`);
  await expect(page.locator('.seo-log tr[data-status="sent"]').first()).toBeVisible();

  // Noindexed site: nothing is sent, and the editor explains why.
  await setToggle(page, 'indexing', 'seo_noindex_site', true);
  await request.post(`${MOCK}/reset`);
  await newPost(page, `Hidden e2e ${Date.now()}`);
  await expect(page.locator('[data-seo-request]')).toBeDisabled();
  await expect(page.locator('[data-seo-request-out]')).toContainText('noindex');
  await page.waitForTimeout(1500);
  const quiet = (await mockLog(request)).filter((r) => r.path !== '/log');
  expect(quiet).toEqual([]);
  await setToggle(page, 'indexing', 'seo_noindex_site', false);

  // Clean up: engines off, key removed.
  await page.goto(`${BASE}/admin/seo/instant`);
  page.once('dialog', (d) => d.accept());
  await page.locator('button:has-text("Remove key")').click();
  await expect(page.locator('body')).toContainText('key was removed');
  await page.goto(`${BASE}/admin/seo/instant`);
  for (const key of ['seo_indexnow_enabled', 'seo_google_indexing']) {
    await page.locator(`input[type=checkbox][name="s[${key}]"]`).setChecked(false, { force: true });
  }
  await page.click('.save-bar button[type=submit]');
  await expect(page.locator('textarea[name="seo_idx[google_key]"]')).toBeVisible();
});
