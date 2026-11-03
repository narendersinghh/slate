<?php partial('header', ['bare' => true]); $p = post(); ?>
<article class="wrap entry">
  <?= can_frontend_edit() ? editable('content', 'div', null, ['class' => 'entry-content prose prose-wide']) : '<div class="entry-content prose prose-wide">' . the_content() . '</div>' ?>
</article>
<?php partial('footer', ['bare' => true]); ?>
