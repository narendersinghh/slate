<?php
use Slate\Auth;
use Slate\Posts;
use SlateModules\LeaderNetwork\Network;
$owner = Network::owner((int)$p['id']);
$isOwner = $owner && Auth::user() && (int)$owner['user_id'] === Auth::id();
$featured = Network::get('featured') ? Network::isFeatured((int)$p['id']) : [];
$booking = Network::get('bookings') && (!Network::get('booking_needs_claim') || ($owner && $owner['booking_enabled']));
$first = explode(' ', trim($p['title']))[0];
?>
<link rel="stylesheet" href="<?= e(url('/modules/leader-network/assets/ln.css')) ?>?v=1">
<section class="ln-profile-actions" data-ln-actions aria-label="Profile options">
  <div class="ln-wrap">
    <div class="ln-pa-grid<?= $booking ? '' : ' ln-pa-single' ?>">
      <div class="ln-pa-card">
        <?php if ($owner): ?>
          <p class="ln-eyebrow"><span class="ln-verified" aria-hidden="true">✓</span> Verified profile</p>
          <h2 class="ln-h3">Managed by <?= e($p['title']) ?>’s team</h2>
          <p class="ln-muted">This profile is claimed and kept up to date by its verified owner<?= $featured ? ' and is currently <strong>featured</strong>' : '' ?>.</p>
          <?php if ($isOwner): ?><a class="ln-btn ln-btn-primary" href="<?= e(url('/account/profile/' . $p['id'] . '/')) ?>">Edit this profile</a><?php endif; ?>
        <?php elseif (Network::get('claims')): ?>
          <p class="ln-eyebrow">Is this you?</p>
          <h2 class="ln-h3">Claim the <?= e($p['title']) ?> profile</h2>
          <ul class="ln-ticks">
            <li>Keep your story accurate and add new milestones</li>
            <li>Receive talk, interview and event requests directly</li>
            <li>Get featured at the top of your category</li>
          </ul>
          <a class="ln-btn ln-btn-primary" href="<?= e(url('/claim/' . $p['id'] . '/')) ?>" rel="nofollow">Claim this profile — it’s free</a>
          <p class="ln-small ln-muted">For <?= e($p['title']) ?> or their official team. Every claim is verified by hand.</p>
        <?php endif; ?>
      </div>
      <?php if ($booking): ?>
      <div class="ln-pa-card" id="ln-book">
        <p class="ln-eyebrow">Bookings</p>
        <h2 class="ln-h3">Invite <?= e($first) ?> to your event</h2>
        <?php if ($owner && $owner['booking_note']): ?><p class="ln-muted"><?= e($owner['booking_note']) ?></p><?php endif; ?>
        <form class="ln-form ln-book-form" method="post" action="<?= e(url('/book/' . $p['id'] . '/')) ?>" data-ln-book>
          <div class="ln-hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
          <input type="hidden" name="ln_ts" value="0">
          <div class="ln-row2">
            <label class="ln-field"><span class="ln-label">Your name</span><input class="ln-input" name="name" required autocomplete="name"></label>
            <label class="ln-field"><span class="ln-label">Email</span><input class="ln-input" type="email" name="email" required autocomplete="email"></label>
          </div>
          <div class="ln-row2">
            <label class="ln-field"><span class="ln-label">Organisation</span><input class="ln-input" name="organization" autocomplete="organization"></label>
            <label class="ln-field"><span class="ln-label">Phone</span><input class="ln-input" name="phone" inputmode="tel" autocomplete="tel"></label>
          </div>
          <div class="ln-row2">
            <label class="ln-field"><span class="ln-label">Type of event</span><select class="ln-input" name="event_type">
              <option>Keynote / talk</option><option>Panel discussion</option><option>Workshop / training</option><option>Interview / podcast</option><option>Campus event</option><option>Award ceremony</option><option>Other</option></select></label>
            <label class="ln-field"><span class="ln-label">Event date</span><input class="ln-input" type="date" name="event_date"></label>
          </div>
          <div class="ln-row2">
            <label class="ln-field"><span class="ln-label">City / online</span><input class="ln-input" name="location"></label>
            <label class="ln-field"><span class="ln-label">Audience size</span><select class="ln-input" name="audience"><option value="">—</option><option>Under 50</option><option>50–200</option><option>200–1,000</option><option>1,000+</option><option>Online</option></select></label>
          </div>
          <label class="ln-field"><span class="ln-label">About the event</span><textarea class="ln-input" name="message" rows="3" required minlength="10" placeholder="Theme, audience, format and anything else that helps."></textarea></label>
          <button class="ln-btn ln-btn-primary" type="submit">Send request</button>
          <p class="ln-small ln-muted" data-ln-book-msg role="status"></p>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <p class="ln-small ln-muted ln-pa-foot">Spotted an error on this page? <a href="<?= e(url('/contact-us/')) ?>">Tell us</a> — we fact-check every update.</p>
  </div>
</section>
<?php if ($booking): ?>
<script>
(function () {
  var f = document.querySelector('[data-ln-book]'); if (!f) return;
  f.ln_ts.value = Date.now();
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = f.querySelector('[data-ln-book-msg]'), btn = f.querySelector('button[type=submit]');
    btn.disabled = true; msg.textContent = 'Sending…';
    fetch(f.action, { method: 'POST', body: new FormData(f), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { msg.textContent = d.message || d.error || 'Sent.'; if (d.ok) { f.reset(); f.ln_ts.value = Date.now(); } })
      .catch(function () { msg.textContent = 'Something went wrong. Please try again.'; })
      .finally(function () { btn.disabled = false; });
  });
})();
</script>
<?php endif; ?>
