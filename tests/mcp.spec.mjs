// End-to-end: an AI agent builds a small website through the MCP server only (design system, content model, template,
// designed and normal pages, items, menu, SEO, redirect, form), then the public site is checked for all of it.
// Also: a read-only key cannot write, sensitive settings are never readable, modules cannot be installed, deletions need confirm.
// Needs a running Slate site: SLATE_URL, SLATE_USER, SLATE_PASS. The forms part runs only when the Forms module is active.
import { test, expect } from '@playwright/test';

const BASE = process.env.SLATE_URL || 'http://127.0.0.1:8000';
const USER = process.env.SLATE_USER || 'citest';
const PASS = process.env.SLATE_PASS || 'ci-password-123';

test.setTimeout(180_000);
test.describe.configure({ mode: 'serial' });

const rid = Date.now().toString(36).slice(-6); // unique per run, so the spec can run again on the same site
const keys = {};
const made = {};

async function login(page) {
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=login]', USER);
  await page.fill('input[name=password]', PASS);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/admin\/?$/);
}

async function createKey(page, name, scope) {
  await page.goto(`${BASE}/admin/profile#api-keys`);
  const form = page.locator('#api-keys form.inline-form');
  await form.locator('input[name=name]').fill(name);
  await form.locator('select[name=scope]').selectOption(scope);
  await form.locator('button[type=submit]').click();
  const key = (await page.locator('#api-keys code.secret').innerText()).trim();
  expect(key).toMatch(/^slt_[a-f0-9]{10}_[A-Za-z0-9]{40}$/);
  return key;
}

let seq = 0;
/** One JSON-RPC call to the MCP endpoint. */
async function rpc(request, key, method, params = {}) {
  const res = await request.post(`${BASE}/api/mcp`, {
    headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json', Cookie: '' },
    data: { jsonrpc: '2.0', id: ++seq, method, params },
  });
  expect(res.status(), `${method} HTTP status`).toBe(200);
  return res.json();
}

/** Call a tool; returns { error, text, data } (error = JSON-RPC error, isError results come back as text). */
async function tool(request, name, args = {}, key = keys.write) {
  const r = await rpc(request, key, 'tools/call', { name, arguments: args });
  if (r.error) return { error: r.error };
  const text = r.result.content[0].text;
  return r.result.isError ? { isError: true, text } : { data: r.result.structuredContent, text };
}

async function ok(request, name, args = {}) {
  const r = await tool(request, name, args);
  expect(r.error, `${name}: ${JSON.stringify(r.error)}`).toBeUndefined();
  expect(r.isError, `${name}: ${r.text}`).toBeFalsy();
  return r.data;
}

/** A page as a visitor sees it (no session cookie). */
async function visit(request, url) {
  const res = await request.get(url, { headers: { Cookie: '' } });
  expect(res.status(), url).toBe(200);
  return res.text();
}

test('setup: switch the MCP server on and create a read & write and a read-only key', async ({ page }) => {
  await login(page);
  await page.goto(`${BASE}/admin/settings/integrations/mcp`);
  const box = page.locator('input[type=checkbox][name="s[mcp_enabled]"]');
  if (!(await box.isChecked())) {
    await box.setChecked(true, { force: true });
    await page.click('.save-bar button[type=submit]');
    await expect(page.locator('input[type=checkbox][name="s[mcp_enabled]"]')).toBeChecked();
  }
  keys.write = await createKey(page, `E2E MCP write ${rid}`, 'write');
  keys.read = await createKey(page, `E2E MCP read ${rid}`, 'read');
});

