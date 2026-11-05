<?php
require __DIR__ . '/_form.php';
use Slate\Posts;
Posts::prime($ids);
$items = array_filter(array_map(static fn($id) => Posts::find((int)$id), $ids), static fn($p) => $p && $p['status'] === 'publish');
if (!$items) return;
?>
<link rel="stylesheet" href="<?= e(url('/modules/leader-network/assets/ln.css')) ?>?v=1">
<section class="ln-featured-strip" data-ln-featured aria-label="Featured <?= e($term['name']) ?>">
  <div class="ln-wrap">
    <p class="ln-eyebrow">Featured in <?= e($term['name']) ?></p>
    <div class="ln-feat-row">
      <?php foreach ($items as $p): $tag = trim(strip_tags((string)($p['fields']['tagline'] ?? $p['fields']['profession'] ?? ''))); ?>
        <a class="ln-feat-card" href="<?= e(Posts::permalink($p)) ?>">
          <?= ln_thumb($p, 'ln-feat-img') ?>
          <span class="ln-feat-body"><span class="ln-badge ln-badge-star">Featured</span>
            <strong class="ln-feat-name"><?= e($p['title']) ?></strong>
            <?php if ($tag): ?><span class="ln-feat-tag"><?= e(str_limit($tag, 80)) ?></span><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
