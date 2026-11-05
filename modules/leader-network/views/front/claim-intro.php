<?php require __DIR__ . '/_form.php'; $next = url('/claim/' . $p['id'] . '/'); ?>
<section class="ln-wrap ln-claim-intro">
  <div class="ln-claim-hero">
    <?= ln_thumb($p, 'ln-avatar ln-avatar-lg') ?>
    <div>
      <p class="ln-eyebrow">Claim profile</p>
      <h1 class="ln-h1">Is this you, <?= e($p['title']) ?>?</h1>
      <p class="ln-lead">Verified owners — or their official team — can keep this profile accurate, add new milestones, receive booking requests and get featured in their category.</p>
    </div>
  </div>
  <ol class="ln-steps">
    <li><strong>Create a free account</strong><span>Use an email address you can access.</span></li>
    <li><strong>Send your claim</strong><span>Add a link that proves the connection — an official website, a verified social profile or a company page.</span></li>
    <li><strong>We verify it</strong><span>Our team checks every claim by hand, usually within 2 working days.</span></li>
  </ol>
  <div class="ln-actions">
    <a class="ln-btn ln-btn-primary" href="<?= e(url('/account/register/') . '?next=' . rawurlencode($next)) ?>">Create account &amp; claim</a>
    <a class="ln-btn" href="<?= e(url('/account/login/') . '?next=' . rawurlencode($next)) ?>">I already have an account</a>
  </div>
</section>
