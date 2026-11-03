<article class="card">
  <?php if ($p['featured_media_id']): ?>
    <a class="card-media" href="<?= e(permalink($p)) ?>" tabindex="-1" aria-hidden="true"><?= image((int)$p['featured_media_id'], ['size' => 'thumb', 'alt' => '']) ?></a>
  <?php endif; ?>
  <div class="card-body">
    <?php if ($cats = terms($p, 'category')): ?><a class="eyebrow" href="<?= e(term_link($cats[0])) ?>"><?= e($cats[0]['name']) ?></a><?php endif; ?>
    <h2 class="card-title"><a href="<?= e(permalink($p)) ?>"><?= e($p['title']) ?></a></h2>
    <p class="card-excerpt"><?= e(excerpt($p, 150)) ?></p>
    <time class="meta" datetime="<?= e(gmdate('c', strtotime(($p['published_at'] ?: $p['created_at']) . ' UTC'))) ?>"><?= e(post_date($p)) ?></time>
  </div>
</article>
