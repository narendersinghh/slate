<?php
use Slate\Updater;
$info = \Slate\Options::get('update_info');
$available = is_array($info) && version_compare($info['latest'] ?? '0', SLATE_VERSION, '>');
$backups = glob(SLATE_CONTENT . '/backups/slate-*.zip') ?: [];
rsort($backups);
?>
<div class="card-pad stack">
  <div class="update-box<?= $available ? ' has-update' : '' ?>">
    <div class="update-version">
      <small class="muted">Installed</small><strong><?= e(SLATE_VERSION) ?></strong>
    </div>
    <?= icon('chevron-right', 20) ?>
    <div class="update-version">
      <small class="muted">Latest release</small><strong><?= e($info['latest'] ?? '—') ?></strong>
      <?php if (!empty($info['checked_at'])): ?><small class="muted">checked <?= e(time_ago(gmdate('Y-m-d H:i:s', (int)$info['checked_at']))) ?></small><?php endif; ?>
    </div>
    <div class="update-actions">
      <button class="btn" type="submit" formaction="<?= e(admin_url('system/updates/check')) ?>" formnovalidate><?= icon('refresh', 15) ?> Check now</button>
      <?php if ($available): ?>
        <button class="btn btn-primary" type="submit" formaction="<?= e(admin_url('system/updates/install')) ?>" data-confirm="Install Slate <?= e($info['latest']) ?>? A backup of the current core is created first." formnovalidate><?= icon('download', 15) ?> Update to <?= e($info['latest']) ?></button>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($available && !empty($info['notes'])): ?>
    <div class="release-notes"><h3><?= e($info['name'] ?? $info['latest']) ?></h3><pre><?= e($info['notes']) ?></pre><?php if (!empty($info['url'])): ?><a href="<?= e($info['url']) ?>" target="_blank" rel="noopener">View on GitHub →</a><?php endif; ?></div>
  <?php endif; ?>
  <div class="muted small">
    Updates come from GitHub Releases of <code><?= e(Updater::repo()) ?></code>. Publish a release with a tag like <code>v0.2.0</code>; optionally attach a <code>slate.zip</code> asset.
    The updater replaces <code><?= e(implode(', ', Updater::CORE_PATHS)) ?></code> and never touches <code>config.php</code>, <code>content/</code>, <code>modules/</code> or other themes.
  </div>
  <?php if ($backups): ?>
    <div><strong class="small-label">Backups</strong><ul class="list list-compact"><?php foreach (array_slice($backups, 0, 5) as $b): ?><li><span class="list-main"><?= icon('box', 14) ?> <code><?= e(basename($b)) ?></code></span><small class="muted"><?= e(format_bytes(filesize($b))) ?></small></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
</div>
