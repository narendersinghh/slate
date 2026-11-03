<?php
use Slate\Media;
use Slate\Options;
use Slate\Settings;

$tabs = Settings::tabs();
$tabDef = $tabs[$tab];
?>
<div class="page-head">
  <div><h1>Settings</h1><p class="muted">Everything about your website in one place.</p></div>
</div>

<nav class="settings-tabs" aria-label="Settings categories">
  <?php foreach ($tabs as $tk => $t): if (!admin_can_settings($tk)) continue; ?>
    <a href="<?= e(admin_url("settings/$tk/" . array_key_first($t['sections']))) ?>" class="<?= $tk === $tab ? 'active' : '' ?>"<?= $tk === $tab ? ' aria-current="page"' : '' ?>><?= icon($t['icon'], 16) ?><span><?= e($t['label']) ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="settings-layout">
  <nav class="settings-side" aria-label="<?= e($tabDef['label']) ?> sections">
    <?php foreach ($tabDef['sections'] as $sk => $s): ?>
      <a href="<?= e(admin_url("settings/$tab/$sk")) ?>" class="<?= $sk === $section ? 'active' : '' ?>"<?= $sk === $section ? ' aria-current="page"' : '' ?>><?= e($s['label']) ?></a>
    <?php endforeach; ?>
  </nav>

  <form class="settings-body card" method="post" action="<?= e(admin_url("settings/$tab/$section")) ?>" data-settings-form>
    <?= csrf_field() ?>
    <div class="card-head"><h2><?= e($def['label']) ?></h2></div>
    <?php if (!empty($def['fields'])): ?>
    <div class="settings-fields">
      <?php foreach ($def['fields'] as $f):
        if ($f['type'] === 'info'): ?>
          <div class="setting-row setting-info"><div class="setting-label"><strong><?= e($f['label']) ?></strong></div><div class="setting-control"><p class="muted"><?= e($f['help'] ?? '') ?></p></div></div>
        <?php continue; endif;
        $key = $f['key'];
        $val = Options::get($key);
        $name = "s[$key]";
        $show = !empty($f['show_if']) ? ' data-show-if="' . e($f['show_if']) . '"' : '';
      ?>
      <div class="setting-row<?= !empty($f['danger']) ? ' is-danger' : '' ?>"<?= $show ?>>
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
              <textarea class="input<?= $f['type'] === 'code' ? ' code' : '' ?>" id="s_<?= e($key) ?>" name="<?= e($name) ?>" rows="<?= (int)($f['rows'] ?? 4) ?>"<?= $f['type'] === 'code' ? ' spellcheck="false"' : '' ?><?= !empty($f['counter']) ? ' data-count="' . (int)$f['counter'] . '"' : '' ?>><?= e((string)$val) ?></textarea>
              <?php break;
            case 'number': ?>
              <input class="input input-num" type="number" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="<?= e((string)$val) ?>"<?= isset($f['min']) ? ' min="' . $f['min'] . '"' : '' ?><?= isset($f['max']) ? ' max="' . $f['max'] . '"' : '' ?><?= isset($f['step']) ? ' step="' . $f['step'] . '"' : '' ?>>
              <?php break;
            case 'secret': $has = (string)$val !== ''; ?>
              <span class="input-group">
                <input class="input" type="password" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="" placeholder="<?= $has ? '•••••••••••• (saved — leave empty to keep)' : 'Not set' ?>" autocomplete="new-password">
                <?php if ($has): ?><label class="btn btn-ghost"><input type="checkbox" name="s[<?= e($key) ?>__clear]" value="1"> Clear</label><?php endif; ?>
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
              <input class="input" type="<?= $f['type'] === 'email' ? 'email' : 'text' ?>" id="s_<?= e($key) ?>" name="<?= e($name) ?>" value="<?= e((string)$val) ?>"<?= !empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '' ?><?= !empty($f['counter']) ? ' data-count="' . (int)$f['counter'] . '"' : '' ?>>
          <?php endswitch; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($def['render'])): ?>
      <div class="settings-custom"><?php require __DIR__ . '/settings/' . basename($def['render']) . '.php'; ?></div>
    <?php endif; ?>

    <?php if (!empty($def['fields']) || in_array($def['render'] ?? '', ['seo-types', 'redirects', 'menus', 'design-system'], true)): ?>
    <div class="card-foot sticky-foot">
      <span class="muted" data-dirty hidden>Unsaved changes</span>
      <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?> Save changes</button>
    </div>
    <?php endif; ?>
  </form>
</div>
<?php if (($def['render'] ?? '') === 'menus') { $scripts = ['menus.js']; } ?>
