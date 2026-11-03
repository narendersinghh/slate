<?php
use Slate\Auth;
use Slate\Roles;
use Slate\Totp;

$profile = $profile ?? false;
$u = $user ?? ['id' => 0, 'username' => '', 'email' => '', 'display_name' => '', 'role' => (string)\Slate\Options::get('default_role', 'subscriber'), 'status' => 'active', 'meta' => '{}', 'totp_secret' => null];
$meta = json_decode((string)$u['meta'], true) ?: [];
$isSelf = (int)$u['id'] === Auth::id();
?>
<div class="page-head">
  <div><h1><?= e($title) ?></h1><?php if ($user): ?><p class="muted">@<?= e($u['username']) ?> · member since <?= e(local_date($u['created_at'])) ?></p><?php endif; ?></div>
  <?php if (!$profile): ?><div class="page-actions"><a class="btn" href="<?= e(admin_url('users')) ?>">← All users</a></div><?php endif; ?>
</div>

<div class="split split-wide">
  <form class="card" method="post" action="<?= e($profile ? admin_url('profile') : admin_url('users/save')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <div class="card-head"><h2>Account</h2></div>
    <div class="card-pad grid-form">
      <div class="field"><label>Username</label><input class="input" name="username" value="<?= e($u['username']) ?>" <?= $user ? 'disabled' : 'required' ?> autocomplete="off"></div>
      <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= e($u['email']) ?>" required></div>
      <div class="field"><label>Display name</label><input class="input" name="display_name" value="<?= e($u['display_name']) ?>"></div>
      <div class="field"><label>Website</label><input class="input" type="url" name="website" value="<?= e($meta['website'] ?? '') ?>"></div>
      <?php if (!$profile): ?>
      <div class="field"><label>Role</label>
        <select class="input" name="role"<?= $isSelf ? ' disabled' : '' ?>>
          <?php foreach (Roles::all() as $k => $r): ?><option value="<?= e($k) ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>Status</label>
        <select class="input" name="status"<?= $isSelf ? ' disabled' : '' ?>>
          <option value="active"<?= $u['status'] === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="suspended"<?= $u['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option>
        </select></div>
      <?php endif; ?>
      <div class="field span-2"><label>Bio</label><textarea class="input" name="bio" rows="3"><?= e($meta['bio'] ?? '') ?></textarea></div>
    </div>
    <div class="card-head"><h2><?= $user ? 'Change password' : 'Password' ?></h2></div>
    <div class="card-pad grid-form">
      <?php if ($profile): ?><div class="field"><label>Current password</label><input class="input" type="password" name="current_password" autocomplete="current-password"></div><?php endif; ?>
      <div class="field">
        <label><?= $user ? 'New password' : 'Password' ?></label>
        <span class="input-group"><input class="input" type="password" name="password" autocomplete="new-password" <?= $user ? '' : 'required' ?> data-password>
        <button class="btn btn-ghost" type="button" data-generate-password>Generate</button></span>
        <div class="strength" data-strength><i></i></div>
        <small class="help">At least <?= (int)\Slate\Options::get('security_min_password', 10) ?> characters.<?= $user ? ' Leave empty to keep the current password.' : '' ?></small>
      </div>
    </div>
    <div class="card-foot">
      <button class="btn btn-primary" type="submit"><?= $user ? 'Save changes' : 'Create user' ?></button>
      <?php if ($user && !$profile && !$isSelf): ?>
        <button class="btn btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('users/' . $u['id'] . '/delete')) ?>" data-confirm="Delete this user? Their content will be reassigned to you.">Delete user</button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($profile): ?>
  <section class="card">
    <div class="card-head"><h2><?= icon('lock', 16) ?> Two-factor authentication</h2></div>
    <div class="card-pad stack">
      <?php if (!empty($u['totp_secret'])): ?>
        <div class="alert alert-success"><?= icon('check', 16) ?> 2FA is active on your account.</div>
        <form method="post" action="<?= e(admin_url('profile/2fa')) ?>" class="stack-sm">
          <?= csrf_field() ?><input type="hidden" name="disable" value="1">
          <label class="field"><span>Confirm your password to disable</span><input class="input" type="password" name="password" required></label>
          <button class="btn btn-danger" type="submit">Disable 2FA</button>
        </form>
      <?php else: $secret = $_SESSION['totp_setup']; $uri = Totp::uri($secret, $u['email'], site_name()); ?>
        <p>Protect your account with an authenticator app (Google Authenticator, 1Password, Authy…).</p>
        <div class="qr-box">
          <div class="qr-icon"><?= icon('lock', 28) ?></div>
          <div class="stack-sm">
            <small class="muted">Add a new account in your authenticator app and enter this setup key (time-based):</small>
            <code class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
            <a class="btn btn-sm" href="<?= e($uri) ?>">Open in authenticator app</a>
          </div>
        </div>
        <form method="post" action="<?= e(admin_url('profile/2fa')) ?>" class="inline-form">
          <?= csrf_field() ?>
          <input class="input otp" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" required maxlength="7">
          <button class="btn btn-primary" type="submit">Verify & enable</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
