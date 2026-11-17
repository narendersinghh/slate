<?php
use Slate\Webhooks;
$hooks = Webhooks::hooks();
$events = Webhooks::events();
$secret = \Slate\Session::takeValue('new_webhook_secret');
$recent = Webhooks::recent(25);
$urls = array_column($hooks, 'url', 'id');
?>
<div class="card-pad stack">
  <?php if ($secret): ?>
    <div class="alert alert-success stack-sm"><strong><?= icon('check', 16) ?> Signing secret, copy it now, it is not shown again:</strong>
      <code class="secret" style="user-select:all;word-break:break-all"><?= e($secret) ?></code>
      <small>Verify each request: <code>X-Webhook-Signature</code> must equal <code>sha256=</code>HMAC-SHA256(secret, <code>X-Webhook-Timestamp</code> + "." + raw body).</small></div>
  <?php endif; ?>
  <?php if ($hooks): ?>
  <ul class="list list-compact">
    <?php foreach ($hooks as $h): ?>
      <li><span class="list-main"><span><strong><?= e($h['url']) ?></strong>
        <small><?= $h['active'] ? '<span class="text-success">Active</span>' : 'Paused' ?> · <?= e(implode(', ', in_array('*', $h['events'], true) ? ['all events'] : $h['events'])) ?></small></span></span>
        <span class="row-between">
          <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url("system/webhooks/{$h['id']}/ping")) ?>" formnovalidate>Send test</button>
          <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url("system/webhooks/{$h['id']}/toggle")) ?>" formnovalidate><?= $h['active'] ? 'Pause' : 'Resume' ?></button>
          <button class="btn btn-sm btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url("system/webhooks/{$h['id']}/remove")) ?>" formnovalidate data-confirm="Remove this webhook?"><?= icon('trash', 14) ?></button>
        </span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="stack-sm">
    <strong class="small-label">Add a webhook</strong>
    <input class="input" type="url" name="wh_url" placeholder="https://hooks.example.com/slate" aria-label="Webhook URL">
    <div class="wh-events">
      <label class="check"><input type="checkbox" name="wh_events[]" value="*"> <strong>All events</strong></label>
      <?php foreach ($events as $k => $label): ?>
        <label class="check"><input type="checkbox" name="wh_events[]" value="<?= e($k) ?>"> <?= e($label) ?> <code><?= e($k) ?></code></label>
      <?php endforeach; ?>
    </div>
    <div><button class="btn btn-primary" type="submit" formaction="<?= e(admin_url('system/webhooks/add')) ?>" formnovalidate><?= icon('plus', 15) ?> Add webhook</button></div>
  </div>
  <?php if ($recent): ?>
  <div><strong class="small-label">Recent deliveries</strong>
    <ul class="list list-compact">
      <?php foreach ($recent as $d): ?>
        <li><span class="list-main"><span class="pill <?= $d['status'] === 'delivered' ? 'pill-success' : ($d['status'] === 'failed' ? 'pill-danger' : 'pill-warn') ?>"><?= e($d['status']) ?></span>
          <span><strong><?= e($d['event']) ?></strong> <small><?= icon('arrow-right', 12) ?> <?= e($urls[$d['hook_id']] ?? 'removed webhook') ?> · <?= e(time_ago($d['created_at'])) ?> · <?= (int)$d['attempts'] ?> attempt(s)<?= $d['response_code'] ? ' · HTTP ' . (int)$d['response_code'] : '' ?><?= $d['status'] !== 'delivered' && $d['response'] ? ' · ' . e(str_limit((string)$d['response'], 80)) : '' ?></small></span></span>
          <?php if ($d['status'] === 'failed' && isset($urls[$d['hook_id']])): ?><button class="btn btn-sm" type="submit" formaction="<?= e(admin_url("system/webhooks/deliveries/{$d['id']}/retry")) ?>" formnovalidate>Retry</button><?php endif; ?></li>
      <?php endforeach; ?>
    </ul></div>
  <?php endif; ?>
</div>
