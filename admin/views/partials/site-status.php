<?php
/**
 * Key settings at a glance, with quick switches for the ones people flip most.
 * Used on the dashboard and on Settings → Overview.
 */
use Slate\AdminAccess;
use Slate\Auth;
use Slate\Backup;
use Slate\Integrity;
use Slate\Options;
use Slate\Passkeys;

$me = Auth::user();
$items = [];
$add = static function (string $state, string $icon, string $label, string $value, string $href = '', ?string $toggle = null, bool $on = false) use (&$items) {
    $items[] = compact('state', 'icon', 'label', 'value', 'href', 'toggle', 'on');
};
$canSettings = Auth::can('manage_settings');

if ($canSettings) {
    $hidden = (bool)Options::get('seo_noindex_site');
    $add($hidden ? 'warn' : 'ok', 'search', 'Search engines', $hidden ? 'Hidden, site is noindexed' : 'Visible to Google & others', admin_url('settings/seo/indexing'), 'seo_noindex_site', !$hidden);
    $maint = (bool)Options::get('maintenance_mode');
    $add($maint ? 'warn' : 'ok', 'monitor', 'Website', $maint ? 'Maintenance mode, visitors see a notice' : 'Online for everyone', admin_url('settings/system/maintenance'), 'maintenance_mode', !$maint);
    $cache = (bool)Options::get('cache_pages', true);
    $add($cache ? 'ok' : 'info', 'zap', 'Page cache', $cache ? 'On, pages load instantly' : 'Off, every page is built live', admin_url('settings/performance/cache'), 'cache_pages', $cache);
    $smtp = (string)Options::get('smtp_host') !== '' && Options::get('mail_transport', 'auto') !== 'php';
    $add($smtp ? 'ok' : 'info', 'mail', 'Email delivery', $smtp ? 'SMTP · ' . Options::get('smtp_host') : 'Server mail (add SMTP for best delivery)', admin_url('settings/email/delivery'));
}
if (Auth::can('manage_updates')) {
    $sched = (string)Options::get('backups_schedule', 'off');
    $last = Backup::list()[0] ?? null;
    $add($sched === 'off' ? 'warn' : 'ok', 'database', 'Backups', ($sched === 'off' ? 'Automatic backups off' : ucfirst($sched))
        . ($last ? ' · last ' . time_ago(gmdate('Y-m-d H:i:s', $last['time'])) : ' · none yet'), admin_url('settings/system/backups'));
    $ir = Integrity::report();
    $bad = $ir ? count(array_filter($ir['issues'] ?? [], static fn($i) => ($i['level'] ?? '') === 'danger')) : 0;
    $add($bad ? 'danger' : ($ir ? 'ok' : 'info'), 'shield', 'Core files', $bad ? "$bad unexpected change(s)" : ($ir ? 'Match the official release' : 'Not checked yet'), admin_url('settings/security/check'));
}
if ($canSettings) {
    $host = AdminAccess::adminHost();
    $slug = admin_slug();
    $add($host !== '' || $slug !== 'admin' ? 'ok' : 'warn', 'lock', 'Dashboard address',
        $host !== '' ? 'Private domain · ' . $host : ($slug !== 'admin' ? 'Custom URL /' . $slug : 'Default /admin, easy for bots to find'), admin_url('settings/security/access'));
}
$secured = Passkeys::count((int)$me['id']) > 0 || \Slate\DB::val('SELECT totp_secret IS NOT NULL AND totp_secret <> \'\' FROM {{users}} WHERE id = :id', ['id' => $me['id']]);
$add($secured ? 'ok' : 'warn', 'key', 'Your sign-in', $secured ? 'Protected with 2FA or a passkey' : 'Password only, add a passkey or 2FA', admin_url('profile'));
?>
<div class="status-grid" data-quick-settings data-url="<?= e(admin_url('settings/quick')) ?>">
  <?php foreach ($items as $it): ?>
    <div class="status-item status-<?= e($it['state']) ?>">
      <span class="status-icon"><?= icon($it['icon'], 16) ?></span>
      <a class="status-text" href="<?= e($it['href']) ?>">
        <span class="status-label"><?= e($it['label']) ?></span>
        <span class="status-value"><?= e($it['value']) ?></span>
      </a>
      <?php if ($it['toggle']): ?>
        <input type="checkbox" class="switch switch-sm" data-quick="<?= e($it['toggle']) ?>"<?= $it['on'] ? ' checked' : '' ?> aria-label="<?= e($it['label']) ?>">
      <?php else: ?>
        <a class="status-go" href="<?= e($it['href']) ?>" aria-label="Open <?= e($it['label']) ?> settings"><?= icon('chevron-right', 15) ?></a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
