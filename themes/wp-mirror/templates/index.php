<?php $result = page_data('result');
$snap = null;
if ((int)($result['page'] ?? 1) === 1) {
    if (($t = page_data('term'))) {
        $snap = wpm_archive_snapshot('term-' . $t['taxonomy'] . '-' . $t['slug']);
    } elseif (page_data('kind') === 'archive' && ($ty = page_data('type'))) {
        $snap = wpm_archive_snapshot('type-' . ($ty['slug'] ?? ''));
    }
}
partial('header');
if ($snap !== null): ?>
<?= $snap ?>
<?php partial('footer'); return; endif; ?>
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
