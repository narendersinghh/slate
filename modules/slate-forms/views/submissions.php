<?php
use Slate\DB;
use Slate\Posts;
use SlateModules\Forms\Forms;

$self = admin_url('m/submissions');
$current = $self . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

// ---------------------------------------------------------------- single submission
if (($sid = (int)($_GET['id'] ?? 0)) > 0):
    $s = DB::row('SELECT * FROM {{form_submissions}} WHERE id = :id', ['id' => $sid]);
    if (!$s): ?>
      <div class="card empty-state"><?= icon('message', 32) ?><h2>Submission not found</h2><p><a href="<?= e($self) ?>">Back to all submissions</a></p></div>
    <?php return; endif;
    if ($s['status'] === 'new') {
        DB::update('form_submissions', ['status' => 'read'], ['id' => $sid]);
        $s['status'] = 'read';
    }
    $rows = Forms::rows($s);
    $email = '';
    foreach ($rows as $r) {
        if (filter_var($r['value'], FILTER_VALIDATE_EMAIL)) { $email = $r['value']; break; }
    }
    $prev = DB::val('SELECT id FROM {{form_submissions}} WHERE id > :id ORDER BY id ASC LIMIT 1', ['id' => $sid]);
    $next = DB::val('SELECT id FROM {{form_submissions}} WHERE id < :id ORDER BY id DESC LIMIT 1', ['id' => $sid]);
?>
<div class="row-between" style="margin-bottom:16px">
  <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>"><?= icon('chevron-left', 15) ?> All submissions</a>
  <span>
    <?php if ($prev): ?><a class="btn btn-sm" href="<?= e($self . '?id=' . $prev) ?>">Newer</a><?php endif; ?>
    <?php if ($next): ?><a class="btn btn-sm" href="<?= e($self . '?id=' . $next) ?>">Older</a><?php endif; ?>
  </span>
</div>
<div class="split split-wide">
  <section class="card">
    <div class="card-head"><h2><?= e($s['form_title']) ?></h2><span class="pill<?= $s['status'] === 'spam' ? '' : ' pill-soft' ?>"><?= e(Forms::STATUSES[$s['status']] ?? $s['status']) ?></span></div>
    <div class="card-pad">
      <dl class="sub-detail">
        <?php foreach ($rows as $r): ?>
          <dt><?= e($r['label']) ?></dt>
          <dd><?= $r['value'] === '' ? '<span class="muted">—</span>' : (filter_var($r['value'], FILTER_VALIDATE_URL) ? '<a href="' . e($r['value']) . '" target="_blank" rel="noopener noreferrer">' . e($r['value']) . '</a>' : e($r['value'])) ?></dd>
        <?php endforeach; ?>
      </dl>
    </div>
  </section>
  <aside class="stack">
    <section class="card"><div class="card-pad stack-sm">
      <div><strong class="small-label">Received</strong><div><?= e(local_date($s['created_at'], 'M j, Y H:i')) ?></div></div>
      <?php if ($s['page_url']): ?><div><strong class="small-label">Page</strong><div><a href="<?= e($s['page_url']) ?>" target="_blank" rel="noopener"><?= e($s['page_title'] ?: $s['page_url']) ?></a></div></div><?php endif; ?>
      <div><strong class="small-label">IP address</strong><div><?= e($s['ip'] ?: '—') ?></div></div>
      <?php if ($s['user_agent']): ?><div><strong class="small-label">Browser</strong><div class="small muted"><?= e($s['user_agent']) ?></div></div><?php endif; ?>
      <?php if ($s['source'] !== 'slate'): ?><div><strong class="small-label">Source</strong><div>Imported from the previous website</div></div><?php endif; ?>
    </div></section>
    <form method="post" action="<?= e($self . '/bulk') ?>" class="card"><div class="card-pad stack-sm">
      <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= (int)$sid ?>"><input type="hidden" name="return" value="<?= e($self . '?id=' . $sid) ?>">
      <?php if ($email): ?><a class="btn btn-primary" href="mailto:<?= e($email) ?>?subject=<?= rawurlencode('Re: ' . $s['form_title']) ?>"><?= icon('message', 15) ?> Reply by email</a><?php endif; ?>
      <button class="btn" name="action" value="new">Mark as unread</button>
      <?php if ($s['status'] === 'spam'): ?><button class="btn" name="action" value="read">Not spam</button><?php else: ?><button class="btn" name="action" value="spam">Mark as spam</button><?php endif; ?>
      <button class="btn btn-ghost btn-danger" name="action" value="delete" data-confirm="Delete this submission permanently?" onclick="this.form.return.value='<?= e($self) ?>'">Delete</button>
    </div></form>
  </aside>
</div>
<?php return; endif;

