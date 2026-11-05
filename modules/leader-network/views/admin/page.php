<?php
use Slate\Auth;
use Slate\ContentModel;
use Slate\DB;
use Slate\Posts;
use Slate\Terms;
use SlateModules\LeaderNetwork\Network;

Network::expireFeatured();
$tab = (string)($_GET['tab'] ?? 'claims');
$counts = [
    'claims' => (int)DB::val("SELECT COUNT(*) FROM {{ln_claims}} WHERE status = 'pending'"),
    'edits' => (int)DB::val("SELECT COUNT(*) FROM {{ln_edits}} WHERE status = 'pending'"),
    'bookings' => (int)DB::val("SELECT COUNT(*) FROM {{ln_bookings}} WHERE status = 'new'"),
    'featured' => (int)DB::val("SELECT COUNT(*) FROM {{ln_featured}} WHERE status = 'pending'"),
];
$tabs = ['claims' => 'Claims', 'owners' => 'Owners', 'edits' => 'Profile updates', 'bookings' => 'Bookings', 'featured' => 'Featured', 'settings' => 'Settings'];
$action = admin_url('m/leader-network/action');
$user = static fn(int $id) => DB::row('SELECT id, display_name, email, meta FROM {{users}} WHERE id = :id', ['id' => $id]);
$postLink = static function (int $id): string {
    $p = Posts::find($id);
    return $p ? '<a href="' . e(Posts::permalink($p)) . '" target="_blank" rel="noopener"><strong>' . e($p['title']) . '</strong></a> <a class="muted small" href="' . e(admin_url('content/' . $p['type'] . '/' . $p['id'])) . '">edit</a>' : '#' . $id;
};
$btn = static fn(string $do, int $id, string $label, string $cls = 'btn-sm', string $extra = '') =>
    '<form method="post" action="' . e(admin_url('m/leader-network/action')) . '" style="display:inline">' . csrf_field() . '<input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="id" value="' . $id . '">' . $extra
    . '<button class="btn ' . $cls . '" type="submit">' . $label . '</button></form>';
?>
<p class="muted" style="margin-top:0">Visitors can create accounts, claim profiles, keep them up to date, receive booking requests and pay for featured placement. Public pages:
  <a href="<?= e(url('/' . Network::get('otd_slug') . '/')) ?>" target="_blank">On this day</a> · <a href="<?= e(url('/account/')) ?>" target="_blank">Member account</a></p>
<nav class="settings-tabs" style="margin-bottom:18px">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="<?= $tab === $k ? 'is-active' : '' ?>" href="<?= e(admin_url('m/leader-network?tab=' . $k)) ?>"><?= e($label) ?><?= !empty($counts[$k]) ? ' <span class="pill pill-accent">' . $counts[$k] . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'claims'):
    $rows = DB::all("SELECT * FROM {{ln_claims}} ORDER BY (status = 'pending') DESC, id DESC LIMIT 200"); ?>
  <?php if (!$rows): ?><div class="card empty-state"><?= icon('users', 32) ?><h2>No claims yet</h2><p class="muted">Every profile shows a “Claim this profile” box. Claims appear here for review.</p></div><?php else: ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Profile</th><th>Claimant</th><th>Proof</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $c): $u = $user((int)$c['user_id']); $meta = json_decode((string)($u['meta'] ?? ''), true) ?: []; ?>
      <tr>
        <td><?= $postLink((int)$c['post_id']) ?><br><small class="muted"><?= e(local_date($c['created_at'], 'M j, Y H:i')) ?></small></td>
        <td><?= e($c['full_name']) ?> <small class="muted">(<?= e($c['relation']) ?>)</small><br><a href="mailto:<?= e($u['email'] ?? '') ?>"><?= e($u['email'] ?? '') ?></a>
          <?= empty($meta['email_verified']) ? ' <span class="pill">email unverified</span>' : ' <span class="pill pill-success">email verified</span>' ?><?= $c['phone'] ? '<br>' . e($c['phone']) : '' ?></td>
        <td><a href="<?= e($c['proof_url']) ?>" target="_blank" rel="noopener nofollow"><?= e(mb_strimwidth($c['proof_url'], 0, 50, '…')) ?></a><?= $c['message'] ? '<br><small>' . nl2br(e($c['message'])) . '</small>' : '' ?></td>
        <td><span class="status status-<?= $c['status'] === 'approved' ? 'publish' : ($c['status'] === 'pending' ? 'draft' : 'trash') ?>"><?= e($c['status']) ?></span><?= $c['admin_note'] ? '<br><small class="muted">' . e($c['admin_note']) . '</small>' : '' ?></td>
        <td class="nowrap"><?php if ($c['status'] === 'pending'): ?>
          <?= $btn('claim_approve', (int)$c['id'], 'Approve', 'btn-sm btn-primary') ?>
          <?= $btn('claim_reject', (int)$c['id'], 'Reject', 'btn-sm btn-ghost', '<input class="input input-sm" name="note" placeholder="Reason (emailed)" style="width:150px">') ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>

