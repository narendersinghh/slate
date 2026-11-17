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
<div class="page-head">
  <div><h1>Modules</h1><p class="muted">Add features to your site. Install from a registry, a zip, GitHub, or write your own, with or without AI.</p></div>
  <a class="btn" href="<?= e(admin_url('modules/available?refresh=1')) ?>"><?= icon('refresh', 16) ?> Check for updates</a>
</div>

<nav class="tabs-line" aria-label="Modules">
  <?php foreach ($tabs as $k => [$label, $path, $ic]): ?>
    <a href="<?= e(admin_url($path)) ?>" class="<?= $k === $activeTab ? 'active' : '' ?>"><?= icon($ic, 16) ?> <?= e($label) ?>
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
  <?php foreach ($errors as $slug => $er): ?>
    <div class="banner banner-danger"><span class="ic"><?= icon('alert', 18) ?></span>
      <div><strong><?= e($modules[$slug]['name'] ?? $slug) ?> was switched off automatically</strong> because it crashed
        (<?= e(time_ago($er['time'] ?? null)) ?>): <code><?= e($er['message']) ?></code><?= !empty($er['file']) ? ' <small class="muted">in ' . e($er['file']) . ':' . (int)$er['line'] . '</small>' : '' ?>
        <br><small class="muted">Your site kept working. Fix or update the module, then activate it again.</small></div>
      <form method="post" action="<?= e(admin_url("modules/$slug/dismiss")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-ghost">Dismiss</button></form>
    </div>
  <?php endforeach; ?>

  <?php if (!$modules): ?>
    <div class="card empty-state"><?= icon('puzzle', 36) ?><h2>No modules installed</h2>
      <p class="muted">Browse available modules or add your own.</p>
      <a class="btn btn-primary" href="<?= e(admin_url('modules/add')) ?>"><?= icon('plus', 16) ?> Add a module</a></div>
  <?php else: ?>
  <?php
    $groups = Modules::adminGroups();
    $counts = ['all' => count($modules), 'active' => count(array_filter($modules, static fn($m) => $m['active'])), 'updates' => count($updates)];
    $counts['inactive'] = $counts['all'] - $counts['active'];
  ?>
  <div class="lt-top module-filter">
    <nav class="tabs-pill" aria-label="Show" data-module-view>
      <button type="button" class="active" data-view="all">All <span><?= $counts['all'] ?></span></button>
      <button type="button" data-view="active">Active <span><?= $counts['active'] ?></span></button>
      <button type="button" data-view="inactive">Inactive <span><?= $counts['inactive'] ?></span></button>
      <?php if ($counts['updates']): ?><button type="button" data-view="update">Updates <span><?= $counts['updates'] ?></span></button><?php endif; ?>
    </nav>
    <label class="lt-search"><?= icon('search', 15) ?><input class="input" type="search" placeholder="Search installed modules…" data-module-filter aria-label="Search modules"></label>
  </div>
  <div class="card module-list">
    <?php foreach ($modules as $m): $slug = $m['slug']; $up = $updates[$slug] ?? null; $deps = $m['depends'];
        $g = $groups[$slug] ?? null;
        $open = $m['active'] && $g && $g['pages'] ? admin_url('m/' . $g['pages'][0]['slug']) : '';
        $settingsUrl = '';
        if ($m['active']) {
            if (isset(Modules::settingsSections()[$slug])) $settingsUrl = admin_url('settings/modules/' . $slug);
            elseif ($g && $g['settings']) $settingsUrl = isset($g['settings']['url']) ? admin_url($g['settings']['url']) : admin_url('m/' . $g['settings']['slug']);
            elseif ($m['settings'] !== '' && isset(Modules::adminPages()[$m['settings']])) $settingsUrl = admin_url('m/' . $m['settings']);
        } ?>
    <div class="module-row<?= $m['active'] ? ' is-active' : '' ?><?= isset($errors[$slug]) ? ' has-error' : '' ?>"
         data-module-card="<?= e(strtolower($m['name'] . ' ' . $m['description'] . ' ' . implode(' ', $m['tags']))) ?>" data-state="<?= $m['active'] ? 'active' : 'inactive' ?><?= $up ? ' update' : '' ?>">
      <span class="module-icon"><?= icon($m['icon'] ?: 'puzzle', 18) ?></span>
      <div class="module-main">
        <div class="module-title"><strong><?= e($m['name']) ?></strong> <small class="muted">v<?= e($m['version']) ?></small>
          <?php if ($m['active']): ?><span class="pill pill-success">Active</span><?php endif; ?>
          <?php if (isset($errors[$slug])): ?><span class="pill pill-danger">Crashed</span><?php endif; ?>
          <?php if ($up): ?><span class="pill pill-accent">Update <?= e($up['version']) ?></span><?php endif; ?>
        </div>
        <p class="muted module-desc"><?= e($m['description']) ?></p>
        <div class="module-meta">
          <?php if ($m['author']): ?><small class="muted">By <?= e($m['author']) ?></small><?php endif; ?>
          <?php if ($deps): ?><small class="muted">· Needs</small> <?php foreach ($deps as $d => $c): ?><span class="pill pill-soft"><?= e(($modules[$d]['name'] ?? $d) . ($c !== '*' ? " $c" : '')) ?></span><?php endforeach; ?><?php endif; ?>
          <span class="module-links">
            <?php if ($open): ?><a href="<?= e($open) ?>">Open</a><?php endif; ?>
            <?php if ($settingsUrl): ?><a href="<?= e($settingsUrl) ?>">Settings</a><?php endif; ?>
            <a href="<?= e(admin_url("modules/$slug/check")) ?>">Details</a>
            <a href="<?= e(admin_url("modules/$slug/download")) ?>" title="Download as an installable .zip">Download</a>
            <?php if ($m['homepage'] !== ''): ?><a href="<?= e($m['homepage']) ?>" target="_blank" rel="noopener noreferrer">Website</a><?php endif; ?>
          </span>
        </div>
      </div>
      <div class="module-actions">
        <?php if ($up): ?>
          <form method="post" action="<?= e(admin_url("modules/$slug/update")) ?>"><?= csrf_field() ?>
            <button class="btn btn-sm btn-primary" type="submit" data-confirm="Update <?= e($m['name']) ?> to <?= e($up['version']) ?>? The current version is backed up first."><?= icon('download', 14) ?> Update</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(admin_url("modules/$slug/toggle")) ?>"><?= csrf_field() ?>
          <button class="btn btn-sm<?= $m['active'] ? ' btn-ghost' : '' ?>" type="submit"><?= $m['active'] ? 'Deactivate' : 'Activate' ?></button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
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
  <div class="row-between module-filter">
    <input class="input" type="search" placeholder="Search modules…" data-module-filter aria-label="Search modules">
    <small class="muted"><?= count($available) ?> modules</small>
  </div>
  <div class="card-grid">
    <?php foreach ($available as $a): $inst = $a['installed']; $canUpdate = $inst !== null && version_compare($a['version'], $inst, '>'); ?>
    <div class="card module-card" data-module-card="<?= e(strtolower($a['name'] . ' ' . $a['description'] . ' ' . implode(' ', $a['tags']))) ?>">
      <div class="card-pad">
        <div class="row-between">
          <span class="type-icon"><?= icon('box', 20) ?></span>
          <?php if ($inst === null): ?>
            <form method="post" action="<?= e(admin_url('modules/install/' . $a['slug'])) ?>"><?= csrf_field() ?>
              <button class="btn btn-sm btn-primary"<?= $locked !== '' || version_compare(SLATE_VERSION, $a['requires'], '<') ? ' disabled' : '' ?>><?= icon('download', 15) ?> Install</button></form>
          <?php elseif ($canUpdate): ?>
            <form method="post" action="<?= e(admin_url('modules/' . $a['slug'] . '/update')) ?>"><?= csrf_field() ?>
              <button class="btn btn-sm btn-primary"<?= $locked !== '' ? ' disabled' : '' ?>>Update to <?= e($a['version']) ?></button></form>
          <?php else: ?>
            <span class="pill pill-success"><?= icon('check', 13) ?> Installed</span>
          <?php endif; ?>
        </div>
        <h3><?= e($a['name']) ?> <small class="muted">v<?= e($a['version']) ?></small></h3>
        <p class="muted"><?= e($a['description']) ?></p>
        <?php if (version_compare(SLATE_VERSION, $a['requires'], '<')): ?><p><span class="pill pill-warn">Needs Slate <?= e($a['requires']) ?></span></p><?php endif; ?>
        <div class="module-foot">
          <small class="muted"><?= $a['author'] ? 'By ' . e($a['author']) . ' · ' : '' ?><?= e($a['registry']) ?>
            <?php foreach ($a['tags'] as $t): ?> · <?= e($t) ?><?php endforeach; ?></small>
          <?php if ($a['homepage']): ?><a href="<?= e($a['homepage']) ?>" target="_blank" rel="noopener noreferrer">Website</a><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
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
