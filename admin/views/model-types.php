<?php
use Slate\ContentModel;
use Slate\Posts;

$cmTab = 'types';
require __DIR__ . '/partials/model-tabs.php';
$presets = (require __DIR__ . '/partials/model-presets.php')['types'];
$custom = array_filter($types, static fn($t) => empty($t['builtin']));
$urlOf = static function (string $slug, array $t): string {
    if (empty($t['public'])) {
        return '';
    }
    if ($slug === 'post') {
        return preg_replace('/%([a-z]+)%/', '{$1}', Posts::postStructure());
    }
    $base = trim((string)($t['rewrite'] ?? ''), '/');
    return '/' . ($base !== '' ? preg_replace('/%([a-z0-9_-]+)%/', '{$1}', $base) . '/' : '') . '{slug}/';
};
?>
<div class="card cm-list">
  <div class="cm-list-head" aria-hidden="true">
    <span>Post type</span><span>Content and address</span><span>Grouped by and extra fields</span><span></span>
  </div>
  <?php foreach ($types as $slug => $t):
    $taxes = ContentModel::taxonomiesFor($slug);
    $groups = ContentModel::groupsFor('post', $slug);
    $n = (int)($counts[$slug] ?? 0);
    $url = $urlOf($slug, $t); ?>
  <div class="cm-row">
    <a class="cm-main" href="<?= e(admin_url('model/types/' . $slug)) ?>">
      <span class="cm-icon"><?= icon($t['icon'] ?? 'file', 20) ?></span>
      <span class="cm-name">
        <strong><?= e($t['plural']) ?></strong>
        <small><?= e($t['singular']) ?> · <code><?= e($slug) ?></code></small>
      </span>
    </a>
    <div class="cm-meta">
      <a class="cm-count" href="<?= e(admin_url('content/' . $slug)) ?>"><strong><?= number_format($n) ?></strong> <?= $n === 1 ? 'item' : 'items' ?></a>
      <?php if ($url !== ''): ?>
        <span class="cm-url" title="Address of each item"><?= icon('link', 13) ?><span><?= e($url) ?></span></span>
      <?php else: ?>
        <span class="cm-url is-off"><?= icon('lock', 13) ?><span>Not on the website</span></span>
      <?php endif; ?>
    </div>
    <div class="cm-links">
      <?php foreach ($taxes as $txSlug => $tx): ?>
        <a class="cm-chip" href="<?= e(admin_url('model/taxonomies/' . $txSlug)) ?>"><?= icon(!empty($tx['hierarchical']) ? 'folder' : 'tag', 12) ?><?= e($tx['plural']) ?></a>
      <?php endforeach; ?>
      <?php foreach ($groups as $g): ?>
        <a class="cm-chip is-fields" href="<?= e(admin_url('model/fields/' . $g['id'])) ?>"><?= icon('list', 12) ?><?= e($g['title']) ?> <span><?= count($g['fields'] ?? []) ?></span></a>
      <?php endforeach; ?>
      <?php if (!$taxes && !$groups): ?><span class="muted small">Nothing attached yet</span><?php endif; ?>
      <?php if (!empty($t['builtin'])): ?><span class="pill">Built-in</span><?php endif; ?>
      <?php if (isset($t['show_in_menu']) && !$t['show_in_menu']): ?><span class="pill pill-soft">Hidden from menu</span><?php endif; ?>
    </div>
    <div class="cm-actions">
      <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('content/' . $slug . '/new')) ?>" title="Add <?= e(strtolower($t['singular'])) ?>" aria-label="Add <?= e(strtolower($t['singular'])) ?>"><?= icon('plus', 15) ?><span class="cm-hide-sm">Add</span></a>
      <a class="btn btn-sm" href="<?= e(admin_url('model/types/' . $slug)) ?>"><?= icon('edit', 14) ?><span>Edit</span></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!$custom): ?>
<section class="card cm-teach">
  <div class="cm-teach-text">
    <span class="cm-teach-icon"><?= icon('sparkles', 20) ?></span>
    <div>
      <h2>Add your own kind of content</h2>
      <p class="muted">Pages and posts cover the basics. When something has its own details and its own list on the website, give it a post type. Pick a starting point, you can change everything later.</p>
    </div>
  </div>
  <div class="cm-presets">
    <?php foreach ($presets as $key => $p): ?>
      <a class="cm-preset" href="<?= e(admin_url('model/types/new') . '?preset=' . $key) ?>"><?= icon($p[2], 16) ?><?= e($p[1]) ?></a>
    <?php endforeach; ?>
    <a class="cm-preset is-blank" href="<?= e(admin_url('model/types/new')) ?>"><?= icon('plus', 16) ?>Start from scratch</a>
  </div>
</section>
<?php endif; ?>
