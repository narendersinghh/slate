<?php
/**
 * SEO panel used for posts and terms.
 * @var array $seo  current values
 * @var string $kind 'post'|'term'
 * @var string $previewUrl
 * @var string $previewTitle
 */
use Slate\Media;
use Slate\Seo;

$seo += ['title' => '', 'description' => '', 'focus_keyword' => '', 'canonical' => '', 'robots_index' => 'default', 'nofollow' => false,
    'og_title' => '', 'og_description' => '', 'og_image' => null, 'schema_type' => '', 'schema_custom' => ''];
$ogm = $seo['og_image'] ? Media::find((int)$seo['og_image']) : null;
?>
<section class="card seo-panel" data-seo-panel>
  <div class="card-head">
    <h2><?= icon('search', 16) ?> SEO</h2>
    <div class="tabs-pill tabs-sm" data-tabs>
      <button type="button" class="active" data-tab="seo-general">General</button>
      <button type="button" data-tab="seo-social">Social</button>
      <button type="button" data-tab="seo-advanced">Advanced</button>
    </div>
  </div>
  <div class="card-pad">
    <div data-tab-panel="seo-general">
      <div class="serp" aria-label="Google preview">
        <div class="serp-site"><span class="serp-fav"></span><span><span data-serp-site><?= e(site_name()) ?></span><small data-serp-url><?= e($previewUrl) ?></small></span></div>
        <div class="serp-title" data-serp-title><?= e($previewTitle) ?></div>
        <div class="serp-desc" data-serp-desc></div>
      </div>
      <div class="field">
        <label>Meta title <span class="counter" data-counter-for="seo_title" data-max="60"></span></label>
        <input class="input" name="seo[title]" id="seo_title" value="<?= e($seo['title']) ?>" placeholder="<?= e($previewTitle) ?>" data-template="<?= e((string)\Slate\Options::get('seo_title_template')) ?>">
        <small class="help">Variables: %title% %site% %sep% %tagline%. Leave empty to use the default template.</small>
      </div>
      <div class="field">
        <label>Meta description <span class="counter" data-counter-for="seo_description" data-max="160"></span></label>
        <textarea class="input" name="seo[description]" id="seo_description" rows="3" placeholder="Auto-generated from the excerpt/content if empty."><?= e($seo['description']) ?></textarea>
      </div>
      <?php if ($kind === 'post'): ?>
      <div class="field">
        <label>Focus keyphrase</label>
        <input class="input" name="seo[focus_keyword]" id="seo_focus" value="<?= e($seo['focus_keyword']) ?>" placeholder="e.g. best running shoes">
      </div>
      <ul class="seo-checks" data-seo-checks></ul>
      <?php endif; ?>
      <?php if (\Slate\Ai::enabled() && \Slate\Auth::can('use_ai')): ?>
        <button type="button" class="btn btn-sm btn-ai" data-ai-seo><?= icon('sparkles', 15) ?> Generate with AI</button>
      <?php endif; ?>
    </div>

    <div data-tab-panel="seo-social" hidden>
      <div class="field"><label>Social title</label><input class="input" name="seo[og_title]" value="<?= e($seo['og_title']) ?>" placeholder="Defaults to the meta title"></div>
      <div class="field"><label>Social description</label><textarea class="input" name="seo[og_description]" rows="2" placeholder="Defaults to the meta description"><?= e($seo['og_description']) ?></textarea></div>
      <div class="field">
        <label>Social image <small class="muted">1200×630 recommended</small></label>
        <div class="media-field" data-media-field data-kind="image">
          <input type="hidden" name="seo[og_image]" value="<?= e($ogm['id'] ?? '') ?>">
          <div class="media-field-preview"><?php if ($ogm): ?><figure data-id="<?= (int)$ogm['id'] ?>"><img src="<?= e(Media::url($ogm, 'thumb')) ?>" alt=""></figure><?php endif; ?></div>
          <div class="media-field-actions"><button type="button" class="btn btn-sm" data-media-choose>Choose image</button><button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $ogm ? '' : ' hidden' ?>>Remove</button></div>
        </div>
        <small class="help">Falls back to the featured image, then the default share image.</small>
      </div>
    </div>

    <div data-tab-panel="seo-advanced" hidden>
      <div class="grid-form">
        <div class="field">
          <label>Search engine indexing</label>
          <select class="input" name="seo[robots_index]">
            <option value="default"<?= $seo['robots_index'] === 'default' ? ' selected' : '' ?>>Default for this type</option>
            <option value="index"<?= $seo['robots_index'] === 'index' ? ' selected' : '' ?>>Index</option>
            <option value="noindex"<?= $seo['robots_index'] === 'noindex' ? ' selected' : '' ?>>Noindex</option>
          </select>
        </div>
        <div class="field">
          <label>&nbsp;</label>
          <label class="switch-row"><input type="checkbox" class="switch" name="seo[nofollow]" value="1"<?= $seo['nofollow'] ? ' checked' : '' ?>><span>Nofollow links on this page</span></label>
        </div>
      </div>
      <div class="field"><label>Canonical URL</label><input class="input" type="url" name="seo[canonical]" value="<?= e($seo['canonical']) ?>" placeholder="<?= e($previewUrl) ?>"></div>
      <?php if ($kind === 'post'): ?>
      <div class="field">
        <label>Schema type</label>
        <select class="input" name="seo[schema_type]">
          <option value="">Default for this type</option>
          <?php foreach (Seo::SCHEMA_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $seo['schema_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (\Slate\Auth::can('unfiltered_html')): ?>
      <div class="field">
        <label>Custom JSON-LD schema</label>
        <textarea class="input code" name="seo[schema_custom]" rows="6" spellcheck="false" placeholder='{"@type":"FAQPage","mainEntity":[...]}'><?= e($seo['schema_custom']) ?></textarea>
        <small class="help">Added to the page’s schema graph. Must be valid JSON.</small>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
