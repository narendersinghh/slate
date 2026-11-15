<?php
use Slate\Media;
use Slate\Options;
use Slate\Settings;

$tabs = Settings::tabs();
$tabDef = $tabs[$tab] ?? null;
?>
<div class="settings-shell">
  <aside class="settings-nav" aria-label="Settings">
    <div class="settings-search">
      <?= icon('search', 15) ?>
      <input class="input" type="search" placeholder="Find a setting…" aria-label="Find a setting" data-settings-search
             data-index="<?= e(json_encode(Settings::index(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-base="<?= e(admin_url('settings')) ?>">
      <div class="settings-search-results" hidden></div>
    </div>
    <select class="input settings-jump" aria-label="Go to settings section" onchange="if (this.value) location.href = this.value">
      <option value="<?= e(admin_url('settings')) ?>"<?= $tab === '' ? ' selected' : '' ?>>Overview</option>
      <?php foreach ($tabs as $tk => $t): if (!admin_can_settings($tk)) continue; ?>
        <optgroup label="<?= e($t['label']) ?>">
          <?php foreach ($t['sections'] as $sk => $sct): ?><option value="<?= e(admin_url("settings/$tk/$sk")) ?>"<?= $tk === $tab && $sk === $section ? ' selected' : '' ?>><?= e($sct['label']) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <a class="settings-nav-home<?= $tab === '' ? ' active' : '' ?>" href="<?= e(admin_url('settings')) ?>"><?= icon('grid', 15) ?> Overview</a>
    <?php foreach ($tabs as $tk => $t): if (!admin_can_settings($tk)) continue; $open = $tk === $tab; ?>
      <details class="settings-nav-group"<?= $open ? ' open' : '' ?>>
        <summary class="<?= $open ? 'current' : '' ?>"><?= icon($t['icon'], 15) ?><span><?= e($t['label']) ?></span><?= icon('chevron-down', 14) ?></summary>
        <div class="settings-nav-items">
          <?php foreach ($t['sections'] as $sk => $s): $on = $open && $sk === $section; ?>
            <a href="<?= e(admin_url("settings/$tk/$sk")) ?>" class="<?= $on ? 'active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($s['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </aside>

  <?php if (!$tabDef): ?>
  <div class="settings-main">
    <div class="settings-head"><h1>Settings</h1><p class="muted">The most important switches first, then everything else by topic.</p></div>
    <h2 class="section-title">At a glance</h2>
    <?php require __DIR__ . '/partials/site-status.php'; ?>
    <h2 class="section-title">All settings</h2>
    <div class="settings-cats">
      <?php foreach ($tabs as $tk => $t): if (!admin_can_settings($tk)) continue; ?>
        <div class="settings-cat card">
          <a class="settings-cat-head" href="<?= e(admin_url("settings/$tk/" . array_key_first($t['sections']))) ?>">
            <span class="settings-cat-icon"><?= icon($t['icon'], 18) ?></span>
            <span><strong><?= e($t['label']) ?></strong><small class="muted"><?= e($t['description'] ?? '') ?></small></span>
          </a>
          <div class="settings-cat-links">
            <?php foreach ($t['sections'] as $sk => $sct): ?><a href="<?= e(admin_url("settings/$tk/$sk")) ?>"><?= e($sct['label']) ?></a><?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <form class="settings-main" method="post" action="<?= e(admin_url("settings/$tab/$section")) ?>" data-settings-form>
    <?= csrf_field() ?>
    <div class="settings-head">
      <div class="crumbs"><a href="<?= e(admin_url('settings')) ?>">Settings</a><?= icon('chevron-right', 13) ?><span><?= e($tabDef['label']) ?></span></div>
      <h1><?= e($def['label']) ?></h1>
      <?php if (!empty($def['intro'])): ?><p class="muted"><?= e($def['intro']) ?></p><?php endif; ?>
    </div>

    <?php if (!empty($def['render_first']) && !empty($def['render'])): ?>
      <div class="settings-custom"><?php require __DIR__ . '/settings/' . basename($def['render']) . '.php'; ?></div>
    <?php endif; ?>
    <?php if (!empty($def['fields'])): ?>
    <div class="card settings-card">
      <?php foreach ($def['fields'] as $f):
        if ($f['type'] === 'heading'): ?>
          <div class="setting-heading"><h3><?= e($f['label']) ?></h3><?php if (!empty($f['help'])): ?><p class="muted small"><?= e($f['help']) ?></p><?php endif; ?></div>
        <?php continue; endif;
        if ($f['type'] === 'info'): ?>
          <div class="setting-note"><?= icon('info', 16) ?><div><strong><?= e($f['label']) ?></strong> <span class="muted"><?= e($f['help'] ?? '') ?></span></div></div>
        <?php continue; endif;
        $key = $f['key'];
        $val = Options::get($key);
        $name = "s[$key]";
        $show = !empty($f['show_if']) ? ' data-show-if="' . e($f['show_if']) . '"' : '';
        $isToggle = $f['type'] === 'toggle';
      ?>
      <div class="setting-row<?= !empty($f['danger']) ? ' is-danger' : '' ?><?= $isToggle ? ' is-toggle' : '' ?>"<?= $show ?> id="set-<?= e($key) ?>">
        <div class="setting-label">
          <label for="s_<?= e($key) ?>"><?= e($f['label']) ?></label>
          <?php if (!empty($f['help'])): ?><small class="help"><?= e($f['help']) ?></small><?php endif; ?>
        </div>
        <div class="setting-control">
          <?php switch ($f['type']):
            case 'toggle': ?>
              <input type="hidden" name="<?= e($name) ?>" value="0">
              <input type="checkbox" class="switch" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="1"<?= $val ? ' checked' : '' ?> data-key="<?= e($key) ?>">
              <?php break;
            case 'select': $opts = Settings::options($f['options']); ?>
              <select class="input" id="s_<?= e($key) ?>" name="<?= e($name) ?>" data-key="<?= e($key) ?>">
                <?php foreach ($opts as $ov => $ol): ?><option value="<?= e($ov) ?>"<?= (string)$val === (string)$ov ? ' selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
              </select>
              <?php break;
            case 'textarea': case 'code': ?>
              <textarea class="input<?= $f['type'] === 'code' ? ' code' : '' ?>" id="s_<?= e($key) ?>" name="<?= e($name) ?>" rows="<?= (int)($f['rows'] ?? 4) ?>"<?= $f['type'] === 'code' ? ' spellcheck="false"' : '' ?><?= !empty($f['counter']) ? ' data-count="' . (int)$f['counter'] . '"' : '' ?><?= !empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '' ?>><?= e((string)$val) ?></textarea>
              <?php break;
            case 'number': ?>
              <span class="input-suffix-wrap"><input class="input input-num" type="number" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="<?= e((string)$val) ?>"<?= isset($f['min']) ? ' min="' . $f['min'] . '"' : '' ?><?= isset($f['max']) ? ' max="' . $f['max'] . '"' : '' ?><?= isset($f['step']) ? ' step="' . $f['step'] . '"' : '' ?>><?php if (!empty($f['suffix'])): ?><span class="muted small"><?= e($f['suffix']) ?></span><?php endif; ?></span>
              <?php break;
            case 'secret': $has = (string)$val !== ''; ?>
              <span class="input-group">
                <input class="input" type="password" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="" placeholder="<?= $has ? '•••••••••••• (saved — leave empty to keep)' : 'Not set' ?>" autocomplete="new-password">
                <?php if ($has): ?><label class="btn btn-ghost btn-sm"><input type="checkbox" name="s[<?= e($key) ?>__clear]" value="1"> Clear</label><?php endif; ?>
              </span>
              <?php break;
            case 'media': $m = $val ? Media::find((int)$val) : null; ?>
              <div class="media-field" data-media-field data-kind="image">
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($m['id'] ?? '') ?>">
                <div class="media-field-preview"><?php if ($m): ?><figure data-id="<?= (int)$m['id'] ?>"><img src="<?= e(Media::url($m, 'thumb')) ?>" alt=""></figure><?php endif; ?></div>
                <div class="media-field-actions"><button type="button" class="btn btn-sm" data-media-choose>Choose image</button><button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $m ? '' : ' hidden' ?>>Remove</button></div>
              </div>
              <?php break;
            case 'color': ?>
              <span class="input-group"><input type="color" class="color-input" name="<?= e($name) ?>" value="<?= e((string)$val) ?>" id="s_<?= e($key) ?>"><code><?= e((string)$val) ?></code></span>
              <?php break;
            default: ?>
              <input class="input" type="<?= $f['type'] === 'email' ? 'email' : ($f['type'] === 'url' ? 'url' : 'text') ?>" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="<?= e((string)$val) ?>"<?= !empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '' ?><?= !empty($f['counter']) ? ' data-count="' . (int)$f['counter'] . '"' : '' ?>>
          <?php endswitch; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($def['render']) && empty($def['render_first'])): ?>
      <div class="settings-custom"><?php require __DIR__ . '/settings/' . basename($def['render']) . '.php'; ?></div>
    <?php endif; ?>
    <?php if (!empty($def['callback']) && is_callable($def['callback'])): ?>
      <div class="settings-custom"><?php ($def['callback'])(); ?></div>
    <?php endif; ?>

    <?php if (!empty($def['fields']) || in_array($def['render'] ?? '', ['seo-types', 'redirects', 'menus', 'design-system'], true)): ?>
    <div class="save-bar">
      <span class="muted" data-dirty hidden><?= icon('info', 14) ?> You have unsaved changes</span>
      <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Save changes</button>
    </div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<?php if ($tabDef && ($def['render'] ?? '') === 'menus') { $scripts = ['menus.js']; } ?>
