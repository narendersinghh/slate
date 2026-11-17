<?php
/** Terms of a taxonomy: the list on its own page, and New / Edit each on their own page. */
use Slate\AdminUi;
use Slate\ContentModel;
use Slate\Terms;

$slug = $tax['slug'];
$mode ??= $term ? 'edit' : 'list';
$usedBy = implode(', ', array_map(static fn($pt) => ContentModel::type($pt)['plural'] ?? $pt, $tax['post_types'])) ?: '-';

if ($mode === 'list'):
    echo AdminUi::header($tax['plural'], 'Used by: ' . $usedBy, 'tag', ['Add new ' . strtolower($tax['singular']) => [admin_url("terms/$slug/new"), 'primary', 'plus']]);
    // The list is rendered before any form partial could touch shared variables.
    echo '<div class="terms-list">' . $table->render($rows, $total) . '</div>';
else:
    $t = $term ?? ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'parent_id' => null, 'fields' => [], 'seo' => []];
    $back = admin_url("terms/$slug");
?>
<div class="page-head term-head">
  <div class="page-head-left">
    <a class="icon-btn" href="<?= e($back) ?>" aria-label="Back to <?= e(strtolower($tax['plural'])) ?>" title="All <?= e(strtolower($tax['plural'])) ?>"><?= icon('chevron-left') ?></a>
    <div><small class="muted"><?= e($tax['plural']) ?></small><h1 class="h-compact"><?= $mode === 'new' ? 'New ' . e(strtolower($tax['singular'])) : e($t['name']) ?></h1></div>
  </div>
  <?php if ($term): ?><div class="page-actions"><a class="btn btn-ghost" href="<?= e(Terms::link($term)) ?>" target="_blank" rel="noopener"><?= icon('eye', 16) ?> View</a></div><?php endif; ?>
</div>

<form class="term-form" method="post" action="<?= e(admin_url("terms/$slug/save")) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
  <section class="card">
    <div class="card-head"><h2>Details</h2></div>
    <div class="card-pad stack">
      <div class="term-grid">
        <div class="field"><label for="term-name">Name</label><input class="input" id="term-name" name="name" value="<?= e($t['name']) ?>" required data-title<?= $mode === 'new' ? ' autofocus' : '' ?>></div>
        <div class="field"><label for="term-slug">Slug <small class="muted">(in the address)</small></label><input class="input" id="term-slug" name="slug" value="<?= e($t['slug']) ?>" placeholder="Made from the name" data-slug></div>
        <?php if ($tax['hierarchical']): ?>
        <div class="field"><label for="term-parent">Parent</label>
          <select class="input" id="term-parent" name="parent_id"><option value="">None (top level)</option>
            <?php foreach ($terms as $o): if ($o['id'] === $t['id']) continue; ?><option value="<?= $o['id'] ?>"<?= (int)$t['parent_id'] === $o['id'] ? ' selected' : '' ?>><?= str_repeat('- ', $o['depth'] ?? 0) . e($o['name']) ?></option><?php endforeach; ?>
          </select></div>
        <?php endif; ?>
      </div>
      <div class="field"><label for="term-desc">Description</label><textarea class="input" id="term-desc" name="description" rows="3"><?= e($t['description']) ?></textarea></div>
    </div>
  </section>
  <?php if ($fields): ?>
  <section class="card">
    <div class="card-head"><h2>More details</h2></div>
    <div class="card-pad"><div class="field-grid"><?php foreach ($fields as $f): admin_field($f, $t['fields'][$f['name']] ?? null, 'fields[' . $f['name'] . ']'); endforeach; ?></div></div>
  </section>
  <?php endif; ?>
  <?php if ($term): ?>
  <section class="card"><div class="card-pad"><?php $seo = $t['seo']; $kind = 'term'; $previewUrl = Terms::link($term, true); $previewTitle = $term['name'] . ' – ' . site_name(); require __DIR__ . '/partials/seo-panel.php'; ?></div></section>
  <?php endif; ?>
  <div class="save-bar">
    <?php if ($term): ?>
      <button class="btn btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url("terms/$slug/{$term['id']}/delete")) ?>" data-confirm="Delete this <?= e(strtolower($tax['singular'])) ?>? Content using it is kept."><?= icon('trash', 15) ?> Delete</button>
      <span class="mod-spacer"></span>
    <?php endif; ?>
    <a class="btn btn-ghost" href="<?= e($back) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> <?= $term ? 'Save changes' : 'Add ' . e(strtolower($tax['singular'])) ?></button>
  </div>
</form>
<?php endif; ?>
<?php $scripts = ['editor.js']; ?>
