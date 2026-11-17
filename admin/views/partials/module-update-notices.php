<?php
/** Dashboard banners for module updates that failed (and were undone) or wait for approval. */
if (!\Slate\Auth::can('manage_modules')) {
    return;
}
foreach (\Slate\ModuleUpdater::notices() as $n):
    $back = 'dashboard';
    if ($n['kind'] === 'failed'): ?>
<div class="banner banner-danger mod-upd-banner" data-module-update-failed="<?= e($n['slug']) ?>">
  <span class="ic"><?= icon('alert', 18) ?></span>
  <div><strong>Updating <?= e($n['name']) ?> to <?= e((string)$n['version']) ?> failed and was undone. Your site is unchanged.</strong>
    <?php require SLATE_ADMIN . '/views/partials/module-update-report.php'; ?></div>
</div>
    <?php else: ?>
<div class="banner mod-upd-banner" data-module-update-approval="<?= e($n['slug']) ?>">
  <span class="ic"><?= icon('shield', 18) ?></span>
  <div><strong><?= e($n['name']) ?> <?= e((string)$n['version']) ?> waits for your approval.</strong> It adds: <?= e(implode('; ', (array)($n['reasons'] ?? []))) ?>
    <?php require SLATE_ADMIN . '/views/partials/module-update-report.php'; ?></div>
</div>
    <?php endif;
endforeach;
