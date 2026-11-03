<?php
use Slate\ContentModel;

$g = $group ?? ['id' => '', 'title' => '', 'position' => 'main', 'location' => ['post_types' => [], 'taxonomies' => []], 'fields' => []];
?>
<div class="page-head">
  <div><h1><?= e($group ? 'Edit “' . $g['title'] . '”' : 'New field group') ?></h1>
    <p class="muted">In templates: <code>&lt;?= e(field('field_name')) ?&gt;</code></p></div>
  <div class="page-actions"><a class="btn" href="<?= e(admin_url('model/fields')) ?>">Cancel</a></div>
</div>
<form method="post" action="<?= e(admin_url('model/fields/save')) ?>" data-field-builder>
  <?= csrf_field() ?>
  <input type="hidden" name="group_json" data-group-json>
  <script type="application/json" data-group><?= json_encode($g, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
  <script type="application/json" data-field-types><?= json_encode(ContentModel::FIELD_TYPES, JSON_HEX_TAG) ?></script>
  <script type="application/json" data-post-types><?= json_encode(array_map(static fn($t) => $t['plural'], ContentModel::types()), JSON_HEX_TAG) ?></script>
  <div class="split split-wide">
    <div class="stack">
      <section class="card">
        <div class="card-pad"><input class="title-input title-input-sm" data-g="title" placeholder="Field group title (e.g. Project details)" required></div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Fields</h2><button type="button" class="btn btn-sm btn-primary" data-add-field><?= icon('plus', 15) ?> Add field</button></div>
        <div class="fb-list" data-fields></div>
        <div class="card-pad muted center" data-fields-empty>No fields yet — add your first field.</div>
      </section>
    </div>
    <div class="stack">
      <section class="card">
        <div class="card-head"><h2>Show on</h2></div>
        <div class="card-pad stack-sm">
          <strong class="small-label">Post types</strong>
          <?php foreach (ContentModel::types() as $slug => $t): ?>
            <label class="choice"><input type="checkbox" data-loc="post_types" value="<?= e($slug) ?>"> <?= e($t['plural']) ?></label>
          <?php endforeach; ?>
          <strong class="small-label">Taxonomies</strong>
          <?php foreach (ContentModel::taxonomies() as $slug => $t): ?>
            <label class="choice"><input type="checkbox" data-loc="taxonomies" value="<?= e($slug) ?>"> <?= e($t['plural']) ?></label>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="card">
        <div class="card-head"><h2>Placement</h2></div>
        <div class="card-pad">
          <select class="input" data-g="position"><option value="main">Main column (below the editor)</option><option value="side">Sidebar</option></select>
        </div>
      </section>
      <div class="row-between">
        <button class="btn btn-primary" type="submit">Save field group</button>
        <?php if ($group): ?><button class="btn btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('model/fields/' . $g['id'] . '/delete')) ?>" data-confirm="Delete this field group?" formnovalidate>Delete</button><?php endif; ?>
      </div>
    </div>
  </div>
</form>
<?php $scripts = ['fields-builder.js']; ?>
