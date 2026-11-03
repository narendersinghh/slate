<?php
use Slate\ContentModel;
use Slate\Icons;

$d = $def ?? ['slug' => '', 'singular' => '', 'plural' => '', 'icon' => 'box', 'public' => true, 'has_archive' => true, 'hierarchical' => false,
    'show_in_menu' => true, 'rewrite' => '', 'menu_position' => 30, 'supports' => ['title', 'editor', 'featured_image', 'seo', 'frontend_edit'], 'description' => '', 'builtin' => false];
$builtin = !empty($d['builtin']);
?>
<div class="page-head">
  <div><h1><?= e($def ? 'Edit “' . $d['plural'] . '”' : 'New post type') ?></h1></div>
  <div class="page-actions"><a class="btn" href="<?= e(admin_url('model/types')) ?>">Cancel</a></div>
</div>
<form method="post" action="<?= e(admin_url('model/types/save')) ?>" class="split split-wide">
  <?= csrf_field() ?>
  <input type="hidden" name="original" value="<?= e($def['slug'] ?? '') ?>">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Labels</h2></div>
      <div class="card-pad grid-form">
        <div class="field"><label>Plural name</label><input class="input" name="plural" value="<?= e($d['plural']) ?>" placeholder="Projects" required data-plural></div>
        <div class="field"><label>Singular name</label><input class="input" name="singular" value="<?= e($d['singular']) ?>" placeholder="Project" required data-singular></div>
        <div class="field"><label>Key (slug)</label><input class="input" name="slug" value="<?= e($d['slug']) ?>" placeholder="project" pattern="[a-z][a-z0-9_-]{1,38}" <?= $builtin ? 'disabled' : 'required' ?> data-key>
          <small class="help">Used internally. Lowercase, no spaces.<?= $def && !$builtin ? ' Changing it migrates existing content.' : '' ?></small></div>
        <div class="field"><label>URL base</label><input class="input" name="rewrite" value="<?= e($d['rewrite']) ?>" placeholder="projects" data-rewrite>
          <small class="help">e.g. <code><?= e(site_url()) ?><span data-rewrite-preview><?= e($d['rewrite'] ?: 'projects') ?></span>/my-item/</code></small></div>
        <div class="field span-2"><label>Description</label><input class="input" name="description" value="<?= e($d['description'] ?? '') ?>"></div>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><h2>Features</h2></div>
      <div class="card-pad choice-grid">
        <?php foreach (ContentModel::SUPPORTS as $k => $l): ?>
          <label class="choice-card"><input type="checkbox" name="supports[]" value="<?= e($k) ?>"<?= in_array($k, $d['supports'], true) ? ' checked' : '' ?>><span><?= e($l) ?></span></label>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Behaviour</h2></div>
      <div class="card-pad stack-sm">
        <label class="switch-row"><input type="checkbox" class="switch" name="public" value="1"<?= $d['public'] ? ' checked' : '' ?>><span>Public <small class="muted">— has URLs on the website</small></span></label>
        <label class="switch-row"><input type="checkbox" class="switch" name="has_archive" value="1"<?= $d['has_archive'] ? ' checked' : '' ?>><span>Archive page <small class="muted">— list at /<?= e($d['rewrite'] ?: 'base') ?>/</small></span></label>
        <label class="switch-row"><input type="checkbox" class="switch" name="hierarchical" value="1"<?= $d['hierarchical'] ? ' checked' : '' ?>><span>Hierarchical <small class="muted">— parent/child like pages</small></span></label>
        <label class="switch-row"><input type="hidden" name="show_in_menu" value="0"><input type="checkbox" class="switch" name="show_in_menu" value="1"<?= $d['show_in_menu'] ? ' checked' : '' ?>><span>Show in admin menu</span></label>
        <div class="field"><label>Menu position</label><input class="input" type="number" name="menu_position" value="<?= (int)$d['menu_position'] ?>"></div>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><h2>Icon</h2></div>
      <div class="card-pad icon-picker">
        <?php foreach (['box', 'file-text', 'file', 'briefcase', 'shopping', 'calendar', 'users', 'user', 'star', 'book', 'image', 'map-pin', 'message', 'globe', 'tag', 'folder', 'layers', 'zap', 'home', 'monitor'] as $ic): ?>
          <label title="<?= e($ic) ?>"><input type="radio" name="icon" value="<?= e($ic) ?>"<?= $d['icon'] === $ic ? ' checked' : '' ?>><span><?= icon($ic, 18) ?></span></label>
        <?php endforeach; ?>
      </div>
    </section>
    <div class="row-between">
      <button class="btn btn-primary" type="submit">Save post type</button>
      <?php if ($def && !$builtin): ?>
        <button class="btn btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('model/types/' . $d['slug'] . '/delete')) ?>" data-confirm="Delete this post type? Its content stays in the database unless you tick the box below.">Delete</button>
      <?php endif; ?>
    </div>
    <?php if ($def && !$builtin): ?><label class="choice muted"><input type="checkbox" name="delete_content" value="1"> Also delete all its content when deleting</label><?php endif; ?>
  </div>
</form>
