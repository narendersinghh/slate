<?php
$admin = (string)\Slate\Options::get('admin_email');
$me = (string)(\Slate\Auth::user()['email'] ?? $admin);
?>
<div class="card-pad stack-sm">
  <strong class="small-label">Send a test email</strong>
  <p class="muted small">Save your settings first, then send a test to check delivery. Password resets, form notifications and modules all use these settings.</p>
  <div class="inline-form">
    <input class="input" type="email" name="test_to" value="<?= e($me) ?>" aria-label="Send test to">
    <button class="btn" type="submit" formaction="<?= e(admin_url('system/email/test')) ?>" formnovalidate><?= icon('message', 15) ?> Send test</button>
  </div>
  <p class="muted small">Hostinger mailboxes: host <code>smtp.hostinger.com</code>, port 465 (SSL) or 587 (STARTTLS), your full email address and its password.</p>
</div>
