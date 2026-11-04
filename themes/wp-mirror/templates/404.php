<?php $snap = wpm_archive_snapshot('special-404'); ?>
<?php partial('header'); ?>
<?php if ($snap !== null): ?>
<?= $snap ?>
<?php else: ?>
<main id="content" class="site-main slate-mirror-main"><div class="smx-wrap smx-404"><h1>Page not found</h1><p>The page you are looking for doesn’t exist.</p><p><a class="btn" href="<?= e(home_url()) ?>">Back to homepage</a></p></div></main>
<?php endif; ?>
<?php partial('footer'); ?>
