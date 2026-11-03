<?php partial('header'); $result = page_data('result'); ?>
<div class="wrap">
  <header class="page-header">
    <h1><?= e(page_data('title', site_name())) ?></h1>
    <?php if (page_data('kind') === 'home'): ?><p class="lead"><?= e(site_tagline()) ?></p><?php endif; ?>
  </header>
  <?php if ($result && $result['items']): ?>
    <div class="cards"><?php foreach ($result['items'] as $p) partial('card', ['p' => $p]); ?></div>
    <?= paginate($result) ?>
  <?php else: ?>
    <p class="empty">Nothing published yet.</p>
  <?php endif; ?>
</div>
<?php partial('footer'); ?>
