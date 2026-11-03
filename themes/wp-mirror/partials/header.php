<?php $site = wpm_site(); $bare = !empty($bare); ?>
<!doctype html>
<html lang="<?= e($site['lang'] ?: option('language', 'en')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wpm_assets($site['head']); ?>
<?php wpm_page_assets(); ?>
<link rel="stylesheet" href="<?= e(theme_url('assets/mirror.css')) ?>">
<?php slate_head(); ?>
</head>
<body class="<?= e(wpm_body_class()) ?>">
<?php slate_body_open(); ?>
<?= $site['wrap_open'] ?>
<?php if (!$bare): ?>
  <?php if ($site['header'] !== ''): ?><?= $site['header'] ?><?php else: ?>
  <header class="slate-mirror-header"><div class="smx-wrap"><a href="<?= e(home_url()) ?>" class="smx-brand"><?= e(site_name()) ?></a><nav><?= menu('primary', 'smx-menu') ?></nav></div></header>
  <?php endif; ?>
<?php endif; ?>
