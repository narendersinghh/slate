<?php $log = \Slate\DB::all('SELECT a.*, u.display_name FROM {{activity_log}} a LEFT JOIN {{users}} u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100'); ?>
<div class="table-wrap"><table class="table table-compact">
  <thead><tr><th>When</th><th>User</th><th>Action</th><th>Object</th><th class="hide-sm">IP</th></tr></thead>
  <tbody>
  <?php foreach ($log as $a): ?>
    <tr><td class="nowrap"><?= e(local_date($a['created_at'], 'M j, H:i')) ?></td><td><?= e($a['display_name'] ?: '—') ?></td><td><span class="pill pill-soft"><?= e(str_replace('_', ' ', $a['action'])) ?></span></td><td><?= e($a['object']) ?></td><td class="hide-sm"><code><?= e($a['ip']) ?></code></td></tr>
  <?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="5" class="muted center">No activity recorded yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
