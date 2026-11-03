<?php partial('header'); $result = page_data('result'); ?>
<main id="content" class="site-main slate-mirror-main">
  <div class="smx-wrap smx-archive">
    <header class="smx-archive-head">
      <h1><?= e(page_data('title', page_data('kind') === 'search' ? 'Search: ' . page_data('query') : site_name())) ?></h1>
      <?php if (($t = page_data('term')) && $t['description']): ?><p><?= e($t['description']) ?></p><?php endif; ?>
    </header>
    <?php if ($result && $result['items']): ?>
      <div class="smx-grid"><?php foreach ($result['items'] as $p) partial('card', ['p' => $p]); ?></div>
      <?= paginate($result) ?>
    <?php else: ?><p>Nothing found.</p><?php endif; ?>
  </div>
</main>
<?php partial('footer'); ?>
