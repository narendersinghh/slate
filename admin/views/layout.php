<?php
use Slate\Ai;
use Slate\Auth;
use Slate\ContentModel;
use Slate\Csrf;
use Slate\Modules;
use Slate\Options;
use Slate\Session;

$user = Auth::user();
$current = '/' . trim((string)preg_replace('#^' . preg_quote(admin_url(), '#') . '#', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''), '/');
$is = static fn(string $prefix): bool => $prefix === '/' ? $current === '/' : str_starts_with($current . '/', rtrim($prefix, '/') . '/');
$update = Auth::can('manage_updates') ? Options::get('update_info') : null;
$hasUpdate = is_array($update) && version_compare($update['latest'] ?? '0', SLATE_VERSION, '>');
$flashes = Session::takeFlash();
$navItem = static function (string $href, string $iconName, string $label, bool $active, string $extra = '') {
    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e($href) . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . icon($iconName) . '<span>' . e($label) . '</span>' . $extra . '</a>';
};
?><!doctype html>
<html lang="en" data-theme="<?= e($_COOKIE['slate_theme'] ?? 'auto') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? 'Dashboard') . ' ‹ ' . Options::get('site_title') . ' — Slate') ?></title>
<link rel="preload" href="<?= e(url('admin/assets/fonts/inter.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(url('admin/assets/fonts/instrument-serif.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="icon" type="image/svg+xml" href="<?= e(url('admin/assets/brand/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<?= \Slate\DesignSystem::adminCss() ?>
<script>
(function(){var t=document.documentElement.dataset.theme;if(t==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.mode=t;if(localStorage.getItem('slate_nav')==='collapsed')document.documentElement.classList.add('nav-collapsed')})();
window.SLATE = <?= json_encode([
    'admin' => admin_url(), 'api' => admin_url('api'), 'csrf' => Csrf::token(), 'site' => url(),
    'ai' => Ai::enabled() && Auth::can('use_ai'), 'aiActions' => array_map(static fn($a) => $a['label'], Ai::ACTIONS),
    'user' => ['id' => $user['id'], 'name' => $user['display_name']],
], JSON_UNESCAPED_SLASHES) ?>;
</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<a class="skip" href="#main">Skip to content</a>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <a href="<?= e(admin_url()) ?>" class="brand-link">
        <?= icon_logo() ?>
      </a>
      <button class="icon-btn nav-collapse" type="button" data-action="collapse-nav" aria-label="Collapse sidebar"><?= icon('chevron-left', 16) ?></button>
    </div>
    <nav class="nav" aria-label="Main">
      <?= $navItem(admin_url(), 'grid', 'Dashboard', $is('/')) ?>

      <?php if (Auth::can('edit_posts')): ?>
      <div class="nav-label">Content</div>
      <?php foreach (ContentModel::types() as $slug => $t): if (empty($t['show_in_menu'])) continue;
          $open = $is("/content/$slug") || array_filter(array_keys(ContentModel::taxonomiesFor($slug)), static fn($tx) => $is("/terms/$tx")); ?>
        <div class="nav-group<?= $open ? ' open' : '' ?>">
          <?= $navItem(admin_url("content/$slug"), $t['icon'] ?? 'file', $t['plural'], (bool)$open) ?>
          <?php if ($open): ?>
          <div class="nav-sub">
            <a href="<?= e(admin_url("content/$slug")) ?>" class="<?= $current === "/content/$slug" ? 'active' : '' ?>">All <?= e(strtolower($t['plural'])) ?></a>
            <a href="<?= e(admin_url("content/$slug/new")) ?>" class="<?= $current === "/content/$slug/new" ? 'active' : '' ?>">Add new</a>
            <?php if (Auth::can('manage_terms')) foreach (ContentModel::taxonomiesFor($slug) as $tx => $tdef): ?>
              <a href="<?= e(admin_url("terms/$tx")) ?>" class="<?= $is("/terms/$tx") ? 'active' : '' ?>"><?= e($tdef['plural']) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if (Auth::can('upload_media')): ?>
        <?= $navItem(admin_url('media'), 'image', 'Media', $is('/media')) ?>
      <?php endif; ?>

      <?php if (Auth::can('manage_content_model') || Auth::can('manage_users') || Auth::can('manage_modules')): ?>
      <div class="nav-label">Build</div>
      <?php endif; ?>
      <?php if (Auth::can('manage_content_model')): ?>
        <div class="nav-group<?= $is('/model') ? ' open' : '' ?>">
          <?= $navItem(admin_url('model/types'), 'layers', 'Content model', $is('/model')) ?>
          <?php if ($is('/model')): ?>
          <div class="nav-sub">
            <a href="<?= e(admin_url('model/types')) ?>" class="<?= $is('/model/types') ? 'active' : '' ?>">Post types</a>
            <a href="<?= e(admin_url('model/taxonomies')) ?>" class="<?= $is('/model/taxonomies') ? 'active' : '' ?>">Taxonomies</a>
            <a href="<?= e(admin_url('model/fields')) ?>" class="<?= $is('/model/fields') ? 'active' : '' ?>">Custom fields</a>
          </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (Auth::can('manage_content_model')): ?>
        <?= $navItem(admin_url('templates'), 'code', 'Templates', $is('/templates')) ?>
      <?php endif; ?>
      <?php if (Auth::can('manage_users')): ?>
        <?= $navItem(admin_url('users'), 'users', 'Users', $is('/users')) ?>
      <?php endif; ?>
      <?php if (Auth::can('manage_modules')): ?>
        <?= $navItem(admin_url('modules'), 'puzzle', 'Modules', $is('/modules')) ?>
      <?php endif; ?>
      <?php foreach (Modules::adminPages() as $mp): if (!Auth::can($mp['cap'])) continue; ?>
        <?= $navItem(admin_url('m/' . $mp['slug']), $mp['icon'], $mp['label'], $is('/m/' . $mp['slug'])) ?>
      <?php endforeach; ?>

      <div class="nav-label">System</div>
      <?= $navItem(admin_url('settings'), 'settings', 'Settings', $is('/settings'), $hasUpdate ? '<span class="badge-dot" title="Update available"></span>' : '') ?>
    </nav>
    <div class="sidebar-foot">
      <span>Slate <?= e(SLATE_VERSION) ?></span>
      <?php if ($hasUpdate): ?><a href="<?= e(admin_url('settings/system/updates')) ?>" class="pill pill-accent">Update <?= e($update['latest']) ?></a><?php endif; ?>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-action="close-nav"></div>

  <div class="main-wrap">
    <header class="topbar">
      <button class="icon-btn mobile-only" type="button" data-action="open-nav" aria-label="Open menu"><?= icon('menu') ?></button>
      <button class="search-trigger" type="button" data-action="palette">
        <?= icon('search', 16) ?><span>Search or jump to…</span><kbd>⌘K</kbd>
      </button>
      <div class="topbar-actions">
        <a class="btn btn-ghost btn-sm hide-sm" href="<?= e(url()) ?>" target="_blank" rel="noopener"><?= icon('external', 15) ?> View site</a>
        <?php if (Auth::can('edit_posts')): ?>
        <div class="dropdown">
          <button class="btn btn-primary btn-sm" type="button" data-dropdown><?= icon('plus', 15) ?> <span class="hide-sm">New</span></button>
          <div class="dropdown-menu">
            <?php foreach (ContentModel::types() as $slug => $t): if (empty($t['show_in_menu'])) continue; ?>
              <a href="<?= e(admin_url("content/$slug/new")) ?>"><?= icon($t['icon'] ?? 'file', 15) ?> <?= e($t['singular']) ?></a>
            <?php endforeach; ?>
            <?php if (Auth::can('upload_media')): ?><a href="<?= e(admin_url('media')) ?>#upload"><?= icon('upload', 15) ?> Upload media</a><?php endif; ?>
            <?php if (Auth::can('manage_users')): ?><a href="<?= e(admin_url('users/new')) ?>"><?= icon('user', 15) ?> User</a><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <button class="icon-btn" type="button" data-action="theme" aria-label="Toggle dark mode"><span class="show-light"><?= icon('moon') ?></span><span class="show-dark"><?= icon('sun') ?></span></button>
        <div class="dropdown">
          <button class="avatar" type="button" data-dropdown aria-label="Account menu"><?= e(mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1))) ?></button>
          <div class="dropdown-menu dropdown-right">
            <div class="dropdown-head"><strong><?= e($user['display_name']) ?></strong><small><?= e(\Slate\Roles::label($user['role'])) ?></small></div>
            <a href="<?= e(admin_url('profile')) ?>"><?= icon('user', 15) ?> Profile & security</a>
            <a href="<?= e(admin_url('logout')) ?>?_csrf=<?= e(Csrf::token()) ?>"><?= icon('logout', 15) ?> Sign out</a>
          </div>
        </div>
      </div>
    </header>

    <main id="main" class="main">
      <?= $__content ?>
    </main>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite">
  <?php foreach ($flashes as $f): ?>
    <div class="toast toast-<?= e($f['type']) ?>"><?= icon($f['type'] === 'error' ? 'alert' : ($f['type'] === 'success' ? 'check' : 'info'), 16) ?><span><?= e($f['message']) ?></span></div>
  <?php endforeach; ?>
</div>

<div class="palette" id="palette" hidden>
  <div class="palette-box" role="dialog" aria-label="Command palette">
    <div class="palette-input"><?= icon('search', 18) ?><input type="text" placeholder="Search content, settings…" autocomplete="off" aria-label="Search"></div>
    <div class="palette-results" role="listbox"></div>
    <div class="palette-foot"><span><kbd>↑</kbd><kbd>↓</kbd> navigate</span><span><kbd>↵</kbd> open</span><span><kbd>esc</kbd> close</span></div>
  </div>
</div>

<script src="<?= e(asset_url('admin/assets/admin.js')) ?>"></script>
<script src="<?= e(asset_url('admin/assets/media.js')) ?>"></script>
<?php if (!empty($scripts)) foreach ($scripts as $s): ?><script src="<?= e(asset_url('admin/assets/' . $s)) ?>"></script><?php endforeach; ?>
</body>
</html>