// ---------------------------------------------------------------- list
[$where, $params] = forms_filter_sql();
$page = max(1, (int)($_GET['pg'] ?? 1));
$per = 50;
$total = (int)DB::val("SELECT COUNT(*) FROM {{form_submissions}} $where", $params);
$items = DB::all("SELECT * FROM {{form_submissions}} $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$counts = array_column(DB::all('SELECT status, COUNT(*) c FROM {{form_submissions}} GROUP BY status'), 'c', 'status');
$forms = Posts::query(['type' => Forms::TYPE, 'status' => 'any', 'limit' => 200, 'count' => false, 'orderby' => 'title', 'order' => 'ASC'])['items'];
$status = (string)($_GET['status'] ?? '');
$qs = static function (array $set) {
    $q = array_merge(array_intersect_key($_GET, array_flip(['form', 'status', 'q'])), $set);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return admin_url('m/submissions') . ($q ? '?' . http_build_query($q) : '');
};
?>
<div class="row-between" style="margin:-8px 0 16px;gap:12px;flex-wrap:wrap">
  <p class="muted" style="margin:0">Every form on your website sends its entries here. Build forms under <a href="<?= e(admin_url('content/form')) ?>">Content → Forms</a>.</p>
  <span>
    <a class="btn btn-sm" href="<?= e(admin_url('content/form/new')) ?>"><?= icon('plus', 15) ?> New form</a>
    <a class="btn btn-sm" href="<?= e(str_replace('m/submissions', 'm/submissions/export', $qs([]))) ?>"><?= icon('download', 15) ?> Export CSV</a>
  </span>
</div>
<div class="tabs-inline" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
  <?php foreach (['' => 'Inbox', 'new' => 'New', 'read' => 'Read', 'spam' => 'Spam', 'all' => 'All'] as $k => $l):
    $n = $k === '' ? (int)($counts['new'] ?? 0) + (int)($counts['read'] ?? 0) : ($k === 'all' ? array_sum($counts) : (int)($counts[$k] ?? 0)); ?>
    <a class="btn btn-sm<?= $status === $k ? ' btn-primary' : ' btn-ghost' ?>" href="<?= e($qs(['status' => $k, 'pg' => null])) ?>"><?= e($l) ?> <span class="muted"><?= number_format($n) ?></span></a>
  <?php endforeach; ?>
</div>
<form class="filter-bar" method="get" action="<?= e($self) ?>">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <select class="input input-sm" name="form" onchange="this.form.submit()">
    <option value="">All forms</option>
    <?php foreach ($forms as $fm): ?><option value="<?= (int)$fm['id'] ?>"<?= (int)($_GET['form'] ?? 0) === (int)$fm['id'] ? ' selected' : '' ?>><?= e($fm['title']) ?></option><?php endforeach; ?>
  </select>
  <input class="input input-sm" type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="Search submissions…">
  <button class="btn btn-sm">Filter</button>
</form>
<?php if (!$items): ?>
  <div class="card empty-state"><?= icon('message', 32) ?><h2>No submissions here</h2><p class="muted">New entries appear as soon as visitors send a form.</p></div>
<?php else: ?>
<form method="post" action="<?= e($self . '/bulk') ?>">
  <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($current) ?>">
  <div class="filter-bar">
    <select class="input input-sm" name="action"><option value="read">Mark as read</option><option value="new">Mark as unread</option><option value="spam">Mark as spam</option><option value="delete">Delete</option></select>
    <button class="btn btn-sm" data-confirm="Apply to the selected submissions?">Apply to selected</button>
    <span class="muted small"><?= number_format($total) ?> submission<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="card"><div class="table-wrap"><table class="table sub-table">
    <thead><tr><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('[data-sub-cb]').forEach(c=>c.checked=this.checked)" aria-label="Select all"></th><th>Received</th><th>Form</th><th>Details</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $s): $rows = array_slice(Forms::rows($s), 0, 3); ?>
      <tr<?= $s['status'] === 'new' ? ' style="font-weight:600"' : '' ?>>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" data-sub-cb aria-label="Select"></td>
        <td class="nowrap"><a href="<?= e($self . '?id=' . $s['id']) ?>"><?= e(local_date($s['created_at'], 'M j, Y H:i')) ?></a><br><small class="muted"><?= e($s['page_title'] ?: (string)parse_url($s['page_url'], PHP_URL_PATH)) ?></small></td>
        <td><?= e($s['form_title']) ?><?= $s['status'] === 'new' ? ' <span class="pill pill-accent">New</span>' : ($s['status'] === 'spam' ? ' <span class="pill">Spam</span>' : '') ?></td>
        <td><?php foreach ($rows as $r): ?><div><span class="muted"><?= e($r['label']) ?>:</span> <?= e(mb_strimwidth($r['value'], 0, 90, '…')) ?></div><?php endforeach; ?></td>
        <td><a class="icon-btn" href="<?= e($self . '?id=' . $s['id']) ?>" title="Open"><?= icon('eye', 15) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</form>
<?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
  <div class="row-between" style="margin-top:12px">
    <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
    <span>
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($qs(['pg' => $page - 1])) ?>">Previous</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($qs(['pg' => $page + 1])) ?>">Next</a><?php endif; ?>
    </span>
  </div>
<?php endif; ?>
<?php endif; ?>
