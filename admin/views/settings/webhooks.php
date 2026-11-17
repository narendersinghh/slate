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
    <?php
      $groupNames = ['content' => 'Content', 'media' => 'Media', 'form' => 'Forms', 'comment' => 'Comments'];
      $evGroups = [];
      foreach ($events as $k => $label) {
          $g = $groupNames[strtok($k, '.')] ?? 'System';
          $evGroups[$g][$k] = trim((string)preg_replace('/\s*\([^)]*\)\s*$/', '', $label));
      }
    ?>
    <fieldset class="wh-events" data-wh-events>
      <legend class="small-label">Send these events</legend>
      <div class="wh-mode">
        <label class="wh-mode-opt"><input type="radio" name="wh_mode" value="all" checked data-wh-mode> <span><strong>All events</strong><small class="muted">Everything below, and events added later</small></span></label>
        <label class="wh-mode-opt"><input type="radio" name="wh_mode" value="some" data-wh-mode> <span><strong>Only these events</strong><small class="muted">Pick from the list</small></span></label>
      </div>
      <input type="hidden" name="wh_events[]" value="*" data-wh-all>
      <div class="wh-groups" data-wh-list hidden>
        <?php foreach ($evGroups as $g => $list): ?>
          <div class="wh-group"><span class="wh-group-title"><?= e($g) ?></span>
            <?php foreach ($list as $k => $label): ?>
              <label class="wh-ev"><input type="checkbox" name="wh_events[]" value="<?= e($k) ?>" disabled> <span><?= e($label) ?><code><?= e($k) ?></code></span></label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <script>
      (function () {
        const box = document.querySelector('[data-wh-events]');
        if (!box) return;
        const sync = () => {
          const some = box.querySelector('[data-wh-mode][value="some"]').checked;
          box.querySelector('[data-wh-list]').hidden = !some;
          box.querySelector('[data-wh-all]').disabled = some;
          box.querySelectorAll('.wh-ev input').forEach((c) => { c.disabled = !some; });
        };
        box.addEventListener('change', (e) => { if (e.target.matches('[data-wh-mode]')) sync(); });
        sync();
      })();
    </script>
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
