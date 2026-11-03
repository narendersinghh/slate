<?php
/**
 * Web installer — only reachable while config.php does not exist.
 */
declare(strict_types=1);

use Slate\Csrf;
use Slate\Installer;

if (\Slate\App::path() !== '/install') {
    redirect(url('install'));
}
header('X-Robots-Tag: noindex');
header('X-Frame-Options: DENY');
\Slate\Session::start();

$errors = [];
$done = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::valid($_POST['_csrf'] ?? null)) {
        $errors[] = 'Security token expired, please try again.';
    } else {
        $errors = Installer::run($_POST);
        $done = !$errors;
    }
}
$req = Installer::requirements();
$critical = ['PHP ' . SLATE_MIN_PHP . '+', 'PDO (MySQL or SQLite)', 'sodium (encryption)', 'Root folder writable (config.php)', 'content/ writable'];
$blocked = (bool)array_filter($critical, static fn($k) => empty($req[$k]));
$v = static fn(string $k, string $d = '') => e($_POST[$k] ?? $d);
$guessUrl = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Install Slate</title>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<script>document.documentElement.dataset.mode=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'</script>
</head>
<body class="auth-page">
<main class="auth-card auth-card-wide">
  <div class="auth-brand">
    <svg viewBox="0 0 32 32" width="44" height="44" aria-hidden="true"><rect width="32" height="32" rx="9" fill="url(#g)"/><path d="M10 11.5c0-1.9 1.6-3.5 3.5-3.5H22v4h-8v2.5h4.5c1.9 0 3.5 1.6 3.5 3.5v2.5c0 1.9-1.6 3.5-3.5 3.5H10v-4h8v-2.5h-4.5c-1.9 0-3.5-1.6-3.5-3.5z" fill="#fff"/><defs><linearGradient id="g" x1="0" y1="0" x2="32" y2="32"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs></svg>
    <h1><?= $done ? 'Slate is installed 🎉' : 'Install Slate ' . e(SLATE_VERSION) ?></h1>
    <p class="muted"><?= $done ? 'Your website is ready.' : 'A fast, secure, SEO-ready CMS. This takes about a minute.' ?></p>
  </div>

  <?php if ($done): ?>
    <div class="stack">
      <a class="btn btn-primary btn-lg btn-block" href="<?= e(url('admin/login')) ?>">Sign in to the dashboard</a>
      <a class="btn btn-block" href="<?= e(url()) ?>">View the website</a>
    </div>
  <?php else: ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert', 16) ?> <?= e($err) ?></div><?php endforeach; ?>

  <details class="req-list" <?= $blocked ? 'open' : '' ?>>
    <summary><?= $blocked ? '⚠️ Some requirements are missing' : '✓ Server meets all requirements' ?></summary>
    <ul><?php foreach ($req as $k => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✕' ?> <?= e($k) ?></li><?php endforeach; ?></ul>
  </details>

  <form method="post" class="stack" data-install>
    <?= Csrf::field() ?>
    <fieldset class="stack-sm">
      <legend>Website</legend>
      <label class="field"><span>Site title</span><input class="input" name="site_title" value="<?= $v('site_title', 'My Slate Site') ?>" required></label>
      <label class="field"><span>Site URL</span><input class="input" name="site_url" value="<?= $v('site_url', $guessUrl) ?>" required></label>
      <label class="choice"><input type="checkbox" name="noindex" value="1" <?= !empty($_POST['noindex']) ? 'checked' : '' ?>> Discourage search engines for now (staging)</label>
    </fieldset>

    <fieldset class="stack-sm">
      <legend>Database</legend>
      <div class="seg">
        <label><input type="radio" name="db_driver" value="mysql" <?= ($_POST['db_driver'] ?? 'mysql') === 'mysql' ? 'checked' : '' ?><?= extension_loaded('pdo_mysql') ? '' : ' disabled' ?>> MySQL / MariaDB</label>
        <label><input type="radio" name="db_driver" value="sqlite" <?= ($_POST['db_driver'] ?? '') === 'sqlite' ? 'checked' : '' ?><?= extension_loaded('pdo_sqlite') ? '' : ' disabled' ?>> SQLite (no setup)</label>
      </div>
      <div class="grid-form" data-mysql>
        <label class="field"><span>Host</span><input class="input" name="db_host" value="<?= $v('db_host', 'localhost') ?>"></label>
        <label class="field"><span>Port</span><input class="input" name="db_port" value="<?= $v('db_port', '3306') ?>"></label>
        <label class="field"><span>Database name</span><input class="input" name="db_name" value="<?= $v('db_name') ?>"></label>
        <label class="field"><span>Username</span><input class="input" name="db_user" value="<?= $v('db_user') ?>" autocomplete="off"></label>
        <label class="field"><span>Password</span><input class="input" type="password" name="db_pass" autocomplete="new-password"></label>
        <label class="field"><span>Table prefix</span><input class="input" name="db_prefix" value="<?= $v('db_prefix', 'sl_') ?>"></label>
      </div>
    </fieldset>

    <fieldset class="stack-sm">
      <legend>Administrator</legend>
      <div class="grid-form">
        <label class="field"><span>Username</span><input class="input" name="username" value="<?= $v('username') ?>" required autocomplete="off" pattern="[a-zA-Z0-9_.\-]{3,60}"></label>
        <label class="field"><span>Email</span><input class="input" type="email" name="email" value="<?= $v('email') ?>" required></label>
        <label class="field span-2"><span>Password <small class="muted">(min. 10 characters)</small></span><input class="input" type="password" name="password" minlength="10" required autocomplete="new-password"></label>
      </div>
    </fieldset>
    <button class="btn btn-primary btn-lg btn-block" type="submit" <?= $blocked ? 'disabled' : '' ?>>Install Slate</button>
  </form>
  <script>
    (function(){var f=document.querySelector('[data-install]');function u(){var s=f.querySelector('[name=db_driver]:checked');f.querySelector('[data-mysql]').hidden=s&&s.value==='sqlite'}f.addEventListener('change',u);u()})();
  </script>
  <?php endif; ?>
</main>
</body>
</html>
