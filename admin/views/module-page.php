<?php
/** Every module page: a standard header (unless the module printed AdminUi::header()) and the module's tabs. */
$nav = !empty($moduleSlug) ? \Slate\AdminUi::moduleNav($moduleSlug, $currentUrl) : '';
if (str_contains($html, '<!--slate:module-tabs-->')) {
    $html = preg_replace('/<!--slate:module-tabs-->/', $nav, $html, 1);
    $html = str_replace('<!--slate:module-tabs-->', '', $html);
    $nav = '';
}
?>
<?php if ($nav !== '' || !str_contains(substr($html, 0, 800), 'class="mod-head"')): ?>
<div class="mod-head">
  <div class="mod-head-title">
    <?php if (!empty($icon)): ?><span class="mod-head-icon"><?= icon($icon, 19) ?></span><?php endif; ?>
    <div><h1><?= e($module ?: $title) ?></h1><?php if ($module && $nav === ''): ?><p class="muted"><?= e($title) ?></p><?php endif; ?></div>
  </div>
</div>
<?= $nav ?>
<?php endif; ?>
<div class="module-page"><?= $html ?></div>
