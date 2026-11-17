<?php
/**
 * Modules: installed · available (registries) · add new (upload, URL, blank, AI) · sources · code check · AI draft review.
 * @var string $tab
 * @var array  $modules
 * @var array  $errors
 * @var string $locked
 */
use Slate\Modules;

$tabs = ['installed' => ['Installed', 'modules', 'puzzle'], 'available' => ['Available', 'modules/available', 'download'],
    'add' => ['Add new', 'modules/add', 'plus'], 'sources' => ['Sources', 'modules/sources', 'link']];
$activeTab = in_array($tab, ['check', 'draft'], true) ? '' : $tab;
$updates ??= [];
$riskPill = ['high' => 'pill-danger', 'medium' => 'pill-warn', 'info' => 'pill-soft'];
$riskWord = ['high' => 'Review carefully', 'medium' => 'Common', 'info' => 'Normal'];

$renderScan = static function (array $scan) use ($riskPill, $riskWord): void { ?>
  <?php if ($scan['errors']): ?>
    <div class="banner banner-danger"><span class="ic"><?= icon('alert', 18) ?></span><div><strong>Syntax errors, this code cannot run.</strong>
      <ul class="scan-list"><?php foreach (array_slice($scan['errors'], 0, 8) as $er): ?><li><code><?= e($er) ?></code></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>
  <?php if (!$scan['findings']): ?>
    <p class="muted"><?= icon('check', 16) ?> Nothing notable: the code only uses Slate's normal hooks and helpers (<?= (int)$scan['files'] ?> PHP files checked).</p>
  <?php else: ?>
    <p class="muted"><?= (int)$scan['files'] ?> PHP files checked. This module's code can:</p>
    <ul class="scan-list">
      <?php foreach ($scan['findings'] as $f): ?>
        <li><span class="pill <?= $riskPill[$f['risk']] ?? 'pill-soft' ?>"><?= e($riskWord[$f['risk']] ?? $f['risk']) ?></span>
          <strong><?= e($f['label']) ?></strong> <small class="muted"><?= e(implode(', ', $f['where'])) ?></small></li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">Modules run with full access to your site, like WordPress plugins. Only activate code from people you trust,
      and look closely at anything marked “Review carefully”.</p>
  <?php endif; ?>
<?php };
?>
<?php
$more = static function (array $links): string {
    $links = array_filter($links);
    if (!$links) {
        return '';
    }
    $h = '<div class="dropdown"><button type="button" class="icon-btn mod-more" data-dropdown aria-label="More actions" title="More">' . icon('more', 16) . '</button><div class="dropdown-menu dropdown-right">';
    foreach ($links as [$label, $url, $ic, $ext]) {
        $h .= '<a href="' . e($url) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . icon($ic, 15) . ' ' . e($label) . '</a>';
    }
    return $h . '</div></div>';
};
echo \Slate\AdminUi::header('Modules', 'Add features to your site from a registry, a zip, GitHub, or write your own, with or without AI.', 'puzzle', [
    'Add module' => [admin_url('modules/add'), 'primary', 'plus'],
    'Check for updates' => [admin_url('modules/available?refresh=1'), '', 'refresh'],
]);
?>
<nav class="tabs-line mod-tabs" aria-label="Modules">
  <?php foreach ($tabs as $k => [$label, $path, $ic]): ?>
    <a href="<?= e(admin_url($path)) ?>" class="<?= $k === $activeTab ? 'active' : '' ?>"<?= $k === $activeTab ? ' aria-current="page"' : '' ?>><?= e($label) ?>
      <?php if ($k === 'installed' && $updates): ?><span class="pill pill-accent"><?= count($updates) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if (Modules::safeMode()): ?>
  <div class="banner banner-danger"><span class="ic"><?= icon('shield', 18) ?></span><div><strong>Safe mode is on, no module is running.</strong>
    Delete the file <code>content/safe-mode</code> to load modules again.</div></div>
<?php endif; ?>
<?php if ($locked !== '' && in_array($tab, ['add', 'available'], true)): ?>
  <div class="banner"><span class="ic"><?= icon('shield', 18) ?></span><div><?= e($locked) ?></div></div>
<?php endif; ?>

