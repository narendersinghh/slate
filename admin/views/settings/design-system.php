<?php
use Slate\DesignSystem;

$ds = DesignSystem::get();
$fontOptions = static function (string $current): string {
    $out = '<optgroup label="Google Fonts">';
    $list = DesignSystem::FONTS;
    if (!in_array($current, $list, true) && !isset(DesignSystem::SYSTEM_FONTS[$current])) {
        array_unshift($list, $current);
    }
    foreach ($list as $f) {
        $out .= '<option value="' . e($f) . '"' . ($f === $current ? ' selected' : '') . '>' . e($f) . '</option>';
    }
    $out .= '</optgroup><optgroup label="System">';
    foreach (DesignSystem::SYSTEM_FONTS as $k => $l) {
        $out .= '<option value="' . e($k) . '"' . ($k === $current ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $out . '</optgroup>';
};
?>
<?php if ($u = DesignSystem::fontsUrl($ds)): ?><link rel="stylesheet" href="<?= e($u) ?>"><?php endif; ?>
<div class="card-pad stack ds">
  <p class="muted" style="margin:0">Global styles for your website. Themes use these as CSS variables (<code>var(--color-primary)</code>, <code>var(--font-heading)</code>, <code>var(--type-h1)</code>…) and Elementor’s <code>--e-global-*</code> variables are mapped too, so imported Elementor designs follow these settings.</p>

  <div class="ds-preview" style="--p:<?= e($ds['colors'][0]['value'] ?? '#F26B2D') ?>">
    <span class="eyebrow">Live preview</span>
    <div style="font-family:<?= e('"' . $ds['fonts']['heading'] . '"') ?>;font-size:40px;line-height:1.1;font-weight:<?= (int)$ds['type']['h1']['weight'] ?>" data-pv-heading>The quick brown fox jumps</div>
    <p style="font-family:<?= e('"' . $ds['fonts']['body'] . '"') ?>;font-size:16px;margin:10px 0 14px" data-pv-body>Body text in your chosen font. Clean, readable copy for every page of the site.</p>
    <div class="ds-swatches"><?php foreach ($ds['colors'] as $c): ?><span style="background:<?= e($c['value']) ?>" title="<?= e($c['name']) ?>"></span><?php endforeach; ?></div>
  </div>

  <h3 class="section-title">Global colours</h3>
  <div class="table-wrap"><table class="table table-form" data-repeat-table>
    <thead><tr><th>Colour</th><th>Name</th><th>ID (CSS variable)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($ds['colors'] as $c): ?>
      <tr>
        <td style="width:150px"><span class="input-group"><input type="color" class="color-input" value="<?= e(preg_match('/^#[0-9a-f]{6}$/i', $c['value']) ? $c['value'] : '#000000') ?>" oninput="this.nextElementSibling.value=this.value"><input class="input input-sm" name="ds[color_value][]" value="<?= e($c['value']) ?>" style="width:96px" oninput="if(/^#[0-9a-f]{6}$/i.test(this.value))this.previousElementSibling.value=this.value"></span></td>
        <td><input class="input input-sm" name="ds[color_name][]" value="<?= e($c['name']) ?>"></td>
        <td><span class="input-group"><code>--color-</code><input class="input input-sm" name="ds[color_id][]" value="<?= e($c['id']) ?>"></span></td>
        <td><button type="button" class="icon-btn" data-remove-row aria-label="Remove"><?= icon('trash', 15) ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div><button type="button" class="btn btn-sm" data-add-row><?= icon('plus', 15) ?> Add colour</button></div>

  <h3 class="section-title">Typography</h3>
  <div class="grid-form">
    <label class="field"><span>Heading font</span><select class="input" name="ds[fonts][heading]" data-font="heading"><?= $fontOptions($ds['fonts']['heading']) ?></select></label>
    <label class="field"><span>Body font</span><select class="input" name="ds[fonts][body]" data-font="body"><?= $fontOptions($ds['fonts']['body']) ?></select></label>
  </div>
  <label class="switch-row"><input type="checkbox" class="switch" name="ds[fonts][google]" value="1"<?= $ds['fonts']['google'] ? ' checked' : '' ?>><span>Load fonts from Google Fonts</span></label>
  <div class="table-wrap"><table class="table table-form">
    <thead><tr><th>Element</th><th>Font</th><th>Size (px)</th><th>Weight</th><th>Line height</th><th>Letter spacing (em)</th></tr></thead>
    <tbody>
    <?php foreach (DesignSystem::TYPE_KEYS as $k => $label): $t = $ds['type'][$k]; ?>
      <tr>
        <td><strong><?= e($label) ?></strong><br><code class="muted">var(--type-<?= e($k) ?>)</code></td>
        <td><select class="input input-sm" name="ds[type][<?= e($k) ?>][font]"><option value="heading"<?= $t['font'] === 'heading' ? ' selected' : '' ?>>Heading</option><option value="body"<?= $t['font'] === 'body' ? ' selected' : '' ?>>Body</option></select></td>
        <td><input class="input input-sm" type="number" step="1" name="ds[type][<?= e($k) ?>][size]" value="<?= e($t['size']) ?>" style="width:80px"></td>
        <td><select class="input input-sm" name="ds[type][<?= e($k) ?>][weight]"><?php foreach ([300, 400, 500, 600, 700, 800, 900] as $w): ?><option<?= (int)$t['weight'] === $w ? ' selected' : '' ?>><?= $w ?></option><?php endforeach; ?></select></td>
        <td><input class="input input-sm" type="number" step="0.05" name="ds[type][<?= e($k) ?>][line]" value="<?= e($t['line']) ?>" style="width:80px"></td>
        <td><input class="input input-sm" type="number" step="0.01" name="ds[type][<?= e($k) ?>][tracking]" value="<?= e($t['tracking']) ?>" style="width:80px"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="stack-sm">
    <label class="switch-row"><input type="checkbox" class="switch" name="ds[responsive]" value="1"<?= $ds['responsive'] ? ' checked' : '' ?>><span>Fluid sizes — large headings scale down on mobile</span></label>
    <label class="switch-row"><input type="checkbox" class="switch" name="ds[apply_elements]" value="1"<?= $ds['apply_elements'] ? ' checked' : '' ?>><span>Apply typography to body &amp; h1–h6 automatically <small class="muted">(turn off for mirrored Elementor sites)</small></span></label>
  </div>

  <h3 class="section-title">Layout</h3>
  <div class="grid-form">
    <label class="field"><span>Container width (px)</span><input class="input" type="number" name="ds[layout][container]" value="<?= (int)$ds['layout']['container'] ?>"></label>
    <label class="field"><span>Section spacing (px)</span><input class="input" type="number" name="ds[layout][section_space]" value="<?= (int)$ds['layout']['section_space'] ?>"></label>
    <label class="field"><span>Corner radius (px)</span><input class="input" type="number" name="ds[layout][radius]" value="<?= (int)$ds['layout']['radius'] ?>"></label>
    <label class="field"><span>Button radius (px)</span><input class="input" type="number" name="ds[layout][button_radius]" value="<?= (int)$ds['layout']['button_radius'] ?>"></label>
  </div>

  <h3 class="section-title">Global classes</h3>
  <p class="muted" style="margin:0">Reusable styles you can add to any element (<code>class="btn"</code>) in the editor, templates or on-page editing. Write CSS declarations only.</p>
  <div class="table-wrap"><table class="table table-form" data-repeat-table>
    <thead><tr><th style="width:200px">Class name</th><th>CSS declarations</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_merge($ds['classes'], [['name' => '', 'css' => '']]) as $c): ?>
      <tr>
        <td><span class="input-group"><code>.</code><input class="input input-sm" name="ds[class_name][]" value="<?= e($c['name']) ?>" placeholder="card-shadow"></span></td>
        <td><textarea class="input code" rows="2" name="ds[class_css][]" placeholder="box-shadow:0 10px 30px rgba(0,0,0,.08);border-radius:var(--radius);"><?= e($c['css']) ?></textarea></td>
        <td><button type="button" class="icon-btn" data-remove-row aria-label="Remove"><?= icon('trash', 15) ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div><button type="button" class="btn btn-sm" data-add-row><?= icon('plus', 15) ?> Add class</button></div>

  <h3 class="section-title">Dashboard</h3>
  <label class="switch-row"><input type="checkbox" class="switch" name="ds[admin_match]" value="1"<?= $ds['admin_match'] ? ' checked' : '' ?>><span>Use these brand colours &amp; fonts in the Slate dashboard too <small class="muted">(match the admin to your website)</small></span></label>
</div>
<style>
.ds-preview{border:1px solid var(--border);border-radius:16px;padding:22px 24px;background:var(--surface-2)}
.ds-preview [data-pv-heading]{color:var(--text)}
.ds-swatches{display:flex;gap:8px}.ds-swatches span{width:34px;height:34px;border-radius:10px;border:1px solid var(--border)}
</style>
<script>
document.querySelectorAll('[data-font]').forEach(function (s) {
  s.addEventListener('change', function () {
    var f = s.value, id = 'gf-' + f.replace(/\W/g, '');
    if (!document.getElementById(id) && !/^(system-ui|Georgia|ui-monospace)$/.test(f)) {
      var l = document.createElement('link'); l.id = id; l.rel = 'stylesheet'; l.href = 'https://fonts.googleapis.com/css2?family=' + f.replace(/ /g, '+') + ':wght@400;600;700&display=swap'; document.head.appendChild(l);
    }
    document.querySelector(s.dataset.font === 'heading' ? '[data-pv-heading]' : '[data-pv-body]').style.fontFamily = '"' + f + '"';
  });
});
</script>