test('discover: initialize, tools, prompt, resource, capabilities', async ({ request }) => {
  const init = await rpc(request, keys.write, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } });
  expect(init.result.capabilities.prompts).toBeTruthy();
  expect(init.result.capabilities.resources).toBeTruthy();
  expect(init.result.instructions).toContain('build_site');

  const list = await rpc(request, keys.write, 'tools/list');
  const names = list.result.tools.map((t) => t.name);
  for (const n of ['get_site', 'create_content', 'write_page', 'update_design_system', 'save_post_type', 'save_taxonomy', 'save_field_group',
    'save_template', 'save_menu', 'set_seo_defaults', 'set_schema_mapping', 'add_redirects', 'get_settings', 'list_modules', 'edit_page']) {
    expect(names, n).toContain(n);
  }
  for (const t of list.result.tools) {
    expect(t.inputSchema.type, t.name).toBe('object');
    expect(t.description.length, t.name).toBeGreaterThan(20);
  }

  const prompts = await rpc(request, keys.write, 'prompts/list');
  expect(prompts.result.prompts.map((p) => p.name)).toContain('build_site');
  const prompt = await rpc(request, keys.write, 'prompts/get', { name: 'build_site', arguments: { brief: 'A garden studio' } });
  const guide = prompt.result.messages[0].content.text;
  expect(guide).toContain('Design system first');
  expect(guide).toContain('A garden studio');
  const res = await rpc(request, keys.write, 'resources/read', { uri: 'slate://guide/build-site' });
  expect(res.result.contents[0].text).toContain('How to build a site with Slate');

  const caps = await ok(request, 'describe_capabilities');
  expect(caps.key_scope).toBe('write');
  expect(caps.groups['Design system'].every((t) => t.available)).toBe(true);
  expect(caps.not_available_through_mcp.join(' ')).toMatch(/modules/i);
  const me = await ok(request, 'get_current_user');
  expect(me.username).toBe(USER);
  expect(JSON.stringify(me)).not.toMatch(/token_hash|password|email/);

  const site = await ok(request, 'get_site');
  expect(site.you.tools).toContain('save_template');
  expect(site.menu_locations.map((l) => l.location)).toContain('footer');
  made.formsActive = site.modules.some((m) => m.slug === 'slate-forms');
});

