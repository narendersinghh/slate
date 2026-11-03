<?php use Slate\Roles; ?>
<div class="page-head">
  <div><h1>Users</h1><p class="muted"><?= count($users) ?> team members and subscribers.</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(admin_url('users/new')) ?>"><?= icon('plus', 16) ?> Add user</a></div>
</div>
<section class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>User</th><th>Role</th><th class="hide-sm">2FA</th><th class="num hide-sm">Content</th><th class="hide-md">Last login</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><a class="user-cell" href="<?= e(admin_url('users/' . $u['id'])) ?>"><span class="avatar avatar-sm"><?= e(mb_strtoupper(mb_substr($u['display_name'] ?: $u['username'], 0, 1))) ?></span>
          <span><strong><?= e($u['display_name'] ?: $u['username']) ?></strong><small class="muted"><?= e($u['email']) ?></small></span></a></td>
        <td><span class="pill"><?= e(Roles::label($u['role'])) ?></span><?php if ($u['status'] !== 'active'): ?> <span class="status status-trash">Suspended</span><?php endif; ?></td>
        <td class="hide-sm"><?= $u['totp_secret'] ? '<span class="pill pill-success">' . icon('lock', 12) . ' On</span>' : '<span class="muted">Off</span>' ?></td>
        <td class="num hide-sm"><?= (int)$u['posts'] ?></td>
        <td class="hide-md"><?= e(time_ago($u['last_login'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="card">
  <div class="card-head"><h2>Roles & capabilities</h2></div>
  <div class="table-wrap"><table class="table table-compact caps-table">
    <thead><tr><th>Capability</th><?php foreach (Roles::all() as $r): ?><th class="center"><?= e($r['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (Roles::CAPS as $cap => $label): ?>
      <tr><td><?= e($label) ?></td><?php foreach (Roles::all() as $key => $r): ?><td class="center"><?= in_array($cap, $r['caps'], true) ? '<span class="cap-yes">' . icon('check', 14) . '</span>' : '<span class="muted">—</span>' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
