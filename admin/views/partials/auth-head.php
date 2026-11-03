<?php
/** Shared <head> + brand panel for login and installer. Expects $pageTitle, $quote (HTML). */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<link rel="preload" href="<?= e(url('admin/assets/fonts/instrument-serif.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="icon" type="image/svg+xml" href="<?= e(url('admin/assets/brand/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<script>(function(){var t=(document.cookie.match(/slate_theme=(\w+)/)||[])[1]||'auto';if(t==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.mode=t})();</script>
</head>
<body class="auth-page">
<aside class="auth-art" aria-hidden="true">
  <?= icon_logo(40) ?>
  <div>
    <p class="auth-quote"><?= $quote ?></p>
    <div class="auth-points"><span>SEO built in</span><span>Image optimization</span><span>AI assistant</span><span>Custom content types</span></div>
  </div>
  <div class="auth-art-foot">Slate CMS <?= e(SLATE_VERSION) ?></div>
</aside>
<main class="auth-main">
