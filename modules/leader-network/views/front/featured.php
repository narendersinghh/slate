<?php require __DIR__ . '/_form.php'; use SlateModules\LeaderNetwork\Network; ?>
<section class="ln-wrap ln-narrow">
  <a class="ln-back" href="<?= e(url('/account/')) ?>">← Your account</a>
  <p class="ln-eyebrow">Featured placement</p>
  <h1 class="ln-h1">Put <?= e($p['title']) ?> in the spotlight</h1>
  <p class="ln-lead">Featured profiles appear at the top of their category page with a “Featured” badge, ahead of the regular listing.</p>
  <?php if ($error): ?><div class="ln-alert ln-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$terms): ?>
    <div class="ln-alert">This profile is not in a category yet. Contact us to add one.</div>
  <?php else: ?>
  <form method="post" class="ln-form" action="<?= e(url('/account/featured/' . $p['id'] . '/')) ?>">
    <?= csrf_field() ?>
    <?= ln_field('Category', '<select class="ln-input" name="term_id" required>' . implode('', array_map(static fn($t) => '<option value="' . (int)$t['id'] . '">' . e($t['name']) . '</option>', $terms)) . '</select>') ?>
    <div class="ln-plans">
      <?php foreach ($plans as $i => $pl): ?>
        <label class="ln-plan"><input type="radio" name="plan" value="<?= e($pl['id']) ?>"<?= $i === 0 ? ' checked' : '' ?>>
          <span class="ln-plan-name"><?= e($pl['name']) ?></span>
          <span class="ln-plan-price"><?= e(Network::money((int)$pl['price'], $pl['currency'])) ?></span>
          <span class="ln-small ln-muted"><?= (int)$pl['days'] ?> days of featured placement</span></label>
      <?php endforeach; ?>
    </div>
    <button class="ln-btn ln-btn-primary" type="submit"><?= Network::get('payment_provider') === 'manual' ? 'Request placement' : 'Continue to secure payment' ?></button>
    <p class="ln-muted ln-small"><?= Network::get('payment_provider') === 'manual' ? e(Network::get('manual_instructions')) : 'You will be redirected to our payment partner. The placement starts as soon as the payment is confirmed.' ?></p>
  </form>
  <?php endif; ?>
</section>
