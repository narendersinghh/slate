<?php
use Slate\ContentModel;
use Slate\Templates;

$t = $tpl;
$fields = [];
$taxes = [];
if (in_array($t['kind'], ['single', 'archive'], true)) {
    $fields = ContentModel::fieldsFor('post', $t['target']);
    $taxes = ContentModel::taxonomiesFor($t['target']);
}
$preview = admin_template_preview_url($t);
$tag = static fn(string $code, string $label = '') => '<button type="button" class="tpl-tag" data-insert="' . e($code) . '" title="' . e($label ?: $code) . '"><code>' . e($code) . '</code>' . ($label ? '<small>' . e($label) . '</small>' : '') . '</button>';
?>
<form method="post" action="<?= e(admin_url('templates/save')) ?>" data-tpl-form>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($t['id']) ?>">
  <div class="page-head page-head-sticky">
    <div class="page-head-left">
      <a class="icon-btn" href="<?= e(admin_url('templates')) ?>" aria-label="Back to templates"><?= icon('chevron-left') ?></a>
      <div><small class="muted">Templates · <?= e(Templates::KINDS[$t['kind']] ?? $t['kind']) ?></small><h1 class="h-compact"><?= e($t['name'] ?: 'Template') ?></h1></div>
      <span class="save-state muted" data-save-state></span>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="<?= e($preview ?: '#') ?>" target="_blank" rel="noopener" data-preview<?= $preview ? '' : ' hidden' ?>><?= icon('eye', 16) ?> Preview</a>
      <label class="switch-row btn btn-ghost" style="gap:8px"><input type="checkbox" class="switch" name="enabled" value="1"<?= $t['enabled'] ? ' checked' : '' ?>> Active</label>
      <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Save</button>
    </div>
  </div>

  <div class="editor-layout">
    <div class="editor-main stack">
      <section class="card">
        <div class="card-pad grid-form">
          <label class="field"><span>Name</span><input class="input" name="name" value="<?= e($t['name']) ?>"></label>
          <div class="grid-form" style="gap:10px">
            <label class="field"><span>Type</span><select class="input" name="kind"><?php foreach (Templates::KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $t['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Applies to</span><select class="input" name="target">
              <optgroup label="Post types"><?php foreach (ContentModel::types() as $slug => $ty): ?><option value="<?= e($slug) ?>"<?= $t['target'] === $slug ? ' selected' : '' ?>><?= e($ty['plural']) ?></option><?php endforeach; ?></optgroup>
              <optgroup label="Taxonomies"><?php foreach (ContentModel::taxonomies() as $slug => $tx): ?><option value="<?= e($slug) ?>"<?= $t['target'] === $slug ? ' selected' : '' ?>><?= e($tx['plural']) ?></option><?php endforeach; ?></optgroup>
              <optgroup label="Special"><?php foreach (Templates::SPECIAL as $k => $l): ?><option value="<?= e($k) ?>"<?= $t['target'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></optgroup>
            </select></label>
          </div>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><h2><?= icon('code', 16) ?> HTML</h2>
          <div class="page-actions"><label class="switch-row small"><input type="checkbox" class="switch" name="wrap" value="1"<?= $t['wrap'] ? ' checked' : '' ?>> Use the site header &amp; footer</label>
          <button type="button" class="btn btn-sm btn-ghost" data-starter="<?= e(admin_url('templates/new?kind=' . $t['kind'] . '&target=' . $t['target'])) ?>">Reset to starter</button></div></div>
        <textarea class="input code tpl-code" name="html" rows="26" spellcheck="false" data-code><?= e($t['html']) ?></textarea>
      </section>
      <section class="card">
        <div class="card-head"><h2>CSS <small class="muted">(only loaded with this template — design-system variables like <code>var(--color-primary)</code> work here)</small></h2></div>
        <textarea class="input code tpl-code" name="css" rows="10" spellcheck="false" placeholder=".field-tagline { font: var(--type-h4); color: var(--color-primary); }"><?= e($t['css']) ?></textarea>
      </section>
    </div>

    <aside class="editor-side">
      <section class="card">
        <div class="card-head"><h2>Insert</h2></div>
        <div class="card-pad stack-sm tpl-tags">
          <strong class="small-label">Item</strong>
          <?= $tag('{{title}}', 'Title') . $tag('{{{content}}}', 'Content (HTML)') . $tag('{{excerpt}}', 'Excerpt') . $tag('{{url}}', 'Link') . $tag('{{date}}', 'Date') . $tag('{{image}}', 'Featured image') . $tag('{{edit:title}}', 'Title, editable on page') . $tag('{{edit:content}}', 'Content, editable on page') ?>
          <?php if ($fields): ?>
            <strong class="small-label">Custom fields</strong>
            <?php foreach ($fields as $f):
                echo match ($f['type']) {
                    'richtext' => $tag('{{{field:' . $f['name'] . '}}}', $f['label']),
                    'image' => $tag('{{field:' . $f['name'] . '|image}}', $f['label']),
                    'date' => $tag('{{field:' . $f['name'] . '|date}}', $f['label']),
                    'repeater' => $tag("{{#each field:{$f['name']}}}\n  " . implode(' ', array_map(fn($sf) => ($sf['type'] === 'richtext' ? '{{{item:' . $sf['name'] . '}}}' : '{{item:' . $sf['name'] . '}}'), $f['sub_fields'] ?? [])) . "\n{{/each}}", $f['label'] . ' (repeat)'),
                    'relation' => $tag("{{#each related:{$f['name']}}}<a href=\"{{url}}\">{{title}}</a>{{/each}}", $f['label'] . ' (linked items)'),
                    default => $tag('{{field:' . $f['name'] . '}}', $f['label']),
                };
            endforeach; ?>
          <?php endif; ?>
          <?php if ($taxes): ?>
            <strong class="small-label">Taxonomies</strong>
            <?php foreach ($taxes as $tx => $d): echo $tag('{{{terms:' . $tx . '}}}', $d['plural'] . ' (links)'); endforeach; ?>
          <?php endif; ?>
          <strong class="small-label">Logic &amp; loops</strong>
          <?= $tag("{{#if field:name}}\n  …\n{{else}}\n  …\n{{/if}}", 'If / else') . $tag("{{#posts}}\n  <a href=\"{{url}}\">{{title}}</a>\n{{else}}\n  Nothing yet.\n{{/posts}}", 'Items of this listing') . $tag("{{#query type=post limit=3}}\n  <a href=\"{{url}}\">{{title}}</a>\n{{/query}}", 'Latest items of any type') . $tag('{{{pagination}}}', 'Pagination') ?>
          <strong class="small-label">Site</strong>
          <?= $tag('{{{breadcrumbs}}}', 'Breadcrumbs') . $tag('{{{menu:primary}}}', 'Menu') . $tag('{{site.name}}', 'Site name') . $tag('{{archive.title}}', 'Listing title') . $tag('{{year}}', 'Current year') ?>
          <p class="help">Double braces escape text; triple braces <code>{{{ }}}</code> output HTML. Filters: <code>|image</code> <code>|date:F j, Y</code> <code>|url</code> <code>|nl2br</code> <code>|embed</code>.</p>
        </div>
      </section>
      <?php if ($t['id']): ?>
      <section class="card"><div class="card-pad">
        <button class="btn btn-ghost btn-danger btn-sm" type="submit" formaction="<?= e(admin_url('templates/' . $t['id'] . '/delete')) ?>" data-confirm="Delete this template? Pages fall back to the theme design.">Delete template</button>
      </div></section>
      <?php endif; ?>
    </aside>
  </div>
</form>
<style>
.tpl-code{min-height:420px;border:0;border-radius:0 0 var(--radius-lg) var(--radius-lg);box-shadow:none;font-size:13px;line-height:1.65;tab-size:2}
.tpl-tags .tpl-tag{display:flex;flex-direction:column;align-items:flex-start;gap:2px;width:100%;text-align:left;padding:7px 10px;border:1px solid var(--border);border-radius:9px;background:var(--surface);cursor:pointer;font:inherit;color:var(--text)}
.tpl-tags .tpl-tag:hover{border-color:var(--o-400);background:var(--accent-soft)}
.tpl-tags .tpl-tag code{background:none;padding:0;white-space:pre-wrap;word-break:break-word;font-size:11.5px}
.tpl-tags .tpl-tag small{color:var(--muted);font-size:11.5px}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('[data-tpl-form]');
  const code = form.querySelector('[data-code]');
  let last = code;
  form.querySelectorAll('textarea.tpl-code').forEach((ta) => {
    ta.addEventListener('focus', () => { last = ta; });
    ta.addEventListener('keydown', (e) => {
      if (e.key === 'Tab') { e.preventDefault(); const s = ta.selectionStart; ta.setRangeText('  ', s, ta.selectionEnd, 'end'); }
    });
  });
  form.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
    const ta = last || code; ta.focus();
    ta.setRangeText(b.dataset.insert, ta.selectionStart, ta.selectionEnd, 'end');
  }));
  form.querySelector('[data-starter]').addEventListener('click', async (e) => {
    if (!confirm('Replace the HTML with a fresh starter template that shows every field?')) return;
    const kind = form.kind.value, target = form.target.value;
    const res = await fetch(SLATE.admin + '/templates/new?kind=' + encodeURIComponent(kind) + '&target=' + encodeURIComponent(target), { credentials: 'same-origin' });
    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
    const ta = doc.querySelector('[data-code]');
    if (ta) code.value = ta.value;
  });
  form.addEventListener('submit', async (e) => {
    const btn = e.submitter;
    if (btn && btn.getAttribute('formaction')) return;
    e.preventDefault();
    btn?.classList.add('is-loading');
    const res = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    let d; try { d = await res.json(); } catch (err) { d = { ok: false, error: 'Save failed' }; }
    btn?.classList.remove('is-loading');
    if (!d.ok) return Slate.toast(d.error || 'Save failed', 'error');
    form.id.value = d.id;
    if (location.pathname.endsWith('/new')) history.replaceState(null, '', d.edit_url);
    const pv = document.querySelector('[data-preview]');
    if (d.preview) { pv.href = d.preview; pv.hidden = false; }
    document.querySelector('[data-save-state]').textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    Slate.toast(d.message);
  });
});
</script>
