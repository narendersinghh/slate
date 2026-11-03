<?php
use Slate\ContentModel;
use Slate\Seo;
?>
<div class="card-pad">
  <p class="muted">Defaults for each content type. Individual items can override them in their SEO panel.</p>
  <h3 class="section-title">Post types</h3>
  <div class="table-wrap"><table class="table table-form">
    <thead><tr><th>Type</th><th>Title template</th><th>Indexing</th><th>Schema</th><th class="center">Sitemap</th></tr></thead>
    <tbody>
    <?php foreach (ContentModel::types() as $slug => $t): if (empty($t['public'])) continue; $d = Seo::typeDefaults($slug); ?>
      <tr>
        <td><strong><?= e($t['plural']) ?></strong><br><code class="muted"><?= e($slug) ?></code></td>
        <td><input class="input input-sm" name="seo_types[<?= e($slug) ?>][title_template]" value="<?= e($d['title_template']) ?>" placeholder="<?= e((string)\Slate\Options::get('seo_title_template')) ?>"></td>
        <td><select class="input input-sm" name="seo_types[<?= e($slug) ?>][robots]"><option value="index"<?= $d['robots'] === 'index' ? ' selected' : '' ?>>Index</option><option value="noindex"<?= $d['robots'] === 'noindex' ? ' selected' : '' ?>>Noindex</option></select></td>
        <td><select class="input input-sm" name="seo_types[<?= e($slug) ?>][schema]"><?php foreach (Seo::SCHEMA_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $d['schema'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
        <td class="center"><input type="checkbox" class="switch" name="seo_types[<?= e($slug) ?>][sitemap]" value="1"<?= $d['sitemap'] ? ' checked' : '' ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <h3 class="section-title">Taxonomies</h3>
  <div class="table-wrap"><table class="table table-form">
    <thead><tr><th>Taxonomy</th><th>Title template</th><th>Indexing</th><th class="center">Sitemap</th></tr></thead>
    <tbody>
    <?php foreach (ContentModel::taxonomies() as $slug => $t): if (empty($t['public'])) continue; $d = Seo::taxDefaults($slug); ?>
      <tr>
        <td><strong><?= e($t['plural']) ?></strong><br><code class="muted"><?= e($slug) ?></code></td>
        <td><input class="input input-sm" name="seo_taxonomies[<?= e($slug) ?>][title_template]" value="<?= e($d['title_template']) ?>" placeholder="%title% %sep% %site%"></td>
        <td><select class="input input-sm" name="seo_taxonomies[<?= e($slug) ?>][robots]"><option value="index"<?= $d['robots'] === 'index' ? ' selected' : '' ?>>Index</option><option value="noindex"<?= $d['robots'] === 'noindex' ? ' selected' : '' ?>>Noindex</option></select></td>
        <td class="center"><input type="checkbox" class="switch" name="seo_taxonomies[<?= e($slug) ?>][sitemap]" value="1"<?= $d['sitemap'] ? ' checked' : '' ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
