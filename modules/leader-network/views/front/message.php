<section class="ln-auth">
  <div class="ln-card ln-auth-card">
    <h1 class="ln-h1"><?= e($heading) ?></h1>
    <p class="ln-lead"><?= e($text) ?></p>
    <?php if (!empty($link)): ?><a class="ln-btn ln-btn-primary" href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a><?php endif; ?>
  </div>
</section>
