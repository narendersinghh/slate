<?php
use Slate\AdminAccess;
use Slate\Auth;

$host = AdminAccess::adminHost();
$ips = AdminAccess::allowedIps();
$main = (string)parse_url(site_url(), PHP_URL_HOST);
$suggest = 'cms.' . preg_replace('/^www\./', '', $main);
$dashUrl = AdminAccess::baseUrl() . '/' . admin_slug() . '/';
?>
<div class="card">
  <div class="card-head"><h2>Where your dashboard lives</h2></div>
  <div class="card-pad stack">
    <div class="kv">
      <span>Dashboard address</span><code><?= e($dashUrl) ?></code>
      <span>Public website</span><code><?= e(rtrim(site_url(), '/')) ?></code>
      <span>Allowed from</span><span><?= $ips ? e(implode(', ', $ips)) : 'Any network' ?></span>
    </div>
    <p class="muted small">Three layers, from simplest to strongest: a <strong>custom dashboard URL</strong> (above) hides the login from bots;
      a <strong>private dashboard domain</strong> moves the whole dashboard off your public website; an <strong>IP allow-list</strong> lets only your
      own networks in. Combine them with a passkey or 2FA on every administrator account.</p>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>Private dashboard domain</h2><?php if ($host): ?><span class="pill pill-success">On · <?= e($host) ?></span><?php else: ?><span class="pill">Off</span><?php endif; ?></div>
  <div class="card-pad stack">
    <?php if ($host): ?>
      <p>The dashboard only answers on <strong><?= e($host) ?></strong>. On <?= e($main) ?> the dashboard address and its files are a plain “not found”, and
        your sign-in cookie never reaches the public website. Previews and on-page editing work on <?= e($host) ?>, where the site is shown to signed-in team members only.</p>
      <p class="muted small">Locked out (DNS changed, domain expired)? Run <code>php bin/slate admin:host off</code> over SSH, or delete the <code>admin_host</code> line in config.php.</p>
      <div><button class="btn btn-danger" type="submit" formaction="<?= e(admin_url('system/admin-host/disable')) ?>" formnovalidate
              data-confirm="Move the dashboard back to <?= e($main) ?>? You will be signed in there automatically.">Move dashboard back to <?= e($main) ?></button></div>
    <?php else: ?>
      <p>Run the dashboard on its own subdomain, e.g. <code><?= e($suggest) ?></code>, while visitors use <?= e($main) ?>. The public website then has no login
        page at all, and a problem on the public side (a bad script, a module bug) cannot touch your signed-in session.</p>
      <ol class="steps">
        <li><strong>Create the subdomain</strong> in your hosting panel (Hostinger: <em>Domains › Subdomains</em>; cPanel: <em>Domains</em>). If the panel lets you choose its
          folder, choose this website’s folder: <code><?= e(SLATE_ROOT) ?></code>.</li>
        <li><strong>Only if it got its own empty folder</strong> (you could not choose one): download the bridge kit below and upload its two files into that folder. They run this same website there.</li>
        <li><strong>Turn on SSL</strong> for the subdomain (most panels do it automatically within minutes).</li>
        <li><strong>Check & switch</strong> below. Slate first confirms the subdomain really reaches this website, then moves the dashboard and signs you in there, nothing to lose if a step is missing.</li>
      </ol>
      <div class="inline-form" data-admin-host data-check="<?= e(admin_url('system/admin-host/check')) ?>">
        <input class="input" name="admin_host_new" placeholder="<?= e($suggest) ?>" value="<?= e($_GET['host'] ?? '') ?>" autocomplete="off" spellcheck="false">
        <button class="btn" type="button" data-admin-host-check><?= icon('refresh', 15) ?> Check connection</button>
        <a class="btn btn-ghost" data-admin-host-bridge href="<?= e(admin_url('system/admin-host/bridge')) ?>"><?= icon('download', 15) ?> Bridge kit</a>
      </div>
      <div class="alert" data-admin-host-result hidden></div>
      <div data-admin-host-switch hidden>
        <input type="hidden" name="host" value="">
        <button class="btn btn-primary" type="submit" formaction="<?= e(admin_url('system/admin-host/enable')) ?>" formnovalidate><?= icon('lock', 15) ?> Switch the dashboard to this domain</button>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>IP allow-list</h2><?php if ($ips): ?><span class="pill pill-success">On</span><?php else: ?><span class="pill">Off</span><?php endif; ?></div>
  <div class="card-pad stack">
    <p class="muted small">Only these addresses can open the dashboard; everyone else gets “not found”. One IP or range (CIDR) per line. Your address right now is
      <code><?= e(Auth::ip()) ?></code>. Leave empty to allow any network. Best for fixed office or VPN addresses, home connections often change.</p>
    <textarea class="input code" name="ips" rows="3" placeholder="<?= e(Auth::ip()) ?>"><?= e(implode("\n", $ips)) ?></textarea>
    <div><button class="btn" type="submit" formaction="<?= e(admin_url('system/admin-ips')) ?>" formnovalidate>Save allow-list</button></div>
  </div>
</div>
