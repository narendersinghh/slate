<?php
require __DIR__ . '/_form.php';
use Slate\Posts;
$input = static function (array $f, string $name, mixed $v): string {
    $v = is_array($v) ? '' : (string)$v;
    return match ($f['type']) {
        'textarea' => '<textarea class="ln-input" name="' . e($name) . '" rows="4">' . e($v) . '</textarea>',
        'richtext' => '<textarea class="ln-input" name="' . e($name) . '" rows="6">' . e(trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $v)))) . '</textarea>',
        'url' => '<input class="ln-input" type="url" name="' . e($name) . '" value="' . e($v) . '" placeholder="https://">',
        'email' => '<input class="ln-input" type="email" name="' . e($name) . '" value="' . e($v) . '">',
        'date' => '<input class="ln-input" type="date" name="' . e($name) . '" value="' . e($v) . '">',
        'number' => '<input class="ln-input" type="number" name="' . e($name) . '" value="' . e($v) . '">',
        'select' => '<select class="ln-input" name="' . e($name) . '"><option value=""></option>' . implode('', array_map(static function ($o) use ($v) {
            $val = is_array($o) ? (string)($o['value'] ?? '') : (string)$o; $lab = is_array($o) ? (string)($o['label'] ?? $val) : (string)$o;
            return '<option value="' . e($val) . '"' . ($val === $v ? ' selected' : '') . '>' . e($lab) . '</option>'; }, (array)($f['options'] ?? []))) . '</select>',
        default => '<input class="ln-input" name="' . e($name) . '" value="' . e($v) . '">',
    };
};
?>
<section class="ln-wrap">
  <a class="ln-back" href="<?= e(url('/account/')) ?>">← Your account</a>
  <div class="ln-claim-hero">
    <?= ln_thumb($p, 'ln-avatar ln-avatar-lg') ?>
    <div><p class="ln-eyebrow">Verified owner</p><h1 class="ln-h1"><?= e($p['title']) ?></h1>
      <a class="ln-link" href="<?= e(Posts::permalink($p)) ?>" target="_blank" rel="noopener">View public profile ↗</a></div>
  </div>
  <?= ln_flash_html() ?>
  <?php if ($pendingEdit): ?><div class="ln-alert">Your last changes (<?= e(local_date($pendingEdit['created_at'], 'M j, H:i')) ?>) are waiting for review. Saving again replaces them.</div><?php endif; ?>

  <div class="ln-grid-edit">
    <form method="post" class="ln-card ln-form" action="<?= e(url('/account/profile/' . $p['id'] . '/')) ?>">
      <?= csrf_field() ?>
      <h2 class="ln-h3">Profile content</h2>
      <p class="ln-muted ln-small">Keep it factual — our editors review changes before they go live. Add a source link for new achievements where you can.</p>
      <?php foreach ($fields as $name => $f): $v = $values[$name] ?? ''; ?>
        <?php if ($f['type'] === 'repeater'): $subs = (array)($f['sub_fields'] ?? []); $rows = is_array($v) && $v ? array_values($v) : [[]]; ?>
          <fieldset class="ln-repeater" data-ln-repeater>
            <legend class="ln-label"><?= e($f['label']) ?></legend>
            <?php if (!empty($f['help'])): ?><small class="ln-help"><?= e($f['help']) ?></small><?php endif; ?>
            <div data-rows>
            <?php foreach ($rows as $i => $row): ?>
              <div class="ln-rep-row" data-row>
                <?php foreach ($subs as $sf): ?>
                  <?= ln_field($sf['label'], $input($sf, "fields[$name][$i][{$sf['name']}]", $row[$sf['name']] ?? '')) ?>
                <?php endforeach; ?>
                <button type="button" class="ln-link ln-danger" data-remove>Remove</button>
              </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="ln-btn ln-btn-sm" data-add>+ Add <?= e(strtolower($f['label'])) ?></button>
          </fieldset>
        <?php else: ?>
          <?= ln_field($f['label'], $input($f, "fields[$name]", $v), (string)($f['help'] ?? '')) ?>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$fields): ?><p class="ln-muted">No editable fields are configured yet.</p><?php endif; ?>
      <button class="ln-btn ln-btn-primary" type="submit">Submit changes</button>
    </form>

    <aside class="ln-stack">
      <form method="post" class="ln-card ln-form" action="<?= e(url('/account/profile/' . $p['id'] . '/')) ?>">
        <?= csrf_field() ?><input type="hidden" name="do" value="booking">
        <h2 class="ln-h3">Booking requests</h2>
        <p class="ln-muted ln-small">Let event organisers, schools and companies request you for talks, interviews and appearances. Requests come straight to your inbox — no commission.</p>
        <label class="ln-check"><input type="checkbox" name="booking_enabled" value="1"<?= $owner['booking_enabled'] ? ' checked' : '' ?>> Show a booking form on my profile</label>
        <?= ln_field('Send requests to', '<input class="ln-input" type="email" name="booking_email" value="' . e($owner['booking_email']) . '" placeholder="' . e(\Slate\Auth::user()['email'] ?? '') . '">', 'Leave empty to use your account email.') ?>
        <?= ln_field('Note for organisers (optional)', '<textarea class="ln-input" name="booking_note" rows="3" placeholder="e.g. Available for keynotes in India and the Middle East. Typical lead time: 6 weeks.">' . e($owner['booking_note']) . '</textarea>') ?>
        <button class="ln-btn" type="submit">Save booking settings</button>
      </form>
      <?php if (\SlateModules\LeaderNetwork\Network::get('featured')): ?>
      <div class="ln-card ln-promo">
        <h2 class="ln-h3">Get featured</h2>
        <p class="ln-small">Appear at the top of your category page with a “Featured” badge.</p>
        <a class="ln-btn ln-btn-primary" href="<?= e(url('/account/featured/' . $p['id'] . '/')) ?>">See plans</a>
      </div>
      <?php endif; ?>
    </aside>
  </div>
</section>
<script>
document.querySelectorAll('[data-ln-repeater]').forEach(function (box) {
  var rows = box.querySelector('[data-rows]');
  function renumber() {
    rows.querySelectorAll('[data-row]').forEach(function (row, i) {
      row.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/\]\[\d+\]\[/, '][' + i + ']['); });
    });
  }
  box.querySelector('[data-add]').addEventListener('click', function () {
    var first = rows.querySelector('[data-row]'); var c = first.cloneNode(true);
    c.querySelectorAll('input,textarea,select').forEach(function (el) { el.value = ''; });
    rows.appendChild(c); renumber();
  });
  rows.addEventListener('click', function (e) {
    if (!e.target.matches('[data-remove]')) return;
    var all = rows.querySelectorAll('[data-row]'), row = e.target.closest('[data-row]');
    if (all.length > 1) { row.remove(); } else { row.querySelectorAll('input,textarea,select').forEach(function (el) { el.value = ''; }); }
    renumber();
  });
});
</script>