<?php elseif ($tab === 'owners'):
    $rows = DB::all('SELECT o.*, u.display_name, u.email FROM {{ln_owners}} o LEFT JOIN {{users}} u ON u.id = o.user_id ORDER BY o.since DESC LIMIT 500'); ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Profile</th><th>Owner</th><th>Bookings</th><th>Since</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $o): ?>
      <tr><td><?= $postLink((int)$o['post_id']) ?></td><td><?= e($o['display_name']) ?><br><small class="muted"><?= e($o['email']) ?></small></td>
        <td><?= $o['booking_enabled'] ? '<span class="pill pill-success">on</span>' : '<span class="muted">off</span>' ?></td>
        <td><?= e(local_date($o['since'], 'M j, Y')) ?></td>
        <td><?= $btn('owner_remove', (int)$o['post_id'], 'Remove owner', 'btn-sm btn-ghost btn-danger') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No verified owners yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'edits'):
    $rows = DB::all("SELECT * FROM {{ln_edits}} ORDER BY (status = 'pending') DESC, id DESC LIMIT 100");
    $defs = [];
    foreach (ContentModel::fieldsFor('post', (string)Network::get('post_type')) as $f) { $defs[$f['name']] = $f; }
    $show = static function ($v): string {
        if (is_array($v)) {
            return '<ul style="margin:0;padding-left:16px">' . implode('', array_map(static fn($r) => '<li>' . e(is_array($r) ? implode(' · ', array_filter(array_map(static fn($x) => is_array($x) ? '' : strip_tags((string)$x), $r))) : (string)$r) . '</li>', $v)) . '</ul>';
        }
        return nl2br(e(strip_tags(str_replace('</p>', "\n", (string)$v))));
    }; ?>
  <?php if (!$rows): ?><div class="card empty-state"><h2>No profile updates</h2><p class="muted">Changes submitted by verified owners appear here<?= Network::get('moderate_edits') ? ' for review' : '' ?>.</p></div><?php endif; ?>
  <?php foreach ($rows as $ed): $data = json_decode((string)$ed['data'], true) ?: []; $p = Posts::find((int)$ed['post_id']); $u = $user((int)$ed['user_id']); ?>
    <section class="card">
      <div class="card-head"><h2><?= $postLink((int)$ed['post_id']) ?> <small class="muted">by <?= e($u['display_name'] ?? '?') ?> · <?= e(local_date($ed['created_at'], 'M j, H:i')) ?></small></h2>
        <span class="status status-<?= $ed['status'] === 'approved' ? 'publish' : ($ed['status'] === 'pending' ? 'draft' : 'trash') ?>"><?= e($ed['status']) ?></span></div>
      <div class="table-wrap"><table class="table"><thead><tr><th>Field</th><th>Current</th><th>Proposed</th></tr></thead><tbody>
      <?php foreach ((array)($data['fields'] ?? []) as $name => $new): $old = $p['fields'][$name] ?? ''; if (json_encode($old) === json_encode($new) || (!$old && !$new)) continue; ?>
        <tr><td><strong><?= e($defs[$name]['label'] ?? $name) ?></strong></td><td class="muted" style="max-width:380px"><?= $show($old) ?></td><td style="max-width:380px;background:#f2fbf5"><?= $show($new) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($ed['status'] === 'pending'): ?><div class="card-pad">
        <?= $btn('edit_approve', (int)$ed['id'], 'Publish changes', 'btn-sm btn-primary') ?>
        <?= $btn('edit_reject', (int)$ed['id'], 'Reject', 'btn-sm btn-ghost', '<input class="input input-sm" name="note" placeholder="Reason (emailed)" style="width:200px">') ?>
      </div><?php endif; ?>
    </section>
  <?php endforeach; ?>

