<?php
/** SEO › Schema by type: schema type and property mapping per content type, archive schema per taxonomy. */
use Slate\ContentModel;
use Slate\Media;
use Slate\Posts;
use Slate\Seo;
use Slate\Settings;
use Slate\StructuredData;
use Slate\Terms;

$public = array_filter(ContentModel::types(), static fn($t) => !empty($t['public']));
$editing = (string)($_GET['type'] ?? '');
$editing = isset($public[$editing]) ? $editing : '';
$base = Settings::url('seo', 'schema-types');
$richTest = static fn(string $url) => 'https://search.google.com/test/rich-results?url=' . rawurlencode($url);
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/seo.css')) ?>">
<script src="<?= e(asset_url('admin/assets/seo.js')) ?>" defer></script>
<div class="card-pad stack seo-sd">
<?php if ($editing === ''): ?>
  <h3 class="section-title">Content types</h3>
  <div class="table-wrap"><table class="table table-form">
    <thead><tr><th>Type</th><th>Schema</th><th>Properties</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($public as $slug => $t):
      $schema = Seo::typeDefaults($slug)['schema'];
      $mappable = StructuredData::mappable($schema);
      $saved = StructuredData::savedMap($slug, $schema);
      $map = $mappable ? StructuredData::map($slug, $schema) : [];
      $used = count(array_filter($map, static fn($s) => $s !== ''));
      $legacyDefault = $saved === null && in_array($schema, StructuredData::LEGACY, true);
      $missing = $mappable && !$legacyDefault ? StructuredData::missing($schema, $map) : [];
      $sample = StructuredData::sample($slug);
    ?>
      <tr data-sd-row="<?= e($slug) ?>">
        <td><strong><?= e($t['plural']) ?></strong><br><code class="muted"><?= e($slug) ?></code></td>
        <td><?= e(Seo::SCHEMA_TYPES[$schema] ?? $schema) ?></td>
        <td class="small">
          <?php if (!$mappable): ?><span class="muted">Describes the page itself</span>
          <?php elseif ($legacyDefault): ?><span class="muted">Basic (name, description, image)</span>
          <?php else: ?><?= $used ?> mapped<?= $saved === null ? ' <span class="pill">suggested</span>' : '' ?>
            <?php if ($missing): ?><br><span class="text-warn"><?= icon('alert', 13) ?> Missing for Google: <?= e(implode(', ', $missing)) ?></span><?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="seo-row-actions">
          <?php if ($sample): ?><a class="btn btn-sm btn-ghost" href="<?= e($richTest(Posts::permalink($sample, true))) ?>" target="_blank" rel="noopener" title="Test “<?= e($sample['title']) ?>” in Google's Rich Results Test"><?= icon('external', 14) ?> Test</a><?php endif; ?>
          <a class="btn btn-sm" href="<?= e($base . '?type=' . rawurlencode($slug)) ?>"><?= icon('edit', 14) ?> Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h3 class="section-title">Taxonomy archives</h3>
  <p class="help">Archive pages are marked up as a CollectionPage. Add an ItemList to also list the items shown on each page.</p>
  <div class="table-wrap"><table class="table table-form">
    <thead><tr><th>Taxonomy</th><th>Archive schema</th><th></th></tr></thead>
    <tbody>
    <?php foreach (ContentModel::taxonomies() as $slug => $t): if (empty($t['public'])) continue;
      $mode = StructuredData::taxMode($slug);
      $term = null;
      foreach (Terms::all($slug) as $tt) { if ($tt['count'] > 0) { $term = $tt; break; } }
    ?>
      <tr>
        <td><strong><?= e($t['plural']) ?></strong><br><code class="muted"><?= e($slug) ?></code></td>
        <td><select class="input input-sm" name="sd[tax][<?= e($slug) ?>]" aria-label="Archive schema for <?= e($t['plural']) ?>">
          <option value="collection"<?= $mode === 'collection' ? ' selected' : '' ?>>Collection page</option>
          <option value="itemlist"<?= $mode === 'itemlist' ? ' selected' : '' ?>>Collection page with item list</option>
        </select></td>
        <td class="seo-row-actions"><?php if ($term): ?><a class="btn btn-sm btn-ghost" href="<?= e($richTest(Terms::link($term, true))) ?>" target="_blank" rel="noopener"><?= icon('external', 14) ?> Test</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="help">Breadcrumb lists are added to items, archives and term pages automatically (SEO › Schema).</p>

<?php else:
  $t = $public[$editing];
  $current = Seo::typeDefaults($editing)['schema'];
  $schema = (string)($_GET['as'] ?? $current);
  $schema = array_key_exists($schema, Seo::SCHEMA_TYPES) ? $schema : $current;
  $mappable = StructuredData::mappable($schema);
  $props = StructuredData::props($schema);
  $saved = StructuredData::savedMap($editing, $schema);
  $suggested = StructuredData::suggest($editing, $schema);
  $map = StructuredData::map($editing, $schema);
  $sources = StructuredData::sources($editing);
  $sample = StructuredData::sample($editing);
  $img = StructuredData::typeImage($editing);
  $imgM = $img ? Media::find($img) : null;
