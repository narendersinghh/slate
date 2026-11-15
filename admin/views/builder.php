<?php
/**
 * Visual builder (full screen, no dashboard chrome). Loads GrapesJS only here.
 * @var array $post @var array $type @var array|null $builder
 */
use Slate\Builder;
use Slate\Csrf;
use Slate\DesignSystem;
use Slate\Posts;

$ds = DesignSystem::get();
$isTpl = isset($tpl);
if ($isTpl) {
    $tb = is_array($tpl['builder'] ?? null) && ($tpl['builder']['mode'] ?? '') === 'builder' ? $tpl['builder'] : null;
    $base = admin_url('templates/' . $tpl['id']);
    $preview = admin_template_preview_url($tpl);
    $config = [
        'kind' => 'template',
        'save' => "$base/builder", 'mode' => "$base/builder/mode", 'edit' => $base, 'revisions' => '',
        'view' => $preview ?: '', 'status' => $tpl['enabled'] ? 'publish' : 'draft', 'canPublish' => false,
        'project' => $tb['project'] ?? null, 'html' => $tb ? null : (string)$tpl['html'], 'css' => $tb ? null : (string)$tpl['css'],
        'layout' => $tpl['wrap'] ? 'theme' : 'blank', 'title' => $tpl['name'], 'chrome' => Builder::chrome($sample ?? null),
        'dynamic' => \Slate\Templates::builderBlocks($tpl),
    ];
    $heading = ['Template · Visual builder', $tpl['name']];
} else {
    $base = admin_url("content/{$type['slug']}/{$post['id']}");
    $config = [
        'kind' => 'item',
        'save' => "$base/builder", 'mode' => "$base/builder/mode", 'edit' => $base, 'revisions' => "$base/revisions",
        'view' => Posts::permalink($post) . ($post['status'] !== 'publish' ? '?preview=' . $post['id'] : ''),
        'status' => $post['status'], 'canPublish' => \Slate\Auth::can('publish_posts'),
        'project' => $builder['project'] ?? null, 'html' => $builder ? null : (string)$post['content'], 'css' => null,
        'layout' => $builder['layout'] ?? (string)\Slate\Options::get('builder_default_layout', 'theme'),
        'title' => $post['title'], 'chrome' => Builder::chrome($post), 'dynamic' => [],
    ];
    $heading = [$type['singular'] . ' · Visual builder', $post['title'] ?: 'Untitled'];
}
$config += [
    'blocksUrl' => admin_url('builder/blocks'),
    'csrf' => Csrf::token(),
    'styles' => Builder::canvasStyles(),
    'dsCss' => DesignSystem::css(),
    'colors' => array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'] ?? $c['id'], 'value' => $c['value']], $ds['colors']),
    'fonts' => array_values(array_unique(array_filter([$ds['fonts']['heading'] ?? '', $ds['fonts']['body'] ?? '']))),
    'saved' => Builder::savedBlocks(),
    'ai' => \Slate\Ai::enabled() && \Slate\Auth::can('use_ai'),
    'aiUrl' => admin_url('builder/ai'),
    'aiSettings' => admin_url('settings/ai/provider'),
];
?><!doctype html>
<html lang="en" data-theme="<?= e($_COOKIE['slate_theme'] ?? 'auto') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($heading[1] . ' ‹ Visual builder') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('admin/assets/brand/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/vendor/grapesjs/grapes.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/builder.css')) ?>">
<?= DesignSystem::adminCss() ?>
<script>
(function(){var t=document.documentElement.dataset.theme;if(t==='auto'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.mode=t})();
window.SLATE = <?= json_encode(['admin' => admin_url(), 'api' => admin_url('api'), 'csrf' => Csrf::token(), 'site' => url()], JSON_UNESCAPED_SLASHES) ?>;
window.PB = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
</head>
<body class="pb-app">
<header class="pb-bar">
  <div class="pb-bar-left">
    <a class="icon-btn" href="<?= e($base) ?>" title="Back" aria-label="Back" data-pb-leave><?= icon('chevron-left') ?></a>
    <div class="pb-title"><small class="muted"><?= e($heading[0]) ?></small><strong><?= e($heading[1]) ?></strong></div>
    <span class="pb-state muted" data-pb-state></span>
  </div>
  <div class="pb-bar-mid" role="group" aria-label="Device">
    <button type="button" class="icon-btn is-on" data-device="Desktop" title="Desktop"><?= icon('monitor', 17) ?></button>
    <button type="button" class="icon-btn" data-device="Tablet" title="Tablet (≤ 992px)"><svg class="ic" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
    <button type="button" class="icon-btn" data-device="Mobile" title="Mobile (≤ 480px)"><svg class="ic" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
    <span class="pb-sep"></span>
    <button type="button" class="icon-btn" data-cmd="undo" title="Undo (Ctrl+Z)"><svg class="ic" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg></button>
    <button type="button" class="icon-btn" data-cmd="redo" title="Redo (Ctrl+Shift+Z)"><svg class="ic" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/></svg></button>
    <button type="button" class="icon-btn" data-cmd="outline" title="Show outlines"><?= icon('grid', 17) ?></button>
    <button type="button" class="icon-btn" data-cmd="code" title="Edit HTML & CSS"><?= icon('code', 17) ?></button>
    <button type="button" class="icon-btn" data-cmd="preview" title="Preview"><?= icon('eye', 17) ?></button>
  </div>
  <div class="pb-bar-right">
    <button type="button" class="btn btn-sm btn-ai" data-cmd="ai" title="Design with AI"><?= icon('sparkles', 15) ?> AI</button>
    <select class="input input-sm" data-pb-layout title="Page layout" aria-label="Page layout">
      <option value="theme">With site header & footer</option>
      <option value="blank">Blank page (design everything)</option>
    </select>
    <div class="dropdown">
      <button type="button" class="icon-btn" data-dropdown aria-label="More"><?= icon('more', 18) ?></button>
      <div class="dropdown-menu dropdown-right">
        <?php if ($config['view']): ?><a href="<?= e($config['view']) ?>" target="_blank" rel="noopener"><?= icon('external', 15) ?> <?= $isTpl ? 'Preview' : 'Open the page' ?></a><?php endif; ?>
        <?php if ($config['revisions']): ?><a href="<?= e($config['revisions']) ?>"><?= icon('clock', 15) ?> Revisions</a><?php endif; ?>
        <button type="button" class="dropdown-btn" data-cmd="classic"><?= icon($isTpl ? 'code' : 'file-text', 15) ?> <?= $isTpl ? 'Back to code editor' : 'Switch to classic editor' ?></button>
        <button type="button" class="dropdown-btn" data-cmd="clear"><?= icon('trash', 15) ?> Start from an empty page</button>
      </div>
    </div>
    <button type="button" class="btn btn-sm<?= $isTpl ? ' btn-primary' : '' ?>" data-pb-save="draft">Save</button>
    <?php if (!$isTpl): ?><button type="button" class="btn btn-sm btn-primary" data-pb-save="publish"><?= $post['status'] === 'publish' ? 'Update' : ($config['canPublish'] ? 'Publish' : 'Submit') ?></button><?php endif; ?>
  </div>
</header>

<div class="pb-main">
  <aside class="pb-left">
    <nav class="pb-tabs"><button type="button" class="is-on" data-pane="blocks">Add</button><button type="button" data-pane="layers">Layers</button></nav>
    <div class="pb-pane" data-pane-body="blocks">
      <label class="pb-search"><?= icon('search', 14) ?><input class="input input-sm" type="search" placeholder="Search blocks…" data-block-search></label>
      <div id="pb-blocks"></div>
    </div>
    <div class="pb-pane" data-pane-body="layers" hidden><div id="pb-layers"></div></div>
  </aside>
  <div class="pb-canvas"><div id="pb-editor"></div></div>
  <aside class="pb-right">
    <nav class="pb-tabs"><button type="button" class="is-on" data-pane="style">Style</button><button type="button" data-pane="traits">Settings</button></nav>
    <div class="pb-pane" data-pane-body="style">
      <div class="pb-empty muted" data-pb-noselect>Select an element on the page to style it.</div>
      <div id="pb-styles"></div>
    </div>
    <div class="pb-pane" data-pane-body="traits" hidden>
      <div id="pb-traits"></div>
      <div class="pb-block-save" data-pb-saveblock hidden>
        <button type="button" class="btn btn-sm btn-block" data-cmd="save-block"><?= icon('star', 14) ?> Save element as a reusable block</button>
      </div>
    </div>
  </aside>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>
<script src="<?= e(asset_url('admin/assets/admin.js')) ?>"></script>
<script src="<?= e(asset_url('admin/assets/media.js')) ?>"></script>
<script src="<?= e(asset_url('admin/assets/vendor/grapesjs/grapes.min.js')) ?>"></script>
<script src="<?= e(asset_url('admin/assets/builder.js')) ?>"></script>
</body>
</html>
