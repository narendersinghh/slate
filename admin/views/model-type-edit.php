<?php
use Slate\ContentModel;
use Slate\DB;
use Slate\Icons;
use Slate\Options;

$presets = (require __DIR__ . '/partials/model-presets.php')['types'];
$d = $def ?? ['slug' => '', 'singular' => '', 'plural' => '', 'icon' => 'box', 'public' => true, 'has_archive' => true, 'hierarchical' => false,
    'show_in_menu' => true, 'rewrite' => '', 'menu_position' => 30, 'supports' => ['title', 'editor', 'featured_image', 'seo', 'frontend_edit'], 'description' => '', 'builtin' => false];
if (!$def && ($p = $presets[(string)($_GET['preset'] ?? '')] ?? null)) {
    $d = array_merge($d, ['singular' => $p[0], 'plural' => $p[1], 'icon' => $p[2], 'supports' => $p[3], 'slug' => (string)$_GET['preset'],
        'rewrite' => \slugify($p[1])]);
}
$d += ['public' => true, 'has_archive' => false, 'hierarchical' => false, 'show_in_menu' => true, 'rewrite' => '', 'menu_position' => 30, 'supports' => [], 'description' => '', 'icon' => 'file'];
$builtin = !empty($d['builtin']);
$slug = (string)$d['slug'];
$items = $def ? (int)DB::val('SELECT COUNT(*) FROM {{posts}} WHERE type = :t', ['t' => $slug]) : 0;
$trailing = (bool)Options::get('permalink_trailing_slash', true);
// "page" lives at the site root and "post" follows Settings › Permalinks, so only other types have a URL base to edit.
$urlMode = $slug === 'page' ? 'root' : ($slug === 'post' ? 'post' : 'base');

$featureInfo = [
    'title' => ['type', 'A heading for each item'],
    'editor' => ['edit', 'The main writing area'],
    'excerpt' => ['file-text', 'A short summary for lists and previews'],
    'featured_image' => ['image', 'One main picture per item'],
    'author' => ['user', 'Who wrote it'],
    'page_attributes' => ['sort', 'Order items by hand and nest them'],
    'seo' => ['search', 'Search title, description and preview'],
    'frontend_edit' => ['monitor', 'Change text right on the live page'],
];
$featureLabels = ['page_attributes' => 'Parent and order', 'frontend_edit' => 'Edit on the website'];
$skipIcons = ['plus', 'x', 'x-circle', 'check', 'menu', 'more', 'grip', 'sort', 'logout', 'refresh', 'external', 'command',
    'arrow-up', 'arrow-down', 'arrow-left', 'arrow-right', 'arrow-up-right', 'chevron-down', 'chevron-right', 'chevron-left', 'sparrow'];
