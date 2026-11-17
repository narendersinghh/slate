<?php
/** Template editor: code with a tag palette and a live preview of unsaved changes (admin/assets/template-editor.js). */
use Slate\ContentModel;
use Slate\Templates;

$t = $tpl;
$fields = [];
$taxes = [];
if (in_array($t['kind'], ['single', 'archive'], true)) {
    $type = explode('__', (string)$t['target'], 2)[0];
    $fields = ContentModel::fieldsFor('post', $type);
    $taxes = ContentModel::taxonomiesFor($type);
}
$ai = \Slate\Ai::enabled() && \Slate\Auth::can('use_ai');
$tag = static fn(string $code, string $label) => '<button type="button" class="tpl-tag" data-insert="' . e($code) . '" data-search="' . e(strtolower($label . ' ' . $code)) . '"><span>' . e($label) . '</span><code>' . e(strtok($code, "\n")) . '</code></button>';
$group = static fn(string $title, string $html, bool $open = true) => '<details class="tpl-pal-group"' . ($open ? ' open' : '') . '><summary>' . e($title) . icon('chevron-down', 14) . '</summary><div>' . $html . '</div></details>';

$item = $tag('{{title}}', 'Title') . $tag('{{{content}}}', 'Content') . $tag('{{excerpt}}', 'Excerpt') . $tag('{{url}}', 'Link address')
    . $tag('{{date}}', 'Date') . $tag('{{image}}', 'Featured image') . $tag('{{author}}', 'Author');
$fieldTags = '';
foreach ($fields as $f) {
    $fieldTags .= match ($f['type']) {
        'richtext' => $tag('{{{field:' . $f['name'] . '}}}', $f['label']),
        'image' => $tag('{{field:' . $f['name'] . '|image}}', $f['label']),
        'date' => $tag('{{field:' . $f['name'] . '|date}}', $f['label']),
        'repeater' => $tag("{{#each field:{$f['name']}}}\n  " . implode(' ', array_map(static fn($sf) => ($sf['type'] === 'richtext' ? '{{{item:' . $sf['name'] . '}}}' : '{{item:' . $sf['name'] . '}}'), $f['sub_fields'] ?? [])) . "\n{{/each}}", $f['label'] . ' (list)'),
        'relation' => $tag("{{#each related:{$f['name']}}}\n  <a href=\"{{url}}\">{{title}}</a>\n{{/each}}", $f['label'] . ' (linked items)'),
        default => $tag('{{field:' . $f['name'] . '}}', $f['label']),
    };
}
$taxTags = '';
foreach ($taxes as $tx => $d) {
    $taxTags .= $tag('{{{terms:' . $tx . '}}}', $d['plural'] . ' (links)');
}
$logic = $tag("{{#if field:name}}\n  …\n{{else}}\n  …\n{{/if}}", 'If / else')
    . $tag("{{#posts}}\n  <a href=\"{{url}}\">{{title}}</a>\n{{else}}\n  Nothing yet.\n{{/posts}}", 'Items of this listing')
    . $tag("{{#query type=post limit=3}}\n  <a href=\"{{url}}\">{{title}}</a>\n{{/query}}", 'Latest items of any type')
    . $tag("{{#terms taxonomy=category limit=10}}\n  <a href=\"{{url}}\">{{name}}</a>\n{{/terms}}", 'Terms of a taxonomy')
    . $tag('{{{pagination}}}', 'Page numbers');
$site = $tag('{{{breadcrumbs}}}', 'Breadcrumbs') . $tag('{{{menu:primary}}}', 'Menu') . $tag('{{site.name}}', 'Site name')
    . $tag('{{archive.title}}', 'Listing title') . $tag('{{year}}', 'Current year');
