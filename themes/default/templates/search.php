<?php partial('header'); $result = page_data('result'); ?>
<div class="wrap">
  <header class="page-header">
    <h1>Search</h1>
    <form class="search-form" role="search" action="<?= e(home_url()) ?>"><input type="search" name="s" value="<?= e(page_data('query')) ?>" placeholder="Search…" aria-label="Search"><button>Search</button></form>
  </header>
  <?php if ($result['items']): ?>
    <p class="meta"><?= (int)$result['total'] ?> results for “<?= e(page_data('query')) ?>”</p>
    <div class="cards"><?php foreach ($result['items'] as $p) partial('card', ['p' => $p]); ?></div>
    <?= paginate($result) ?>
  <?php else: ?><p class="empty">No results. Try different keywords.</p><?php endif; ?>
</div>
<?php partial('footer'); ?>
