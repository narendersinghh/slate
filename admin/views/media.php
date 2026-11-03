<?php use Slate\Options; ?>
<div class="page-head">
  <div>
    <h1>Media library</h1>
    <p class="muted">
      <?php if (Options::get('media_optimize', true)): ?>
        <?= icon('zap', 14) ?> Auto-optimization on: <?= Options::get('media_webp', true) ? 'WebP' : 'original format' ?>, <?= (int)Options::get('media_quality', 87) ?>% quality, max <?= (int)Options::get('media_max_edge', 1600) ?>px<?= Options::get('media_keep_transparency', true) ? ', transparency kept' : '' ?>.
      <?php else: ?>Images are stored as uploaded.<?php endif; ?>
      <?php if (\Slate\Auth::can('manage_settings')): ?><a href="<?= e(admin_url('settings/media/optimization')) ?>">Change</a><?php endif; ?>
    </p>
  </div>
  <div class="page-actions"><label class="btn btn-primary"><?= icon('upload', 16) ?> Upload files<input type="file" multiple hidden data-upload-input></label></div>
</div>
<div class="media-app" data-media-app data-mode="library"></div>

