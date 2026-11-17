<?php
use Slate\ContentModel;
use Slate\Templates;

$byKey = [];
foreach ($templates as $t) {
    $byKey[$t['kind'] . '/' . $t['target']][] = $t;
}
$rows = [];
foreach (ContentModel::types() as $slug => $type) {
    if (empty($type['public'])) {
        continue;
    }
    $rows['Content types'][] = ['single', $slug, $type['singular'] . ' page', 'Every single ' . strtolower($type['singular']) . ' (/' . trim((string)($type['rewrite'] ?: ''), '/') . ($type['rewrite'] ? '/' : '') . '…)', $type['icon'] ?? 'file'];
    if ($slug !== 'page' && (!empty($type['has_archive']) || $slug === 'post')) {
        $rows['Content types'][] = ['archive', $slug, $type['plural'] . ' archive', $slug === 'post' ? 'Blog listing' : 'Listing of all ' . strtolower($type['plural']), 'list'];
    }
}
foreach (ContentModel::taxonomies() as $slug => $tax) {
    if (!empty($tax['public'])) {
        $rows['Taxonomies'][] = ['term', $slug, $tax['singular'] . ' archive', 'Items in a ' . strtolower($tax['singular']) . ' (/' . trim((string)$tax['rewrite'], '/') . '/…)', 'tag'];
    }
}
foreach (Templates::SPECIAL as $k => $label) {
    $rows['Special pages'][] = ['special', $k, $label, $k === '404' ? 'Shown when a URL does not exist' : 'Results of ?s= searches', $k === '404' ? 'alert' : 'search'];
}
?>
<div class="page-head">
  <div><h1>Templates</h1><p class="muted">Design how every page type looks, site-wide. Templates use HTML and simple tags like <code>{{title}}</code> or <code>{{field:tagline}}</code>; without one, the active theme decides.</p></div>
</div>

<?php foreach ($rows as $group => $list): ?>
<section class="card">
  <div class="card-head"><h2><?= e($group) ?></h2></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Template</th><th class="hide-sm">Applies to</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as [$kind, $target, $label, $desc, $ic]): $existing = $byKey["$kind/$target"] ?? []; $active = array_values(array_filter($existing, fn($t) => $t['enabled'])); ?>
      <tr>
        <td><span class="user-cell"><span class="list-icon"><?= icon($ic, 15) ?></span><span><strong><?= e($label) ?></strong><small class="muted"><?= e($kind . ' / ' . $target) ?></small></span></span></td>
        <td class="hide-sm muted"><?= e($desc) ?></td>
        <td>
          <?php if ($active): ?><span class="status status-publish">Active</span>
          <?php elseif ($existing): ?><span class="status status-draft">Draft</span>
          <?php else: ?><span class="muted small">Theme default</span><?php endif; ?>
        </td>
        <td class="nowrap" style="text-align:right">
          <?php foreach ($existing as $t): ?><a class="btn btn-sm" href="<?= e(admin_url('templates/' . $t['id'])) ?>"><?= icon('edit', 14) ?> <?= e(mb_strimwidth($t['name'], 0, 28, '…')) ?></a> <?php endforeach; ?>
          <a class="btn btn-sm <?= $existing ? 'btn-ghost' : 'btn-primary' ?>" href="<?= e(admin_url('templates/new?kind=' . $kind . '&target=' . $target)) ?>"><?= icon('plus', 14) ?> <?= $existing ? 'New version' : 'Create' ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endforeach; ?>