$icons = array_values(array_diff(Icons::names(), $skipIcons));
$cfg = [
    'existing' => array_values(array_diff(array_keys(ContentModel::types()), [$slug])),
    'reserved' => ContentModel::RESERVED,
    'site' => rtrim(site_url(), '/'),
    'trailing' => $trailing,
    'isNew' => !$def,
];
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/model.css')) ?>">
<form method="post" action="<?= e(admin_url('model/types/save')) ?>" class="cm-edit" data-cm-edit="type">
  <?= csrf_field() ?>
  <input type="hidden" name="original" value="<?= e($def['slug'] ?? '') ?>">
  <script type="application/json" data-cm-config><?= json_encode($cfg, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?></script>

  <div class="cm-edit-head">
    <a class="icon-btn" href="<?= e(admin_url('model/types')) ?>" aria-label="Back to post types"><?= icon('chevron-left') ?></a>
    <span class="cm-icon" data-cm-icon-preview><?= icon($d['icon'], 20) ?></span>
    <div class="cm-edit-title">
      <small class="muted">Post types</small>
      <h1 data-cm-title data-new="New post type"><?= e($def ? $d['plural'] : 'New post type') ?></h1>
    </div>
    <?php if ($builtin): ?><span class="pill">Built-in</span><?php endif; ?>
  </div>

  <div class="cm-edit-layout">
    <div class="cm-edit-main">
      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('edit', 17) ?> Basics</h2></div>
        <div class="card-pad cm-fields-grid">
          <label class="field"><span>Name of one item</span>
            <input class="input" name="singular" value="<?= e($d['singular']) ?>" placeholder="Project" required maxlength="60" data-cm-singular autocomplete="off">
            <small class="help">Used in buttons like "Add <span data-cm-add-hint>project</span>".</small></label>
          <label class="field"><span>Name of the list</span>
            <input class="input" name="plural" value="<?= e($d['plural']) ?>" placeholder="Projects" required maxlength="60" data-cm-plural autocomplete="off">
            <small class="help">Shown in the dashboard menu. Filled in for you.</small></label>
          <label class="field span-2"><span>Description <small class="muted">optional</small></span>
            <input class="input" name="description" value="<?= e($d['description'] ?? '') ?>" placeholder="What is this content for?" maxlength="200"></label>

          <div class="field span-2">
            <span>Icon</span>
            <div class="cm-iconpick" data-cm-iconpick>
              <button type="button" class="cm-iconpick-current" data-cm-icon-toggle aria-expanded="false">
                <span class="cm-icon cm-icon-sm" data-cm-icon-current><?= icon($d['icon'], 18) ?></span>
                <span><strong data-cm-icon-name><?= e($d['icon']) ?></strong><small class="muted">Change icon</small></span>
                <?= icon('chevron-down', 16) ?>
              </button>
              <div class="cm-iconpick-panel" data-cm-icon-panel hidden>
                <label class="cm-search"><?= icon('search', 15) ?><input class="input input-sm" type="search" placeholder="Search icons" data-cm-icon-search aria-label="Search icons"></label>
                <div class="cm-icon-grid" role="radiogroup" aria-label="Icon">
                  <?php foreach ($icons as $ic): ?>
                    <label title="<?= e($ic) ?>" data-name="<?= e($ic) ?>"><input type="radio" name="icon" value="<?= e($ic) ?>"<?= $d['icon'] === $ic ? ' checked' : '' ?> aria-label="<?= e($ic) ?>"><span><?= icon($ic, 18) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="field span-2">
            <span>Web address</span>
            <?php if ($urlMode === 'base'): ?>
              <div class="cm-url-input">
                <span class="cm-url-host"><?= e(preg_replace('#^https?://#', '', rtrim(site_url(), '/'))) ?>/</span>
                <input class="input" name="rewrite" value="<?= e($d['rewrite']) ?>" placeholder="projects" data-cm-rewrite autocomplete="off" spellcheck="false" aria-label="URL base">
                <span class="cm-url-tail">/item-name<?= $trailing ? '/' : '' ?></span>
              </div>
              <small class="help">Every item lives under this address. You can use a taxonomy in it, like <code>work/%service%</code>.</small>
            <?php elseif ($urlMode === 'root'): ?>
              <input type="hidden" name="rewrite" value="">
              <p class="help cm-note"><?= icon('info', 14) ?> Pages live at the top of your site, like <code>/about/</code>. Child pages add their parent: <code>/about/team/</code>.</p>
            <?php else: ?>
              <input type="hidden" name="rewrite" value="<?= e($d['rewrite']) ?>">
              <p class="help cm-note"><?= icon('info', 14) ?> Post addresses follow the pattern in <a href="<?= e(admin_url('settings/general/permalinks')) ?>">Settings, Permalinks</a>.</p>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('layers', 17) ?> Features</h2><span class="muted small">What the editor shows for each item</span></div>
        <div class="card-pad cm-feature-grid">
          <?php foreach (ContentModel::SUPPORTS as $k => $l): [$fi, $fh] = $featureInfo[$k] ?? ['check', '']; ?>
            <label class="cm-feature"><input type="checkbox" name="supports[]" value="<?= e($k) ?>"<?= in_array($k, (array)$d['supports'], true) ? ' checked' : '' ?>>
              <span class="cm-feature-icon"><?= icon($fi, 16) ?></span>
              <span class="cm-feature-text"><strong><?= e($featureLabels[$k] ?? $l) ?></strong><?php if ($fh): ?><small><?= e($fh) ?></small><?php endif; ?></span>
              <span class="cm-feature-check"><?= icon('check', 13) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="cm-feature"><input type="checkbox" name="hierarchical" value="1"<?= !empty($d['hierarchical']) ? ' checked' : '' ?>>
            <span class="cm-feature-icon"><?= icon('folder', 16) ?></span>
            <span class="cm-feature-text"><strong>Parent and child items</strong><small>Items can sit under other items, like pages</small></span>
            <span class="cm-feature-check"><?= icon('check', 13) ?></span>
          </label>
        </div>
      </section>

      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('globe', 17) ?> Where it appears</h2></div>
        <div class="cm-switches">
          <label class="cm-switch"><span><strong>On the website</strong><small>Each item gets its own page and can show up in site search, the sitemap and the REST API.</small></span>
            <input type="checkbox" class="switch" name="public" value="1"<?= !empty($d['public']) ? ' checked' : '' ?> data-cm-public></label>
          <label class="cm-switch" data-cm-needs-public><span><strong>List page</strong><small>A page that lists all items<span data-cm-archive-hint><?= $urlMode === 'base' && $d['rewrite'] !== '' ? ' at /' . e($d['rewrite']) . '/' : '' ?></span>.</small></span>
            <input type="checkbox" class="switch" name="has_archive" value="1"<?= !empty($d['has_archive']) ? ' checked' : '' ?> data-cm-archive></label>
          <label class="cm-switch"><span><strong>In the dashboard menu</strong><small>Editors find it in the sidebar on the left.</small></span>
            <input type="hidden" name="show_in_menu" value="0"><input type="checkbox" class="switch" name="show_in_menu" value="1"<?= !empty($d['show_in_menu']) ? ' checked' : '' ?> data-cm-menu></label>
        </div>
      </section>

      <details class="card cm-section cm-advanced" data-cm-advanced>
        <summary class="card-head"><h2><?= icon('settings', 17) ?> Advanced</h2><span class="cm-summary-hint muted small">Key and menu order</span><?= icon('chevron-down', 16) ?></summary>
        <div class="card-pad cm-fields-grid">
          <label class="field"><span>Key</span>
            <input class="input" name="slug" value="<?= e($slug) ?>" placeholder="project" pattern="[a-z][a-z0-9_\-]{1,38}" <?= $builtin ? 'disabled' : 'required' ?> data-cm-key autocomplete="off" spellcheck="false">
            <small class="help" data-cm-key-help><?= $builtin ? 'Built-in types keep their key.' : 'Used by themes and the API. Lowercase letters, numbers, dashes and underscores. Filled in for you.' ?><?= $def && !$builtin ? ' Changing it moves all existing items to the new key.' : '' ?></small>
            <small class="cm-error" data-cm-key-error hidden></small></label>
          <label class="field"><span>Position in the menu</span>
            <input class="input" type="number" name="menu_position" value="<?= (int)$d['menu_position'] ?>" min="0" max="999">
            <small class="help">Lower numbers sit higher. Posts are 10, pages 20.</small></label>
        </div>
      </details>

      <?php if ($def && !$builtin): ?>
      <section class="card cm-danger">
        <div class="cm-danger-text">
          <h2>Delete this post type</h2>
          <p class="muted"><?= $items ? 'It has ' . number_format($items) . ' ' . ($items === 1 ? 'item' : 'items') . '. They stay in the database and come back if you create the type again, unless you also delete them.' : 'It has no items yet.' ?></p>
          <?php if ($items): ?><label class="choice"><input type="checkbox" name="delete_content" value="1"> Also delete its <?= number_format($items) ?> <?= $items === 1 ? 'item' : 'items' ?> for good</label><?php endif; ?>
        </div>
        <button class="btn btn-danger" type="submit" formaction="<?= e(admin_url('model/types/' . $slug . '/delete')) ?>" formnovalidate data-confirm="Delete the post type “<?= e($d['plural']) ?>”?"><?= icon('trash', 15) ?> Delete post type</button>
      </section>
      <?php endif; ?>
    </div>

    <aside class="cm-edit-side">
      <section class="card cm-preview">
        <div class="card-head"><h2><?= icon('eye', 17) ?> Preview</h2></div>
        <div class="card-pad stack-sm">
          <span class="small-label">Dashboard menu</span>
          <div class="cm-menu-mock" data-cm-menu-mock>
            <span class="cm-menu-item"><span data-cm-icon-mini><?= icon($d['icon'], 16) ?></span><span data-cm-plural-mock><?= e($d['plural'] ?: 'Projects') ?></span></span>
          </div>
          <span class="small-label">Addresses</span>
          <ul class="cm-urls" data-cm-urls data-mode="<?= e($urlMode) ?>"></ul>
          <span class="small-label">Editor</span>
          <div class="cm-editor-mock" data-cm-editor-mock></div>
        </div>
      </section>
    </aside>
  </div>

  <div class="save-bar cm-save-bar">
    <a class="btn btn-ghost" href="<?= e(admin_url('model/types')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> <?= $def ? 'Save changes' : 'Create post type' ?></button>
  </div>
</form>
<?php $scripts = ['model.js']; ?>
