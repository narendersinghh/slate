<?php
use Slate\ContentModel;

$d = $def ?? ['slug' => '', 'singular' => '', 'plural' => '', 'post_types' => [], 'hierarchical' => true, 'public' => true, 'rewrite' => '', 'builtin' => false];
$builtin = !empty($d['builtin']);
?>
<div class="page-head">
  <div><h1><?= e($def ? 'Edit “' . $d['plural'] . '”' : 'New taxonomy') ?></h1></div>
  <div class="page-actions"><a class="btn" href="<?= e(admin_url('model/taxonomies')) ?>">Cancel</a></div>
</div>
<form method="post" action="<?= e(admin_url('model/taxonomies/save')) ?>" class="split split-wide">
  <?= csrf_field() ?>
  <input type="hidden" name="original" value="<?= e($def['slug'] ?? '') ?>">
  <section class="card">
    <div class="card-head"><h2>Labels</h2></div>
    <div class="card-pad grid-form">
      <div class="field"><label>Plural name</label><input class="input" name="plural" value="<?= e($d['plural']) ?>" placeholder="Project types" required data-plural></div>
      <div class="field"><label>Singular name</label><input class="input" name="singular" value="<?= e($d['singular']) ?>" placeholder="Project type" required data-singular></div>
      <div class="field"><label>Key (slug)</label><input class="input" name="slug" value="<?= e($d['slug']) ?>" placeholder="project_type" <?= $builtin ? 'disabled' : 'required' ?> data-key></div>
      <div class="field"><label>URL base</label><input class="input" name="rewrite" value="<?= e($d['rewrite']) ?>" placeholder="project-type" data-rewrite></div>
    </div>
  </section>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Attach to</h2></div>
      <div class="card-pad stack-sm">
        <?php foreach (ContentModel::types() as $slug => $t): ?>
          <label class="choice"><input type="checkbox" name="post_types[]" value="<?= e($slug) ?>"<?= in_array($slug, $d['post_types'], true) ? ' checked' : '' ?>> <?= icon($t['icon'] ?? 'file', 15) ?> <?= e($t['plural']) ?></label>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card">
      <div class="card-head"><h2>Behaviour</h2></div>
      <div class="card-pad stack-sm">
        <label class="switch-row"><input type="checkbox" class="switch" name="hierarchical" value="1"<?= $d['hierarchical'] ? ' checked' : '' ?>><span>Hierarchical <small class="muted">— like categories (checkboxes). Off = like tags.</small></span></label>
        <label class="switch-row"><input type="checkbox" class="switch" name="public" value="1"<?= $d['public'] ? ' checked' : '' ?>><span>Public archive pages</span></label>
      </div>
    </section>
    <div class="row-between">
      <button class="btn btn-primary" type="submit">Save taxonomy</button>
      <?php if ($def && !$builtin): ?><button class="btn btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('model/taxonomies/' . $d['slug'] . '/delete')) ?>" data-confirm="Delete this taxonomy and all its terms?">Delete</button><?php endif; ?>
    </div>
  </div>
</form>
