<?php
use Slate\Auth;
use Slate\ContentModel;
use Slate\Media;
use Slate\Posts;
use Slate\Terms;

$slug = $type['slug'];
$p = $post ?? ['id' => 0, 'title' => '', 'slug' => '', 'content' => '', 'excerpt' => '', 'status' => 'draft', 'parent_id' => null, 'menu_order' => 0,
    'featured_media_id' => null, 'template' => null, 'fields' => [], 'seo' => [], 'published_at' => null, 'author_id' => Auth::id(), 'type' => $slug];
$supports = static fn(string $f) => in_array($f, $type['supports'] ?? [], true);
$permalink = $post ? Posts::permalink($post, true) : site_url(Posts::trail(($slug === 'page' ? '' : ($slug === 'post' ? '' : '/' . $type['rewrite'])) . '/your-slug'));
$base = $post ? preg_replace('#[^/]+/?$#', '', $permalink) : preg_replace('#your-slug/?$#', '', $permalink);
$canPublish = Auth::can('publish_posts');
$featured = $p['featured_media_id'] ? Media::find((int)$p['featured_media_id']) : null;
$mainGroups = array_filter($groups, static fn($g) => ($g['position'] ?? 'main') === 'main');
$sideGroups = array_filter($groups, static fn($g) => ($g['position'] ?? 'main') === 'side');
$pubLocal = $p['published_at'] ? local_date($p['published_at'], 'Y-m-d\TH:i') : '';
?>
<form method="post" action="<?= e(admin_url("content/$slug/save")) ?>" class="editor-form" data-post-form data-type="<?= e($slug) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

  <div class="page-head page-head-sticky">
    <div class="page-head-left">
      <a class="icon-btn" href="<?= e(admin_url("content/$slug")) ?>" aria-label="Back to <?= e($type['plural']) ?>"><?= icon('chevron-left') ?></a>
      <div>
        <small class="muted"><?= e($type['plural']) ?></small>
        <h1 class="h-compact"><?= e($title) ?></h1>
      </div>
      <span class="status status-<?= e($p['status']) ?>" data-status-badge><?= e(Posts::STATUSES[$p['status']] ?? '') ?></span>
      <span class="save-state muted" data-save-state></span>
    </div>
    <div class="page-actions">
      <?php if ($post): ?>
        <a class="btn btn-ghost" href="<?= e(Posts::permalink($post) . ($post['status'] !== 'publish' ? '?preview=' . $post['id'] : '')) ?>" target="_blank" rel="noopener" data-view-link><?= icon('eye', 16) ?> <span class="hide-sm"><?= $post['status'] === 'publish' ? 'View' : 'Preview' ?></span></a>
      <?php endif; ?>
      <button class="btn" type="submit" name="status" value="draft" data-save="draft"><?= $p['status'] === 'publish' ? 'Switch to draft' : 'Save draft' ?></button>
      <button class="btn btn-primary" type="submit" name="status" value="<?= $canPublish ? 'publish' : 'pending' ?>" data-save="publish">
        <?= $canPublish ? ($p['status'] === 'publish' ? 'Update' : 'Publish') : 'Submit for review' ?>
      </button>
    </div>
  </div>

  <div class="editor-layout">
    <div class="editor-main">
      <?php if ($supports('title')): ?>
      <input class="title-input" name="title" value="<?= e($p['title']) ?>" placeholder="Add title" aria-label="Title" data-title autofocus>
      <div class="permalink">
        <?= icon('link', 14) ?>
        <span class="muted" data-permalink-base><?= e($base) ?></span><input class="slug-input" name="slug" value="<?= e($p['slug']) ?>" placeholder="auto" data-slug aria-label="URL slug" spellcheck="false"><?= \Slate\Options::get('permalink_trailing_slash', true) ? '<span class="muted">/</span>' : '' ?>
        <button type="button" class="link" data-copy="<?= e($permalink) ?>"><?= icon('copy', 13) ?></button>
      </div>
      <?php endif; ?>

      <?php if ($supports('editor')): ?>
      <div class="editor" data-editor data-main-editor>
        <textarea name="content" hidden><?= e($p['content']) ?></textarea>
      </div>
      <?php endif; ?>

      <?php foreach ($mainGroups as $g): ?>
      <section class="card">
        <div class="card-head"><h2><?= e($g['title']) ?></h2></div>
        <div class="card-pad field-grid">
          <?php foreach ($g['fields'] as $f): $value = $p['fields'][$f['name']] ?? null; $name = 'fields[' . $f['name'] . ']'; require __DIR__ . '/partials/field.php'; endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <?php if ($supports('excerpt')): ?>
      <section class="card">
        <div class="card-head"><h2>Excerpt</h2><?php if (\Slate\Ai::enabled()): ?><button type="button" class="btn btn-ghost btn-sm btn-ai" data-ai-excerpt><?= icon('sparkles', 14) ?> Write with AI</button><?php endif; ?></div>
        <div class="card-pad"><textarea class="input" name="excerpt" rows="3" placeholder="A short summary shown in listings and used as the default meta description."><?= e($p['excerpt']) ?></textarea></div>
      </section>
      <?php endif; ?>

      <?php if ($supports('seo')): ?>
        <?php $seo = $p['seo']; $kind = 'post'; $previewUrl = $permalink; $previewTitle = $post ? \Slate\Seo::postTitle($post) : site_name(); require __DIR__ . '/partials/seo-panel.php'; ?>
      <?php endif; ?>
    </div>

    <aside class="editor-side">
      <section class="card">
        <div class="card-head"><h2>Publish</h2></div>
        <div class="card-pad stack-sm">
          <div class="field">
            <label>Status</label>
            <select class="input" name="status_select" data-status-select>
              <?php foreach (['draft' => 'Draft', 'pending' => 'Pending review', 'publish' => 'Published', 'private' => 'Private'] as $k => $l):
                if (!$canPublish && in_array($k, ['publish', 'private'], true) && $p['status'] !== $k) continue; ?>
                <option value="<?= $k ?>"<?= $p['status'] === $k || ($p['status'] === 'scheduled' && $k === 'publish') ? ' selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Publish date</label>
            <input class="input" type="datetime-local" name="published_at" value="<?= e($pubLocal) ?>">
            <small class="help">Pick a future date to schedule.</small>
          </div>
          <?php if ($authors && $supports('author')): ?>
          <div class="field">
            <label>Author</label>
            <select class="input" name="author_id">
              <?php foreach ($authors as $a): ?><option value="<?= (int)$a['id'] ?>"<?= (int)$p['author_id'] === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <?php if ($post): ?>
          <div class="meta-list">
            <div><span>Created</span><span><?= e(local_date($post['created_at'], 'M j, Y g:i a')) ?></span></div>
            <div><span>Updated</span><span data-updated><?= e(time_ago($post['updated_at'])) ?></span></div>
          </div>
          <div class="side-links">
            <button type="submit" class="link" formaction="<?= e(admin_url("content/$slug/{$post['id']}/duplicate")) ?>"><?= icon('copy', 14) ?> Duplicate</button>
            <?php if ($post['status'] !== 'trash'): ?>
            <input type="hidden" name="ids[]" value="<?= (int)$post['id'] ?>">
            <button type="submit" class="link link-danger" formaction="<?= e(admin_url("content/$slug/bulk")) ?>" name="action" value="trash" data-confirm="Move to trash?" data-trash-id="<?= (int)$post['id'] ?>"><?= icon('trash', 14) ?> Move to trash</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </section>

      <?php if (\Slate\Ai::enabled() && Auth::can('use_ai') && $supports('editor')): ?>
      <section class="card ai-card">
        <div class="card-head"><h2><?= icon('sparkles', 16) ?> AI assistant</h2></div>
        <div class="card-pad stack-sm">
          <select class="input" data-ai-action>
            <?php foreach (\Slate\Ai::ACTIONS as $k => $a): if (in_array($k, ['seo_meta', 'excerpt', 'alt'], true)) continue; ?>
              <option value="<?= e($k) ?>"><?= e($a['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea class="input" rows="2" data-ai-input placeholder="Instruction or brief (for “Custom” and “Draft”)"></textarea>
          <button type="button" class="btn btn-ai btn-block" data-ai-run><?= icon('sparkles', 15) ?> Run</button>
          <small class="help">Applies to the selected text, or the whole content if nothing is selected. You can review before applying.</small>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($supports('page_attributes') || $type['hierarchical'] || !empty($templates)): ?>
      <section class="card">
        <div class="card-head"><h2>Attributes</h2></div>
        <div class="card-pad stack-sm">
          <?php if ($type['hierarchical']): ?>
          <div class="field">
            <label>Parent</label>
            <select class="input" name="parent_id">
              <option value="">(no parent)</option>
              <?php foreach ($parents as $par): ?><option value="<?= (int)$par['id'] ?>"<?= (int)$p['parent_id'] === (int)$par['id'] ? ' selected' : '' ?>><?= e($par['title'] ?: '(no title)') ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Order</label><input class="input" type="number" name="menu_order" value="<?= (int)$p['menu_order'] ?>"></div>
          <?php endif; ?>
          <?php if (!empty($templates)): ?>
          <div class="field">
            <label>Template</label>
            <select class="input" name="template">
              <option value="">Default</option>
              <?php foreach ($templates as $k => $l): ?><option value="<?= e($k) ?>"<?= $p['template'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php foreach (ContentModel::taxonomiesFor($slug) as $tx => $tdef): $selected = array_column($postTerms[$tx] ?? [], 'id'); ?>
      <section class="card">
        <div class="card-head"><h2><?= e($tdef['plural']) ?></h2><?php if (Auth::can('manage_terms')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url("terms/$tx")) ?>" target="_blank">Manage</a><?php endif; ?></div>
        <div class="card-pad">
          <?php if ($tdef['hierarchical']): ?>
            <input type="hidden" name="terms[<?= e($tx) ?>][]" value="">
            <div class="term-checklist">
              <?php foreach (Terms::tree($tx) as $t): ?>
                <label class="choice" style="padding-left:<?= (int)$t['depth'] * 18 ?>px"><input type="checkbox" name="terms[<?= e($tx) ?>][]" value="<?= (int)$t['id'] ?>"<?= in_array($t['id'], $selected, true) ? ' checked' : '' ?>> <?= e($t['name']) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="add-term" data-add-term="<?= e($tx) ?>">
              <input class="input input-sm" placeholder="New <?= e(strtolower($tdef['singular'])) ?>…" data-new-term>
            </div>
          <?php else: ?>
            <div class="tag-input" data-tags>
              <input type="hidden" name="terms[<?= e($tx) ?>]" value="<?= e(implode(',', array_column($postTerms[$tx] ?? [], 'name'))) ?>">
              <div class="chips" data-chips></div>
              <input class="input input-sm" placeholder="Add <?= e(strtolower($tdef['plural'])) ?>, separated by commas" data-tag-entry data-taxonomy="<?= e($tx) ?>" autocomplete="off">
              <div class="suggest" data-suggest hidden></div>
            </div>
          <?php endif; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <?php if ($supports('featured_image')): ?>
      <section class="card">
        <div class="card-head"><h2>Featured image</h2></div>
        <div class="card-pad">
          <div class="media-field media-field-lg" data-media-field data-kind="image">
            <input type="hidden" name="featured_media_id" value="<?= e($featured['id'] ?? '') ?>">
            <div class="media-field-preview"><?php if ($featured): ?><figure data-id="<?= (int)$featured['id'] ?>"><img src="<?= e(Media::url($featured)) ?>" alt=""></figure><?php endif; ?></div>
            <div class="media-field-actions">
              <button type="button" class="btn btn-sm" data-media-choose><?= icon('image', 15) ?> <?= $featured ? 'Replace' : 'Set featured image' ?></button>
              <button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $featured ? '' : ' hidden' ?>>Remove</button>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php foreach ($sideGroups as $g): ?>
      <section class="card">
        <div class="card-head"><h2><?= e($g['title']) ?></h2></div>
        <div class="card-pad field-grid">
          <?php foreach ($g['fields'] as $f): $value = $p['fields'][$f['name']] ?? null; $name = 'fields[' . $f['name'] . ']'; require __DIR__ . '/partials/field.php'; endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </aside>
  </div>
</form>
<?php $scripts = ['editor.js']; ?>
