<?php
/** Styles editor (Appearance › Styles): colours, variables, typography, classes and layout, edited in admin/assets/design-system.js. */
use Slate\DesignSystem;

$ds = DesignSystem::get();
?>
<?php if ($u = DesignSystem::fontsUrl($ds)): ?><link rel="stylesheet" href="<?= e($u) ?>"><?php endif; ?>
<input type="hidden" name="ds_json" data-ds-json>
<script type="application/json" data-ds-config><?= json_encode([
    'ds' => $ds,
    'fonts' => DesignSystem::FONTS,
    'systemFonts' => DesignSystem::SYSTEM_FONTS,
    'typeKeys' => DesignSystem::TYPE_KEYS,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<div class="dse" data-ds-editor>
  <noscript><p class="muted">The styles editor needs JavaScript.</p></noscript>
</div>
