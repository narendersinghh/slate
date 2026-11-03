<?php
use Slate\Options;
use SlateModules\WpImport\Importer;

$job = Importer::job();
$conn = Options::get('wpi_conn') ?: [];
?>
<div class="wpi" data-wpi data-base="<?= e(admin_url('m/wp-import')) ?>">
  <p class="muted" style="margin-top:0">Move a WordPress site to Slate: post types, taxonomies, ACF fields, content, media (optimized to WebP), SEO meta, menus, settings and URLs — plus a mirror of the theme / Elementor design. Running it again updates what was imported; it never duplicates.</p>

  <section class="card" data-step="connect">
    <div class="card-head"><h2>1 · Connect</h2></div>
    <div class="card-pad stack-sm">
      <div class="tabs-pill" data-src-tabs style="align-self:flex-start">
        <button type="button" class="active" data-src="rest">Live site (recommended)</button>
        <button type="button" data-src="wxr">Export file (.xml)</button>
      </div>
      <div data-src-panel="rest" class="stack-sm">
        <div class="grid-form">
          <label class="field span-2"><span>WordPress site URL</span><input class="input" data-in="url" value="<?= e($conn['url'] ?? '') ?>" placeholder="https://example.com"></label>
          <label class="field"><span>Administrator username</span><input class="input" data-in="user" value="<?= e($conn['user'] ?? '') ?>" autocomplete="off"></label>
          <label class="field"><span>Application password</span><input class="input" type="password" data-in="password" autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"></label>
        </div>
        <small class="help">In WordPress: <strong>Users → Profile → Application Passwords</strong> → add “Slate” → copy. Admin access lets Slate read drafts, menus and settings. Never use your login password here.</small>
      </div>
      <div data-src-panel="wxr" class="stack-sm" hidden>
        <label class="field"><span>WordPress export (Tools → Export → All content)</span><input class="input" type="file" accept=".xml" data-file></label>
        <small class="help">Media files are downloaded from the original site, so it must still be online during the import.</small>
      </div>
      <div><button type="button" class="btn btn-primary" data-connect><?= icon('search', 15) ?> Analyse site</button></div>
    </div>
  </section>

  <section class="card" data-step="review" hidden>
    <div class="card-head"><h2>2 · Review &amp; options</h2><span class="muted" data-site></span></div>
    <div class="card-pad stack">
      <div class="stat-grid" data-overview style="margin:0"></div>
      <div>
        <strong class="small-label">Content to import</strong>
        <div class="choice-grid" data-types style="margin-top:8px"></div>
      </div>
      <div>
        <strong class="small-label">Options</strong>
        <div class="choice-grid" style="margin-top:8px">
          <?php foreach ([
              'model' => ['Create post types, taxonomies & custom fields', true], 'media' => ['Import media (auto-optimized to WebP)', true],
              'seo' => ['SEO titles, descriptions, robots, canonical, OG', true], 'menus' => ['Menus', true], 'settings' => ['Site title, tagline, timezone, homepage', true],
              'redirects' => ['301 redirects for any URL that changes', true], 'design' => ['Mirror the design (theme + Elementor CSS/JS, header, footer)', true],
              'activate_theme' => ['Activate the mirrored theme when done', true], 'drafts' => ['Include drafts & private content', true],
          ] as $k => [$label, $on]): ?>
            <label class="choice-card"><input type="checkbox" data-opt="<?= e($k) ?>"<?= $on ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="row-between">
        <span class="muted small">Large sites take a while — keep this tab open. You can close it and resume later.</span>
        <button type="button" class="btn btn-primary btn-lg" data-start><?= icon('download', 16) ?> Start import</button>
      </div>
    </div>
  </section>

  <section class="card" data-step="run"<?= $job ? '' : ' hidden' ?>>
    <div class="card-head"><h2>3 · Import</h2><span class="muted" data-label><?= $job ? ($job['finished_at'] ? 'Finished ' . e(time_ago($job['finished_at'])) : 'Paused — click Resume') : '' ?></span></div>
    <div class="card-pad stack-sm">
      <div class="wpi-bar"><i data-bar style="width:<?= $job && $job['finished_at'] ? 100 : 0 ?>%"></i></div>
      <div class="type-tags" data-stats></div>
      <pre class="wpi-log" data-log><?= e(implode("\n", array_slice($job['log'] ?? [], -40))) ?></pre>
      <div class="row-between">
        <div class="page-actions">
          <?php if ($job && !$job['finished_at']): ?><button type="button" class="btn btn-primary" data-resume>Resume</button><?php endif; ?>
          <a class="btn" href="<?= e(url()) ?>" target="_blank">View site</a>
        </div>
        <div class="page-actions">
          <button type="button" class="btn btn-ghost" data-reset>Clear import history</button>
          <button type="button" class="btn btn-ghost btn-danger" data-reset-delete>Delete imported content</button>
        </div>
      </div>
    </div>
  </section>
