<?php use Slate\ContentModel; ?>
<div class="page-head">
  <div><h1>Custom fields</h1><p class="muted">Field groups add structured data to post types and taxonomies. Values are stored inline (no extra queries).</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(admin_url('model/fields/new')) ?>"><?= icon('plus', 16) ?> New field group</a></div>
</div>
<?php require __DIR__ . '/partials/model-tabs.php'; ?>
<?php if (!$groups): ?>
  <div class="card empty-state"><?= icon('list', 36) ?><h2>No field groups yet</h2><p class="muted">Create one to add fields like price, subtitle, gallery, location…</p>
    <a class="btn btn-primary" href="<?= e(admin_url('model/fields/new')) ?>">Create field group</a></div>
<?php else: ?>
<div class="card-grid">
  <?php foreach ($groups as $g): ?>
  <a class="card type-card" href="<?= e(admin_url('model/fields/' . $g['id'])) ?>">
    <span class="type-icon"><?= icon('list', 20) ?></span>
    <span class="type-body">
      <strong><?= e($g['title']) ?></strong>
      <small class="muted"><?= count($g['fields']) ?> fields · <?= e(implode(', ', array_map(static fn($f) => $f['label'], array_slice($g['fields'], 0, 4)))) ?></small>
      <span class="type-tags">
        <?php foreach ($g['location']['post_types'] ?? [] as $pt): ?><span class="pill pill-soft"><?= e(ContentModel::type($pt)['plural'] ?? $pt) ?></span><?php endforeach; ?>
        <?php foreach ($g['location']['taxonomies'] ?? [] as $tx): ?><span class="pill pill-soft"><?= icon('tag', 11) ?> <?= e(ContentModel::taxonomy($tx)['plural'] ?? $tx) ?></span><?php endforeach; ?>
      </span>
    </span>
    <?= icon('chevron-right', 16) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
