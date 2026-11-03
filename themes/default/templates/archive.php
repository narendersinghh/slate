<?php partial('header'); $result = page_data('result'); $term = page_data('term'); ?>
<div class="wrap">
  <?= breadcrumbs() ?>
  <header class="page-header">
    <h1><?= e(page_data('title')) ?></h1>
    <?php if ($term && $term['description']): ?><p class="lead"><?= e($term['description']) ?></p><?php endif; ?>
  </header>
  <?php if ($result['items']): ?>
    <div class="cards"><?php foreach ($result['items'] as $p) partial('card', ['p' => $p]); ?></div>
    <?= paginate($result) ?>
  <?php else: ?>
    <p class="empty">No items found.</p>
  <?php endif; ?>
</div>
<?php partial('footer'); ?>
