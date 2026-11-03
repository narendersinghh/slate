<?php use Slate\ContentModel; ?>
<div class="page-head">
  <div><h1>Post types</h1><p class="muted">Model any content: projects, products, team members, events, case studies…</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(admin_url('model/types/new')) ?>"><?= icon('plus', 16) ?> New post type</a></div>
</div>
<?php require __DIR__ . '/partials/model-tabs.php'; ?>
<div class="card-grid">
  <?php foreach ($types as $slug => $t): ?>
  <a class="card type-card" href="<?= e(admin_url('model/types/' . $slug)) ?>">
    <span class="type-icon"><?= icon($t['icon'] ?? 'file', 20) ?></span>
    <span class="type-body">
      <strong><?= e($t['plural']) ?> <?= !empty($t['builtin']) ? '<span class="pill">Built-in</span>' : '' ?></strong>
      <small class="muted"><code><?= e($slug) ?></code> · <?= (int)($counts[$slug] ?? 0) ?> items · /<?= e($t['rewrite'] ?? '') ?></small>
      <span class="type-tags">
        <?php foreach (ContentModel::taxonomiesFor($slug) as $tx): ?><span class="pill pill-soft"><?= e($tx['plural']) ?></span><?php endforeach; ?>
        <?php $fc = count(ContentModel::fieldsFor('post', $slug)); if ($fc): ?><span class="pill pill-soft"><?= $fc ?> fields</span><?php endif; ?>
        <?php if (empty($t['public'])): ?><span class="pill pill-soft">Private</span><?php endif; ?>
      </span>
    </span>
    <?= icon('chevron-right', 16) ?>
  </a>
  <?php endforeach; ?>
</div>
