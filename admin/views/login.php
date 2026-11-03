<?php use Slate\Options; ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in ‹ <?= e(Options::get('site_title')) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<script>(function(){var t=(document.cookie.match(/slate_theme=(\w+)/)||[])[1]||'auto';if(t==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.mode=t})();</script>
</head>
<body class="auth-page">
<main class="auth-card">
  <div class="auth-brand">
    <svg viewBox="0 0 32 32" width="40" height="40" aria-hidden="true"><rect width="32" height="32" rx="9" fill="url(#g)"/><path d="M10 11.5c0-1.9 1.6-3.5 3.5-3.5H22v4h-8v2.5h4.5c1.9 0 3.5 1.6 3.5 3.5v2.5c0 1.9-1.6 3.5-3.5 3.5H10v-4h8v-2.5h-4.5c-1.9 0-3.5-1.6-3.5-3.5z" fill="#fff"/><defs><linearGradient id="g" x1="0" y1="0" x2="32" y2="32"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs></svg>
    <h1><?= $step === '2fa' ? 'Two-factor verification' : 'Sign in to ' . e(Options::get('site_title')) ?></h1>
    <p class="muted"><?= $step === '2fa' ? 'Enter the 6-digit code from your authenticator app.' : 'Welcome back.' ?></p>
  </div>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= icon('alert', 16) ?> <?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(admin_url('login')) ?>" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= e($_GET['redirect'] ?? $_POST['redirect'] ?? '') ?>">
    <?php if ($step === '2fa'): ?>
      <label class="field"><span>Authentication code</span>
        <input class="input input-lg otp" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus>
      </label>
    <?php else: ?>
      <label class="field"><span>Username or email</span>
        <input class="input input-lg" name="login" value="<?= e($login ?? '') ?>" autocomplete="username" required autofocus>
      </label>
      <label class="field"><span>Password</span>
        <span class="input-group">
          <input class="input input-lg" type="password" name="password" autocomplete="current-password" required>
          <button class="btn btn-ghost" type="button" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password'">Show</button>
        </span>
      </label>
    <?php endif; ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit"><?= $step === '2fa' ? 'Verify' : 'Sign in' ?></button>
  </form>
  <p class="auth-foot"><a href="<?= e(url()) ?>">← Back to <?= e(Options::get('site_title')) ?></a></p>
</main>
</body>
</html>
