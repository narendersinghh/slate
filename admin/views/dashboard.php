<?php
use Slate\Auth;
use Slate\ContentModel;
use Slate\Posts;

$hour = (int)local_date(now(), 'G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="dash-top">
  <div><h1><?= e($greet) ?>, <?= e(explode(' ', Auth::user()['display_name'])[0]) ?></h1><p class="muted"><?= e(local_date(now(), 'l, F j')) ?> · <?= e(site_name()) ?></p></div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url()) ?>" target="_blank" rel="noopener"><?= icon('eye', 16) ?> View site</a>
    <?php if (Auth::can('edit_posts')): ?><a class="btn btn-primary" href="<?= e(admin_url('content/post/new')) ?>"><?= icon('edit', 16) ?> Write a post</a><?php endif; ?>
  </div>
</div>

<?php if (\Slate\Auth::can('manage_updates') && ($ir = \Slate\Integrity::report()) && ($bad = count(array_filter($ir['issues'], static fn($i) => $i['level'] === 'danger')))): ?>
<div class="banner banner-danger"><span class="ic"><?= icon('shield', 18) ?></span>
  <div><strong><?= $bad ?> file<?= $bad === 1 ? '' : 's' ?> changed unexpectedly.</strong> The file integrity check found changes nobody made through Slate.</div>
  <a class="btn btn-sm" href="<?= e(admin_url('settings/security/check')) ?>">Review</a>
</div>
<?php endif; ?>
<?php if (!empty($update) && version_compare($update['latest'] ?? '0', SLATE_VERSION, '>')): ?>
<div class="banner banner-accent">
  <?= icon('download', 18) ?>
  <div><strong>Slate <?= e($update['latest']) ?> is available.</strong> You are on <?= e(SLATE_VERSION) ?>.</div>
  <a class="btn btn-sm btn-primary" href="<?= e(admin_url('settings/system/updates')) ?>">Review update</a>
</div>
<?php endif; ?>



<div class="stat-grid stat-grid-compact">
  <?php foreach ($stats as $slug => $st): ?>
  <a class="stat" href="<?= e(admin_url("content/$slug")) ?>">
    <span class="stat-top"><span class="stat-icon"><?= icon($st['type']['icon'] ?? 'file', 17) ?></span><span class="stat-arrow"><?= icon('arrow-up-right', 16) ?></span></span>
    <span class="stat-value"><?= number_format($st['count']) ?></span>
    <span class="stat-label"><?= e($st['type']['plural']) ?> <span class="stat-hint">· published</span></span>
  </a>
  <?php endforeach; ?>
  <a class="stat" href="<?= e(admin_url('media')) ?>">
    <span class="stat-top"><span class="stat-icon"><?= icon('image', 17) ?></span><span class="stat-arrow"><?= icon('arrow-up-right', 16) ?></span></span>
    <span class="stat-value"><?= number_format($media['count']) ?></span>
    <span class="stat-label">Media files <span class="stat-hint">· <?= e(format_bytes($media['saved'])) ?> saved</span></span>
  </a>
</div>

<div class="dash-layout">
<div class="dash-main grid-2">
  <section class="card">
    <div class="card-head"><h2>Recently edited</h2></div>
    <?php if (!$recent): ?>
      <div class="card-pad muted">Nothing yet. <a href="<?= e(admin_url('content/post/new')) ?>">Write your first post →</a></div>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($recent as $p): $t = ContentModel::type($p['type']); ?>
      <li>
        <a class="list-main" href="<?= e(admin_url("content/{$p['type']}/{$p['id']}")) ?>">
          <span class="list-icon"><?= icon($t['icon'] ?? 'file', 16) ?></span>
          <span><strong><?= e($p['title'] ?: '(no title)') ?></strong><small><?= e($t['singular'] ?? $p['type']) ?> · <?= e(time_ago($p['updated_at'])) ?></small></span>
        </a>
        <span class="status status-<?= e($p['status']) ?>"><?= e(Posts::STATUSES[$p['status']] ?? $p['status']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>SEO health</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('settings/seo/titles')) ?>">SEO settings</a></div>
    <?php if (!$seoIssues): ?>
      <div class="card-pad"><div class="alert alert-success"><?= icon('check', 16) ?> Everything looks good.</div></div>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($seoIssues as [$level, $msg, $link]): ?>
      <li><a class="list-main" href="<?= e($link) ?>"><span class="dot dot-<?= e($level) ?>"></span><span><?= e($msg) ?></span></a><?= icon('chevron-right', 16) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <?php if (Auth::can('edit_posts')): ?>
  <section class="card">
    <div class="card-head"><h2>Quick draft</h2></div>
    <form class="card-pad stack" method="post" action="<?= e(admin_url('content/post/save')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="status" value="draft">
      <input class="input" name="title" placeholder="Title" required>
      <textarea class="input" name="content" rows="3" placeholder="What’s on your mind?"></textarea>
      <div><button class="btn btn-primary" type="submit">Save draft</button></div>
    </form>
    <?php if ($drafts): ?>
    <ul class="list list-compact">
      <?php foreach ($drafts as $d): ?>
        <li><a class="list-main" href="<?= e(admin_url("content/{$d['type']}/{$d['id']}")) ?>"><span><?= e($d['title'] ?: '(no title)') ?> <small><?= e(time_ago($d['updated_at'])) ?></small></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Activity</h2></div>
    <?php if (!$activity): ?>
      <div class="card-pad muted">No activity yet.</div>
    <?php else: ?>
    <ul class="list list-compact">
      <?php foreach ($activity as $a): ?>
        <li><span class="list-main"><span class="list-icon"><?= icon('activity', 14) ?></span><span><?= e($a['display_name'] ?: 'System') ?> · <?= e(str_replace('_', ' ', $a['action'])) ?> <small><?= e($a['object']) ?></small></span></span><small class="muted"><?= e(time_ago($a['created_at'])) ?></small></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
<aside class="dash-side">
  <?php if (\Slate\Auth::can('manage_settings') || \Slate\Auth::can('manage_updates')): ?>
  <section class="card">
    <div class="card-head"><h2>Site status</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('settings')) ?>">Settings</a></div>
    <?php $compact = true; require __DIR__ . '/partials/site-status.php'; ?>
  </section>
  <?php endif; ?>
  <?php if (\Slate\Auth::can('manage_settings')): ?>
  <section class="card">
    <div class="card-head"><h2>Settings</h2></div>
    <nav class="dash-links">
      <?php foreach (\Slate\Settings::tabs() as $tk => $t): if (!admin_can_settings($tk)) continue; ?>
        <a href="<?= e(admin_url("settings/$tk/" . array_key_first($t['sections']))) ?>"><?= icon($t['icon'], 15) ?><span><?= e($t['label']) ?></span><?= icon('chevron-right', 14) ?></a>
      <?php endforeach; ?>
    </nav>
  </section>
  <?php endif; ?>
</aside>
</div>
