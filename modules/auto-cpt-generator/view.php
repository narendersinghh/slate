<?php use Slate\Ai; ?>
<div class="acg card card-pad" data-acg data-base="<?= e(admin_url('m/auto-cpt')) ?>">
  <p class="muted" style="margin-top:0">Generate post types, taxonomies and custom fields in seconds. Review the result before anything is created.</p>
  <div class="tabs-pill" data-acg-tabs style="margin-bottom:18px">
    <button type="button" class="active" data-tab="wp">From a WordPress site</button>
    <button type="button" data-tab="wxr">From a WordPress export</button>
    <button type="button" data-tab="ai">Describe it (AI)</button>
    <button type="button" data-tab="json">JSON</button>
  </div>

  <div data-panel="wp" class="stack-sm">
    <div class="grid-form">
      <label class="field span-2"><span>WordPress site URL</span><input class="input" data-in="url" placeholder="https://example.com"></label>
      <label class="field"><span>Username <small class="muted">(optional)</small></span><input class="input" data-in="user" autocomplete="off"></label>
      <label class="field"><span>Application password <small class="muted">(optional)</small></span><input class="input" type="password" data-in="password" autocomplete="new-password"></label>
    </div>
    <small class="help">Without credentials only public post types are read. Custom fields are detected from ACF (enable “Show in REST API” on field groups) and registered meta.</small>
    <div><button type="button" class="btn btn-primary" data-run="scan-wp"><?= icon('search', 15) ?> Analyse site</button></div>
  </div>

  <div data-panel="wxr" class="stack-sm" hidden>
    <label class="field"><span>WordPress export file (Tools → Export → All content)</span><input class="input" type="file" accept=".xml" data-file></label>
    <small class="help">Detects every post type, taxonomy and ACF field, including repeaters.</small>
    <div><button type="button" class="btn btn-primary" data-run="scan-wxr"><?= icon('upload', 15) ?> Analyse export</button></div>
  </div>

  <div data-panel="ai" class="stack-sm" hidden>
    <label class="field"><span>Describe the website or the content you need</span>
      <textarea class="input" rows="4" data-in="description" placeholder="A real-estate agency: property listings with price, bedrooms, bathrooms, area, gallery and location; agents with phone and email; neighbourhood guides."></textarea></label>
    <?php if (!Ai::enabled()): ?><small class="help">Configure a provider under <a href="<?= e(admin_url('settings/ai/provider')) ?>">Settings → AI</a> to use this.</small><?php endif; ?>
    <div><button type="button" class="btn btn-ai" data-run="describe"<?= Ai::enabled() ? '' : ' disabled' ?>><?= icon('sparkles', 15) ?> Generate model</button></div>
  </div>

  <div data-panel="json" class="stack-sm" hidden>
    <small class="help">Paste or edit a spec: <code>{"post_types":[…],"taxonomies":[…],"field_groups":[…]}</code></small>
  </div>

  <div data-result hidden style="margin-top:24px">
    <h2 style="margin-bottom:12px">Review</h2>
    <div data-summary class="card-grid"></div>
    <details style="margin:4px 0 14px"><summary class="muted" style="cursor:pointer">Edit as JSON</summary>
      <textarea class="input code" rows="14" data-spec style="margin-top:10px"></textarea></details>
    <button type="button" class="btn btn-primary" data-apply><?= icon('check', 15) ?> Create content model</button>
  </div>
  <ul class="list list-compact" data-log style="margin-top:16px"></ul>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('[data-acg]');
  const { api, toast, esc } = window.Slate;
  const base = root.dataset.base;
  const post = async (path, body, isForm) => {
    const res = await fetch(base + path, { method: 'POST', credentials: 'same-origin', body: isForm ? body : JSON.stringify(body),
      headers: Object.assign({ 'X-CSRF-Token': SLATE.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, isForm ? {} : { 'Content-Type': 'application/json' }) });
    try { return await res.json(); } catch (e) { return { ok: false, error: 'Server error (' + res.status + ')' }; }
  };
  root.querySelectorAll('[data-acg-tabs] button').forEach((b) => b.addEventListener('click', () => {
    root.querySelectorAll('[data-acg-tabs] button').forEach((x) => x.classList.toggle('active', x === b));
    root.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== b.dataset.tab; });
    if (b.dataset.tab === 'json') { root.querySelector('[data-result]').hidden = false; root.querySelector('details').open = true; }
  }));
  const val = (k) => root.querySelector('[data-in="' + k + '"]')?.value || '';
  const show = (spec) => {
    root.querySelector('[data-spec]').value = JSON.stringify(spec, null, 2);
    const card = (title, rows) => '<div class="card card-pad"><h3 style="margin-bottom:8px">' + esc(title) + '</h3>' + (rows.length ? '<ul class="list list-compact" style="padding:0">' + rows.join('') + '</ul>' : '<p class="muted">None</p>') + '</div>';
    const li = (a, b) => '<li><span class="list-main"><span><strong>' + esc(a) + '</strong><small>' + esc(b) + '</small></span></span></li>';
    root.querySelector('[data-summary]').innerHTML =
      card('Post types', (spec.post_types || []).map((t) => li(t.plural, '/' + (t.rewrite || t.slug) + '/ · ' + t.slug))) +
      card('Taxonomies', (spec.taxonomies || []).map((t) => li(t.plural, (t.post_types || []).join(', ') + (t.builtin ? ' · built-in' : '')))) +
      card('Custom fields', (spec.field_groups || []).map((g) => li(g.title, (g.fields || []).map((f) => f.name + ':' + f.type).join(', '))));
    root.querySelector('[data-result]').hidden = false;
  };
  root.querySelectorAll('[data-run]').forEach((b) => b.addEventListener('click', async () => {
    b.classList.add('is-loading');
    let r;
    if (b.dataset.run === 'scan-wxr') {
      const f = root.querySelector('[data-file]').files[0];
      if (!f) { b.classList.remove('is-loading'); return toast('Choose an export file first', 'error'); }
      const fd = new FormData(); fd.append('file', f); r = await post('/scan-wxr', fd, true);
    } else if (b.dataset.run === 'describe') r = await post('/describe', { description: val('description') });
    else r = await post('/scan-wp', { url: val('url'), user: val('user'), password: val('password') });
    b.classList.remove('is-loading');
    r.ok ? show(r.spec) : toast(r.error || 'Failed', 'error', 7000);
  }));
  root.querySelector('[data-apply]').addEventListener('click', async (e) => {
    let spec;
    try { spec = JSON.parse(root.querySelector('[data-spec]').value || '{}'); } catch (err) { return toast('The JSON is not valid', 'error'); }
    const btn = e.currentTarget;
    btn.classList.add('is-loading');
    const r = await post('/apply', { spec });
    btn.classList.remove('is-loading');
    if (!r.ok) return toast(r.error, 'error', 7000);
    root.querySelector('[data-log]').innerHTML = r.log.map((l) => '<li><span class="list-main"><span class="dot dot-info"></span><span>' + esc(l) + '</span></span></li>').join('');
    toast('Content model created. Reload to see new items in the sidebar.');
  });
});
</script>
