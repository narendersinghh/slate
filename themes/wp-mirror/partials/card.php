<article class="smx-card">
  <?php if ($p['featured_media_id']): ?><a href="<?= e(permalink($p)) ?>" class="smx-card-img"><?= image((int)$p['featured_media_id'], ['size' => 'thumb', 'alt' => '']) ?></a><?php endif; ?>
  <div class="smx-card-body">
    <h2 class="smx-card-title"><a href="<?= e(permalink($p)) ?>"><?= e($p['title']) ?></a></h2>
    <p><?= e(excerpt($p, 150)) ?></p>
    <time><?= e(post_date($p)) ?></time>
  </div>
</article>
