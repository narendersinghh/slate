<?php
use Slate\ContentModel;
use Slate\Options;

$g = $group ?? ['id' => '', 'title' => '', 'position' => 'main', 'location' => ['post_types' => [], 'taxonomies' => []], 'fields' => []];
$stored = isset((Options::get('field_groups') ?: [])[$g['id'] ?? '']);
$meta = require __DIR__ . '/partials/model-field-types.php';
$types = [];
foreach (ContentModel::FIELD_TYPES as $k => $label) {
    $m = $meta[$k] ?? [$label, 'file', 'More', ''];
    $types[$k] = ['label' => $m[0], 'icon' => icon($m[1], 16), 'group' => $m[2], 'hint' => $m[3]];
}
$postTypes = array_map(static fn($t) => ['label' => $t['plural'], 'icon' => icon($t['icon'] ?? 'file', 14)], ContentModel::types());
$taxes = array_map(static fn($t) => ['label' => $t['plural'], 'icon' => icon(!empty($t['hierarchical']) ? 'folder' : 'tag', 14)], ContentModel::taxonomies());
$cfg = [
    'group' => $g,
    'types' => $types,
    'postTypes' => (object)$postTypes,
    'taxonomies' => (object)$taxes,
    'subTypes' => ['text', 'textarea', 'richtext', 'number', 'email', 'url', 'tel', 'select', 'toggle', 'date', 'color', 'image'],
    'icons' => ['grip' => icon('grip', 16), 'up' => icon('chevron-down', 15), 'copy' => icon('copy', 15), 'trash' => icon('trash', 15),
        'chevron' => icon('chevron-right', 16), 'lock' => icon('lock', 13), 'plus' => icon('plus', 15), 'alert' => icon('alert', 14)],
];
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/model.css')) ?>">
<form method="post" action="<?= e(admin_url('model/fields/save')) ?>" class="cm-edit" data-field-builder novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="group_json" data-group-json>
  <script type="application/json" data-fb-config><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

  <div class="cm-edit-head">
    <a class="icon-btn" href="<?= e(admin_url('model/fields')) ?>" aria-label="Back to custom fields"><?= icon('chevron-left') ?></a>
    <span class="cm-icon"><?= icon('list', 20) ?></span>
    <div class="cm-edit-title">
      <small class="muted">Custom fields</small>
      <h1 data-fb-title data-new="New field group"><?= e($group ? $g['title'] : 'New field group') ?></h1>
    </div>
  </div>

  <div class="cm-edit-layout cm-fb-layout">
    <div class="cm-edit-main">
      <section class="card cm-section">
        <div class="card-pad stack">
          <label class="field"><span>Group name</span>
            <input class="input input-lg" data-g="title" placeholder="For example: Project details" maxlength="80" autocomplete="off">
            <small class="help">Shown as the heading of the box in the editor.</small></label>
          <div class="field">
            <span>Show on</span>
            <div class="cm-loc">
              <?php foreach (ContentModel::types() as $slug => $t): ?>
                <label class="cm-toggle-chip"><input type="checkbox" data-loc="post_types" value="<?= e($slug) ?>"><?= icon($t['icon'] ?? 'file', 14) ?><?= e($t['plural']) ?></label>
              <?php endforeach; ?>
            </div>
            <details class="cm-loc-more" data-fb-tax-more>
              <summary><?= icon('tag', 13) ?> Also on taxonomy terms <?= icon('chevron-down', 14) ?></summary>
              <div class="cm-loc">
                <?php foreach (ContentModel::taxonomies() as $slug => $t): ?>
                  <label class="cm-toggle-chip"><input type="checkbox" data-loc="taxonomies" value="<?= e($slug) ?>"><?= icon(!empty($t['hierarchical']) ? 'folder' : 'tag', 14) ?><?= e($t['plural']) ?></label>
                <?php endforeach; ?>
              </div>
            </details>
            <p class="cm-loc-summary" data-fb-where></p>
          </div>
          <div class="field">
            <span>Place in the editor</span>
            <div class="seg cm-seg">
              <label><input type="radio" name="_fb_position" value="main" data-g="position"> Below the content</label>
              <label><input type="radio" name="_fb_position" value="side" data-g="position"> In the sidebar</label>
            </div>
          </div>
        </div>
      </section>

      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('rows', 17) ?> Fields <span class="pill" data-fb-count>0</span></h2><span class="muted small cm-hide-sm">Drag to reorder. Click a field to edit it.</span></div>
        <ol class="fb-list" data-fields></ol>
        <div class="fb-empty" data-fields-empty>
          <?= icon('plus', 22) ?>
          <strong>No fields yet</strong>
          <span class="muted small">Pick a field type under "Add a field" to start.</span>
        </div>
        <button type="button" class="btn btn-sm fb-jump" data-fb-jump><?= icon('plus', 15) ?> Add a field</button>
      </section>
    </div>

    <aside class="cm-edit-side">
      <section class="card fb-palette" id="fb-palette">
        <div class="card-head"><h2><?= icon('plus', 17) ?> Add a field</h2></div>
        <div class="card-pad" data-fb-palette></div>
      </section>
      <section class="card fb-preview-card">
        <div class="card-head"><h2><?= icon('eye', 17) ?> Preview</h2><span class="muted small" data-fb-preview-where></span></div>
        <div class="card-pad"><div class="fb-preview" data-fb-preview></div></div>
      </section>
    </aside>
  </div>

  <?php if ($group && $stored): ?>
  <section class="card cm-danger">
    <div class="cm-danger-text">
      <h2>Delete this field group</h2>
      <p class="muted">The boxes disappear from the editor. Values already saved stay on the content, and come back if you add the same field names again.</p>
    </div>
    <button class="btn btn-danger" type="submit" formaction="<?= e(admin_url('model/fields/' . $g['id'] . '/delete')) ?>" formnovalidate data-confirm="Delete the field group “<?= e($g['title']) ?>”?"><?= icon('trash', 15) ?> Delete field group</button>
  </section>
  <?php endif; ?>

  <div class="save-bar cm-save-bar">
    <a class="btn btn-ghost" href="<?= e(admin_url('model/fields')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit" data-fb-save><?= icon('check', 15) ?> <?= $group ? 'Save changes' : 'Create field group' ?></button>
  </div>
</form>
<?php $scripts = ['fields-builder.js']; ?>
