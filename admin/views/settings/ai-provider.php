<?php use Slate\Ai; $preset = Ai::preset(); ?>
<div class="card-pad">
  <script type="application/json" data-ai-presets><?= json_encode(Ai::PRESETS, JSON_HEX_TAG) ?></script>
  <div class="banner" data-ai-info>
    <?= icon('sparkles', 18) ?>
    <div>
      <strong data-ai-label><?= e($preset['label']) ?></strong> · default model <code data-ai-model><?= e($preset['model'] ?: '—') ?></code>
      <span data-ai-keys><?= $preset['keys'] ? ' · <a href="' . e($preset['keys']) . '" target="_blank" rel="noopener">Get an API key</a>' : '' ?></span>
      <br><small class="muted">Suggested models: <span data-ai-models><?= e(implode(', ', $preset['models'])) ?></span></small>
    </div>
    <button class="btn btn-sm" type="button" data-ai-test>Test connection</button>
  </div>
  <p class="muted small">The AI assistant appears in the editor (improve, shorten, expand, FAQ, drafts, custom instructions), the SEO panel (meta title/description) and the media library (alt text). Content is only sent to the provider when a team member clicks an AI action.</p>
</div>