</div>
<style>
.wpi-bar{height:10px;border-radius:99px;background:var(--surface-3);overflow:hidden}
.wpi-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--o-400),var(--o-600));border-radius:99px;transition:width .4s}
.wpi-log{max-height:300px;overflow:auto;font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const root = document.querySelector('[data-wpi]');
  const { toast, esc } = window.Slate;
  const base = root.dataset.base;
  let src = 'rest', running = false;
  const post = async (path, body, isForm) => {
    try {
      const res = await fetch(base + path, { method: 'POST', credentials: 'same-origin', body: isForm ? body : JSON.stringify(body || {}),
        headers: Object.assign({ 'X-CSRF-Token': SLATE.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, isForm ? {} : { 'Content-Type': 'application/json' }) });
      const t = await res.text();
      try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Server error (' + res.status + '): ' + t.replace(/<[^>]+>/g, ' ').slice(0, 300) }; }
    } catch (e) { return { ok: false, error: 'Network error — ' + e.message, network: true }; }
  };
  const $ = (s) => root.querySelector(s);
  root.querySelectorAll('[data-src]').forEach((b) => b.addEventListener('click', () => {
    src = b.dataset.src;
    root.querySelectorAll('[data-src]').forEach((x) => x.classList.toggle('active', x === b));
    root.querySelectorAll('[data-src-panel]').forEach((p) => { p.hidden = p.dataset.srcPanel !== src; });
  }));
  const stat = (label, value, hint) => '<div class="stat"><span class="stat-label">' + esc(label) + '</span><span class="stat-value" style="font-size:34px;margin-top:6px">' + esc(value) + '</span><span class="stat-hint">' + esc(hint || '') + '</span></div>';
  const showScan = (scan) => {
    const s = scan.summary, c = scan.counts;
    $('[data-site]').textContent = (s.site || '') + ' · ' + s.url;
    let html = '';
    Object.entries(c.types || {}).forEach(([wp, t]) => { html += stat(wp, t.total, '→ ' + t.slug); });
    html += stat('Media', s.media ?? '—', 'files');
    html += stat('Detected', [s.elementor ? 'Elementor' : null, s.seo || null, s.acf ? 'ACF' : null].filter(Boolean).join(' · ') || '—', s.auth ? 'Signed in as ' + (s.user || 'admin') : 'Public access only');
    $('[data-overview]').innerHTML = html;
    $('[data-types]').innerHTML = Object.entries(c.types || {}).map(([wp, t]) => '<label class="choice-card"><input type="checkbox" data-type="' + esc(wp) + '" checked><span>' + esc(wp) + ' <small class="muted">(' + t.total + ')</small></span></label>').join('');
    $('[data-step="review"]').hidden = false;
    $('[data-step="review"]').scrollIntoView({ behavior: 'smooth' });
  };
  $('[data-connect]').addEventListener('click', async (e) => {
    const b = e.currentTarget; b.classList.add('is-loading');
    let r;
    if (src === 'wxr') {
      const f = $('[data-file]').files[0];
      if (!f) { b.classList.remove('is-loading'); return toast('Choose the export file', 'error'); }
      const fd = new FormData(); fd.append('file', f); r = await post('/upload', fd, true);
    } else {
      r = await post('/connect', { url: $('[data-in="url"]').value, user: $('[data-in="user"]').value, password: $('[data-in="password"]').value });
    }
    b.classList.remove('is-loading');
    r.ok ? showScan(r.scan) : toast(r.error || 'Failed', 'error', 9000);
  });
  const render = (r) => {
    $('[data-bar]').style.width = r.progress + '%';
    $('[data-label]').textContent = r.done ? 'Finished ✓' : r.step + ' — ' + r.progress + '%';
    $('[data-stats]').innerHTML = Object.entries(r.stats || {}).map(([k, v]) => '<span class="pill">' + esc(k) + ': ' + v + '</span>').join('');
    const log = $('[data-log]'); log.textContent = (r.log || []).join('\n'); log.scrollTop = log.scrollHeight;
  };
  const loop = async () => {
    if (running) return;
    running = true;
    $('[data-step="run"]').hidden = false;
    let fails = 0;
    while (running) {
      const r = await post('/step');
      if (!r.ok) {
        if (++fails > 4) { toast(r.error || 'Import paused', 'error', 9000); running = false; break; }
        await new Promise((res) => setTimeout(res, 2000 * fails));
        continue;
      }
      fails = 0;
      render(r);
      if (r.done) { running = false; toast('Import finished!'); break; }
    }
  };
  $('[data-start]').addEventListener('click', async (e) => {
    const options = { types: [...root.querySelectorAll('[data-type]:checked')].map((c) => c.dataset.type) };
    root.querySelectorAll('[data-opt]').forEach((c) => { options[c.dataset.opt] = c.checked; });
    if (!options.types.length) return toast('Select at least one content type', 'error');
    const btn = e.currentTarget;
    btn.classList.add('is-loading');
    const r = await post('/start', { options });
    btn.classList.remove('is-loading');
    if (!r.ok) return toast(r.error, 'error', 8000);
    $('[data-step="run"]').scrollIntoView({ behavior: 'smooth' });
    loop();
  });
  $('[data-resume]')?.addEventListener('click', loop);
  $('[data-reset]').addEventListener('click', async () => { if (confirm('Forget the import history? Imported content stays.')) { await post('/reset', {}); location.reload(); } });
  $('[data-reset-delete]').addEventListener('click', async () => { if (confirm('Permanently delete ALL content, media and terms that were imported?')) { await post('/reset', { delete: true }); location.reload(); } });
});
</script>
