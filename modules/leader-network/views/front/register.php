<?php require __DIR__ . '/_form.php'; ?>
<section class="ln-auth">
  <div class="ln-card ln-auth-card">
    <p class="ln-eyebrow">Free account</p>
    <h1 class="ln-h1">Join <?= e(site_name()) ?></h1>
    <p class="ln-lead">Claim and update your profile, receive booking requests and get featured in your category.</p>
    <?= ln_errors($errors) ?>
    <form method="post" class="ln-form" action="<?= e(url('/account/register/')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="ln-hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
      <?= ln_field('Full name', '<input class="ln-input" name="name" autocomplete="name" required value="' . e($old['name']) . '">') ?>
      <?= ln_field('Email', '<input class="ln-input" type="email" name="email" autocomplete="email" required value="' . e($old['email']) . '">') ?>
      <?= ln_field('Password', '<input class="ln-input" type="password" name="password" autocomplete="new-password" required minlength="10">', 'At least 10 characters, with letters and numbers or symbols.') ?>
      <label class="ln-check"><input type="checkbox" name="terms" value="1" required> I agree to the terms and privacy policy.</label>
      <button class="ln-btn ln-btn-primary" type="submit">Create account</button>
    </form>
    <p class="ln-muted">Already a member? <a href="<?= e(url('/account/login/') . ($next ? '?next=' . rawurlencode($next) : '')) ?>">Sign in</a></p>
  </div>
</section>
