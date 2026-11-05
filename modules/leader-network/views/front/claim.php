<?php require __DIR__ . '/_form.php'; ?>
<section class="ln-wrap ln-narrow">
  <a class="ln-back" href="<?= e(\Slate\Posts::permalink($p)) ?>">← Back to the profile</a>
  <div class="ln-claim-hero">
    <?= ln_thumb($p, 'ln-avatar ln-avatar-lg') ?>
    <div><p class="ln-eyebrow">Claim profile</p><h1 class="ln-h1"><?= e($p['title']) ?></h1></div>
  </div>
  <?= ln_flash_html() ?>
  <?php if ($owner && (int)$owner['user_id'] === (int)$u['id']): ?>
    <div class="ln-alert ln-alert-success">You manage this profile. <a href="<?= e(url('/account/profile/' . $p['id'] . '/')) ?>">Open the editor</a></div>
  <?php elseif ($owner): ?>
    <div class="ln-alert">This profile is already managed by a verified owner. If you believe this is wrong, contact us.</div>
  <?php elseif ($pending): ?>
    <div class="ln-alert">Your claim was sent on <?= e(local_date($pending['created_at'], 'F j, Y')) ?> and is waiting for review. We will email you.</div>
  <?php else: ?>
    <?= ln_errors($errors) ?>
    <form method="post" class="ln-form ln-card" action="<?= e(url('/claim/' . $p['id'] . '/')) ?>">
      <?= csrf_field() ?>
      <?= ln_field('How are you connected to this profile?', '<select class="ln-input" name="relation" required><option value="">Choose…</option>'
          . implode('', array_map(static fn($k, $v) => '<option value="' . $k . '"' . (($_POST['relation'] ?? '') === $k ? ' selected' : '') . '>' . $v . '</option>',
            ['self', 'team', 'agency', 'family', 'estate'], ['This is me', 'I work on their team / office', 'Their agency or management', 'Family member', 'Estate or foundation (for legacy profiles)'])) . '</select>') ?>
      <?= ln_field('Your full name', '<input class="ln-input" name="full_name" required value="' . e($_POST['full_name'] ?? $u['display_name']) . '">') ?>
      <?= ln_field('Phone (optional)', '<input class="ln-input" name="phone" inputmode="tel" value="' . e($_POST['phone'] ?? '') . '">') ?>
      <?= ln_field('Verification link', '<input class="ln-input" type="url" name="proof_url" required placeholder="https://" value="' . e($_POST['proof_url'] ?? '') . '">', 'An official website, verified social profile or organisation page that confirms the connection. Using an email on the official domain helps too.') ?>
      <?= ln_field('Anything else we should know? (optional)', '<textarea class="ln-input" name="message" rows="4">' . e($_POST['message'] ?? '') . '</textarea>') ?>
      <label class="ln-check"><input type="checkbox" name="confirm" value="1" required> I confirm the information is true and I am authorised to manage this profile.</label>
      <button class="ln-btn ln-btn-primary" type="submit">Send claim</button>
      <p class="ln-muted ln-small">Signed in as <?= e($u['email']) ?>.</p>
    </form>
  <?php endif; ?>
</section>
