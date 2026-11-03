<?php use Slate\ContentModel; ?>
<div class="page-head">
  <div><h1>Taxonomies</h1><p class="muted">Group content: categories, tags, project types, locations, brands…</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(admin_url('model/taxonomies/new')) ?>"><?= icon('plus', 16) ?> New taxonomy</a></div>
</div>
<?php require __DIR__ . '/partials/model-tabs.php'; ?>
<div class="card-grid">
  <?php foreach ($taxonomies as $slug => $t): ?>
  <a class="card type-card" href="<?= e(admin_url('model/taxonomies/' . $slug)) ?>">
    <span class="type-icon"><?= icon($t['hierarchical'] ? 'folder' : 'tag', 20) ?></span>
    <span class="type-body">
      <strong><?= e($t['plural']) ?> <?= !empty($t['builtin']) ? '<span class="pill">Built-in</span>' : '' ?></strong>
      <small class="muted"><code><?= e($slug) ?></code> · <?= $t['hierarchical'] ? 'Hierarchical' : 'Flat' ?> · /<?= e($t['rewrite']) ?>/</small>
      <span class="type-tags"><?php foreach ($t['post_types'] as $pt): ?><span class="pill pill-soft"><?= e(ContentModel::type($pt)['plural'] ?? $pt) ?></span><?php endforeach; ?></span>
    </span>
    <?= icon('chevron-right', 16) ?>
  </a>
  <?php endforeach; ?>
</div>
