<?php
use Slate\ContentModel;
use Slate\Options;

$cmTab = 'fields';
require __DIR__ . '/partials/model-tabs.php';
$ftypes = require __DIR__ . '/partials/model-field-types.php';
$stored = Options::get('field_groups') ?: [];
?>
<?php if (!$groups): ?>
<section class="card cm-teach cm-teach-lg">
  <div class="cm-teach-text">
    <span class="cm-teach-icon"><?= icon('list', 20) ?></span>
    <div>
      <h2>Give your content its own details</h2>
      <p class="muted">A field group adds extra boxes to the editor, like a project's client, year and photo gallery, or an event's date and venue. You choose where it shows up, and your theme can print each value.</p>
    </div>
  </div>
  <ol class="cm-steps">
    <li><span>1</span><div><strong>Name the group</strong><small class="muted">For example "Project details".</small></div></li>
    <li><span>2</span><div><strong>Add fields</strong><small class="muted">Text, numbers, images, dates, lists and more.</small></div></li>
    <li><span>3</span><div><strong>Choose where it shows</strong><small class="muted">On one or more post types or taxonomies.</small></div></li>
  </ol>
  <div><a class="btn btn-primary" href="<?= e(admin_url('model/fields/new')) ?>"><?= icon('plus', 16) ?> Create a field group</a></div>
</section>
<?php else: ?>
<div class="card cm-list">
  <div class="cm-list-head" aria-hidden="true">
    <span>Field group</span><span>Fields</span><span>Shows on</span><span></span>
  </div>
  <?php foreach ($groups as $g):
    $fields = array_values(array_filter((array)($g['fields'] ?? []), static fn($f) => ($f['type'] ?? '') !== 'tab'));
    $where = array_merge((array)($g['location']['post_types'] ?? []), (array)($g['location']['taxonomies'] ?? [])); ?>
  <div class="cm-row">
    <a class="cm-main" href="<?= e(admin_url('model/fields/' . $g['id'])) ?>">
      <span class="cm-icon"><?= icon('list', 20) ?></span>
      <span class="cm-name">
        <strong><?= e($g['title']) ?></strong>
        <small><?= count($fields) ?> <?= count($fields) === 1 ? 'field' : 'fields' ?> · <?= ($g['position'] ?? 'main') === 'side' ? 'Sidebar' : 'Below the editor' ?></small>
      </span>
    </a>
    <div class="cm-fields">
      <?php foreach (array_slice($fields, 0, 5) as $f): $ft = $ftypes[$f['type']] ?? [$f['type'], 'file']; ?>
        <span class="cm-field" title="<?= e($ft[0]) ?>"><?= icon($ft[1], 12) ?><?= e($f['label'] ?: $f['name']) ?></span>
      <?php endforeach; ?>
      <?php if (count($fields) > 5): ?><span class="cm-field is-more">+<?= count($fields) - 5 ?> more</span><?php endif; ?>
      <?php if (!$fields): ?><span class="muted small">No fields yet</span><?php endif; ?>
    </div>
    <div class="cm-links">
      <?php foreach ((array)($g['location']['post_types'] ?? []) as $pt): $d = ContentModel::type($pt); ?>
        <span class="cm-chip"><?= icon($d['icon'] ?? 'file', 12) ?><?= e($d['plural'] ?? $pt) ?></span>
      <?php endforeach; ?>
      <?php foreach ((array)($g['location']['taxonomies'] ?? []) as $tx): $d = ContentModel::taxonomy($tx); ?>
        <span class="cm-chip"><?= icon('tag', 12) ?><?= e($d['plural'] ?? $tx) ?></span>
      <?php endforeach; ?>
      <?php if (!$where): ?><span class="pill pill-warn">Not shown anywhere yet</span><?php endif; ?>
      <?php if (!isset($stored[$g['id']])): ?><span class="pill">From a module</span><?php endif; ?>
    </div>
    <div class="cm-actions">
      <?php if (isset($stored[$g['id']])): ?>
      <form method="post" action="<?= e(admin_url('model/fields/' . $g['id'] . '/duplicate')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-sm btn-ghost" type="submit" title="Duplicate" aria-label="Duplicate <?= e($g['title']) ?>"><?= icon('copy', 14) ?><span class="cm-hide-sm">Duplicate</span></button>
      </form>
      <?php endif; ?>
      <a class="btn btn-sm" href="<?= e(admin_url('model/fields/' . $g['id'])) ?>"><?= icon('edit', 14) ?><span>Edit</span></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<p class="muted small cm-foot-note"><?= icon('code', 13) ?> In a template, print a value with <code>&lt;?= e(field('field_name')) ?&gt;</code>.</p>
<?php endif; ?>
