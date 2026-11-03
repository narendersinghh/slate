<?php
use Slate\DB;

$rows = DB::all('SELECT * FROM {{wpi_forms}} ORDER BY id DESC LIMIT 200');
DB::exec('UPDATE {{wpi_forms}} SET is_read = 1 WHERE is_read = 0');
?>
<p class="muted" style="margin-top:0">Submissions from Elementor forms on your site. Each one is also emailed to the administration email (Settings → General).</p>
<?php if (!$rows): ?>
  <div class="card empty-state"><?= icon('message', 32) ?><h2>No submissions yet</h2></div>
<?php else: ?>
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th>Received</th><th>Form</th><th>Details</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $data = json_decode((string)$r['data'], true) ?: []; ?>
    <tr>
      <td class="nowrap"><?= e(local_date($r['created_at'], 'M j, Y H:i')) ?><br><small class="muted"><?= e($r['page']) ?></small></td>
      <td><strong><?= e($r['form']) ?></strong><?= $r['is_read'] ? '' : ' <span class="pill pill-accent">New</span>' ?></td>
      <td><?php foreach ($data as $k => $v): ?><div><span class="muted"><?= e(ucfirst((string)$k)) ?>:</span> <?= nl2br(e(is_array($v) ? implode(', ', $v) : (string)$v)) ?></div><?php endforeach; ?></td>
      <td><form method="post" action="<?= e(admin_url('m/forms/' . $r['id'] . '/delete')) ?>"><?= csrf_field() ?><button class="icon-btn" data-confirm="Delete this submission?"><?= icon('trash', 15) ?></button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
