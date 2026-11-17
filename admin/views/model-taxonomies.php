<?php
use Slate\ContentModel;
use Slate\DB;

$cmTab = 'taxonomies';
require __DIR__ . '/partials/model-tabs.php';
$presets = (require __DIR__ . '/partials/model-presets.php')['taxonomies'];
$termCounts = array_column(DB::all('SELECT taxonomy, COUNT(*) c FROM {{terms}} GROUP BY taxonomy'), 'c', 'taxonomy');
$custom = array_filter($taxonomies, static fn($t) => empty($t['builtin']));
?>
<div class="card cm-list">
  <div class="cm-list-head" aria-hidden="true">
    <span>Taxonomy</span><span>Terms and address</span><span>Used by</span><span></span>
  </div>
  <?php foreach ($taxonomies as $slug => $t):
    $n = (int)($termCounts[$slug] ?? 0);
    $hier = !empty($t['hierarchical']); ?>
  <div class="cm-row">
    <a class="cm-main" href="<?= e(admin_url('model/taxonomies/' . $slug)) ?>">
      <span class="cm-icon"><?= icon($hier ? 'folder' : 'tag', 20) ?></span>
      <span class="cm-name">
        <strong><?= e($t['plural']) ?></strong>
        <small><?= $hier ? 'Nested, like categories' : 'Flat, like tags' ?> · <code><?= e($slug) ?></code></small>
      </span>
    </a>
    <div class="cm-meta">
      <a class="cm-count" href="<?= e(admin_url('terms/' . $slug)) ?>"><strong><?= number_format($n) ?></strong> <?= $n === 1 ? 'term' : 'terms' ?></a>
      <?php if (!empty($t['public'])): ?>
        <span class="cm-url" title="Address of each term page"><?= icon('link', 13) ?><span>/<?= e(($t['rewrite'] ?? '') !== '' ? $t['rewrite'] . '/' : '') ?>{term}/</span></span>
      <?php else: ?>
        <span class="cm-url is-off"><?= icon('lock', 13) ?><span>No term pages</span></span>
      <?php endif; ?>
    </div>
    <div class="cm-links">
      <?php foreach ((array)($t['post_types'] ?? []) as $pt): $ptDef = ContentModel::type($pt); ?>
        <a class="cm-chip" href="<?= e(admin_url('model/types/' . $pt)) ?>"><?= icon($ptDef['icon'] ?? 'file', 12) ?><?= e($ptDef['plural'] ?? $pt) ?></a>
      <?php endforeach; ?>
      <?php if (empty($t['post_types'])): ?><span class="pill pill-warn">Not attached to any post type</span><?php endif; ?>
      <?php if (!empty($t['builtin'])): ?><span class="pill">Built-in</span><?php endif; ?>
    </div>
    <div class="cm-actions">
      <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('terms/' . $slug)) ?>" title="Manage <?= e(strtolower($t['plural'])) ?>" aria-label="Manage <?= e(strtolower($t['plural'])) ?>"><?= icon('list', 15) ?><span class="cm-hide-sm">Terms</span></a>
      <a class="btn btn-sm" href="<?= e(admin_url('model/taxonomies/' . $slug)) ?>"><?= icon('edit', 14) ?><span>Edit</span></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!$custom): ?>
<section class="card cm-teach">
  <div class="cm-teach-text">
    <span class="cm-teach-icon"><?= icon('tag', 20) ?></span>
    <div>
      <h2>Group content your own way</h2>
      <p class="muted">A taxonomy is a set of labels you can give items, like a project's services or an event's region. Visitors get a page per label. Nested ones work like folders, flat ones like tags.</p>
    </div>
  </div>
  <div class="cm-presets">
    <?php foreach ($presets as $key => $p): ?>
      <a class="cm-preset" href="<?= e(admin_url('model/taxonomies/new') . '?preset=' . $key) ?>"><?= icon($p[2] ? 'folder' : 'tag', 16) ?><?= e($p[1]) ?></a>
    <?php endforeach; ?>
    <a class="cm-preset is-blank" href="<?= e(admin_url('model/taxonomies/new')) ?>"><?= icon('plus', 16) ?>Start from scratch</a>
  </div>
</section>
<?php endif; ?>