?>
  <div class="row-between">
    <a class="btn btn-sm btn-ghost" href="<?= e($base) ?>"><?= icon('arrow-left', 14) ?> All types</a>
    <?php if ($sample): ?><a class="btn btn-sm" href="<?= e($richTest(Posts::permalink($sample, true))) ?>" target="_blank" rel="noopener"><?= icon('external', 14) ?> Test in Google Rich Results</a><?php endif; ?>
  </div>
  <input type="hidden" name="sd[type]" value="<?= e($editing) ?>">
  <div class="grid-form">
    <div class="field">
      <label for="sd_schema">Schema type for <?= e($t['plural']) ?></label>
      <select class="input" id="sd_schema" name="sd[schema]" data-sd-schema>
        <?php foreach (Seo::SCHEMA_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $schema === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <?php if ($schema !== $current): ?><small class="help text-warn">Not saved yet. Currently: <?= e(Seo::SCHEMA_TYPES[$current] ?? $current) ?>.</small><?php endif; ?>
    </div>
    <div class="field">
      <label>Default share image for <?= e(strtolower($t['plural'])) ?></label>
      <div class="media-field" data-media-field data-kind="image">
        <input type="hidden" name="sd[og_image]" value="<?= e($imgM['id'] ?? '') ?>">
        <div class="media-field-preview"><?php if ($imgM): ?><figure data-id="<?= (int)$imgM['id'] ?>"><img src="<?= e(Media::url($imgM, 'thumb')) ?>" alt=""></figure><?php endif; ?></div>
        <div class="media-field-actions"><button type="button" class="btn btn-sm" data-media-choose>Choose image</button><button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $imgM ? '' : ' hidden' ?>>Remove</button></div>
      </div>
      <small class="help">Used for sharing and schema when an item has no social or featured image.</small>
    </div>
  </div>

  <?php if (!$mappable): ?>
    <p class="muted"><?= $schema === 'None' ? 'No schema is added for this type (breadcrumbs stay).' : 'This type describes the page itself, so there are no properties to map.' ?></p>
  <?php else: ?>
    <?php if ($saved === null): ?>
      <div class="setting-note"><?= icon('sparkles', 16) ?><div><strong>Suggested mapping.</strong> <span class="muted">Matched by field names and types<?= in_array($schema, StructuredData::LEGACY, true) ? '. Until you save, this type keeps its basic output (name, description, image)' : '' ?>. Check it and save.</span></div></div>
    <?php endif; ?>
    <div class="table-wrap"><table class="table table-form seo-map">
      <thead><tr><th>Property</th><th>Value from</th></tr></thead>
      <tbody>
      <?php foreach ($props as $path => $p): $cur = (string)($map[$path] ?? ''); $fixed = str_starts_with($cur, '='); ?>
        <tr>
          <td><strong><?= e($p['label']) ?></strong><?php if ($p['req']): ?> <span class="pill pill-warn" title="Required for Google rich results">required</span><?php endif; ?><br><code class="muted"><?= e($path) ?></code></td>
          <td>
            <div class="seo-map-src">
              <select class="input input-sm" name="sd[map][<?= e($path) ?>]" data-sd-source aria-label="Value for <?= e($p['label']) ?>">
                <option value="">Not used</option>
                <?php foreach ($sources as $group => $opts): ?>
                  <optgroup label="<?= e($group) ?>">
                    <?php foreach ($opts as $v => $l): ?><option value="<?= e($v) ?>"<?= $cur === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
                <option value="="<?= $fixed ? ' selected' : '' ?>>Fixed value…</option>
              </select>
              <input class="input input-sm" name="sd[fixed][<?= e($path) ?>]" value="<?= e($fixed ? substr($cur, 1) : '') ?>" placeholder="Same for every item" data-sd-fixed<?= $fixed ? '' : ' hidden' ?> aria-label="Fixed value for <?= e($p['label']) ?>">
            </div>
            <?php if ($saved !== null && ($suggested[$path] ?? '') !== '' && $suggested[$path] !== $cur && str_starts_with((string)$suggested[$path], 'field:')): ?>
              <small class="help">Suggestion: <?= e(substr((string)$suggested[$path], 6)) ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($missing = StructuredData::missing($schema, $map)): ?>
      <p class="text-warn small"><?= icon('alert', 14) ?> Google needs these for rich results: <?= e(implode(', ', $missing)) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($sample): ?>
    <details class="seo-details">
      <summary>Preview on “<?= e($sample['title']) ?>” (saved settings)</summary>
      <pre class="seo-jsonld"><code><?= e((string)json_encode(['@context' => 'https://schema.org', '@graph' => StructuredData::preview($sample)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code></pre>
    </details>
  <?php else: ?>
    <p class="muted small">Publish a <?= e(strtolower($t['singular'])) ?> to preview and test its schema.</p>
  <?php endif; ?>
<?php endif; ?>
</div>