<?php /* ================================================================ INSTALLED */ if ($tab === 'installed'): ?>
  <?php if (!$modules): ?>
    <div class="card empty-state"><?= icon('puzzle', 36) ?><h2>No modules installed</h2>
      <p class="muted">Browse available modules or add your own.</p>
      <a class="btn btn-primary" href="<?= e(admin_url('modules/add')) ?>"><?= icon('plus', 16) ?> Add a module</a></div>
  <?php else: ?>
  <?php
    $groups = Modules::adminGroups();
    $counts = ['all' => count($modules), 'active' => count(array_filter($modules, static fn($m) => $m['active'])), 'update' => count($updates)];
    $counts['inactive'] = $counts['all'] - $counts['active'];
  ?>
  <div class="mod-toolbar">
    <div class="mod-seg" role="group" aria-label="Show" data-module-view>
      <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'update' => 'Updates'] as $v => $l): if ($v === 'update' && !$counts['update']) continue; ?>
        <button type="button" class="<?= $v === 'all' ? 'active' : '' ?>" data-view="<?= $v ?>"><?= $l ?> <span><?= $counts[$v] ?></span></button>
      <?php endforeach; ?>
    </div>
    <label class="mod-search"><?= icon('search', 15) ?><input class="input" type="search" placeholder="Search installed modules" data-module-filter aria-label="Search modules"></label>
  </div>
  <div class="mod-grid">
    <?php foreach ($modules as $m): $slug = $m['slug']; $up = $updates[$slug] ?? null; $deps = $m['depends']; $err = $errors[$slug] ?? null;
        $g = $groups[$slug] ?? null;
        $open = $m['active'] && $g && $g['pages'] ? admin_url('m/' . $g['pages'][0]['slug']) : '';
        $settingsUrl = '';
        if ($m['active']) {
            if (isset(Modules::settingsSections()[$slug])) $settingsUrl = \Slate\Settings::url('modules', $slug);
            elseif ($g && $g['settings']) $settingsUrl = isset($g['settings']['url']) ? admin_url($g['settings']['url']) : admin_url('m/' . $g['settings']['slug']);
            elseif ($m['settings'] !== '' && isset(Modules::adminPages()[$m['settings']])) $settingsUrl = admin_url('m/' . $m['settings']);
        } ?>
    <article class="mod-card<?= $m['active'] ? ' is-active' : '' ?><?= $err ? ' has-error' : '' ?>"
         data-module-card="<?= e(strtolower($m['name'] . ' ' . $m['description'] . ' ' . implode(' ', $m['tags']))) ?>" data-state="<?= $m['active'] ? 'active' : 'inactive' ?><?= $up ? ' update' : '' ?>">
      <div class="mod-card-head">
        <span class="mod-icon"><?= icon($m['icon'] ?: 'puzzle', 19) ?></span>
        <div class="mod-title"><strong><?= e($m['name']) ?></strong><small class="muted">v<?= e($m['version']) ?><?= $m['author'] ? ' · ' . e($m['author']) : '' ?></small></div>
        <form method="post" action="<?= e(admin_url("modules/$slug/toggle")) ?>"><?= csrf_field() ?>
          <button type="submit" class="mod-switch" role="switch" aria-checked="<?= $m['active'] ? 'true' : 'false' ?>" title="<?= $m['active'] ? 'Active. Click to switch off' : 'Off. Click to activate' ?>" aria-label="<?= e(($m['active'] ? 'Deactivate ' : 'Activate ') . $m['name']) ?>"><span></span></button></form>
      </div>
      <p class="mod-desc muted"><?= e($m['description']) ?></p>
      <?php if ($err): ?>
        <div class="mod-alert is-danger"><?= icon('alert', 15) ?><div><strong>Switched off after a crash</strong> <span class="muted">(<?= e(time_ago($err['time'] ?? null)) ?>)</span><code><?= e($err['message']) ?></code>
          <form method="post" action="<?= e(admin_url("modules/$slug/dismiss")) ?>"><?= csrf_field() ?><button class="link-btn">Dismiss</button></form></div></div>
      <?php elseif ($up): ?>
        <div class="mod-alert"><?= icon('download', 15) ?><div>Version <?= e($up['version']) ?> is available.</div>
          <form method="post" action="<?= e(admin_url("modules/$slug/update")) ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-primary" type="submit" data-confirm="Update <?= e($m['name']) ?> to <?= e($up['version']) ?>? The current version is backed up first.">Update</button></form></div>
      <?php endif; ?>
      <?php if ($deps): ?><div class="mod-deps"><small class="muted">Needs</small><?php foreach ($deps as $d => $c): ?><span class="pill pill-soft"><?= e(($modules[$d]['name'] ?? $d) . ($c !== '*' ? " $c" : '')) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="mod-card-foot">
        <?php if ($open): ?><a class="btn btn-sm" href="<?= e($open) ?>"><?= icon('external', 14) ?> Open</a><?php endif; ?>
        <?php if ($settingsUrl): ?><a class="btn btn-sm btn-ghost" href="<?= e($settingsUrl) ?>"><?= icon('settings', 14) ?> Settings</a><?php endif; ?>
        <?php if (!$open && !$settingsUrl): ?><a class="btn btn-sm btn-ghost" href="<?= e(admin_url("modules/$slug/check")) ?>"><?= icon('info', 14) ?> Details</a><?php endif; ?>
        <span class="mod-spacer"></span>
        <?= $more([
            ($open || $settingsUrl) ? ['Details and code check', admin_url("modules/$slug/check"), 'shield', false] : null,
            ['Download as .zip', admin_url("modules/$slug/download"), 'download', false],
            $m['homepage'] !== '' ? ['Website', $m['homepage'], 'globe', true] : null,
        ]) ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="muted small mod-none" hidden data-module-none>No module matches.</p>
  <?php endif; ?>

