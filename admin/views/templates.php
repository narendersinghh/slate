<?php
/** Templates: one card per page type of the site, showing what designs it today and its versions. */
use Slate\AdminUi;
use Slate\ContentModel;
use Slate\Templates;

$byKey = [];
foreach ($templates as $t) {
    $byKey[$t['kind'] . '/' . $t['target']][] = $t;
}
$groups = [];
foreach (ContentModel::types() as $slug => $type) {
    if (empty($type['public'])) {
        continue;
    }
    $base = trim((string)($type['rewrite'] ?: ''), '/');
    $groups['Item pages'][] = ['single', $slug, $slug === 'page' ? 'Pages' : $type['singular'] . ' page', 'Each ' . strtolower($type['singular']) . ($base !== '' ? ' at /' . $base . '/…' : ''), $type['icon'] ?? 'file'];
    if ($slug !== 'page' && (!empty($type['has_archive']) || $slug === 'post')) {
        $groups['Listings'][] = ['archive', $slug, $type['plural'] . ' listing', $slug === 'post' ? 'The blog: all posts, newest first' : 'All ' . strtolower($type['plural']) . ' on one page', 'list'];
    }
}
foreach (ContentModel::taxonomies() as $slug => $tax) {
    if (!empty($tax['public'])) {
        $groups['Categories and tags'][] = ['term', $slug, $tax['singular'] . ' page', 'Items in one ' . strtolower($tax['singular']) . ' (/' . trim((string)$tax['rewrite'], '/') . '/…)', 'tag'];
    }
}
foreach (Templates::SPECIAL as $k => $label) {
    $groups['Special pages'][] = ['special', $k, $label, $k === '404' ? 'Shown when an address does not exist' : 'Results of a site search', $k === '404' ? 'alert' : 'search'];
}
$active = count(array_filter($templates, static fn($t) => !empty($t['enabled'])));
$ai = \Slate\Ai::enabled() && \Slate\Auth::can('use_ai');
echo AdminUi::header('Templates', 'How each kind of page on your site looks. Without a template, the theme decides.', 'code');
?>
<div class="tpl-top">
  <div class="kpis kpis-inline">
    <div class="kpi"><span>Active templates</span><strong><?= $active ?></strong></div>
    <div class="kpi"><span>Drafts</span><strong><?= count($templates) - $active ?></strong></div>
    <div class="kpi"><span>Page types</span><strong><?= array_sum(array_map('count', $groups)) ?></strong></div>
  </div>
</div>
<?php $gkey = static fn(string $g): string => strtolower(preg_replace('/[^a-z]+/i', '-', $g)); ?>
<div class="mod-toolbar tpl-toolbar">
  <div class="mod-seg" role="tablist" aria-label="Page types" data-tpl-tabs>
    <?php foreach ($groups as $group => $list): $inUse = 0; foreach ($list as [$k, $t]) { foreach ($byKey["$k/$t"] ?? [] as $x) { if (!empty($x['enabled'])) { $inUse++; break; } } } ?>
      <button type="button" role="tab" data-tab="<?= e($gkey($group)) ?>" title="<?= $inUse ?> of <?= count($list) ?> use a template"><?= e($group) ?> <span><?= count($list) ?></span></button>
    <?php endforeach; ?>
    <button type="button" role="tab" data-tab="all">All <span><?= array_sum(array_map('count', $groups)) ?></span></button>
  </div>
  <label class="tpl-find"><?= icon('search', 15) ?><input class="input" type="search" placeholder="Find a page type" data-tpl-find aria-label="Find a page type"></label>
</div>

