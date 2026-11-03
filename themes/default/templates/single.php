<?php partial('header'); $p = post(); ?>
<article class="wrap narrow entry">
  <?= breadcrumbs() ?>
  <header class="entry-header">
    <?php if ($cats = terms($p, 'category')): ?><a class="eyebrow" href="<?= e(term_link($cats[0])) ?>"><?= e($cats[0]['name']) ?></a><?php endif; ?>
    <?= editable('title', 'h1', null, ['class' => 'entry-title']) ?>
    <?php if ($p['type'] !== 'page'): ?><p class="meta"><time datetime="<?= e(gmdate('c', strtotime(($p['published_at'] ?: $p['created_at']) . ' UTC'))) ?>"><?= e(post_date()) ?></time></p><?php endif; ?>
  </header>
  <?php if ($p['featured_media_id']): ?><figure class="entry-media"><?= featured_image(null, ['loading' => 'eager', 'fetchpriority' => 'high']) ?></figure><?php endif; ?>
  <?php if (can_frontend_edit()): ?>
    <?= editable('content', 'div', null, ['class' => 'entry-content prose']) ?>
  <?php else: ?>
    <div class="entry-content prose"><?= the_content() ?></div>
  <?php endif; ?>
  <?php if ($tags = terms($p, 'tag')): ?>
    <p class="tags"><?php foreach ($tags as $t): ?><a href="<?= e(term_link($t)) ?>">#<?= e($t['name']) ?></a> <?php endforeach; ?></p>
  <?php endif; ?>
</article>
<?php partial('footer'); ?>
