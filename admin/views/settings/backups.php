<?php
use Slate\Backup;
$list = Backup::list();
\Slate\Options::lazy('backup_last');
$last = (int)\Slate\Options::get('backup_last', 0);
$disk = @disk_free_space(SLATE_CONTENT);
?>
<div class="card-pad stack">
  <div class="row-between">
    <div><strong class="small-label">Back up now</strong>
      <p class="muted small">Last backup: <?= $last ? e(time_ago(gmdate('Y-m-d H:i:s', $last))) : 'never' ?><?= $disk ? ' · ' . e(format_bytes($disk)) . ' free on disk' : '' ?></p></div>
    <div class="row-between">
      <button class="btn" type="submit" formaction="<?= e(admin_url('system/backups/create')) ?>" formnovalidate><?= icon('box', 15) ?> Database</button>
      <button class="btn" type="submit" formaction="<?= e(admin_url('system/backups/create?files=1')) ?>" formnovalidate
        data-confirm="Back up the database and all files (uploads, modules, themes)? This can take a few minutes on large sites."><?= icon('box', 15) ?> Database + files</button>
    </div>
  </div>
  <?php if ($list): ?>
  <ul class="list list-compact">
    <?php foreach ($list as $b): ?>
      <li><span class="list-main"><?= icon($b['kind'] === 'files' ? 'folder' : 'box', 15) ?><span><strong><?= e($b['name']) ?></strong>
        <small><?= $b['kind'] === 'files' ? 'Files' : 'Database' ?> · <?= e(format_bytes($b['size'])) ?> · <?= e(time_ago(gmdate('Y-m-d H:i:s', $b['time']))) ?></small></span></span>
        <span class="row-between">
          <a class="btn btn-sm" href="<?= e(admin_url('system/backups/download/' . $b['name'])) ?>"><?= icon('download', 14) ?> Download</a>
          <button class="btn btn-sm btn-ghost btn-danger" type="submit" name="backup" value="<?= e($b['name']) ?>" formaction="<?= e(admin_url('system/backups/delete')) ?>" formnovalidate
            data-confirm="Delete <?= e($b['name']) ?> permanently?"><?= icon('trash', 14) ?></button>
        </span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted small">No backups yet.</p><?php endif; ?>
  <p class="muted small">Backups are stored in <code>content/backups/</code> (never reachable from the web). Download them regularly and keep a copy somewhere else.
    Downloads contain everything, including <code>config.php</code> secrets, store them safely.
    To restore a database backup over SSH: <code>php bin/slate backup:restore site-…-db.sql.gz</code> (a safety backup is made first).</p>
</div>