test('build: design system, content model, template, pages, items, menu, SEO, redirect', async ({ request }) => {
  // 1. Design system: a brand colour and a global class with a hover state.
  const ds = await ok(request, 'update_design_system', {
    colors: [{ id: `brand-${rid}`, name: 'Brand', value: '#2a7d4f' }],
    classes: [{ name: `card-${rid}`, css: 'padding:24px;border-radius:var(--radius);background:var(--color-background);', hover: 'box-shadow:0 8px 24px rgba(0,0,0,.15);' }],
  });
  expect(ds.colors.map((c) => c.id)).toContain(`brand-${rid}`);

  // 2. Content model: a post type with fields and a taxonomy.
  const type = `svc-${rid}`;
  made.type = type;
  await ok(request, 'save_post_type', { slug: type, singular: 'Service', plural: 'Services', rewrite: `services-${rid}` });
  made.tax = `area-${rid}`;
  await ok(request, 'save_taxonomy', { slug: made.tax, singular: 'Area', plural: 'Areas', post_types: [type], hierarchical: true });
  const fg = await ok(request, 'save_field_group', {
    title: 'Service details', post_types: [type], fields: [
      { name: 'price', label: 'Price', type: 'number' },
      { name: 'faq', label: 'FAQ', type: 'repeater', sub_fields: [{ name: 'q', label: 'Question', type: 'text' }, { name: 'a', label: 'Answer', type: 'textarea' }] },
    ],
  });
  made.group = fg.field_group.id;
  const model = await ok(request, 'get_content_model');
  expect(model.post_types.find((t) => t.slug === type).taxonomies).toContain(made.tax);

  // 3. A single template for the type, made active.
  const tpl = await ok(request, 'save_template', {
    kind: 'single', target: type, name: `Service ${rid}`, enabled: true, inside_theme: true,
    html: `<article class="section"><div class="container card-${rid}"><p class="eyebrow">Service ${rid}</p><h1>{{title}}</h1>`
      + '<p class="svc-price">From {{field:price}} EUR</p><div class="svc-body">{{{content}}}</div><p class="svc-areas">{{terms:' + made.tax + '|plain}}</p>'
      + '{{#each field:faq}}<details><summary>{{item:q}}</summary><p>{{item:a}}</p></details>{{/each}}</div></article>',
    css: '.svc-price{color:var(--color-brand-' + rid + ')}',
  });
  expect(tpl.active).toBe(true);
  made.template = tpl.id;

  // 4. Items with fields and terms (the term is created by name).
  const a = await ok(request, 'create_content', {
    type, title: `Garden design ${rid}`, content: '<p>We design calm, green gardens.</p>', publish: true,
    fields: { price: 490, faq: [{ q: 'How long does it take?', a: 'About two weeks.' }] }, terms: { [made.tax]: ['North'] },
    seo: { title: `Garden design in the North ${rid}`, description: 'Calm, green gardens designed for you.' },
  });
  expect(a.status).toBe('publish');
  made.itemA = a;
  const b = await ok(request, 'create_content', { type, title: `Lawn care ${rid}`, content: '<p>Weekly mowing.</p>', publish: true, fields: { price: 90 } });
  made.itemB = b;

  // 5. Pages: a designed page (with a live list of the services) and a normal page.
  const home = await ok(request, 'write_page', {
    title: `Studio ${rid}`, slug: `studio-${rid}`, publish: true,
    html: `<!doctype html><html><head><title>Studio</title><style>.hero-${rid}{padding:var(--section-space) 0;color:var(--color-brand-${rid})}</style></head><body>`
      + `<section class="hero-${rid}"><div class="container"><h1>Gardens that grow ${rid}</h1><p>Design and care for city gardens.</p></div></section>`
      + `<section><ul class="svc-list">{{#query type=${type} limit=5 orderby=title order=asc}}<li><a href="{{url}}">{{title}}</a></li>{{/query}}</ul></section></body></html>`,
  });
  made.home = home;
  const about = await ok(request, 'create_content', { type: 'page', title: `About ${rid}`, content: `<h2>About the studio</h2><p>Since 2009 ${rid}.</p>`, publish: true });
  made.about = about;

  // 6. A menu in the footer location (the previous footer menu is put back at the end).
  const menus = await ok(request, 'list_menus');
  made.footerBefore = menus.locations.find((l) => l.location === 'footer')?.menu_id || null;
  const menu = await ok(request, 'save_menu', {
    name: `Footer ${rid}`, location: 'footer', items: [
      { label: `Studio ${rid}`, type: 'post', object_id: home.id },
      { label: 'Services', type: 'custom', url: `/services-${rid}/`, children: [{ label: '', type: 'post', object_id: a.id }] },
      { label: `About ${rid}`, type: 'post', object_id: about.id },
    ],
  });
  made.menu = menu.menu.id;

  // 7. SEO: title template of the type, schema type and a mapping with a custom field and a fixed value.
  await ok(request, 'set_seo_defaults', { type, title_template: `%title% | Services ${rid} | %site%`, schema: 'Service' });
  const map = await ok(request, 'set_schema_mapping', { type, schema: 'Service', map: { name: '@title', 'provider.name': `=Green Studio ${rid}`, areaServed: `tax:${made.tax}` } });
  expect(map.mapping['provider.name']).toBe(`=Green Studio ${rid}`);
  await ok(request, 'update_content', { id: b.id, seo: { description: `Weekly lawn care ${rid}.` } });

  // 8. A redirect from an old address.
  await ok(request, 'add_redirects', { redirects: [{ from: `/old-services-${rid}/`, to: `/services-${rid}/` }] });
});

