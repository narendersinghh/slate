<?php
use Slate\Icons;
use Slate\Options;

$pageTitle = 'Sign in ‹ ' . Options::get('site_title');
$quote = 'Content that’s <em>fast</em>, findable &amp; beautifully simple.';
require __DIR__ . '/partials/auth-head.php';
?>
  <div class="auth-card">
    <div class="auth-brand">
      <?= Icons::logo(48) ?>
      <span class="eyebrow"><?= e(Options::get('site_title')) ?></span>
      <h1><?= $step === '2fa' ? 'One more <em>step</em>' : 'Welcome <em>back</em>' ?></h1>
      <p class="muted"><?= $step === '2fa' ? 'Enter the 6-digit code from your authenticator app.' : 'Sign in to manage your website.' ?></p>
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
            <button class="btn btn-ghost" type="button" onclick="var i=this.previousElementSibling;i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Show':'Hide'">Show</button>
          </span>
        </label>
      <?php endif; ?>
      <button class="btn btn-primary btn-lg btn-block" type="submit"><?= $step === '2fa' ? 'Verify' : 'Sign in' ?> →</button>
    </form>
    <p class="auth-foot"><a href="<?= e(url()) ?>">← Back to <?= e(Options::get('site_title')) ?></a></p>
  </div>
</main>
</body>
</html>
