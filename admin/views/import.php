<?php
/** @var string $step upload|review|done */
use Slate\AdminUi;
use Slate\ContentModel;
use Slate\HtmlPages;
use Slate\SiteImport;

$meta = $meta ?? [];
$mb = static fn($bytes) => $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
echo AdminUi::header('Import pages', 'Bring in pages made elsewhere (HTML, CSS, JS, images, fonts), from files or from a link. Each becomes a Slate page you can edit by clicking on it, with its own design or your site\'s styles.', 'upload');
echo AdminUi::steps(['upload' => 'Upload or link', 'review' => 'Choose pages and design', 'done' => 'Done'], $step);
?>
<?php if ($step === 'upload'): $tab = input('tab') === 'url' ? 'url' : 'files'; ?>
<?php foreach (['x', 'file', 'palette', 'code', 'image', 'type', 'box'] as $ic): ?><template data-import-icon="<?= $ic ?>"><?= icon($ic, $ic === 'x' ? 14 : 15) ?></template><?php endforeach; ?>
<div class="import-tabs"><?= AdminUi::views(['files' => ['Upload files', admin_url('import')], 'url' => ['From a link', admin_url('import?tab=url')]], $tab) ?></div>
<?php if ($tab === 'files'): ?>
<form class="card" method="post" action="<?= e(admin_url('import/upload')) ?>" enctype="multipart/form-data" data-site-import>
  <?= csrf_field() ?>
  <div class="card-pad stack">
    <label class="import-drop" data-import-drop>
      <span class="import-drop-icon"><?= icon('upload', 26) ?></span>
      <strong>Drop a .zip of your site, a folder, or the files of a page here</strong>
      <span class="muted small">Pick an HTML page together with its CSS, JavaScript, images and fonts: they are treated as one page (or one site). Up to 3,000 files.</span>
      <input type="file" name="files[]" multiple accept=".zip,.html,.htm,.css,.js,.mjs,.json,.png,.jpg,.jpeg,.gif,.webp,.avif,.svg,.ico,.bmp,.woff,.woff2,.ttf,.otf,.eot,.mp4,.webm,.ogg,.mp3,.pdf" data-import-files>
    </label>
    <div class="row-between">
      <span class="import-pickers">
        <label class="btn btn-ghost btn-sm"><input type="file" multiple hidden data-import-more accept=".zip,.html,.htm,.css,.js,.mjs,.json,.png,.jpg,.jpeg,.gif,.webp,.avif,.svg,.ico,.bmp,.woff,.woff2,.ttf,.otf,.eot,.mp4,.webm,.ogg,.mp3,.pdf"> <?= icon('plus', 15) ?> Add more files</label>
        <label class="btn btn-ghost btn-sm"><input type="file" webkitdirectory multiple hidden data-import-folder> <?= icon('folder', 15) ?> Choose a folder</label>
      </span>
      <span class="muted small" data-import-picked></span>
    </div>
    <div class="import-files" data-import-list hidden></div>
    <div class="alert" data-import-msg hidden></div>
    <div><button class="btn btn-primary" type="submit" data-import-go><?= icon('upload', 16) ?> Upload and find pages</button></div>
  </div>
</form>
<div class="card card-pad muted small stack-sm">
  <strong class="text-2">How it works</strong>
  <span>1. Files that belong together are linked automatically: if the page asks for <code>css/style.css</code> and you picked <code>style.css</code>, that file is used. Stylesheets and scripts no page asks for can be attached to the page in the next step.</span>
  <span>2. Links between your pages are pointed at their new Slate addresses; images, styles and scripts keep working.</span>
  <span>3. Texts, images, icons and repeating parts (sliders, card lists) become editable: open the page and click what you want to change.</span>
  <span>4. Goraiya (the assistant in the top bar) can then connect lists to your content, so new posts appear automatically.</span>
