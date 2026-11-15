<?php
/** Settings → Security → Security check: one page that says what is protected and what still needs doing. */
use Slate\AdminAccess;
use Slate\DB;
use Slate\Integrity;
use Slate\Modules;
use Slate\ModuleScanner;
use Slate\Options;

$checks = [];
$add = static function (string $state, string $title, string $detail, string $fix = '') use (&$checks): void {
    $checks[] = compact('state', 'title', 'detail', 'fix');
};
$https = str_starts_with(site_url(), 'https://');
$add($https ? 'ok' : 'bad', 'HTTPS', $https ? 'The site address uses HTTPS.' : 'The site address is not HTTPS. Install a certificate and update the URL in config.php.');
$add(Options::get('security_hsts') ? 'ok' : ($https ? 'warn' : 'info'), 'HSTS', Options::get('security_hsts')
    ? 'Browsers are told to always use HTTPS.' : 'Turn on HSTS so browsers never connect over plain HTTP.', admin_url('settings/security/headers'));

$slug = admin_slug();
$add($slug === 'admin' ? 'warn' : 'ok', 'Dashboard address', $slug === 'admin'
    ? 'The dashboard is at the default /admin, the first place bots look.' : 'The dashboard uses a custom address (/' . e($slug) . ').', admin_url('settings/security/access'));
$host = AdminAccess::adminHost();
$add($host ? 'ok' : 'info', 'Separate admin domain', $host
    ? 'The dashboard only answers on ' . e($host) . '; on the public domain it does not exist.'
    : 'Optional, strongest protection: serve the dashboard on a private (sub)domain only, set <code>admin_host</code> in config.php or run <code>php bin/slate admin:host cms.yourdomain.com</code>.');
$ips = AdminAccess::allowedIps();
$add($ips ? 'ok' : 'info', 'Dashboard IP allow-list', $ips ? 'Only ' . e(implode(', ', $ips)) . ' can reach the dashboard.'
    : 'Optional: limit the dashboard to your own IP addresses, <code>php bin/slate admin:ips "1.2.3.4"</code>.');

$admins = DB::all("SELECT u.username, u.totp_secret, (SELECT COUNT(*) FROM {{passkeys}} p WHERE p.user_id = u.id) AS passkeys FROM {{users}} u WHERE u.role = 'administrator' AND u.status = 'active'");
$no2fa = array_column(array_filter($admins, static fn($u) => empty($u['totp_secret']) && !(int)$u['passkeys']), 'username');
$add($no2fa ? 'bad' : 'ok', 'Two-factor authentication', $no2fa
    ? 'Administrators without 2FA: ' . e(implode(', ', $no2fa)) . '. A stolen password is then enough to take over the site.'
    : 'Every administrator uses 2FA (authenticator app or passkey).' . (Options::get('security_require_2fa') ? ' It is required.' : ''), admin_url('profile') . '#2fa');
if (!Options::get('security_require_2fa')) {
    $add('warn', 'Require 2FA', 'Make 2FA mandatory for administrators.', admin_url('settings/security/login'));
}
$add(Options::get('security_login_alerts', true) ? 'ok' : 'warn', 'Sign-in alerts', 'Administrators ' . (Options::get('security_login_alerts', true) ? 'get' : 'do not get') . ' an email when they sign in from a new address.', admin_url('settings/security/login'));
$add(SLATE_DEBUG ? 'bad' : 'ok', 'Debug mode', SLATE_DEBUG ? 'Debug mode is on: error details are shown to visitors. Set <code>debug</code> to false in config.php.' : 'Off.');

$perm = @fileperms(SLATE_ROOT . '/config.php');
$worldReadable = $perm !== false && ($perm & 0004);
$add($worldReadable ? 'warn' : 'ok', 'config.php permissions', $worldReadable
    ? 'config.php (database password, encryption key) is readable by other accounts on the server. Set its permissions to 600.' : 'Only readable by the site’s own account.');
$add(version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'bad', 'PHP version', 'PHP ' . e(PHP_VERSION) . '.');

Options::lazy('backup_last');
$lastBackup = (int)Options::get('backup_last', 0);
$add($lastBackup > time() - 8 * 86400 ? 'ok' : 'warn', 'Backups', $lastBackup ? 'Last backup ' . e(time_ago(gmdate('Y-m-d H:i:s', $lastBackup))) . '.' : 'No backup made yet. Turn on automatic backups.', admin_url('settings/system/backups'));
$add((int)Options::get('cron_server_seen', 0) > time() - 3600 ? 'ok' : 'info', 'Scheduled tasks', (int)Options::get('cron_server_seen', 0) > time() - 3600
    ? 'A server cron runs tasks on time.' : 'Tasks run after visits. Add a server cron so checks and backups run on time.', admin_url('settings/system/cron'));
