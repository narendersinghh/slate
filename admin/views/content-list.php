<?php
use Slate\ContentModel;
use Slate\Posts;

$slug = $type['slug'];
$taxes = ContentModel::taxonomiesFor($slug);
$base = admin_url("content/$slug");
$qs = static fn(array $over) => $base . '?' . http_build_query(array_filter(array_merge(['status' => $status !== 'all' ? $status : null, 'q' => $_GET['q'] ?? null], $over)));
$total = array_sum(array_diff_key($counts, ['trash' => 0]));
?>
<div class="page-head">
  <div>
    <h1><?= e($type['plural']) ?></h1>
    <?php if (!empty($type['description'])): ?><p class="muted"><?= e($type['description']) ?></p><?php endif; ?>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= e($base . '/new') ?>"><?= icon('plus', 16) ?> Add <?= e(strtolower($type['singular'])) ?></a>
  </div>
</div>

<div class="toolbar">
  <nav class="tabs-pill" aria-label="Filter by status">
    <a href="<?= e($qs(['status' => null])) ?>" class="<?= $status === 'all' ? 'active' : '' ?>">All <span><?= $total ?></span></a>
    <?php foreach (Posts::STATUSES as $k => $label): if (!$counts[$k]) continue; ?>
      <a href="<?= e($qs(['status' => $k])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <form class="search-inline" method="get">
    <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <?= icon('search', 16) ?><input class="input" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search <?= e(strtolower($type['plural'])) ?>…">
  </form>
</div>

<form method="post" action="<?= e($base . '/bulk') ?>" class="card" data-bulk>
  <?= csrf_field() ?>
  <div class="bulk-bar" hidden>
    <span class="bulk-count">0 selected</span>
    <?php if ($status === 'trash'): ?>
      <button class="btn btn-sm" name="action" value="restore">Restore</button>
      <button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="Permanently delete the selected items?">Delete permanently</button>
    <?php else: ?>
      <button class="btn btn-sm" name="action" value="publish">Publish</button>
      <button class="btn btn-sm" name="action" value="draft">Move to draft</button>
      <button class="btn btn-sm btn-danger" name="action" value="trash">Move to trash</button>
    <?php endif; ?>
  </div>
  <?php if (!$result['items']): ?>
    <div class="empty-state">
      <?= icon($type['icon'] ?? 'file', 36) ?>
      <h2><?= !empty($_GET['q']) ? 'No results' : 'No ' . e(strtolower($type['plural'])) . ' yet' ?></h2>
      <p class="muted"><?= !empty($_GET['q']) ? 'Try another search.' : 'Create your first one to get started.' ?></p>
      <a class="btn btn-primary" href="<?= e($base . '/new') ?>"><?= icon('plus', 16) ?> Add <?= e(strtolower($type['singular'])) ?></a>
    </div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th class="col-check"><input type="checkbox" data-check-all aria-label="Select all"></th>
      <th>Title</th>
      <?php foreach ($taxes as $tx => $tdef): ?><th class="hide-md"><?= e($tdef['plural']) ?></th><?php endforeach; ?>
      <?php if (in_array('author', $type['supports'], true)): ?><th class="hide-md">Author</th><?php endif; ?>
      <th class="hide-sm">SEO</th>
      <th>Date</th>
    </tr></thead>
    <tbody>
    <?php foreach ($result['items'] as $p):
        $seoScore = (int)!empty($p['seo']['title']) + (int)!empty($p['seo']['description']) + (int)!empty($p['featured_media_id']);
        $depth = 0;
        if ($type['hierarchical'] && $p['parent_id']) { $depth = 1; } ?>
      <tr>
        <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" aria-label="Select"></td>
        <td>
          <a class="row-title" href="<?= e($base . '/' . $p['id']) ?>"><?= $depth ? '<span class="muted">— </span>' : '' ?><?= e($p['title'] ?: '(no title)') ?></a>
          <?php if ($p['status'] !== 'publish'): ?><span class="status status-<?= e($p['status']) ?>"><?= e(Posts::STATUSES[$p['status']]) ?></span><?php endif; ?>
          <?php if (\Slate\Seo::isFrontPage($p)): ?><span class="pill">Homepage</span><?php endif; ?>
          <div class="row-actions">
            <a href="<?= e($base . '/' . $p['id']) ?>">Edit</a>
            <a href="<?= e(Posts::permalink($p) . ($p['status'] !== 'publish' ? '?preview=' . $p['id'] : '')) ?>" target="_blank" rel="noopener"><?= $p['status'] === 'publish' ? 'View' : 'Preview' ?></a>
            <button type="submit" formaction="<?= e($base . '/' . $p['id'] . '/duplicate') ?>" class="link">Duplicate</button>
          </div>
        </td>
        <?php foreach ($taxes as $tx => $tdef): ?>
          <td class="hide-md"><?= e(implode(', ', array_column($p['terms'][$tx] ?? [], 'name'))) ?: '<span class="muted">—</span>' ?></td>
        <?php endforeach; ?>
        <?php if (in_array('author', $type['supports'], true)): ?><td class="hide-md"><?= e($authors[$p['author_id']] ?? '—') ?></td><?php endif; ?>
        <td class="hide-sm"><span class="seo-dots" title="<?= $seoScore ?>/3: meta title, description, image"><?php for ($i = 0; $i < 3; $i++): ?><i class="<?= $i < $seoScore ? 'on' : '' ?>"></i><?php endfor; ?></span></td>
        <td class="nowrap"><small class="muted"><?= $p['status'] === 'publish' ? 'Published' : 'Modified' ?></small><br><?= e(local_date($p['status'] === 'publish' ? $p['published_at'] : $p['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($result['pages'] > 1): ?>
  <div class="card-foot pager">
    <span class="muted"><?= number_format($result['total']) ?> items</span>
    <div>
      <?php if ($result['page'] > 1): ?><a class="btn btn-sm" href="<?= e($qs(['p' => $result['page'] - 1])) ?>">← Prev</a><?php endif; ?>
      <span class="muted">Page <?= $result['page'] ?> of <?= $result['pages'] ?></span>
      <?php if ($result['page'] < $result['pages']): ?><a class="btn btn-sm" href="<?= e($qs(['p' => $result['page'] + 1])) ?>">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</form>
