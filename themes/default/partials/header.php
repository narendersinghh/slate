<!doctype html>
<html lang="<?= e(option('language', 'en')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= e(theme_url('assets/style.css')) ?>">
<?php slate_head(); ?>
</head>
<body class="<?= e('kind-' . page_data('kind', 'page')) ?>">
<?php slate_body_open(); ?>
<?php if (empty($bare)): ?>
<a class="skip-link" href="#content">Skip to content</a>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="site-brand" href="<?= e(home_url()) ?>" rel="home">
      <?php if ($logo = (int)option('site_logo')): ?><?= image($logo, ['class' => 'site-logo', 'alt' => site_name(), 'loading' => 'eager', 'size' => 'thumb']) ?><?php else: ?><span class="site-mark" aria-hidden="true"></span><?php endif; ?>
      <span class="site-name"><?= e(site_name()) ?></span>
    </a>
    <button class="nav-toggle" aria-expanded="false" aria-controls="site-nav" onclick="var n=document.getElementById('site-nav');var o=n.classList.toggle('open');this.setAttribute('aria-expanded',o)"><span></span><span class="sr-only">Menu</span></button>
    <nav id="site-nav" class="site-nav" aria-label="Primary"><?= menu('primary', 'menu') ?></nav>
  </div>
</header>
<?php endif; ?>
<main id="content" class="site-main">
