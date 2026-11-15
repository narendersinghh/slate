<?php use Slate\Roles; ?>
<div class="page-head">
  <div><h1>Users</h1><p class="muted">Your team and registered members.</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(admin_url('users/new')) ?>"><?= icon('plus', 16) ?> Add user</a></div>
</div>

<?= $table->render($rows, $total) ?>

<details class="card disclosure">
  <summary class="card-head"><h2>Roles & capabilities</h2><span class="muted small">What each role can do</span></summary>
  <div class="table-wrap"><table class="table table-compact caps-table">
    <thead><tr><th>Capability</th><?php foreach (Roles::all() as $r): ?><th class="center"><?= e($r['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (Roles::CAPS as $cap => $label): ?>
      <tr><td><?= e($label) ?></td><?php foreach (Roles::all() as $key => $r): ?><td class="center"><?= in_array($cap, $r['caps'], true) ? '<span class="cap-yes">' . icon('check', 14) . '</span>' : '<span class="muted">-</span>' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</details>
