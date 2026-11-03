<?php partial('header'); $p = post(); $latest = query(['type' => 'post', 'limit' => 3]); ?>
<section class="hero">
  <div class="wrap narrow">
    <?= editable('title', 'h1', null, ['class' => 'hero-title']) ?>
    <?= can_frontend_edit() ? editable('content', 'div', null, ['class' => 'hero-text prose']) : '<div class="hero-text prose">' . the_content() . '</div>' ?>
  </div>
</section>
<?php if ($latest): ?>
<section class="wrap section">
  <h2 class="section-title">Latest posts</h2>
  <div class="cards"><?php foreach ($latest as $lp) partial('card', ['p' => $lp]); ?></div>
</section>
<?php endif; ?>
<?php partial('footer'); ?>
