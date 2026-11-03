</main>
<?php if (empty($bare)): ?>
<footer class="site-footer">
  <div class="wrap footer-inner">
    <p>© <?= date('Y') ?> <?= e(site_name()) ?>. <?= e(site_tagline()) ?></p>
    <nav aria-label="Footer"><?= menu('footer', 'footer-menu') ?></nav>
  </div>
</footer>
<?php endif; ?>
<?php slate_footer(); ?>
</body>
</html>
