<?php partial('header'); $p = post(); ?>
<article class="wrap entry">
  <header class="entry-header"><?= editable('title', 'h1', null, ['class' => 'entry-title']) ?></header>
  <?= can_frontend_edit() ? editable('content', 'div', null, ['class' => 'entry-content prose prose-wide']) : '<div class="entry-content prose prose-wide">' . the_content() . '</div>' ?>
</article>
<?php partial('footer'); ?>
