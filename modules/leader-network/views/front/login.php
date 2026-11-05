<?php require __DIR__ . '/_form.php'; ?>
<section class="ln-auth">
  <div class="ln-card ln-auth-card">
    <p class="ln-eyebrow">Members</p>
    <h1 class="ln-h1">Welcome back</h1>
    <?= ln_flash_html() ?>
    <?php if ($error): ?><div class="ln-alert ln-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="ln-form" action="<?= e(url('/account/login/')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <?= ln_field('Email or username', '<input class="ln-input" name="login" autocomplete="username" required value="' . e($_POST['login'] ?? '') . '">') ?>
      <?= ln_field('Password', '<input class="ln-input" type="password" name="password" autocomplete="current-password" required>') ?>
      <?php if ($needs2fa): ?><?= ln_field('Authentication code', '<input class="ln-input" name="code" inputmode="numeric" autocomplete="one-time-code" required>') ?><?php endif; ?>
      <button class="ln-btn ln-btn-primary" type="submit">Sign in</button>
    </form>
    <p class="ln-muted"><a href="<?= e(url('/account/forgot/')) ?>">Forgot your password?</a></p>
    <p class="ln-muted">New here? <a href="<?= e(url('/account/register/') . ($next ? '?next=' . rawurlencode($next) : '')) ?>">Create a free account</a></p>
  </div>
</section>