</div>
<?php else: ?>
<form class="card" method="post" action="<?= e(admin_url('import/url')) ?>" data-url-import>
  <?= csrf_field() ?>
  <div class="card-pad stack">
    <label class="field"><span>Page address</span>
      <textarea class="input" name="urls" rows="4" required placeholder="https://example.com/&#10;https://example.com/about" spellcheck="false" data-import-urls></textarea>
      <small class="help">One address per line. The page is downloaded with everything it uses: stylesheets (and the fonts and background images inside them), images, scripts, icons and video posters.</small>
    </label>
    <label class="choice"><input type="checkbox" name="follow" value="1" data-import-follow> Also import the pages these link to on the same site (one level deep, up to <?= SiteImport::URL_MAX_PAGES ?> pages), so their links point at each other</label>
    <div class="alert" data-import-msg hidden></div>
    <div class="row-between">
      <button class="btn btn-primary" type="submit" data-import-fetch><?= icon('download', 16) ?> Fetch and find pages</button>
      <span class="muted small"><?= icon('shield', 13) ?> Only public websites. Downloaded scripts are stored as files and never run on the server.</span>
    </div>
  </div>
</form>
<div class="card card-pad muted small stack-sm">
  <strong class="text-2">Good to know</strong>
  <span>Import only pages you own or have the right to use. Files that cannot be downloaded are listed in the report; the page then keeps loading them from the original site.</span>
  <span>Big sites take a while: up to <?= SiteImport::URL_MAX_PAGES ?> pages and 150 MB per import.</span>
</div>
<?php endif; ?>

<?php elseif ($step === 'review'):
    $first = $pages[0]['path'];
    $fromUrl = ($meta['source'] ?? '') === 'url';
    $prev = static fn(string $path, string $mode) => admin_url('import/' . $folder . '/preview') . '?path=' . rawurlencode($path) . '&design=' . $mode;
    $unused = (array)($meta['unused'] ?? []);
    $failed = (array)($meta['failed'] ?? []);
