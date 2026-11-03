<?php $site = wpm_site(); $bare = !empty($bare); ?>
<?php if (!$bare): ?>
  <?php if ($site['footer'] !== ''): ?><?= $site['footer'] ?><?php else: ?>
  <footer class="slate-mirror-footer"><div class="smx-wrap">© <?= date('Y') ?> <?= e(site_name()) ?> <?= menu('footer', 'smx-menu') ?></div></footer>
  <?php endif; ?>
<?php endif; ?>
<?= $site['wrap_close'] ?>
<?php foreach ($site['popups'] ?? [] as $popup) echo $popup; ?>
<?php wpm_assets($site['foot']); ?>
<?php slate_footer(); ?>
</body>
</html>
