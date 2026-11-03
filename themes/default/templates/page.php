<?php partial('header'); $p = post(); ?>
<article class="wrap narrow entry">
  <?= breadcrumbs() ?>
  <header class="entry-header"><?= editable('title', 'h1', null, ['class' => 'entry-title']) ?></header>
  <?php if ($p['featured_media_id']): ?><figure class="entry-media"><?= featured_image(null, ['loading' => 'eager', 'fetchpriority' => 'high']) ?></figure><?php endif; ?>
  <?= can_frontend_edit() ? editable('content', 'div', null, ['class' => 'entry-content prose']) : '<div class="entry-content prose">' . the_content() . '</div>' ?>
</article>
<?php partial('footer'); ?>
