<?php
/**
 * A failed or held module update: expandable error report plus "Try again" / "Review and update" and "Skip this version".
 * Used on the module's card (Modules) and in the dashboard banner.
 * @var array  $n    ModuleUpdater::notices() entry
 * @var string $back optional "dashboard"
 */
$back ??= '';
$failedKind = ($n['kind'] ?? '') === 'failed';
?>
<?php if ($failedKind): ?>
<details class="mod-report" data-update-report>
  <summary>Error report</summary>
  <dl>
    <dt>Step</dt><dd><?= e((string)($n['step'] ?? '')) ?></dd>
    <dt>Error</dt><dd><?= e((string)($n['message'] ?? '')) ?></dd>
    <?php if (!empty($n['file'])): ?><dt>Where</dt><dd><code><?= e((string)$n['file']) ?>:<?= (int)($n['line'] ?? 0) ?></code></dd><?php endif; ?>
    <dt>Undone</dt><dd><?= !isset($n['undone']) || $n['undone'] ? 'Yes, files and data are back as they were.' : 'Partly, see the update log.' ?></dd>
  </dl>
  <?php if (!empty($n['excerpt'])): ?><pre class="mod-excerpt"><?= e((string)$n['excerpt']) ?></pre><?php endif; ?>
  <?php if (!empty($n['log'])): ?><a href="<?= e(admin_url('modules/log/' . $n['log'])) ?>">Full update log</a><?php endif; ?>
</details>
<?php endif; ?>
<div class="mod-upd-actions">
  <form method="post" action="<?= e(admin_url('modules/' . $n['slug'] . '/update')) ?>" data-busy="Updating…"><?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <?php if (!$failedKind): ?><input type="hidden" name="approve" value="1"><?php endif; ?>
    <button class="btn btn-sm btn-primary" type="submit" data-confirm="<?= e(($failedKind ? 'Try the update to ' : 'Approve and install ') . $n['version'] . ' again? It is backed up, checked and undone automatically if it fails.') ?>"><?= $failedKind ? 'Try again' : 'Review and update' ?></button></form>
  <form method="post" action="<?= e(admin_url('modules/' . $n['slug'] . '/skip')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= e((string)$n['version']) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
    <button class="btn btn-sm btn-ghost" type="submit">Skip this version</button></form>
  <?php if (!$failedKind): ?><a class="btn btn-sm btn-ghost" href="<?= e(admin_url('modules/' . $n['slug'] . '/check')) ?>">Details</a><?php endif; ?>
</div>
