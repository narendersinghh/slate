<?php
/**
 * Renders one custom field input.
 * @var array $f      field definition
 * @var mixed $value  current value
 * @var string $name  input name, e.g. fields[price]
 */
use Slate\Media;

$id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
$value ??= $f['default'] ?? '';
$req = !empty($f['required']) ? ' data-required' : '';
$ph = !empty($f['placeholder']) ? ' placeholder="' . e($f['placeholder']) . '"' : '';
$opts = $f['options'] ?? [];
$optPair = static function (string $o): array {
    return str_contains($o, ':') ? array_map('trim', explode(':', $o, 2)) : [$o, $o];
};
?>
<div class="field cf cf-<?= e($f['type']) ?>" style="--w:<?= (int)($f['width'] ?? 100) ?>%">
  <?php if ($f['type'] !== 'toggle'): ?>
  <label for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="req">*</span>' : '' ?></label>
  <?php endif; ?>
  <?php switch ($f['type']):
    case 'textarea': ?>
      <textarea class="input" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="4"<?= $ph . $req ?>><?= e(is_scalar($value) ? $value : '') ?></textarea>
      <?php break;
    case 'code': ?>
      <textarea class="input code" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="6" spellcheck="false"<?= $ph ?>><?= e(is_scalar($value) ? $value : '') ?></textarea>
      <?php break;
    case 'richtext': ?>
      <div class="editor editor-sm" data-editor>
        <textarea name="<?= e($name) ?>" hidden><?= e(is_scalar($value) ? $value : '') ?></textarea>
      </div>
      <?php break;
    case 'number': ?>
      <input class="input" type="number" step="any" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? $value : '') ?>"<?= $ph . $req ?>>
      <?php break;
    case 'email': case 'url': case 'tel': case 'date': case 'color': ?>
      <input class="input" type="<?= e($f['type']) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? $value : '') ?>"<?= $ph . $req ?>>
      <?php break;
    case 'datetime': ?>
      <input class="input" type="datetime-local" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? $value : '') ?>"<?= $req ?>>
      <?php break;
    case 'select': ?>
      <select class="input" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $req ?>>
        <option value="">— Select —</option>
        <?php foreach ($opts as $o): [$v, $l] = $optPair($o); ?>
          <option value="<?= e($v) ?>"<?= (string)$value === $v ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <?php break;
    case 'radio': ?>
      <div class="choice-list">
        <?php foreach ($opts as $o): [$v, $l] = $optPair($o); ?>
          <label class="choice"><input type="radio" name="<?= e($name) ?>" value="<?= e($v) ?>"<?= (string)$value === $v ? ' checked' : '' ?>> <?= e($l) ?></label>
        <?php endforeach; ?>
      </div>
      <?php break;
    case 'checkbox': $vals = (array)$value; ?>
      <div class="choice-list">
        <input type="hidden" name="<?= e($name) ?>[]" value="">
        <?php foreach ($opts as $o): [$v, $l] = $optPair($o); ?>
          <label class="choice"><input type="checkbox" name="<?= e($name) ?>[]" value="<?= e($v) ?>"<?= in_array($v, $vals, true) ? ' checked' : '' ?>> <?= e($l) ?></label>
        <?php endforeach; ?>
      </div>
      <?php break;
    case 'toggle': ?>
      <label class="switch-row">
        <input type="hidden" name="<?= e($name) ?>" value="0">
        <input type="checkbox" class="switch" name="<?= e($name) ?>" value="1"<?= $value ? ' checked' : '' ?>>
        <span><?= e($f['label']) ?></span>
      </label>
      <?php break;
    case 'image': case 'file': $m = $value ? Media::find((int)$value) : null; ?>
      <div class="media-field" data-media-field data-kind="<?= $f['type'] === 'image' ? 'image' : '' ?>">
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($m ? $m['id'] : '') ?>">
        <div class="media-field-preview">
          <?php if ($m): ?><figure data-id="<?= (int)$m['id'] ?>"><?= Media::isImage($m) ? '<img src="' . e(Media::url($m, 'thumb')) . '" alt="">' : '<span class="file-chip">' . e(basename($m['path'])) . '</span>' ?></figure><?php endif; ?>
        </div>
        <div class="media-field-actions">
          <button type="button" class="btn btn-sm" data-media-choose><?= icon('image', 15) ?> <?= $m ? 'Replace' : 'Choose' ?></button>
          <button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $m ? '' : ' hidden' ?>>Remove</button>
        </div>
      </div>
      <?php break;
    case 'gallery': $ids = array_filter(array_map('intval', (array)$value)); Media::prime($ids); ?>
      <div class="media-field" data-media-field data-multiple data-kind="image">
        <input type="hidden" name="<?= e($name) ?>" value="<?= e(implode(',', $ids)) ?>">
        <div class="media-field-preview gallery-preview">
          <?php foreach ($ids as $mid): $m = Media::find($mid); if (!$m) continue; ?>
            <figure data-id="<?= (int)$m['id'] ?>"><img src="<?= e(Media::url($m, 'thumb')) ?>" alt=""></figure>
          <?php endforeach; ?>
        </div>
        <div class="media-field-actions">
          <button type="button" class="btn btn-sm" data-media-choose><?= icon('plus', 15) ?> Add images</button>
          <button type="button" class="btn btn-sm btn-ghost" data-media-remove<?= $ids ? '' : ' hidden' ?>>Clear</button>
        </div>
      </div>
      <?php break;
    case 'relation': $ids = array_filter(array_map('intval', (array)$value)); ?>
      <div class="relation-field" data-relation data-type="<?= e($f['post_type'] ?? '') ?>">
        <input type="hidden" name="<?= e($name) ?>" value="<?= e(implode(',', $ids)) ?>">
        <div class="chips" data-chips>
          <?php if ($ids) { \Slate\Posts::prime($ids); foreach ($ids as $rid) { $rp = \Slate\Posts::find($rid); if ($rp) echo '<span class="chip" data-id="' . (int)$rid . '">' . e($rp['title']) . '<button type="button" aria-label="Remove">×</button></span>'; } } ?>
        </div>
        <input class="input" type="search" placeholder="Search to add…" data-relation-search autocomplete="off">
        <div class="suggest" data-suggest hidden></div>
      </div>
      <?php break;
    case 'repeater': $rows = is_array($value) ? $value : []; ?>
      <div class="repeater" data-repeater data-name="<?= e($name) ?>">
        <textarea name="<?= e($name) ?>" hidden data-repeater-value><?= e(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
        <script type="application/json" data-repeater-schema><?= json_encode($f['sub_fields'] ?? [], JSON_HEX_TAG) ?></script>
        <div class="repeater-rows" data-rows></div>
        <button type="button" class="btn btn-sm" data-repeater-add><?= icon('plus', 15) ?> Add row</button>
      </div>
      <?php break;
    default: ?>
      <input class="input" type="text" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? $value : '') ?>"<?= $ph . $req ?>>
  <?php endswitch; ?>
  <?php if (!empty($f['help'])): ?><small class="help"><?= e($f['help']) ?></small><?php endif; ?>
</div>
