<?php
/** Forgot / reset password. @var string $mode forgot|sent|reset|invalid */
use Slate\Icons;
use Slate\Options;

$pageTitle = 'Reset password ‹ ' . Options::get('site_title');
$quote = 'Content that’s <em>fast</em>, findable &amp; beautifully simple.';
require __DIR__ . '/partials/auth-head.php';
$titles = ['forgot' => 'Forgot your <em>password?</em>', 'sent' => 'Check your <em>inbox</em>', 'reset' => 'Choose a new <em>password</em>', 'invalid' => 'Link <em>expired</em>'];
$intro = [
    'forgot' => 'Enter your username or email and we’ll send you a link to choose a new password.',
    'sent' => 'If an account matches, an email with a reset link is on its way. The link works for one hour.',
    'reset' => 'Pick a strong password you don’t use anywhere else. You will be signed out on other devices.',
    'invalid' => 'This reset link is invalid or has expired. Request a new one.',
];
?>
  <div class="auth-card">
    <div class="auth-brand">
      <?= Icons::logo(48) ?>
      <span class="eyebrow"><?= e(Options::get('site_title')) ?></span>
      <h1><?= $titles[$mode] ?></h1>
      <p class="muted"><?= e($intro[$mode]) ?></p>
    </div>
    <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= icon('alert', 16) ?> <?= e($error) ?></div><?php endif; ?>
    <?php if ($mode === 'forgot'): ?>
      <form method="post" action="<?= e(admin_url('forgot')) ?>" class="stack">
        <?= csrf_field() ?>
        <label class="field"><span>Username or email</span>
          <input class="input input-lg" name="login" autocomplete="username" required autofocus maxlength="191"></label>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link →</button>
      </form>
    <?php elseif ($mode === 'reset'): ?>
      <form method="post" action="<?= e(admin_url('reset')) ?>" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="u" value="<?= (int)$uid ?>"><input type="hidden" name="t" value="<?= e($token) ?>">
        <label class="field"><span>New password</span>
          <input class="input input-lg" type="password" name="password" autocomplete="new-password" required autofocus
            minlength="<?= (int)Options::get('security_min_password', 10) ?>"></label>
        <label class="field"><span>Repeat new password</span>
          <input class="input input-lg" type="password" name="password2" autocomplete="new-password" required></label>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Save password →</button>
      </form>
    <?php elseif ($mode === 'invalid'): ?>
      <a class="btn btn-primary btn-lg btn-block" href="<?= e(admin_url('forgot')) ?>">Request a new link</a>
    <?php endif; ?>
    <p class="auth-foot"><a href="<?= e(admin_url('login')) ?>">← Back to sign in</a></p>
  </div>
</main>
</body>
</html>
