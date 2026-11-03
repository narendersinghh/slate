<?php
use Slate\Terms;

$slug = $tax['slug'];
$t = $term ?? ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'parent_id' => null, 'fields' => [], 'seo' => []];
?>
<div class="page-head">
  <div><h1><?= e($term ? 'Edit ' . strtolower($tax['singular']) : $tax['plural']) ?></h1>
    <p class="muted">Used by: <?= e(implode(', ', array_map(static fn($pt) => \Slate\ContentModel::type($pt)['plural'] ?? $pt, $tax['post_types']))) ?: '—' ?></p></div>
  <?php if ($term): ?><div class="page-actions"><a class="btn" href="<?= e(admin_url("terms/$slug")) ?>">← All <?= e(strtolower($tax['plural'])) ?></a><a class="btn btn-ghost" href="<?= e(Terms::link($term)) ?>" target="_blank"><?= icon('eye', 16) ?> View</a></div><?php endif; ?>
</div>

<div class="split">
  <form class="card" method="post" action="<?= e(admin_url("terms/$slug/save")) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
    <div class="card-head"><h2><?= $term ? 'Details' : 'Add new ' . e(strtolower($tax['singular'])) ?></h2></div>
    <div class="card-pad stack">
      <div class="field"><label>Name</label><input class="input" name="name" value="<?= e($t['name']) ?>" required data-title></div>
      <div class="field"><label>Slug</label><input class="input" name="slug" value="<?= e($t['slug']) ?>" placeholder="auto" data-slug></div>
      <?php if ($tax['hierarchical']): ?>
      <div class="field"><label>Parent</label>
        <select class="input" name="parent_id"><option value="">None</option>
          <?php foreach ($terms as $o): if ($o['id'] === $t['id']) continue; ?><option value="<?= $o['id'] ?>"<?= (int)$t['parent_id'] === $o['id'] ? ' selected' : '' ?>><?= str_repeat('— ', $o['depth'] ?? 0) . e($o['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="field"><label>Description</label><textarea class="input" name="description" rows="3"><?= e($t['description']) ?></textarea></div>
      <?php if ($fields): ?>
        <div class="field-grid"><?php foreach ($fields as $f): $value = $t['fields'][$f['name']] ?? null; $name = 'fields[' . $f['name'] . ']'; require __DIR__ . '/partials/field.php'; endforeach; ?></div>
      <?php endif; ?>
    </div>
    <?php if ($term): ?>
      <div class="card-pad"><?php $seo = $t['seo']; $kind = 'term'; $previewUrl = Terms::link($term, true); $previewTitle = $term['name'] . ' – ' . site_name(); require __DIR__ . '/partials/seo-panel.php'; ?></div>
    <?php endif; ?>
    <div class="card-foot">
      <button class="btn btn-primary" type="submit"><?= $term ? 'Update' : 'Add ' . e(strtolower($tax['singular'])) ?></button>
      <?php if ($term): ?>
        <button class="btn btn-danger btn-ghost" type="submit" formaction="<?= e(admin_url("terms/$slug/{$term['id']}/delete")) ?>" data-confirm="Delete this <?= e(strtolower($tax['singular'])) ?>? Content will not be deleted.">Delete</button>
      <?php endif; ?>
    </div>
  </form>

  <section class="card">
    <?php if (!$terms): ?>
      <div class="empty-state"><?= icon('tag', 32) ?><h2>No <?= e(strtolower($tax['plural'])) ?> yet</h2><p class="muted">Add the first one using the form.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Name</th><th>Slug</th><th class="num">Count</th></tr></thead>
      <tbody>
      <?php foreach ($terms as $o): ?>
        <tr class="<?= $o['id'] === $t['id'] ? 'is-current' : '' ?>">
          <td><a class="row-title" href="<?= e(admin_url("terms/$slug/{$o['id']}")) ?>"><?= str_repeat('<span class="muted">— </span>', $o['depth'] ?? 0) . e($o['name']) ?></a></td>
          <td><code><?= e($o['slug']) ?></code></td>
          <td class="num"><?= (int)$o['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>
<?php $scripts = ['editor.js']; ?>
