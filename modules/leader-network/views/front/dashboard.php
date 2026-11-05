<?php
require __DIR__ . '/_form.php';
use Slate\Posts;
use SlateModules\LeaderNetwork\Network;
$meta = $u['meta'] ?? [];
$statusLabel = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved'];
?>
<section class="ln-wrap">
  <div class="ln-dash-head">
    <div><p class="ln-eyebrow">Your account</p><h1 class="ln-h1">Hello, <?= e($u['display_name']) ?></h1></div>
    <form method="post" action="<?= e(url('/account/logout/')) ?>"><?= csrf_field() ?><button class="ln-btn ln-btn-sm" type="submit">Sign out</button></form>
  </div>
  <?= ln_flash_html() ?>
  <?php if (empty($meta['email_verified']) && !empty($meta['ln_member'])): ?>
    <div class="ln-alert">Please confirm your email address (<?= e($u['email']) ?>).
      <form method="post" class="ln-inline"><?= csrf_field() ?><input type="hidden" name="do" value="resend"><button class="ln-link" type="submit">Resend the link</button></form></div>
  <?php endif; ?>

  <div class="ln-grid-2">
    <div class="ln-card">
      <h2 class="ln-h3">Profiles you manage</h2>
      <?php if (!$owned): ?>
        <p class="ln-muted">You don’t manage a profile yet. Find yours and press <strong>“Claim this profile”</strong> — or open any profile and use the claim link at the bottom.</p>
      <?php endif; ?>
      <?php foreach ($owned as $pid): $p = Posts::find((int)$pid); if (!$p) continue; $o = Network::owner((int)$pid); $feat = Network::isFeatured((int)$pid); ?>
        <div class="ln-owned">
          <?= ln_thumb($p) ?>
          <div class="ln-owned-body">
            <a class="ln-owned-title" href="<?= e(Posts::permalink($p)) ?>"><?= e($p['title']) ?></a>
            <div class="ln-badges"><span class="ln-badge ln-badge-ok">Verified owner</span>
              <?php if ($o && $o['booking_enabled']): ?><span class="ln-badge">Bookings on</span><?php endif; ?>
              <?php if ($feat): ?><span class="ln-badge ln-badge-star">Featured</span><?php endif; ?></div>
          </div>
          <div class="ln-owned-actions">
            <a class="ln-btn ln-btn-sm ln-btn-primary" href="<?= e(url('/account/profile/' . $pid . '/')) ?>">Edit profile</a>
            <?php if (Network::get('featured')): ?><a class="ln-btn ln-btn-sm" href="<?= e(url('/account/featured/' . $pid . '/')) ?>">Get featured</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="ln-card">
      <h2 class="ln-h3">Your claims</h2>
      <?php if (!$claims): ?><p class="ln-muted">No claims yet.</p><?php endif; ?>
      <ul class="ln-list">
        <?php foreach ($claims as $c): $p = Posts::find((int)$c['post_id']); ?>
          <li><span><?= e($p['title'] ?? 'Profile #' . $c['post_id']) ?></span> <span class="ln-status ln-status-<?= e($c['status']) ?>"><?= e($statusLabel[$c['status']] ?? $c['status']) ?></span>
            <?php if ($c['status'] === 'rejected' && $c['admin_note']): ?><small class="ln-muted"><?= e($c['admin_note']) ?></small><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <?php if ($owned): ?>
  <div class="ln-card">
    <h2 class="ln-h3">Booking requests</h2>
    <?php if (!$bookings): ?><p class="ln-muted">No requests yet. Turn on bookings in the profile editor so visitors can reach you.</p><?php else: ?>
    <div class="ln-table-wrap"><table class="ln-table">
      <thead><tr><th>Received</th><th>From</th><th>Event</th><th>Date</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($bookings as $b): ?>
        <tr><td><?= e(local_date($b['created_at'], 'M j')) ?></td>
          <td><strong><?= e($b['name']) ?></strong><br><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a><?= $b['phone'] ? '<br>' . e($b['phone']) : '' ?><?= $b['organization'] ? '<br><small>' . e($b['organization']) . '</small>' : '' ?></td>
          <td><?= e($b['event_type']) ?><?= $b['location'] ? '<br><small>' . e($b['location']) . '</small>' : '' ?><?= $b['audience'] ? '<br><small>Audience: ' . e($b['audience']) . '</small>' : '' ?></td>
          <td><?= e($b['event_date']) ?></td>
          <td class="ln-msg"><?= nl2br(e($b['message'])) ?><?= $b['budget'] ? '<br><small>Budget: ' . e($b['budget']) . '</small>' : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($featured): ?>
  <div class="ln-card">
    <h2 class="ln-h3">Featured placements</h2>
    <ul class="ln-list">
      <?php foreach ($featured as $f): $p = Posts::find((int)$f['post_id']); $t = $f['term_id'] ? \Slate\Terms::find((int)$f['term_id']) : null; ?>
        <li><span><?= e($p['title'] ?? '') ?><?= $t ? ' · ' . e($t['name']) : '' ?></span>
          <span class="ln-status ln-status-<?= e($f['status']) ?>"><?= e(ucfirst($f['status'])) ?><?= $f['status'] === 'active' ? ' until ' . e(local_date($f['ends_at'], 'M j, Y')) : '' ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="ln-card">
    <h2 class="ln-h3">Account details</h2>
    <form method="post" class="ln-form ln-form-row"><?= csrf_field() ?><input type="hidden" name="do" value="name">
      <?= ln_field('Display name', '<input class="ln-input" name="name" value="' . e($u['display_name']) . '">') ?>
      <button class="ln-btn" type="submit">Save</button></form>
    <p class="ln-muted ln-small">Email: <?= e($u['email']) ?> · <a href="<?= e(url('/account/forgot/')) ?>">Change password</a></p>
  </div>
</section>