?>
<form class="stack" method="post" action="<?= e(admin_url('import/' . $folder)) ?>" data-import-review data-preview="<?= e(admin_url('import/' . $folder . '/preview')) ?>">
  <?= csrf_field() ?>
  <?php if ($fromUrl): ?>
  <div class="alert <?= $failed ? '' : 'alert-success' ?>"><?= icon($failed ? 'info' : 'check-circle', 16) ?><div>
    Fetched <?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?> and <?= (int)($meta['fetched'] ?? 0) ?> file<?= (int)($meta['fetched'] ?? 0) === 1 ? '' : 's' ?> (<?= e($mb((int)($meta['bytes'] ?? 0))) ?>).
    <?php if ($failed): ?><?= count($failed) ?> could not be fetched; they are listed in the report after the import.<?php endif; ?>
  </div></div>
  <?php endif; ?>
  <section class="card">
    <div class="card-head"><h2><?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?> found</h2><label class="small"><input type="checkbox" checked data-import-all> Select all</label></div>
    <div class="table-wrap"><table class="table">
      <thead><tr><th class="col-check"></th><th>Page</th><th><?= $fromUrl ? 'Address' : 'File' ?></th><th>New address</th><th class="center">Homepage</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $i => $p): ?>
        <tr>
          <td class="col-check"><input type="checkbox" name="paths[]" value="<?= e($p['path']) ?>" checked></td>
          <td><strong><?= e($p['title']) ?></strong></td>
          <td><?php if ($p['url'] !== ''): ?><a class="cell-code import-src" href="<?= e($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['url']) ?></a><?php else: ?><code class="cell-code"><?= e($p['path']) ?></code><?php endif; ?></td>
          <td class="muted">/<?= e($p['slug'] === 'home' ? 'home' : $p['slug']) ?>/</td>
          <td class="center"><input type="radio" name="home" value="<?= e($p['path']) ?>" aria-label="Make <?= e($p['title']) ?> the homepage"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <?php if ($unused): ?>
  <section class="card" data-import-attach>
    <div class="card-head"><h2>Files no page uses yet</h2></div>
    <div class="card-pad stack-sm">
      <p class="muted small">These stylesheets and scripts were uploaded, but no page asks for them. Attach them to the page<?= count($pages) === 1 ? '' : 's' ?> to apply them (a stylesheet is added to the page head, a script at the end of the page).</p>
      <div class="choice-list">
      <?php foreach ($unused as $f): $isCss = str_ends_with(strtolower($f), '.css'); ?>
        <label class="choice"><input type="checkbox" name="attach[]" value="<?= e($f) ?>" checked> <?= icon($isCss ? 'palette' : 'code', 15) ?> Attach to the page<?= count($pages) === 1 ? '' : 's' ?>: <code class="cell-code"><?= e($f) ?></code></label>
      <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Design</h2>
      <?php if (count($pages) > 1): ?>
      <label class="small import-preview-pick">Preview <select class="input input-sm" data-import-preview-page>
        <?php foreach ($pages as $p): ?><option value="<?= e($p['path']) ?>"><?= e($p['title']) ?></option><?php endforeach; ?>
      </select></label>
      <?php endif; ?>
    </div>
    <div class="card-pad stack-sm">
      <p class="muted small">Choose how the imported pages look. Either way texts, images and repeating parts stay editable, and you can switch later in each page's settings.</p>
      <div class="import-designs">
        <label class="import-design">
          <span class="import-design-head"><input type="radio" name="design" value="keep" checked> <strong>Keep the original design</strong></span>
          <span class="muted small">The page keeps its own CSS exactly. Your site's colours, fonts and global classes stay out, so it looks the same as before.</span>
          <span class="import-thumb"><iframe src="<?= e($prev($first, 'keep')) ?>" title="Preview: original design" sandbox="" loading="lazy" tabindex="-1" data-import-thumb="keep"></iframe></span>
          <a class="small" href="<?= e($prev($first, 'keep')) ?>" target="_blank" rel="noopener" data-import-open="keep"><?= icon('external', 13) ?> Open full size</a>
        </label>
        <label class="import-design">
          <span class="import-design-head"><input type="radio" name="design" value="site"> <strong>Use my site's styles</strong></span>
          <span class="muted small">The page's own CSS and inline colours are removed. Headings, paragraphs, quotes, images, lists, links and buttons follow your design system (Appearance &rsaquo; Styles); button-like links get the global <code>btn</code> class. A light base layout keeps sections, containers, grids and rows readable.</span>
          <span class="import-thumb"><iframe src="<?= e($prev($first, 'site')) ?>" title="Preview: your site's styles" sandbox="" loading="lazy" tabindex="-1" data-import-thumb="site"></iframe></span>
          <a class="small" href="<?= e($prev($first, 'site')) ?>" target="_blank" rel="noopener" data-import-open="site"><?= icon('external', 13) ?> Open full size</a>
        </label>
      </div>
      <small class="help">Previews show the page as it will look, without its scripts and before links between pages are updated.</small>
    </div>
  </section>

  <section class="card">
    <div class="card-pad stack-sm">
      <label class="field"><span>Import as</span><select class="input" name="type" style="max-width:260px">
        <?php foreach (ContentModel::types() as $slug => $t): if (empty($t['public']) || !in_array('editor', (array)($t['supports'] ?? []), true)) continue; ?>
          <option value="<?= e($slug) ?>"<?= $slug === 'page' ? ' selected' : '' ?>><?= e($t['plural']) ?></option>
        <?php endforeach; ?></select></label>
      <label class="field"><span>Status</span><select class="input" name="status" style="max-width:260px">
        <option value="draft">Drafts (review first)</option>
        <?php if (\Slate\Auth::can('publish_posts')): ?><option value="publish">Published right away</option><?php endif; ?>
      </select></label>
      <label class="small"><input type="radio" name="home" value="" checked> Keep my current homepage <span class="muted">(pick a page in the Homepage column above to replace it)</span></label>
    </div>
    <div class="card-foot"><a class="btn btn-ghost" href="<?= e(admin_url('import')) ?>">Start again</a><button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Import pages</button></div>
  </section>
</form>

