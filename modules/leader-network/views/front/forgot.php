<?php require __DIR__ . '/_form.php'; ?>
<section class="ln-auth">
  <div class="ln-card ln-auth-card">
    <h1 class="ln-h1">Reset your password</h1>
    <?php if ($sent): ?>
      <div class="ln-alert ln-alert-success" role="status">If an account exists for that email, a reset link is on its way. It is valid for one hour.</div>
    <?php else: ?>
      <form method="post" class="ln-form" action="<?= e(url('/account/forgot/')) ?>">
        <?= csrf_field() ?>
        <?= ln_field('Email', '<input class="ln-input" type="email" name="email" autocomplete="email" required>') ?>
        <button class="ln-btn ln-btn-primary" type="submit">Send reset link</button>
      </form>
    <?php endif; ?>
    <p class="ln-muted"><a href="<?= e(url('/account/login/')) ?>">Back to sign in</a></p>
  </div>
</section>