<?php foreach ($groups as $group => $list): ?>
<section class="tpl-group" data-tpl-group="<?= e($gkey($group)) ?>" role="tabpanel">
  <h2 class="section-title tpl-group-title"><?= e($group) ?></h2>
  <div class="tpl-grid">
    <?php foreach ($list as [$kind, $target, $label, $desc, $ic]):
        $existing = $byKey["$kind/$target"] ?? [];
        usort($existing, static fn($a, $b) => [(int)!$a['enabled'], $b['updated_at'] ?? ''] <=> [(int)!$b['enabled'], $a['updated_at'] ?? '']);
        $on = array_values(array_filter($existing, static fn($t) => !empty($t['enabled'])))[0] ?? null;
        $new = admin_url('templates/new?kind=' . $kind . '&target=' . $target);
    ?>
    <article class="tpl-card<?= $on ? ' is-active' : '' ?>" data-tpl-card data-search="<?= e(strtolower($label . ' ' . $desc . ' ' . $target)) ?>">
      <div class="tpl-card-head">
        <span class="tpl-card-icon"><?= icon($ic, 17) ?></span>
        <div class="tpl-card-title"><strong><?= e($label) ?></strong><small class="muted"><?= e($desc) ?></small></div>
        <?php if ($on): ?><span class="pill pill-success">Template</span><?php else: ?><span class="pill">Theme</span><?php endif; ?>
      </div>
      <?php if ($existing): ?>
      <ul class="tpl-versions">
        <?php foreach ($existing as $t): $pv = admin_template_preview_url($t); ?>
          <li>
            <a class="tpl-version" href="<?= e(admin_url('templates/' . $t['id'])) ?>">
              <span class="tpl-dot<?= $t['enabled'] ? ' is-on' : '' ?>" aria-hidden="true"></span>
              <span class="tpl-version-name"><?= e($t['name']) ?></span>
              <small class="muted"><?= $t['enabled'] ? 'In use' : 'Draft' ?><?= !empty($t['updated_at']) ? ' · ' . e(time_ago($t['updated_at'])) : '' ?></small>
            </a>
            <?php if ($pv): ?><a class="icon-btn" href="<?= e($pv) ?>" target="_blank" rel="noopener" title="Preview" aria-label="Preview <?= e($t['name']) ?>"><?= icon('eye', 15) ?></a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p class="tpl-empty muted small">Uses the theme's design.</p>
      <?php endif; ?>
      <div class="tpl-card-foot">
        <a class="btn btn-sm<?= $existing ? '' : ' btn-primary' ?>" href="<?= e($new) ?>"><?= icon('plus', 14) ?> <?= $existing ? 'New version' : 'Create template' ?></a>
        <?php if ($ai): ?><button type="button" class="btn btn-sm btn-ghost" data-goraiya-ask="<?= e('Design a template for the ' . $label . ' (' . $kind . ' / ' . $target . ') in my site\'s style, using its fields. Keep it as a draft.') ?>"><?= icon('sparrow', 14) ?> Ask Goraiya</button><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<p class="muted small tpl-none" hidden data-tpl-none>No page type matches.</p>
<script>
(function () {
  const find = document.querySelector('[data-tpl-find]');
  const tabs = [...document.querySelectorAll('[data-tpl-tabs] [data-tab]')];
  const groups = [...document.querySelectorAll('[data-tpl-group]')];
  let tab = (location.hash.slice(1) || '');
  if (!tabs.some((b) => b.dataset.tab === tab)) tab = tabs[0] ? tabs[0].dataset.tab : 'all';
  const show = () => {
    const q = find.value.trim().toLowerCase();
    const active = q ? 'all' : tab; // searching looks through every group
    tabs.forEach((b) => { const on = b.dataset.tab === active; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); });
    document.body.classList.toggle('tpl-all', active === 'all');
    let any = false;
    groups.forEach((g) => {
      let n = 0;
      g.querySelectorAll('[data-tpl-card]').forEach((c) => { const ok = !q || c.dataset.search.includes(q); c.hidden = !ok; if (ok) n++; });
      g.hidden = !n || (active !== 'all' && g.dataset.tplGroup !== active);
      if (!g.hidden) any = true;
    });
    document.querySelector('[data-tpl-none]').hidden = any;
  };
  tabs.forEach((b) => b.addEventListener('click', () => { tab = b.dataset.tab; find.value = ''; history.replaceState(null, '', '#' + tab); show(); }));
  find.addEventListener('input', show);
  show();
})();
</script>
