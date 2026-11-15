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
<title><?= e(($title ?? 'Dashboard') . ' ‹ ' . Options::get('site_title') . ' · Slate') ?></title>
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
    'forms' => ContentModel::type('form') !== null,
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

      <?php if (Auth::can('edit_posts') || Auth::can('upload_media')): ?>
      <div class="nav-label">Content</div>
      <?php endif; ?>
      <?php if (Auth::can('edit_posts')): foreach (ContentModel::types() as $slug => $t): if (empty($t['show_in_menu'])) continue;
          $taxes = Auth::can('manage_terms') ? ContentModel::taxonomiesFor($slug) : [];
          $open = $is("/content/$slug") || array_filter(array_keys($taxes), static fn($tx) => $is("/terms/$tx")); ?>
        <div class="nav-group<?= $open ? ' open' : '' ?>" data-nav-group="type-<?= e($slug) ?>">
          <?= $navItem(admin_url("content/$slug"), $t['icon'] ?? 'file', $t['plural'], (bool)$open, '<button type="button" class="nav-toggle" data-nav-toggle aria-label="Show ' . e(strtolower($t['plural'])) . ' menu">' . icon('chevron-down', 14) . '</button>') ?>
          <div class="nav-sub">
            <a href="<?= e(admin_url("content/$slug")) ?>" class="<?= $current === "/content/$slug" ? 'active' : '' ?>">All <?= e(strtolower($t['plural'])) ?></a>
            <a href="<?= e(admin_url("content/$slug/new")) ?>" class="<?= $current === "/content/$slug/new" ? 'active' : '' ?>">Add new</a>
            <?php foreach ($taxes as $tx => $tdef): ?>
              <a href="<?= e(admin_url("terms/$tx")) ?>" class="<?= $is("/terms/$tx") ? 'active' : '' ?>"><?= e($tdef['plural']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
      <?php if (Auth::can('upload_media')): ?>
        <?= $navItem(admin_url('media'), 'image', 'Media', $is('/media')) ?>
      <?php endif; ?>

      <?php
      // Modules: one entry per module, its pages (and settings) underneath.
      $modGroups = [];
      foreach (Modules::adminGroups() as $mslug => $g) {
          $links = [];
          $badge = 0;
          foreach ($g['pages'] as $mp) {
              if (!Auth::can($mp['cap'])) continue;
              $n = isset($mp['badge']) && is_callable($mp['badge']) ? (int)($mp['badge'])() : 0;
              $badge += $n;
              $links[] = ['href' => admin_url('m/' . $mp['slug']), 'label' => $mp['label'], 'active' => $is('/m/' . $mp['slug']), 'badge' => $n, 'icon' => $mp['icon']];
          }
          if ($g['settings'] && Auth::can($g['settings']['cap'])) {
              $sp = $g['settings'];
              $href = isset($sp['url']) ? admin_url($sp['url']) : admin_url('m/' . $sp['slug']);
              $links[] = ['href' => $href, 'label' => 'Settings', 'active' => isset($sp['url']) ? $is('/' . $sp['url']) : $is('/m/' . $sp['slug']), 'badge' => 0, 'icon' => 'settings'];
          }
          if ($links) {
              $modGroups[$mslug] = $g + ['links' => $links, 'badge' => $badge];
          }
      }
      $countHtml = static fn(int $n) => $n > 0 ? '<span class="nav-count">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
      if ($modGroups || Auth::can('manage_modules')): ?>
      <div class="nav-label">Modules</div>
      <?php foreach ($modGroups as $mslug => $g):
          $active = (bool)array_filter($g['links'], static fn($l) => $l['active']);
          if (count($g['links']) === 1): $l = $g['links'][0]; ?>
            <?= $navItem($l['href'], $g['icon'], $g['name'], $active, $countHtml($l['badge'])) ?>
          <?php else: ?>
          <div class="nav-group<?= $active ? ' open' : '' ?>" data-nav-group="mod-<?= e($mslug) ?>">
            <?= $navItem($g['links'][0]['href'], $g['icon'], $g['name'], $active, $countHtml($g['badge']) . '<button type="button" class="nav-toggle" data-nav-toggle aria-label="Show ' . e($g['name']) . ' menu">' . icon('chevron-down', 14) . '</button>') ?>
            <div class="nav-sub">
              <?php foreach ($g['links'] as $l): ?>
                <a href="<?= e($l['href']) ?>" class="<?= $l['active'] ? 'active' : '' ?>"><?= e($l['label']) ?><?= $countHtml($l['badge']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
      <?php endforeach; ?>
      <?php if (Auth::can('manage_modules')): ?>
        <?= $navItem(admin_url('modules'), 'puzzle', 'Manage modules', $is('/modules')) ?>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (Auth::can('manage_content_model') || Auth::can('manage_settings')): ?>
      <div class="nav-label">Design</div>
      <?php if (Auth::can('manage_content_model')): ?>
        <?= $navItem(admin_url('templates'), 'code', 'Templates', $is('/templates')) ?>
      <?php endif; ?>
      <?php if (admin_can_settings('appearance')): ?>
        <?= $navItem(admin_url('settings/appearance/menus'), 'menu', 'Menus', $is('/settings/appearance/menus')) ?>
        <?= $navItem(admin_url('settings/appearance/design'), 'palette', 'Theme & styles', $is('/settings/appearance/design') || $is('/settings/appearance/theme')) ?>
      <?php endif; ?>
      <?php endif; ?>

      <div class="nav-label">Administration</div>
      <?php if (Auth::can('manage_content_model')): ?>
        <div class="nav-group<?= $is('/model') ? ' open' : '' ?>" data-nav-group="model">
          <?= $navItem(admin_url('model/types'), 'layers', 'Content model', $is('/model'), '<button type="button" class="nav-toggle" data-nav-toggle aria-label="Show content model menu">' . icon('chevron-down', 14) . '</button>') ?>
          <div class="nav-sub">
            <a href="<?= e(admin_url('model/types')) ?>" class="<?= $is('/model/types') ? 'active' : '' ?>">Post types</a>
            <a href="<?= e(admin_url('model/taxonomies')) ?>" class="<?= $is('/model/taxonomies') ? 'active' : '' ?>">Taxonomies</a>
            <a href="<?= e(admin_url('model/fields')) ?>" class="<?= $is('/model/fields') ? 'active' : '' ?>">Custom fields</a>
          </div>
        </div>
      <?php endif; ?>
      <?php if (Auth::can('manage_users')): ?>
        <?= $navItem(admin_url('users'), 'users', 'Users', $is('/users')) ?>
      <?php endif; ?>
      <?php $setOpen = $is('/settings') && !$is('/settings/appearance/menus') && !$is('/settings/appearance/design') && !$is('/settings/appearance/theme') && !$is('/settings/modules'); ?>
      <div class="nav-group<?= $setOpen ? ' open' : '' ?>" data-nav-group="settings">
        <?= $navItem(admin_url('settings'), 'settings', 'Settings', $setOpen, ($hasUpdate ? '<span class="badge-dot" title="Update available"></span>' : '') . '<button type="button" class="nav-toggle" data-nav-toggle aria-label="Show settings menu">' . icon('chevron-down', 14) . '</button>') ?>
        <div class="nav-sub">
          <?php foreach (\Slate\Settings::tabs() as $stk => $st): if (!admin_can_settings($stk) || $stk === 'modules') continue; ?>
            <a href="<?= e(admin_url("settings/$stk/" . array_key_first($st['sections']))) ?>" class="<?= $is("/settings/$stk") ? 'active' : '' ?>"><?= e($st['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
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
        <?php if (Auth::can('manage_settings')): ?>
        <div class="dropdown hide-sm">
          <button class="icon-btn" type="button" data-dropdown aria-label="Settings" title="Settings"><?= icon('settings') ?></button>
          <div class="dropdown-menu dropdown-right settings-menu">
            <div class="dropdown-head"><strong>Settings</strong><small>Ctrl/⌘ K finds any setting</small></div>
            <a href="<?= e(admin_url('settings')) ?>"><?= icon('grid', 15) ?> Overview</a>
            <?php foreach (\Slate\Settings::tabs() as $stk => $st): if (!admin_can_settings($stk)) continue; ?>
              <a href="<?= e(admin_url("settings/$stk/" . array_key_first($st['sections']))) ?>"><?= icon($st['icon'], 15) ?> <?= e($st['label']) ?></a>
            <?php endforeach; ?>
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
