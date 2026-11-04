<?php partial('header'); $p = post();
$wrap = (string)option('wpm_single_' . $p['type'], '');
$content = can_frontend_edit() ? editable('content', 'div', null, ['class' => 'entry-content']) : the_content();
?>
<main id="content" class="site-main slate-mirror-main">
<?php
// The exact imported rendering wins until the item is edited in Slate; then the live layout takes over.
$snap = wpm_single_snapshot($p);
?>
<?php if ($snap !== null): ?>
  <?= $snap ?>
<?php elseif ($wrap !== '' && str_contains($wrap, '{{SLATE_CONTENT}}')): ?>
  <?= strtr($wrap, [
      '{{SLATE_CONTENT}}' => $content,
      '{{SLATE_TITLE}}' => e($p['title']),
      '{{SLATE_DATE}}' => e(post_date()),
      '{{SLATE_IMAGE}}' => e($p['featured_media_id'] ? media_url((int)$p['featured_media_id']) : ''),
  ]) ?>
<?php elseif (wpm_is_elementor($p)): ?>
  <?= $content ?>
<?php else: ?>
  <article class="smx-wrap smx-article post type-<?= e($p['type']) ?>">
    <header class="entry-header"><?= editable('title', 'h1', null, ['class' => 'entry-title']) ?><p class="smx-meta"><?= e(post_date()) ?></p></header>
    <?php if ($p['featured_media_id']): ?><figure class="smx-media"><?= featured_image(null, ['loading' => 'eager']) ?></figure><?php endif; ?>
    <?= can_frontend_edit() ? $content : '<div class="entry-content">' . $content . '</div>' ?>
  </article>
<?php endif; ?>
</main>
<?php partial('footer'); ?>
