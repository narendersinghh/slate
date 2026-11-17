<?php
use Slate\ContentModel;
use Slate\DB;
use Slate\Options;

$presets = (require __DIR__ . '/partials/model-presets.php')['taxonomies'];
$d = $def ?? ['slug' => '', 'singular' => '', 'plural' => '', 'post_types' => [], 'hierarchical' => true, 'public' => true, 'rewrite' => '', 'nested' => true, 'builtin' => false];
if (!$def && ($p = $presets[(string)($_GET['preset'] ?? '')] ?? null)) {
    $d = array_merge($d, ['singular' => $p[0], 'plural' => $p[1], 'hierarchical' => $p[2], 'slug' => (string)$_GET['preset'], 'rewrite' => \slugify($p[0])]);
}
$d += ['post_types' => [], 'hierarchical' => false, 'public' => true, 'rewrite' => '', 'nested' => true];
$builtin = !empty($d['builtin']);
$slug = (string)$d['slug'];
$terms = $def ? (int)DB::val('SELECT COUNT(*) FROM {{terms}} WHERE taxonomy = :t', ['t' => $slug]) : 0;
$trailing = (bool)Options::get('permalink_trailing_slash', true);
$cfg = [
    'existing' => array_values(array_diff(array_keys(ContentModel::taxonomies()), [$slug])),
    'reserved' => ContentModel::RESERVED,
    'site' => rtrim(site_url(), '/'),
    'trailing' => $trailing,
    'isNew' => !$def,
];
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/model.css')) ?>">
<form method="post" action="<?= e(admin_url('model/taxonomies/save')) ?>" class="cm-edit" data-cm-edit="tax">
  <?= csrf_field() ?>
  <input type="hidden" name="original" value="<?= e($def['slug'] ?? '') ?>">
  <script type="application/json" data-cm-config><?= json_encode($cfg, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?></script>

  <div class="cm-edit-head">
    <a class="icon-btn" href="<?= e(admin_url('model/taxonomies')) ?>" aria-label="Back to taxonomies"><?= icon('chevron-left') ?></a>
    <span class="cm-icon" data-cm-tax-icon><?= icon(!empty($d['hierarchical']) ? 'folder' : 'tag', 20) ?></span>
    <div class="cm-edit-title">
      <small class="muted">Taxonomies</small>
      <h1 data-cm-title data-new="New taxonomy"><?= e($def ? $d['plural'] : 'New taxonomy') ?></h1>
    </div>
    <?php if ($builtin): ?><span class="pill">Built-in</span><?php endif; ?>
  </div>

  <div class="cm-edit-layout">
    <div class="cm-edit-main">
      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('edit', 17) ?> Basics</h2></div>
        <div class="card-pad cm-fields-grid">
          <label class="field"><span>Name of one term</span>
            <input class="input" name="singular" value="<?= e($d['singular']) ?>" placeholder="Service" required maxlength="60" data-cm-singular autocomplete="off">
            <small class="help">Used in buttons like "Add <span data-cm-add-hint>service</span>".</small></label>
          <label class="field"><span>Name of the list</span>
            <input class="input" name="plural" value="<?= e($d['plural']) ?>" placeholder="Services" required maxlength="60" data-cm-plural autocomplete="off">
            <small class="help">Shown in the editor and the menu. Filled in for you.</small></label>

          <div class="field span-2">
            <span>Kind</span>
            <div class="cm-kind">
              <label class="cm-kind-opt"><input type="radio" name="hierarchical" value="1"<?= !empty($d['hierarchical']) ? ' checked' : '' ?> data-cm-hier>
                <span class="cm-feature-icon"><?= icon('folder', 16) ?></span><span><strong>Nested</strong><small>Like categories. Terms can have sub-terms, editors tick boxes.</small></span></label>
              <label class="cm-kind-opt"><input type="radio" name="hierarchical" value=""<?= empty($d['hierarchical']) ? ' checked' : '' ?> data-cm-hier>
                <span class="cm-feature-icon"><?= icon('tag', 16) ?></span><span><strong>Flat</strong><small>Like tags. Editors type terms freely.</small></span></label>
            </div>
          </div>
        </div>
      </section>

      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('box', 17) ?> Used by</h2><span class="muted small">Which content can be given these terms</span></div>
        <div class="card-pad cm-feature-grid">
          <?php foreach (ContentModel::types() as $ptSlug => $t): ?>
            <label class="cm-feature"><input type="checkbox" name="post_types[]" value="<?= e($ptSlug) ?>"<?= in_array($ptSlug, (array)$d['post_types'], true) ? ' checked' : '' ?> data-cm-pt data-label="<?= e($t['plural']) ?>">
              <span class="cm-feature-icon"><?= icon($t['icon'] ?? 'file', 16) ?></span>
              <span class="cm-feature-text"><strong><?= e($t['plural']) ?></strong><small><code><?= e($ptSlug) ?></code></small></span>
              <span class="cm-feature-check"><?= icon('check', 13) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card cm-section">
        <div class="card-head"><h2><?= icon('globe', 17) ?> Where it appears</h2></div>
        <div class="cm-switches">
          <label class="cm-switch"><span><strong>Term pages on the website</strong><small>Each term gets a page listing its content, and appears in the sitemap.</small></span>
            <input type="checkbox" class="switch" name="public" value="1"<?= !empty($d['public']) ? ' checked' : '' ?> data-cm-public></label>
        </div>
        <div class="card-pad cm-url-block" data-cm-needs-public>
          <div class="field">
            <span>Web address</span>
            <div class="cm-url-input">
              <span class="cm-url-host"><?= e(preg_replace('#^https?://#', '', rtrim(site_url(), '/'))) ?>/</span>
              <input class="input" name="rewrite" value="<?= e($d['rewrite']) ?>" placeholder="service" data-cm-rewrite autocomplete="off" spellcheck="false" aria-label="URL base">
              <span class="cm-url-tail">/term-name<?= $trailing ? '/' : '' ?></span>
            </div>
            <small class="help">Leave empty to put term pages at the top of your site.</small>
          </div>
        </div>
      </section>

      <details class="card cm-section cm-advanced" data-cm-advanced>
        <summary class="card-head"><h2><?= icon('settings', 17) ?> Advanced</h2><span class="cm-summary-hint muted small">Key and nested addresses</span><?= icon('chevron-down', 16) ?></summary>
        <div class="card-pad cm-fields-grid">
          <label class="field"><span>Key</span>
            <input class="input" name="slug" value="<?= e($slug) ?>" placeholder="service" pattern="[a-z][a-z0-9_\-]{1,38}" <?= $builtin ? 'disabled' : 'required' ?> data-cm-key autocomplete="off" spellcheck="false">
            <small class="help"><?= $builtin ? 'Built-in taxonomies keep their key.' : 'Used by themes and the API. Filled in for you.' ?><?= $def && !$builtin ? ' Changing it moves all existing terms to the new key.' : '' ?></small>
            <small class="cm-error" data-cm-key-error hidden></small></label>
          <div class="field" data-cm-needs-hier>
            <span>Sub-term addresses</span>
            <label class="switch-row"><input type="hidden" name="nested" value="0"><input type="checkbox" class="switch" name="nested" value="1"<?= ($d['nested'] ?? true) ? ' checked' : '' ?>>
              <span>Include the parent, like <code>/<span data-cm-rw-mini><?= e($d['rewrite'] ?: 'service') ?></span>/parent/child/</code></span></label>
          </div>
        </div>
      </details>

      <?php if ($def && !$builtin): ?>
      <section class="card cm-danger">
        <div class="cm-danger-text">
          <h2>Delete this taxonomy</h2>
          <p class="muted"><?= $terms ? 'Its ' . number_format($terms) . ' ' . ($terms === 1 ? 'term is' : 'terms are') . ' deleted too and removed from all content. The content itself stays.' : 'It has no terms yet.' ?></p>
        </div>
        <button class="btn btn-danger" type="submit" formaction="<?= e(admin_url('model/taxonomies/' . $slug . '/delete')) ?>" formnovalidate data-confirm="Delete the taxonomy “<?= e($d['plural']) ?>” and its <?= $terms ?> terms? This cannot be undone."><?= icon('trash', 15) ?> Delete taxonomy</button>
      </section>
      <?php endif; ?>
    </div>

    <aside class="cm-edit-side">
      <section class="card cm-preview">
        <div class="card-head"><h2><?= icon('eye', 17) ?> Preview</h2></div>
        <div class="card-pad stack-sm">
          <span class="small-label">In the editor</span>
          <div class="cm-tax-mock" data-cm-tax-mock></div>
          <span class="small-label">Addresses</span>
          <ul class="cm-urls" data-cm-urls data-mode="tax"></ul>
          <?php if ($def): ?><a class="btn btn-sm" href="<?= e(admin_url('terms/' . $slug)) ?>"><?= icon('list', 14) ?> Manage <?= number_format($terms) ?> <?= $terms === 1 ? 'term' : 'terms' ?></a><?php endif; ?>
        </div>
      </section>
    </aside>
  </div>

  <div class="save-bar cm-save-bar">
    <a class="btn btn-ghost" href="<?= e(admin_url('model/taxonomies')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit"><?= icon('check', 15) ?> <?= $def ? 'Save changes' : 'Create taxonomy' ?></button>
  </div>
</form>
<?php $scripts = ['model.js']; ?>
