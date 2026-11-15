<?php
use Slate\Cron;
use Slate\Options;
$state = Cron::state();
$server = (int)Options::get('cron_server_seen', 0);
$php = PHP_BINARY && !str_contains(PHP_BINARY, 'fpm') && !str_contains(PHP_BINARY, 'lsphp') ? PHP_BINARY : 'php';
?>
<div class="card-pad stack">
  <div class="row-between">
    <div><strong class="small-label">Server cron</strong>
      <p class="muted small"><?= $server > time() - 600 ? '<span class="text-success">Active</span> — last run ' . e(time_ago(gmdate('Y-m-d H:i:s', $server))) : 'Not detected. Tasks run after visits instead.' ?></p></div>
    <button class="btn" type="submit" formaction="<?= e(admin_url('system/cron/run')) ?>" formnovalidate><?= icon('refresh', 15) ?> Run due tasks now</button>
  </div>
  <p class="muted small">Recommended: add this to your hosting's cron jobs (every minute or every 5 minutes):<br>
    <code style="user-select:all"><?= e($php) ?> <?= e(SLATE_ROOT) ?>/bin/slate cron</code></p>
  <ul class="list list-compact">
    <?php foreach (Cron::jobs() as $name => $job): $s = $state[$name] ?? null; ?>
      <li><span class="list-main"><span><strong><?= e($job['label'] ?? $name) ?></strong>
        <small>Every <?= e($job['every'] >= 86400 ? round($job['every'] / 86400, 1) . ' day(s)' : ($job['every'] >= 3600 ? round($job['every'] / 3600, 1) . ' hour(s)' : round($job['every'] / 60) . ' min')) ?>
          · <?= $s ? 'last run ' . e(time_ago(gmdate('Y-m-d H:i:s', (int)$s['last']))) . ' (' . (int)($s['ms'] ?? 0) . ' ms)' : 'not run yet' ?></small></span></span>
        <?php if ($s && empty($s['ok'])): ?><span class="pill pill-danger" title="<?= e($s['error'] ?? '') ?>">Failed</span><?php elseif ($s): ?><span class="pill pill-success">OK</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</div>