<?php /* ================================================================ AVAILABLE */ elseif ($tab === 'available'): ?>
  <?php foreach ($registryErrors as $url => $er): ?>
    <div class="banner"><span class="ic"><?= icon('alert', 18) ?></span><div>Could not read registry <code><?= e($url) ?></code>: <?= e($er) ?>.
      <small class="muted">Check the address under Sources.</small></div></div>
  <?php endforeach; ?>
  <?php if (!$available): ?>
    <div class="card empty-state"><?= icon('download', 36) ?><h2>No modules listed</h2>
      <p class="muted">Add a registry under <a href="<?= e(admin_url('modules/sources')) ?>">Sources</a>, or install a module from a zip or GitHub address.</p></div>
  <?php else: ?>
  <div class="mod-toolbar">
    <label class="mod-search"><?= icon('search', 15) ?><input class="input" type="search" placeholder="Search modules" data-module-filter aria-label="Search modules"></label>
    <small class="muted"><?= count($available) ?> modules</small>
  </div>
  <div class="mod-grid">
    <?php foreach ($available as $a): $inst = $a['installed']; $canUpdate = $inst !== null && version_compare($a['version'], $inst, '>'); $tooOld = version_compare(SLATE_VERSION, $a['requires'], '<');
        $ic = $modules[$a['slug']]['icon'] ?? ($a['icon'] ?? ''); ?>
    <article class="mod-card<?= $inst !== null ? ' is-active' : '' ?>" data-module-card="<?= e(strtolower($a['name'] . ' ' . $a['description'] . ' ' . implode(' ', $a['tags']))) ?>">
      <div class="mod-card-head">
        <span class="mod-icon"><?= icon($ic ?: 'box', 19) ?></span>
        <div class="mod-title"><strong><?= e($a['name']) ?></strong><small class="muted">v<?= e($a['version']) ?><?= $a['author'] ? ' · ' . e($a['author']) : '' ?></small></div>
        <?php if ($inst !== null && !$canUpdate): ?><span class="pill pill-success"><?= icon('check', 12) ?> Installed</span><?php endif; ?>
      </div>
      <p class="mod-desc muted"><?= e($a['description']) ?></p>
      <?php if ($a['tags']): ?><div class="mod-tags"><?php foreach ($a['tags'] as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
      <?php if ($tooOld): ?><div class="mod-alert is-warn"><?= icon('alert', 15) ?><div>Needs Slate <?= e($a['requires']) ?> or newer.</div></div><?php endif; ?>
      <div class="mod-card-foot">
        <?php if ($inst === null): ?>
          <form method="post" action="<?= e(admin_url('modules/install/' . $a['slug'])) ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"<?= $locked !== '' || $tooOld ? ' disabled' : '' ?>><?= icon('download', 14) ?> Install</button></form>
        <?php elseif ($canUpdate): ?>
          <form method="post" action="<?= e(admin_url('modules/' . $a['slug'] . '/update')) ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-primary"<?= $locked !== '' ? ' disabled' : '' ?>><?= icon('download', 14) ?> Update to <?= e($a['version']) ?></button></form>
        <?php else: ?>
          <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('modules/' . $a['slug'] . '/check')) ?>"><?= icon('info', 14) ?> Details</a>
        <?php endif; ?>
        <span class="mod-spacer"></span>
        <small class="muted mod-src" title="Registry"><?= e($a['registry']) ?></small>
        <?php if ($a['homepage']): ?><a class="icon-btn" href="<?= e($a['homepage']) ?>" target="_blank" rel="noopener noreferrer" title="Website" aria-label="Website of <?= e($a['name']) ?>"><?= icon('globe', 15) ?></a><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="muted small mod-none" hidden data-module-none>No module matches.</p>
  <?php endif; ?>

