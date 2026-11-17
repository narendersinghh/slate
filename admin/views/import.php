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

<?php else: ?>
<div class="card">
  <div class="card-head"><h2>Imported <?= count($result) ?> page<?= count($result) === 1 ? '' : 's' ?></h2><a class="btn btn-sm" href="<?= e(admin_url('content/page')) ?>">All pages</a></div>
  <ul class="list">
    <?php foreach ($result as $p): ?>
      <li><span class="list-main"><span class="list-icon"><?= icon('file', 15) ?></span><span><strong><?= e($p['title']) ?></strong><small><?= e($p['path']) ?></small></span></span>
        <span class="page-actions"><a class="btn btn-sm btn-primary" href="<?= e($p['url']) ?>?preview=<?= (int)$p['id'] ?>" target="_blank" rel="noopener"><?= icon('edit', 14) ?> Open and edit</a><a class="btn btn-sm btn-ghost" href="<?= e($p['edit']) ?>">Settings & SEO</a></span></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
