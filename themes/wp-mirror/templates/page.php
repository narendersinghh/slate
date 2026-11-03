<?php partial('header'); $p = post(); ?>
<main id="content" class="site-main slate-mirror-main">
<?php if (wpm_is_elementor($p)): ?>
  <?= can_frontend_edit() ? editable('content', 'div') : the_content() ?>
<?php else: ?>
  <article class="smx-wrap smx-article page type-page">
    <header class="entry-header"><?= editable('title', 'h1', null, ['class' => 'entry-title']) ?></header>
    <?php if ($p['featured_media_id']): ?><figure class="smx-media"><?= featured_image(null, ['loading' => 'eager']) ?></figure><?php endif; ?>
    <?= can_frontend_edit() ? editable('content', 'div', null, ['class' => 'entry-content']) : '<div class="entry-content">' . the_content() . '</div>' ?>
  </article>
<?php endif; ?>
</main>
<?php partial('footer'); ?>