<?php /* ================================================================ ADD NEW */ elseif ($tab === 'add'): $off = $locked !== '' ? ' disabled' : ''; ?>
  <div class="card-grid module-add">
    <form class="card" method="post" action="<?= e(admin_url('modules/upload')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="card-head"><h2><?= icon('upload', 18) ?> Upload a zip</h2></div>
      <div class="card-pad stack-sm">
        <p class="muted">A .zip with <code>module.json</code> and <code>module.php</code> (at the top or inside one folder).</p>
        <input class="input" type="file" name="module" accept=".zip,application/zip" required<?= $off ?>>
        <label class="check"><input type="checkbox" name="replace" value="1"> Replace the installed version if it exists (backed up first)</label>
        <div><button class="btn btn-primary"<?= $off ?>>Upload &amp; check</button></div>
      </div>
    </form>

    <form class="card" method="post" action="<?= e(admin_url('modules/install-url')) ?>">
      <?= csrf_field() ?>
      <div class="card-head"><h2><?= icon('link', 18) ?> From GitHub or a URL</h2></div>
      <div class="card-pad stack-sm">
        <p class="muted">A repository (<code>https://github.com/you/repo</code>), one folder of it
          (<code>…/tree/main/my-module</code>) or a direct .zip link. Private repositories need a token under Sources.</p>
        <input class="input" type="url" name="url" placeholder="https://github.com/…" required<?= $off ?>>
        <details><summary class="muted">Checksum (optional)</summary>
          <input class="input" name="sha256" placeholder="sha256 of the zip" pattern="[A-Fa-f0-9]{64}"></details>
        <label class="check"><input type="checkbox" name="replace" value="1"> Replace the installed version if it exists</label>
        <div><button class="btn btn-primary"<?= $off ?>>Download &amp; check</button></div>
      </div>
    </form>

    <form class="card" method="post" action="<?= e(admin_url('modules/ai')) ?>" data-busy="Writing the module… this can take a minute.">
      <?= csrf_field() ?>
      <div class="card-head"><h2><?= icon('sparkles', 18) ?> Create with AI</h2></div>
      <div class="card-pad stack-sm">
        <?php if (!$aiEnabled): ?>
          <p class="muted">Turn on the AI assistant under <a href="<?= e(admin_url('settings/ai/provider')) ?>">Settings › AI</a> to describe a module and have it written for you.</p>
        <?php else: ?>
          <p class="muted">Describe what you need. You review the code and what it can do before anything is installed.</p>
        <?php endif; ?>
        <textarea class="input" name="request" rows="5" required minlength="15"<?= $off ?: (!$aiEnabled ? ' disabled' : '') ?>
          placeholder="e.g. A simple event booking system: an Events content type with date, venue and seats, a booking form on each event page, an admin list of bookings with CSV export, and an email to the organiser."></textarea>
        <div><button class="btn btn-primary"<?= $off ?: (!$aiEnabled ? ' disabled' : '') ?>><?= icon('sparkles', 15) ?> Write module</button></div>
      </div>
    </form>

    <form class="card" method="post" action="<?= e(admin_url('modules/create')) ?>">
      <?= csrf_field() ?>
      <div class="card-head"><h2><?= icon('code', 18) ?> Start a blank module</h2></div>
      <div class="card-pad stack-sm">
        <p class="muted">Creates <code>modules/&lt;name&gt;/</code> with a manifest, entry file and folders, ready to edit. See <code>docs/modules.md</code>.</p>
        <label class="field"><span>Name</span><input class="input" name="name" required maxlength="60" placeholder="My feature"<?= $off ?>></label>
        <label class="field"><span>Description</span><input class="input" name="description" maxlength="200"<?= $off ?>></label>
        <label class="check"><input type="checkbox" name="admin_page" value="1" checked> Include a settings page</label>
        <div><button class="btn btn-primary"<?= $off ?>>Create module</button></div>
      </div>
    </form>
  </div>
  <script>
    document.querySelectorAll('form[data-busy]').forEach((f) => f.addEventListener('submit', () => {
      const b = f.querySelector('button'); if (b) { b.disabled = true; b.textContent = f.dataset.busy; }
    }));
  </script>

<?php /* ================================================================ SOURCES */ elseif ($tab === 'sources'): ?>
  <form class="card" method="post" action="<?= e(admin_url('modules/sources')) ?>">
    <?= csrf_field() ?>
    <div class="card-head"><h2><?= icon('link', 18) ?> Module sources</h2></div>
    <div class="card-pad stack">
      <label class="field"><span>Registries <small class="muted">one URL per line</small></span>
        <textarea class="input code" name="registries" rows="4" spellcheck="false"><?= e(implode("\n", $registries)) ?></textarea>
        <small class="help">A registry is a JSON file listing modules anyone can publish, your own, your agency's, the community's.
          Format: <code>{"name": "…", "modules": [{"slug", "name", "description", "version", "requires", "download", "sha256"}]}</code>. See <code>docs/modules.md</code>.</small>
      </label>
      <label class="field"><span>GitHub token <small class="muted"><?= $hasToken ? 'saved, enter a new one to replace it' : 'optional' ?></small></span>
        <input class="input" type="password" name="token" autocomplete="off" placeholder="<?= $hasToken ? '••••••••' : 'github_pat_…' ?>">
        <small class="help">For modules in private repositories. Stored encrypted and only ever sent to GitHub. A fine-grained token with read-only “Contents” access is enough.</small>
        <?php if ($hasToken): ?><label class="check"><input type="checkbox" name="token_clear" value="1"> Remove the saved token</label><?php endif; ?>
      </label>
      <div><button class="btn btn-primary">Save sources</button></div>
    </div>
  </form>

<?php /* ================================================================ CHECK / DETAILS */ elseif ($tab === 'check'): $m = $module; $slug = $m['slug']; ?>
  <div class="card">
    <div class="card-head">
      <h2><?= icon('puzzle', 18) ?> <?= e($m['name']) ?> <small class="muted">v<?= e($m['version']) ?></small></h2>
      <div class="row-between">
      <a class="btn" href="<?= e(admin_url("modules/$slug/download")) ?>"><?= icon('download', 15) ?> Download .zip</a>
      <form method="post" action="<?= e(admin_url("modules/$slug/toggle")) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="check">
        <button class="btn <?= $m['active'] ? '' : 'btn-primary' ?>"<?= $scan['errors'] && !$m['active'] ? ' disabled' : '' ?>><?= $m['active'] ? 'Deactivate' : 'Activate' ?></button></form>
      </div>
    </div>
    <div class="card-pad stack-sm">
      <p><?= e($m['description']) ?></p>
      <dl class="module-meta">
        <dt>Folder</dt><dd><code>modules/<?= e($slug) ?>/</code></dd>
        <dt>Author</dt><dd><?= e($m['author'] ?: '-') ?></dd>
        <dt>Requires</dt><dd>Slate <?= e($m['requires']) ?><?= $m['requires_php'] ? ', PHP ' . e($m['requires_php']) : '' ?></dd>
        <?php if ($m['depends']): ?><dt>Needs</dt><dd><?= e(implode(', ', array_map(static fn($d, $c) => $c === '*' ? $d : "$d $c", array_keys($m['depends']), $m['depends']))) ?></dd><?php endif; ?>
        <?php if ($dependents): ?><dt>Needed by</dt><dd><?= e(implode(', ', $dependents)) ?></dd><?php endif; ?>
        <?php if ($m['source']): ?><dt>Updates from</dt><dd><code><?= e($m['source']) ?></code></dd><?php endif; ?>
        <dt>Status</dt><dd><?= $m['active'] ? '<span class="text-success">Active</span>' : 'Inactive' ?></dd>
      </dl>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= icon('shield', 18) ?> Code check</h2></div>
    <div class="card-pad"><?php $renderScan($scan); ?></div>
  </div>
  <?php if ($backups): ?>
  <div class="card">
    <div class="card-head"><h2><?= icon('refresh', 18) ?> Previous versions</h2></div>
    <ul class="list">
      <?php foreach ($backups as $b): ?>
        <li><span class="list-main"><span><strong>v<?= e($b['version']) ?></strong><small><?= e(local_date(gmdate('Y-m-d H:i:s', $b['time']))) ?></small></span></span>
          <form method="post" action="<?= e(admin_url("modules/$slug/restore")) ?>"><?= csrf_field() ?><input type="hidden" name="file" value="<?= e($b['file']) ?>">
            <button class="btn btn-sm" data-confirm="Put v<?= e($b['version']) ?> back in place of the current files?"<?= $locked !== '' ? ' disabled' : '' ?>>Restore</button></form></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if (!$m['active']): ?>
  <form class="card" method="post" action="<?= e(admin_url("modules/$slug/delete")) ?>">
    <?= csrf_field() ?>
    <div class="card-head"><h2><?= icon('trash', 18) ?> Delete</h2></div>
    <div class="card-pad stack-sm">
      <p class="muted">Removes <code>modules/<?= e($slug) ?>/</code>. A backup zip is kept in <code>content/backups/modules/</code>.</p>
      <?php if (is_file(SLATE_MODULES . "/$slug/uninstall.php")): ?>
        <label class="check"><input type="checkbox" name="purge" value="1"> Also delete its data (runs the module's uninstall script, cannot be undone)</label>
      <?php endif; ?>
      <div><button class="btn btn-danger" data-confirm="Delete <?= e($m['name']) ?>?"<?= $locked !== '' ? ' disabled' : '' ?>>Delete module</button></div>
    </div>
  </form>
  <?php endif; ?>

<?php /* ================================================================ AI DRAFT */ elseif ($tab === 'draft'): ?>
  <div class="card">
    <div class="card-head"><h2><?= icon('sparkles', 18) ?> <?= e($draft['name']) ?> <small class="muted">AI draft · not installed</small></h2>
      <div class="row-between">
        <form method="post" action="<?= e(admin_url("modules/draft/$draftId/discard")) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Discard</button></form>
        <form method="post" action="<?= e(admin_url("modules/draft/$draftId/install")) ?>"><?= csrf_field() ?>
          <button class="btn btn-primary"<?= $scan['errors'] || $locked !== '' ? ' disabled' : '' ?>>Install (inactive)</button></form>
      </div>
    </div>
    <div class="card-pad stack-sm">
      <p><strong>You asked:</strong> <?= e($draft['request'] ?? '') ?></p>
      <?php if (!empty($draft['notes'])): ?><p class="muted"><?= nl2br(e($draft['notes'])) ?></p><?php endif; ?>
      <p class="muted small">Folder: <code>modules/<?= e($draft['slug']) ?>/</code> · Review the code below. After installing you can still read it under Details before activating.</p>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= icon('shield', 18) ?> Code check</h2></div>
    <div class="card-pad"><?php $renderScan($scan); ?></div>
  </div>
  <?php foreach ($draft['files'] as $path => $code): ?>
    <details class="card module-file"<?= $path === 'module.php' ? ' open' : '' ?>>
      <summary class="card-head"><h2><?= icon('code', 16) ?> <?= e($path) ?></h2><small class="muted"><?= substr_count($code, "\n") + 1 ?> lines</small></summary>
      <pre><code><?= e($code) ?></code></pre>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