$add(\Slate\Mailer::configured() ? 'ok' : 'info', 'Email', \Slate\Mailer::configured() ? 'A sender address on your domain is set.' : 'Set up email so security alerts and password resets are delivered reliably.', admin_url('settings/email/delivery'));

if (\Slate\Api::enabled()) {
    $keys = (int)DB::val('SELECT COUNT(*) FROM {{api_keys}}');
    $add('info', 'REST API', 'Enabled, ' . $keys . ' key(s). Reading published content ' . (Options::get('api_public_read', true) ? 'is public' : 'needs a key') . '.', admin_url('settings/integrations/api'));
}

$risky = [];
foreach (Modules::all() as $s => $m) {
    if ($m['active']) {
        $scan = ModuleScanner::scan(SLATE_MODULES . "/$s");
        foreach ($scan['findings'] as $f) {
            if ($f['risk'] === 'high') {
                $risky[$s] = $m['name'];
            }
        }
    }
}
$add($risky ? 'warn' : 'ok', 'Module code', $risky ? 'Active modules using shell commands or evaluated code: ' . e(implode(', ', $risky)) . '. Make sure you trust them.' : 'No active module uses shell commands or evaluated code.', admin_url('modules'));

$report = Integrity::report();
$icons = ['ok' => 'check', 'warn' => 'alert', 'bad' => 'alert', 'info' => 'info'];
$labels = ['ok' => 'OK', 'warn' => 'Improve', 'bad' => 'Fix', 'info' => 'Optional'];
$counts = array_count_values(array_column($checks, 'state'));
?>
<div class="card-pad stack">
  <div class="sec-summary">
    <span class="pill pill-success"><?= (int)($counts['ok'] ?? 0) ?> OK</span>
    <?php if (!empty($counts['bad'])): ?><span class="pill pill-danger"><?= (int)$counts['bad'] ?> to fix</span><?php endif; ?>
    <?php if (!empty($counts['warn'])): ?><span class="pill pill-warn"><?= (int)$counts['warn'] ?> to improve</span><?php endif; ?>
    <?php if (!empty($counts['info'])): ?><span class="pill"><?= (int)$counts['info'] ?> optional</span><?php endif; ?>
  </div>
  <ul class="sec-list">
    <?php usort($checks, static fn($a, $b) => ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3][$a['state']] <=> ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3][$b['state']]);
    foreach ($checks as $c): ?>
      <li class="sec-<?= $c['state'] ?>"><span class="sec-icon"><?= icon($icons[$c['state']], 15) ?></span>
        <div><strong><?= e($c['title']) ?></strong> <span class="sec-tag"><?= $labels[$c['state']] ?></span><br><small><?= $c['detail'] ?></small></div>
        <?php if ($c['fix'] !== '' && $c['state'] !== 'ok'): ?><a class="btn btn-sm" href="<?= e($c['fix']) ?>">Open</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>

  <div class="row-between">
    <div><strong class="small-label">File integrity</strong>
      <p class="muted small"><?php if ($report): ?>Last check <?= e(time_ago(gmdate('Y-m-d H:i:s', (int)$report['time']))) ?> · <?= (int)($report['files'] ?? 0) ?> files ·
        <?= !empty($report['official']) ? 'core verified against GitHub (' . e($report['official']) . ')' : 'official file list unavailable, compared with the trusted snapshot only' ?>
        <?php else: ?>Not checked yet.<?php endif; ?></p></div>
    <div class="row-between">
      <button class="btn" type="submit" formaction="<?= e(admin_url('system/integrity/check')) ?>" formnovalidate><?= icon('shield', 15) ?> Check now</button>
      <button class="btn" type="submit" formaction="<?= e(admin_url('system/integrity/trust')) ?>" formnovalidate
        data-confirm="Trust every file as it is now? Only do this when you know all current changes are yours."><?= icon('check', 15) ?> Trust current files</button>
    </div>
  </div>
  <?php if ($report && $report['issues']): ?>
    <ul class="list list-compact">
      <?php foreach (array_slice($report['issues'], 0, 100) as $i): ?>
        <li><span class="list-main"><span class="pill <?= $i['level'] === 'danger' ? 'pill-danger' : ($i['level'] === 'warn' ? 'pill-warn' : '') ?>"><?= e($i['kind']) ?></span>
          <span><strong><code><?= e($i['path']) ?></code></strong><small><?= e($i['detail']) ?></small></span></span></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif ($report): ?>
    <p class="text-success small"><?= icon('check', 14) ?> No unexpected file changes.</p>
  <?php endif; ?>
</div>