test('verify: the public site shows everything', async ({ request }) => {
  const rx = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  // Item page: template, fields, repeater, terms, design class + colour, SEO title, schema, footer menu.
  const item = await visit(request, made.itemA.url);
  expect(item).toContain(`Service ${rid}`);
  expect(item).toMatch(/class="svc-price">From 490 EUR/);
  expect(item).toContain('<summary>How long does it take?</summary>');
  expect(item).toContain('North');
  expect(item).toContain(`<title>Garden design in the North ${rid}</title>`);
  expect(item).toContain(`--color-brand-${rid}:#2a7d4f`);
  expect(item).toMatch(new RegExp(`\\.card-${rid}:hover\\{box-shadow:0 8px 24px`));
  expect(item).toContain(`"provider":{"@type":"Organization","name":"Green Studio ${rid}"}`);
  expect(item).toMatch(/"@type":"Service"/);
  expect(item).toMatch(new RegExp(`<a href="[^"]*/studio-${rid}/"[^>]*>Studio ${rid}</a>`));
  expect(item).toMatch(new RegExp(`<a href="[^"]*/about-${rid}/"[^>]*>About ${rid}</a>`));

  // Second item: the type's title template.
  const lawn = await visit(request, made.itemB.url);
  expect(lawn).toContain(`<title>Lawn care ${rid} | Services ${rid} | `);
  expect(lawn).toContain(`Weekly lawn care ${rid}.`);

  // Designed page: its own design, the design-system variables it uses, the live list of services.
  const home = await visit(request, made.home.url);
  expect(home).toContain(`Gardens that grow ${rid}`);
  expect(home).toContain(`color:var(--color-brand-${rid})`);
  expect(home).toContain(`--color-brand-${rid}:#2a7d4f`);
  expect(home).toMatch(new RegExp(`<a href="[^"]*/garden-design-${rid}/"[^>]*>Garden design ${rid}</a>`));
  expect(home).toMatch(new RegExp(`<a href="[^"]*/lawn-care-${rid}/"[^>]*>Lawn care ${rid}</a>`));

  // Normal page in the theme, with the footer menu.
  const about = await visit(request, made.about.url);
  expect(about).toContain(`Since 2009 ${rid}.`);
  expect(about).toMatch(/aria-label="Footer"/);
  expect(about).toMatch(new RegExp(`footer-menu[\\s\\S]*${rx(`Studio ${rid}`)}`));

  // Redirect.
  const r = await request.get(`${BASE}/old-services-${rid}/`, { maxRedirects: 0, headers: { Cookie: '' } });
  expect(r.status()).toBe(301);
  expect(r.headers()['location']).toContain(`/services-${rid}/`);

  // On-page editing through MCP: change the designed page's heading by id, one revision kept.
  const st = await ok(request, 'get_page_structure', { id: made.home.id });
  const h1 = st.elements.find((e) => e.tag === 'h1');
  expect(h1.text).toBe(`Gardens that grow ${rid}`);
  const ed = await ok(request, 'edit_page', { id: made.home.id, ops: [{ op: 'text', id: h1.id, html: `Gardens that <em>thrive</em> ${rid}` }] });
  expect(ed.revision_saved).toBe(true);
  expect(await visit(request, made.home.url)).toContain(`Gardens that <em>thrive</em> ${rid}`);
});

test('forms: create a form and place it on a page (Forms module)', async ({ request }) => {
  test.skip(!made.formsActive, 'The Forms module is not active on this site.');
  const form = await ok(request, 'save_form', {
    title: `Garden enquiry ${rid}`, fields: [
      { type: 'text', label: 'Your name', required: true },
      { type: 'email', label: 'Email', required: true },
      { type: 'select', label: 'Service', options: ['Design', 'Care'] },
      { type: 'textarea', label: 'Message' },
    ], settings: { submit_label: `Send enquiry ${rid}` },
  });
  expect(form.fields.length).toBe(4);
  made.form = form;
  const list = await ok(request, 'list_forms');
  expect(list.forms.map((f) => f.id)).toContain(form.id);
  const entries = await ok(request, 'list_form_entries', { form_id: form.id });
  expect(entries.counts).toBeDefined();
  expect(entries.entries).toBeUndefined(); // entries are personal data: not shared unless the site allows it

  const page = await ok(request, 'write_page', {
    title: `Contact ${rid}`, slug: `contact-${rid}`, publish: true,
    html: `<!doctype html><html><head><style>.c{max-width:640px;margin:auto}</style></head><body><main class="c"><h1>Contact ${rid}</h1>${form.embed.template_tag}</main></body></html>`,
  });
  const html = await visit(request, page.url);
  expect(html).toMatch(new RegExp(`data-slate-form="${form.id}"`));
  expect(html).toContain(`Send enquiry ${rid}`);
  expect(html).toContain('name="fields[your_name]"');
});

test('import a page from a link (sample site served locally)', async ({ request }) => {
  // CI serves tests/fixtures on 127.0.0.1:8191 and allows local addresses for imports (bin/ci-import); elsewhere this is skipped.
  const fixtures = (process.env.IMPORT_FIXTURES || 'http://127.0.0.1:8191').replace(/\/$/, '');
  const r = await tool(request, 'import_pages_from_url', { urls: [`${fixtures}/link-site/`], design: 'keep' });
  test.skip(Boolean(r.isError && /private|local|resolve|fetch|connect/i.test(r.text)), `No local sample site to import: ${r.text}`);
  expect(r.isError, r.text).toBeFalsy();
  expect(r.data.imported).toBeGreaterThan(0);
  const p = r.data.pages[0];
  expect(p.status).toBe('draft');
  made.imported = r.data.pages.map((x) => x.id);
  const st = await ok(request, 'get_page_structure', { id: p.id });
  expect(st.kind).toBe('designed page');
  expect(st.elements.length).toBeGreaterThan(0);
});