<?php elseif ($tab === 'bookings'):
    $rows = DB::all('SELECT * FROM {{ln_bookings}} ORDER BY id DESC LIMIT 300'); ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Received</th><th>For</th><th>From</th><th>Event</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $b): ?>
      <tr><td class="nowrap"><?= e(local_date($b['created_at'], 'M j, H:i')) ?></td><td><?= $postLink((int)$b['post_id']) ?></td>
        <td><?= e($b['name']) ?><br><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a><?= $b['phone'] ? '<br>' . e($b['phone']) : '' ?><?= $b['organization'] ? '<br><small>' . e($b['organization']) . '</small>' : '' ?></td>
        <td><?= e($b['event_type']) ?> <?= e($b['event_date']) ?><br><small><?= e($b['location']) ?> <?= e($b['audience']) ?> <?= e($b['budget']) ?></small><br><small class="muted"><?= nl2br(e($b['message'])) ?></small></td>
        <td><form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="booking_status"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <select class="input input-sm" name="status" onchange="this.form.submit()"><?php foreach (['new', 'contacted', 'confirmed', 'declined', 'spam'] as $s): ?><option<?= $b['status'] === $s ? ' selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No booking requests yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'featured'):
    $rows = DB::all('SELECT * FROM {{ln_featured}} ORDER BY (status = \'pending\') DESC, id DESC LIMIT 300');
    $cats = Terms::tree((string)Network::get('category_tax')); ?>
  <?php if (Auth::can('manage_settings')): ?>
  <section class="card"><div class="card-head"><h2>Feature a profile manually</h2></div>
    <form class="card-pad grid-form" method="post" action="<?= e($action) ?>" style="grid-template-columns:2fr 2fr 1fr auto;align-items:end"><?= csrf_field() ?><input type="hidden" name="do" value="featured_add">
      <label class="field"><span>Profile ID</span><input class="input" name="post_id" type="number" required placeholder="e.g. 3792"></label>
      <label class="field"><span>Category</span><select class="input" name="term_id"><?php foreach ($cats as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e(str_repeat('— ', (int)($t['depth'] ?? 0)) . $t['name']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Plan</span><select class="input" name="plan"><?php foreach ((array)Network::get('featured_plans') as $pl): ?><option value="<?= e($pl['id']) ?>"><?= e($pl['name']) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-primary">Feature now</button></form></section>
  <?php endif; ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Profile</th><th>Category</th><th>Plan</th><th>Payment</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $f): $t = $f['term_id'] ? Terms::find((int)$f['term_id']) : null; ?>
      <tr><td><?= $postLink((int)$f['post_id']) ?><br><small class="muted"><?= e(local_date($f['created_at'], 'M j, Y')) ?></small></td><td><?= e($t['name'] ?? '—') ?></td>
        <td><?= (int)$f['days'] ?> days · <?= e(Network::money((int)$f['amount'], $f['currency'])) ?></td>
        <td><?= e($f['provider']) ?><br><small class="muted"><?= e($f['payment_ref']) ?></small></td>
        <td><span class="status status-<?= $f['status'] === 'active' ? 'publish' : ($f['status'] === 'pending' ? 'draft' : 'trash') ?>"><?= e($f['status']) ?></span>
          <?= $f['status'] === 'active' ? '<br><small>until ' . e(local_date($f['ends_at'], 'M j, Y')) . '</small>' : '' ?></td>
        <td class="nowrap"><?php if ($f['status'] === 'pending'): ?><?= $btn('featured_activate', (int)$f['id'], 'Mark paid & activate', 'btn-sm btn-primary', '<input class="input input-sm" name="note" placeholder="Payment ref" style="width:120px">') ?><?php endif; ?>
          <?php if (in_array($f['status'], ['pending', 'active'], true)): ?><?= $btn('featured_cancel', (int)$f['id'], 'Cancel', 'btn-sm btn-ghost') ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No featured placements yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'settings'):
    Auth::require('manage_settings');
    $s = Network::settings();
    $fieldDefs = ContentModel::fieldsFor('post', (string)$s['post_type']); ?>
  <form method="post" action="<?= e($action) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="settings">
  <section class="card"><div class="card-head"><h2>Profiles</h2></div><div class="card-pad grid-form">
    <label class="field"><span>Profile post type</span><select class="input" name="post_type"><?php foreach (ContentModel::types() as $slug => $t): ?><option value="<?= e($slug) ?>"<?= $s['post_type'] === $slug ? ' selected' : '' ?>><?= e($t['plural']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Category taxonomy (featured placement, On this day filter)</span><select class="input" name="category_tax"><?php foreach (ContentModel::taxonomies() as $slug => $t): ?><option value="<?= e($slug) ?>"<?= $s['category_tax'] === $slug ? ' selected' : '' ?>><?= e($t['plural']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Country taxonomy</span><select class="input" name="country_tax"><?php foreach (ContentModel::taxonomies() as $slug => $t): ?><option value="<?= e($slug) ?>"<?= $s['country_tax'] === $slug ? ' selected' : '' ?>><?= e($t['plural']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Birth date field</span><input class="input" name="birth_field" value="<?= e($s['birth_field']) ?>"></label>
    <label class="field"><span>Death date field</span><input class="input" name="death_field" value="<?= e($s['death_field']) ?>"></label>
    <label class="field"><span>Key dates field (repeater with “date”, “event”, “source”)</span><input class="input" name="dates_field" value="<?= e($s['dates_field']) ?>"></label>
    <label class="field"><span>“On this day” URL</span><input class="input" name="otd_slug" value="<?= e($s['otd_slug']) ?>"></label>
    <label class="switch-row"><input type="checkbox" class="switch" name="auto_inject" value="1"<?= $s['auto_inject'] ? ' checked' : '' ?>> Add the claim / booking box to profiles and the featured strip to category pages automatically</label>
  </div></section>
  <section class="card"><div class="card-head"><h2>Accounts, claims &amp; editing</h2></div><div class="card-pad grid-form">
    <label class="switch-row"><input type="checkbox" class="switch" name="registration" value="1"<?= $s['registration'] ? ' checked' : '' ?>> Visitors can create accounts</label>
    <label class="switch-row"><input type="checkbox" class="switch" name="claims" value="1"<?= $s['claims'] ? ' checked' : '' ?>> Profiles can be claimed</label>
    <label class="switch-row"><input type="checkbox" class="switch" name="moderate_edits" value="1"<?= $s['moderate_edits'] ? ' checked' : '' ?>> Review owner changes before they go live (recommended)</label>
    <div class="field"><span>Fields owners can edit</span><div style="display:flex;flex-wrap:wrap;gap:6px 18px">
      <?php foreach ($fieldDefs as $f): if (!in_array($f['type'], ['text', 'textarea', 'richtext', 'url', 'email', 'number', 'date', 'repeater', 'select'], true)) continue; ?>
        <label class="small"><input type="checkbox" name="owner_fields[]" value="<?= e($f['name']) ?>"<?= in_array($f['name'], (array)$s['owner_fields'], true) ? ' checked' : '' ?>> <?= e($f['label']) ?></label>
      <?php endforeach; ?></div></div>
    <label class="field"><span>Notification email (defaults to the admin email)</span><input class="input" type="email" name="notify_email" value="<?= e($s['notify_email']) ?>"></label>
  </div></section>
  <section class="card"><div class="card-head"><h2>Bookings</h2></div><div class="card-pad grid-form">
    <label class="switch-row"><input type="checkbox" class="switch" name="bookings" value="1"<?= $s['bookings'] ? ' checked' : '' ?>> Booking requests</label>
    <label class="switch-row"><input type="checkbox" class="switch" name="booking_needs_claim" value="1"<?= $s['booking_needs_claim'] ? ' checked' : '' ?>> Only on claimed profiles whose owner turned bookings on (recommended)</label>
  </div></section>
  <section class="card"><div class="card-head"><h2>Featured placement (paid)</h2></div><div class="card-pad grid-form">
    <label class="switch-row"><input type="checkbox" class="switch" name="featured" value="1"<?= $s['featured'] ? ' checked' : '' ?>> Owners can buy featured placement in their category</label>
    <label class="field"><span>Featured slots per category page</span><input class="input" type="number" name="featured_slots" min="1" max="48" value="<?= (int)$s['featured_slots'] ?>"></label>
    <div class="field"><span>Plans</span>
      <?php $plans = array_merge((array)$s['featured_plans'], [['name' => '', 'days' => '', 'price' => '', 'currency' => 'INR']]); foreach ($plans as $i => $pl): ?>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;margin-bottom:6px">
          <input class="input" name="plans[<?= $i ?>][name]" placeholder="Name, e.g. 30 days" value="<?= e($pl['name']) ?>">
          <input class="input" type="number" name="plans[<?= $i ?>][days]" placeholder="Days" value="<?= e((string)$pl['days']) ?>">
          <input class="input" type="number" name="plans[<?= $i ?>][price]" placeholder="Price" value="<?= e((string)$pl['price']) ?>">
          <input class="input" name="plans[<?= $i ?>][currency]" placeholder="INR" value="<?= e($pl['currency']) ?>"></div>
      <?php endforeach; ?><small class="help">Leave a name empty to remove that plan.</small></div>
    <label class="field"><span>Payment</span><select class="input" name="payment_provider">
      <?php foreach (['manual' => 'Manual (you confirm payments here)', 'razorpay' => 'Razorpay payment links', 'stripe' => 'Stripe Checkout'] as $k => $l): ?><option value="<?= $k ?>"<?= $s['payment_provider'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Manual payment instructions (shown to the owner)</span><textarea class="input" name="manual_instructions" rows="2"><?= e($s['manual_instructions']) ?></textarea></label>
    <label class="field"><span>Razorpay key ID</span><input class="input" name="razorpay_key_id" value="<?= e($s['razorpay_key_id']) ?>"></label>
    <?php foreach (['razorpay_key_secret' => 'Razorpay key secret', 'razorpay_webhook_secret' => 'Razorpay webhook secret (webhook URL: ' . site_url('/ln/webhook/razorpay') . ', event payment_link.paid)',
        'stripe_secret' => 'Stripe secret key', 'stripe_webhook_secret' => 'Stripe webhook signing secret (URL: ' . site_url('/ln/webhook/stripe') . ', event checkout.session.completed)'] as $k => $l): ?>
      <label class="field"><span><?= e($l) ?></span><input class="input" type="password" name="<?= $k ?>" autocomplete="off" placeholder="<?= $s[$k] ? '•••••••• saved — type to replace' : 'Not set' ?>"></label>
    <?php endforeach; ?>
  </div></section>
  <button class="btn btn-primary">Save settings</button>
  </form>
  <section class="card" style="margin-top:20px"><div class="card-head"><h2>Tools</h2></div><div class="card-pad">
    <p class="muted">“On this day” reads an index of birth dates, death dates and key dates. It updates automatically when a profile is saved; rebuild it after bulk imports.</p>
    <?= $btn('reindex', 0, 'Rebuild “On this day” index', 'btn-sm') ?> <span class="muted small"><?= (int)DB::val('SELECT COUNT(*) FROM {{ln_dates}}') ?> dates indexed</span>
  </div></section>
<?php endif; ?>