$targets = '<optgroup label="Content types">';
foreach (ContentModel::types() as $slug => $ty) {
    $targets .= '<option value="' . e($slug) . '"' . ($t['target'] === $slug ? ' selected' : '') . '>' . e($ty['plural']) . '</option>';
}
$targets .= '</optgroup><optgroup label="Taxonomies">';
foreach (ContentModel::taxonomies() as $slug => $tx) {
    $targets .= '<option value="' . e($slug) . '"' . ($t['target'] === $slug ? ' selected' : '') . '>' . e($tx['plural']) . '</option>';
}
$targets .= '</optgroup>';
if (str_contains((string)$t['target'], '__')) {
    $targets .= '<optgroup label="One item"><option value="' . e($t['target']) . '" selected>' . e(Templates::label('single', (string)$t['target'])) . '</option></optgroup>';
}
$targets .= '<optgroup label="Special">';
foreach (Templates::SPECIAL as $k => $l) {
    $targets .= '<option value="' . e($k) . '"' . ($t['target'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
}
$targets .= '</optgroup>';
?>
<form method="post" action="<?= e(admin_url('templates/save')) ?>" class="tpe" data-tpl-form
      data-preview-url="<?= e(admin_url('templates/preview')) ?>" data-items-url="<?= e(admin_url('template-items')) ?>" data-starter-url="<?= e(admin_url('templates/new')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($t['id']) ?>">

  <header class="tpe-bar">
    <a class="icon-btn" href="<?= e(admin_url('templates')) ?>" aria-label="Back to templates" title="All templates"><?= icon('chevron-left') ?></a>
    <div class="tpe-title">
      <input class="tpe-name" name="name" value="<?= e($t['name']) ?>" aria-label="Template name" placeholder="Template name">
      <button type="button" class="tpe-where" data-toggle-settings aria-expanded="<?= $t['id'] === '' ? 'true' : 'false' ?>"><?= icon('settings', 13) ?> <span data-where><?= e(Templates::label($t['kind'], (string)$t['target'])) ?></span><?= icon('chevron-down', 13) ?></button>
    </div>
    <span class="tpe-state muted small" data-save-state></span>
    <div class="tpe-actions">
      <?php if ($ai): ?><button type="button" class="btn btn-sm btn-ghost hide-sm" data-ask-goraiya><?= icon('sparrow', 15) ?> Ask Goraiya</button><?php endif; ?>
      <label class="tpe-live" title="Visitors see this template when it is in use"><input type="checkbox" class="switch" name="enabled" value="1"<?= $t['enabled'] ? ' checked' : '' ?>> <span>In use</span></label>
      <button class="btn btn-primary btn-sm" type="submit" title="Save (Ctrl/⌘ S)"><?= icon('check', 15) ?> Save</button>
    </div>
  </header>

  <section class="tpe-settings card" data-settings<?= $t['id'] === '' ? '' : ' hidden' ?>>
    <label class="field"><span>Kind of page</span><select class="input" name="kind"><?php foreach (Templates::KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $t['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Applies to</span><select class="input" name="target"><?= $targets ?></select></label>
    <label class="switch-row"><input type="checkbox" class="switch" name="wrap" value="1"<?= $t['wrap'] ? ' checked' : '' ?>><span>Show the site's header and footer around it</span></label>
    <div class="tpe-settings-actions">
      <button type="button" class="btn btn-sm btn-ghost" data-starter><?= icon('refresh', 14) ?> Start again from the starter</button>
      <?php if ($t['id']): ?><button class="btn btn-sm btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('templates/' . $t['id'] . '/delete')) ?>" data-confirm="Delete this template? Pages fall back to the theme design."><?= icon('trash', 14) ?> Delete template</button><?php endif; ?>
    </div>
  </section>

  <div class="tpe-work" data-work>
    <aside class="tpe-palette" data-palette>
      <label class="tpe-find"><?= icon('search', 14) ?><input class="input input-sm" type="search" placeholder="Find a tag" data-tag-find aria-label="Find a tag"></label>
      <div class="tpe-pal-scroll">
        <?= $group('This item', $item) ?>
        <?= $fieldTags !== '' ? $group('Custom fields', $fieldTags) : '' ?>
        <?= $taxTags !== '' ? $group('Taxonomies', $taxTags) : '' ?>
        <?= $group('Lists and logic', $logic, false) ?>
        <?= $group('Site', $site, false) ?>
        <p class="tpe-help">Double braces print text safely; triple braces <code>{{{ }}}</code> print HTML. Filters: <code>|image</code> <code>|date:F j, Y</code> <code>|url</code> <code>|nl2br</code> <code>|embed</code>.</p>
      </div>
    </aside>

    <section class="tpe-code">
      <div class="tpe-tabs" role="tablist">
        <button type="button" role="tab" class="is-on" data-code-tab="html">HTML</button>
        <button type="button" role="tab" data-code-tab="css">CSS</button>
        <span class="tpe-tabs-space"></span>
        <button type="button" class="icon-btn" data-toggle-palette title="Show or hide tags" aria-label="Show or hide tags"><?= icon('list', 15) ?></button>
        <button type="button" class="icon-btn" data-toggle-preview title="Show or hide the preview" aria-label="Show or hide the preview"><?= icon('eye', 15) ?></button>
      </div>
      <div class="tpe-editor" data-editor="html">
        <pre class="tpe-gutter" aria-hidden="true"></pre>
        <textarea name="html" spellcheck="false" autocomplete="off" data-code aria-label="Template HTML"><?= e($t['html']) ?></textarea>
      </div>
      <div class="tpe-editor" data-editor="css" hidden>
        <pre class="tpe-gutter" aria-hidden="true"></pre>
        <textarea name="css" spellcheck="false" autocomplete="off" data-code aria-label="Template CSS" placeholder="/* Only loaded with this template. Design-system variables work here, e.g. */&#10;.tagline { font: var(--type-h4); color: var(--color-primary); }"><?= e($t['css']) ?></textarea>
      </div>
    </section>

    <section class="tpe-preview" data-preview-pane>
      <div class="tpe-pv-bar">
        <select class="input input-sm" data-item aria-label="Preview with"><option value="">Preview with: latest item</option></select>
        <div class="tpe-devices" role="group" aria-label="Preview width">
          <button type="button" class="is-on" data-device="100%" title="Desktop" aria-label="Desktop"><?= icon('monitor', 15) ?></button>
          <button type="button" data-device="768px" title="Tablet" aria-label="Tablet"><svg class="ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
          <button type="button" data-device="390px" title="Phone" aria-label="Phone"><svg class="ic" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/></svg></button>
        </div>
        <button type="button" class="icon-btn" data-refresh title="Refresh preview" aria-label="Refresh preview"><?= icon('refresh', 15) ?></button>
        <a class="icon-btn" href="#" target="_blank" rel="noopener" data-open title="Open preview in a new tab" aria-label="Open preview in a new tab"><?= icon('external', 15) ?></a>
      </div>
      <div class="tpe-frame-wrap"><iframe title="Template preview" data-frame></iframe><div class="tpe-pv-msg muted small" data-pv-msg hidden></div></div>
    </section>
  </div>
</form>
<?php $scripts = ['template-editor.js']; ?>