<?php else: $ai = \Slate\Ai::enabled() && \Slate\Auth::can('use_ai'); $fromUrl = ($meta['source'] ?? '') === 'url'; ?>
<div class="card">
  <div class="card-head"><h2>Imported <?= count($result) ?> page<?= count($result) === 1 ? '' : 's' ?></h2><a class="btn btn-sm" href="<?= e(admin_url('content/page')) ?>">All pages</a></div>
  <?php if ($fromUrl): $nFail = count((array)($meta['failed'] ?? [])); ?>
  <div class="import-summary">
    <?= AdminUi::kpis(['Pages' => count($result), 'Files fetched' => (int)($meta['fetched'] ?? 0), 'Could not fetch' => $nFail, 'Downloaded' => $mb((int)($meta['bytes'] ?? 0))]) ?>
  </div>
  <?php endif; ?>
  <div class="import-report">
    <?php foreach ($result as $p): $r = $p['report'] ?? ['editable' => 0, 'loops' => 0, 'issues' => []]; $warn = array_filter($r['issues'], static fn($i) => $i[0] === 'warn'); $mode = $p['design'] ?? 'keep'; ?>
    <section class="import-page" data-import-result data-design="<?= e($mode) ?>">
      <div class="import-page-head">
        <span class="list-icon"><?= icon('file', 15) ?></span>
        <div><strong><?= e($p['title']) ?></strong><small class="muted"><?= e($p['path']) ?> · <?= (int)$r['editable'] ?> editable parts<?= $r['loops'] ? ' · ' . (int)$r['loops'] . ' repeating group' . ($r['loops'] === 1 ? '' : 's') : '' ?><?= ($p['source'] ?? '') !== '' ? ' · ' . (int)$p['fetched'] . ' file' . ((int)$p['fetched'] === 1 ? '' : 's') . ' fetched' : '' ?></small>
          <?php if (($p['source'] ?? '') !== ''): ?><small class="import-src-line"><?= icon('link', 12) ?> <a href="<?= e($p['source']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['source']) ?></a></small><?php endif; ?>
        </div>
        <span class="pill pill-soft" title="Change it in the page's settings"><?= icon($mode === 'site' ? 'palette' : 'layers', 12) ?> <?= e(HtmlPages::DESIGNS[$mode]) ?></span>
        <span class="pill <?= $warn ? 'pill-warn' : 'pill-success' ?>"><?= $warn ? count($warn) . ' to check' : 'Looks good' ?></span>
        <span class="page-actions">
          <a class="btn btn-sm btn-primary" href="<?= e($p['url']) ?>?preview=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><?= icon('edit', 14) ?> Open and edit</a>
          <?php if ($ai): ?><button class="btn btn-sm" type="button" data-goraiya-ask="<?= e('Review the imported page #' . $p['id'] . ' "' . $p['title'] . '" for migration problems and improve it: ' . implode(' ', array_map(static fn($i) => $i[1], $r['issues'])) . ' Add missing alt texts, make repeating parts that were not detected into lists, fix anything that looks broken, and tell me what you changed. Keep the design.') ?>"><?= icon('sparrow', 14) ?> Improve with Goraiya</button><?php endif; ?>
          <a class="btn btn-sm btn-ghost" href="<?= e($p['edit']) ?>">Settings & SEO</a>
        </span>
      </div>
      <?php if ($r['issues']): ?>
      <ul class="import-issues">
        <?php foreach ($r['issues'] as [$level, $msg, $detail]): ?>
          <li class="is-<?= e($level) ?>"><?= icon($level === 'warn' ? 'alert' : 'info', 14) ?><div><?= e($msg) ?><?php if ($detail): ?><small class="muted"><?= e(implode(', ', $detail)) ?></small><?php endif; ?></div></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<p class="muted small">Importing and editing are built in and never need AI. The design can be switched per page in its settings (Design). <?= $ai ? 'Goraiya can additionally review a page and improve it; every change is kept as a revision.' : 'Connect an AI in the top bar (Goraiya) if you want a page reviewed and improved automatically.' ?></p>
<?php endif; ?>
