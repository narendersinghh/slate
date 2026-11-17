<?php
/** @var string $step upload|review|done */
use Slate\AdminUi;
use Slate\ContentModel;

echo AdminUi::header('Import pages', 'Bring in pages made elsewhere (HTML, CSS, JS, images, fonts). Each becomes a Slate page with exactly the same design, and you can edit it by clicking on the page.', 'upload');
echo AdminUi::steps(['upload' => 'Upload', 'review' => 'Choose pages', 'done' => 'Done'], $step);
?>
<?php if ($step === 'upload'): ?>
<form class="card" method="post" action="<?= e(admin_url('import/upload')) ?>" enctype="multipart/form-data" data-import-form>
  <?= csrf_field() ?>
  <div class="card-pad stack">
    <label class="import-drop" data-import-drop>
      <span class="import-drop-icon"><?= icon('upload', 26) ?></span>
      <strong>Drop a .zip of your site, a folder, or the files here</strong>
      <span class="muted small">HTML pages with their CSS, JavaScript, images and fonts. Up to 3,000 files.</span>
      <input type="file" name="files[]" multiple accept=".zip,.html,.htm,.css,.js,.mjs,.json,.png,.jpg,.jpeg,.gif,.webp,.avif,.svg,.ico,.woff,.woff2,.ttf,.otf,.eot,.mp4,.webm,.pdf" data-import-files>
    </label>
    <div class="row-between">
      <label class="btn btn-ghost btn-sm"><input type="file" webkitdirectory multiple hidden data-import-folder> <?= icon('folder', 15) ?> Choose a folder instead</label>
      <span class="muted small" data-import-picked></span>
    </div>
    <div class="alert" data-import-msg hidden></div>
    <div><button class="btn btn-primary" type="submit" data-import-go><?= icon('upload', 16) ?> Upload and find pages</button></div>
  </div>
</form>
<div class="card card-pad muted small stack-sm">
  <strong class="text-2">How it works</strong>
  <span>1. Links between your pages are pointed at their new Slate addresses; images, styles and scripts keep working.</span>
  <span>2. Texts, images, icons and repeating parts (sliders, card lists) become editable: open the page and click what you want to change.</span>
  <span>3. Goraiya (✦ in the top bar) can then connect lists to your content, so new posts appear automatically.</span>
</div>

<?php elseif ($step === 'review'): ?>
<form class="card" method="post" action="<?= e(admin_url('import/' . $folder)) ?>">
  <?= csrf_field() ?>
  <div class="card-head"><h2><?= count($pages) ?> page<?= count($pages) === 1 ? '' : 's' ?> found</h2><label class="small"><input type="checkbox" checked data-check-all-import> Select all</label></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th class="col-check"></th><th>Page</th><th>File</th><th>New address</th><th class="center">Homepage</th></tr></thead>
    <tbody>
    <?php foreach ($pages as $i => $p): ?>
      <tr>
        <td class="col-check"><input type="checkbox" name="paths[]" value="<?= e($p['path']) ?>" checked></td>
        <td><strong><?= e($p['title']) ?></strong></td>
        <td><code class="cell-code"><?= e($p['path']) ?></code></td>
        <td class="muted">/<?= e($p['slug'] === 'home' ? 'home' : $p['slug']) ?>/</td>
        <td class="center"><input type="radio" name="home" value="<?= e($p['path']) ?>"<?= $i === 0 && $p['slug'] === 'home' ? ' checked' : '' ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-pad stack-sm">
    <label class="field"><span>Import as</span><select class="input" name="type" style="max-width:260px">
      <?php foreach (ContentModel::types() as $slug => $t): if (empty($t['public']) || !in_array('editor', (array)($t['supports'] ?? []), true)) continue; ?>
        <option value="<?= e($slug) ?>"<?= $slug === 'page' ? ' selected' : '' ?>><?= e($t['plural']) ?></option>
      <?php endforeach; ?></select></label>
    <label class="field"><span>Status</span><select class="input" name="status" style="max-width:260px">
      <option value="draft">Drafts (review first)</option>
      <?php if (\Slate\Auth::can('publish_posts')): ?><option value="publish">Published right away</option><?php endif; ?>
    </select></label>
    <label class="small"><input type="radio" name="home" value=""> Keep my current homepage</label>
  </div>
  <div class="card-foot"><a class="btn btn-ghost" href="<?= e(admin_url('import')) ?>">Start again</a><button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Import pages</button></div>
</form>

<?php else: $ai = \Slate\Ai::enabled() && \Slate\Auth::can('use_ai'); ?>
<div class="card">
  <div class="card-head"><h2>Imported <?= count($result) ?> page<?= count($result) === 1 ? '' : 's' ?></h2><a class="btn btn-sm" href="<?= e(admin_url('content/page')) ?>">All pages</a></div>
  <div class="import-report">
    <?php foreach ($result as $p): $r = $p['report'] ?? ['editable' => 0, 'loops' => 0, 'issues' => []]; $warn = array_filter($r['issues'], static fn($i) => $i[0] === 'warn'); ?>
    <section class="import-page">
      <div class="import-page-head">
        <span class="list-icon"><?= icon('file', 15) ?></span>
        <div><strong><?= e($p['title']) ?></strong><small class="muted"><?= e($p['path']) ?> · <?= (int)$r['editable'] ?> editable parts<?= $r['loops'] ? ' · ' . (int)$r['loops'] . ' repeating group' . ($r['loops'] === 1 ? '' : 's') : '' ?></small></div>
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
<p class="muted small">Importing and editing are built in and never need AI. <?= $ai ? 'Goraiya can additionally review a page and improve it; every change is kept as a revision.' : 'Connect an AI in the top bar (Goraiya) if you want a page reviewed and improved automatically.' ?></p>
<?php endif; ?>
