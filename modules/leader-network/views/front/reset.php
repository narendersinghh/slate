<?php require __DIR__ . '/_form.php'; ?>
<section class="ln-auth">
  <div class="ln-card ln-auth-card">
    <h1 class="ln-h1">Choose a new password</h1>
    <?php if ($error): ?><div class="ln-alert ln-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="ln-form" action="<?= e(url('/account/reset/')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <?= ln_field('New password', '<input class="ln-input" type="password" name="password" autocomplete="new-password" required minlength="10">') ?>
      <button class="ln-btn ln-btn-primary" type="submit">Save password</button>
    </form>
  </div>
</section>
