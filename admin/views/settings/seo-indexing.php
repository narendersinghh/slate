<?php
/** SEO › Instant indexing: Google service account, IndexNow key, automatic types, manual submission and the log. */
use Slate\ContentModel;
use Slate\Indexing;
use Slate\Options;

$email = Indexing::googleEmail();
$inKey = Indexing::indexNowKey();
$public = array_filter(ContentModel::types(), static fn($t) => !empty($t['public']));
$auto = Indexing::autoTypes();
$counts = Indexing::counts();
$recent = Indexing::recent(50);
$quota = (int)Options::get('seo_google_quota', 200);
$noindex = (bool)Options::get('seo_noindex_site');
$statusPill = static fn(string $s) => match ($s) { 'sent' => 'pill-success', 'failed' => 'pill-danger', 'pending' => 'pill-warn', default => '' };
?>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/seo.css')) ?>">
<div class="card-pad stack seo-idx">
  <?php if (Indexing::testEndpoints()): ?>
    <div class="alert" data-seo-test-endpoints><?= icon('info', 16) ?><span>Test mode: config.php sends indexing requests to other addresses than Google and IndexNow.</span></div>
  <?php endif; ?>
  <?php if ($noindex): ?>
    <div class="alert alert-error"><?= icon('alert', 16) ?><span>The website is set to noindex (SEO, Indexing &amp; robots meta). Nothing is sent to search engines until that is turned off.</span></div>
  <?php endif; ?>

  <section class="seo-box">
    <div class="row-between"><h3 class="seo-box-title"><?= icon('search', 16) ?> Google Indexing API</h3>
      <?php if ($email): ?><span class="pill <?= Options::get('seo_google_indexing') ? 'pill-success' : '' ?>"><?= Options::get('seo_google_indexing') ? 'On' : 'Key saved, turned off above' ?></span><?php endif; ?></div>
    <p class="help seo-note"><?= icon('info', 14) ?> <span>Google officially supports the Indexing API only for pages with <strong>JobPosting</strong> or <strong>BroadcastEvent</strong> (livestream video) structured data. For other pages a request is a hint: Google may crawl sooner, or ignore it. Your XML sitemap stays the main way Google finds pages.</span></p>
    <?php if ($email): ?>
      <p>Service account: <code><?= e($email) ?></code></p>
      <div class="seo-actions">
        <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url('seo/tools/google-test')) ?>" formnovalidate><?= icon('check-circle', 15) ?> Test connection</button>
        <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url('seo/tools/sitemap')) ?>" formnovalidate><?= icon('upload', 15) ?> Submit sitemap to Search Console</button>
        <button class="btn btn-sm btn-ghost btn-danger" type="submit" formaction="<?= e(admin_url('seo/tools/google-remove')) ?>" formnovalidate data-confirm="Remove the Google service account key?"><?= icon('trash', 14) ?> Remove key</button>
      </div>
      <?php if ($sent = Options::get('seo_gsc_sitemap_sent')): ?><small class="muted">Sitemap last submitted <?= e(time_ago((string)$sent)) ?>.</small><?php endif; ?>
      <details class="seo-details"><summary>Replace the key</summary>
    <?php else: ?>
      <ol class="seo-steps">
        <li>In <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud</a>, pick or create a project and enable the <strong>Web Search Indexing API</strong> (and the <strong>Google Search Console API</strong> to submit sitemaps).</li>
        <li>Under IAM, Service accounts, create a service account, then Keys, Add key, JSON. A .json file downloads.</li>
        <li>In <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a>, Settings, Users and permissions, add the service account's email address as an <strong>Owner</strong>.</li>
        <li>Open the .json file in a text editor and paste all of it below, then save.</li>
      </ol>
    <?php endif; ?>
        <div class="field">
          <label for="seo_google_key">Service account key (JSON)</label>
          <textarea class="input code" id="seo_google_key" name="seo_idx[google_key]" rows="4" spellcheck="false" autocomplete="off"
                    placeholder='{"type": "service_account", "client_email": "…", "private_key": "…"}'></textarea>
          <small class="help">Stored encrypted and never shown again.</small>
        </div>
    <?php if ($email): ?></details><?php endif; ?>
  </section>

  <section class="seo-box">
    <h3 class="seo-box-title"><?= icon('zap', 16) ?> IndexNow</h3>
    <?php if ($inKey !== ''): ?>
      <p>Key file: <a href="<?= e(site_url($inKey . '.txt')) ?>" target="_blank" rel="noopener"><code><?= e(site_url($inKey . '.txt')) ?></code></a></p>
      <div class="seo-actions"><button class="btn btn-sm btn-ghost" type="submit" formaction="<?= e(admin_url('seo/tools/indexnow-key')) ?>" formnovalidate data-confirm="Create a new IndexNow key? The old key file stops working."><?= icon('refresh', 14) ?> New key</button></div>
    <?php else: ?>
      <p class="muted">Turn on IndexNow above. A key is created and served for you; participating search engines share submissions with each other.</p>
    <?php endif; ?>
  </section>

  <section class="seo-box">
    <h3 class="seo-box-title"><?= icon('layers', 16) ?> Submit automatically</h3>
    <p class="help">Content types whose items are sent when they are published, updated, unpublished or trashed (with automatic submission on).</p>
    <input type="hidden" name="seo_idx[types_present]" value="1">
    <div class="choice-list seo-types-list">
      <?php foreach ($public as $slug => $t): ?>
        <label class="choice"><input type="checkbox" name="seo_idx[types][]" value="<?= e($slug) ?>"<?= in_array($slug, $auto, true) ? ' checked' : '' ?>> <?= e($t['plural']) ?> <code class="muted"><?= e($slug) ?></code></label>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="seo-box">
    <h3 class="seo-box-title"><?= icon('upload', 16) ?> Submit now</h3>
    <div class="field">
      <label for="seo_submit_urls">Addresses on this website, one per line</label>
      <textarea class="input" id="seo_submit_urls" name="seo_submit_urls" rows="3" placeholder="<?= e(site_url('example-page/')) ?>"></textarea>
    </div>
    <div class="seo-actions">
      <select class="input input-sm" name="seo_submit_action" aria-label="Kind of change">
        <option value="URL_UPDATED">New or updated</option>
        <option value="URL_DELETED">Deleted</option>
      </select>
      <button class="btn btn-sm btn-primary" type="submit" name="seo_submit_mode" value="urls" formaction="<?= e(admin_url('seo/tools/submit')) ?>" formnovalidate>Submit addresses</button>
    </div>
    <div class="seo-actions">
      <select class="input input-sm" name="seo_submit_type" aria-label="Content type">
        <option value="">Or every published item of a type…</option>
        <?php foreach ($public as $slug => $t): ?><option value="<?= e($slug) ?>"><?= e($t['plural']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm" type="submit" name="seo_submit_mode" value="type" formaction="<?= e(admin_url('seo/tools/submit')) ?>" formnovalidate>Submit type</button>
      <small class="muted">The 500 newest. Google requests above the daily limit wait in the queue.</small>
    </div>
  </section>

  <section class="seo-box">
    <div class="row-between">
      <h3 class="seo-box-title"><?= icon('activity', 16) ?> Recent submissions</h3>
      <span class="small muted">
        <?= number_format($counts['pending']) ?> waiting · <?= number_format($counts['failed']) ?> failed
        <?php if (Indexing::googleEnabled()): ?> · Google today: <?= number_format(Indexing::googleUsed()) ?> of <?= number_format($quota) ?><?php endif; ?>
      </span>
    </div>
    <div class="seo-actions">
      <button class="btn btn-sm" type="submit" name="do" value="send" formaction="<?= e(admin_url('seo/tools/queue')) ?>" formnovalidate><?= icon('refresh', 14) ?> Send waiting now</button>
      <?php if ($counts['failed']): ?><button class="btn btn-sm" type="submit" name="do" value="retry" formaction="<?= e(admin_url('seo/tools/queue')) ?>" formnovalidate>Retry failed</button><?php endif; ?>
      <?php if ($counts['pending']): ?><button class="btn btn-sm btn-ghost" type="submit" name="do" value="clear" formaction="<?= e(admin_url('seo/tools/queue')) ?>" formnovalidate data-confirm="Remove every waiting submission from the queue?">Clear queue</button><?php endif; ?>
    </div>
    <?php if ($recent): ?>
    <div class="table-wrap"><table class="table seo-log">
      <thead><tr><th>When</th><th>Engine</th><th>Address</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $row): ?>
        <tr data-engine="<?= e($row['engine']) ?>" data-status="<?= e($row['status']) ?>">
          <td class="nowrap"><?= e(time_ago($row['sent_at'] ?: $row['created_at'])) ?></td>
          <td><?= $row['engine'] === 'google' ? 'Google' : 'IndexNow' ?><br><small class="muted"><?= $row['action'] === 'URL_DELETED' ? 'deleted' : 'updated' ?><?= $row['source'] === 'manual' ? ', on request' : '' ?></small></td>
          <td class="seo-log-url"><a href="<?= e($row['url']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://[^/]+#', '', (string)$row['url']) ?: '/') ?></a></td>
          <td><span class="pill <?= $statusPill((string)$row['status']) ?>"><?= e($row['status'] === 'pending' ? 'waiting' : $row['status']) ?></span></td>
          <td class="small muted"><?= $row['response_code'] ? 'HTTP ' . (int)$row['response_code'] . '. ' : '' ?><?= e(str_limit((string)$row['response'], 160)) ?><?= $row['attempts'] > 1 ? ' (' . (int)$row['attempts'] . ' attempts)' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
      <p class="muted">Nothing sent yet.</p>
    <?php endif; ?>
  </section>
</div>
