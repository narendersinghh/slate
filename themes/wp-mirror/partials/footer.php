<?php $site = wpm_site(); $bare = !empty($bare); ?>
<?php if (!$bare): ?>
  <?php if ($site['footer'] !== ''): ?><?= $site['footer'] ?><?php else: ?>
  <footer class="slate-mirror-footer"><div class="smx-wrap">© <?= date('Y') ?> <?= e(site_name()) ?> <?= menu('footer', 'smx-menu') ?></div></footer>
  <?php endif; ?>
<?php endif; ?>
<?= $site['wrap_close'] ?>
<?php foreach ($site['popups'] ?? [] as $popup) echo $popup; ?>
<?php wpm_assets($site['foot']); ?>
<?php wpm_page_scripts(); ?>
<script>
/* Mark the current page in the mirrored navigation (the captured header is page-neutral). */
(function () {
  var here = location.pathname.replace(/\/+$/, '') || '/';
  document.querySelectorAll('header a[href], [data-elementor-type="header"] a[href]').forEach(function (a) {
    var u; try { u = new URL(a.href, location.href); } catch (e) { return; }
    if (u.origin !== location.origin) return;
    var p = u.pathname.replace(/\/+$/, '') || '/';
    if (p === here) { a.classList.add('elementor-item-active'); a.setAttribute('aria-current', 'page'); var li = a.closest('li'); if (li) li.classList.add('current-menu-item'); }
  });
})();
</script>
<?php slate_footer(); ?>
</body>
</html>
