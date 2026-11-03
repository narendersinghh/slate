<div class="empty-state">
  <?= icon('alert', 40) ?>
  <h1><?= e($title ?? 'Error') ?></h1>
  <p class="muted"><?= e($message ?? '') ?></p>
  <a class="btn" href="<?= e(admin_url()) ?>">Back to dashboard</a>
</div>