test('safety: read-only key, sensitive settings, no module installs, confirm for deletions', async ({ request }) => {
  // A read-only key can read but not write.
  const site = await tool(request, 'get_site', {}, keys.read);
  expect(site.data.you.key_scope).toBe('read');
  expect(site.data.you.tools).not.toContain('save_template');
  for (const [name, args] of [
    ['create_content', { type: 'post', title: 'Nope' }],
    ['update_design_system', { colors: [{ id: 'nope', value: '#000000' }] }],
    ['save_menu', { name: 'Nope' }],
    ['update_settings', { values: { site_title: 'Nope' } }],
    ['save_post_type', { slug: 'nope' }],
  ]) {
    const r = await tool(request, name, args, keys.read);
    expect(r.isError, name).toBe(true);
    expect(r.text, name).toMatch(/read-only/);
  }

  // Sensitive settings are neither readable nor writable.
  const all = await tool(request, 'get_settings', {});
  const dump = all.text;
  for (const secret of ['smtp_pass', 'smtp_user', 'ai_api_key', 'update_token', 'security_admin_slug', 'security_csp', 'api_cors', 'mcp_enabled',
    'code_head', 'webhooks', 'admin_email', 'seo_indexnow_key', 'seo_google_key']) {
    expect(dump, secret).not.toContain(`"${secret}`);
  }
  expect(dump).toContain('"site_title"');
  for (const key of ['ai_api_key', 'smtp_pass', 'security_admin_slug', 'update_token', 'code_head', 'mcp_enabled']) {
    const r = await tool(request, 'update_settings', { values: { [key]: 'x' } });
    expect(r.isError, key).toBe(true);
  }
  const seo = await ok(request, 'get_seo_settings');
  expect(JSON.stringify(seo)).not.toMatch(/indexnow_key|private_key|client_email/);

  // Modules: listing only, nothing that installs, activates or uploads code.
  const names = (await rpc(request, keys.write, 'tools/list')).result.tools.map((t) => t.name);
  expect(names.filter((n) => /install|activate|upload_module|module_upload|delete_module|update_module|user/.test(n) && n !== 'get_current_user')).toEqual([]);
  const mods = await ok(request, 'list_modules');
  expect(Array.isArray(mods.modules)).toBe(true);
  const inst = await tool(request, 'install_module', { slug: 'x' });
  expect(inst.error.code).toBe(-32602);

  // Deleting needs confirm: true.
  const del = await tool(request, 'delete_menu', { id: made.menu });
  expect(del.isError).toBe(true);
  expect(del.text).toMatch(/confirm/);
});

test('clean up through MCP (and check the deletions)', async ({ request }) => {
  await ok(request, 'delete_menu', { id: made.menu, confirm: true });
  if (made.footerBefore) {
    await ok(request, 'save_menu', { id: made.footerBefore, location: 'footer' });
  }
  await ok(request, 'delete_template', { id: made.template, confirm: true });
  await ok(request, 'delete_field_group', { id: made.group, confirm: true });
  await ok(request, 'delete_taxonomy', { slug: made.tax, confirm: true });
  await ok(request, 'remove_redirects', { from: [`/old-services-${rid}/`] });
  await ok(request, 'update_design_system', { remove_colors: [`brand-${rid}`], remove_classes: [`card-${rid}`] });
  await ok(request, 'delete_post_type', { slug: made.type, delete_content: true, confirm: true });
  const model = await ok(request, 'get_content_model');
  expect(model.post_types.map((t) => t.slug)).not.toContain(made.type);
  for (const p of [made.home, made.about]) {
    await ok(request, 'update_content', { id: p.id, status: 'draft' });
  }
  if (made.form) {
    await ok(request, 'save_form', { id: made.form.id, status: 'draft' });
  }
});
