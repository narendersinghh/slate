<?php
/** @var array $type @var array $post @var array $list @var ?array $rev */
use Slate\Revisions;
$slug = $post['type'];
$editUrl = admin_url("content/$slug/{$post['id']}");
?>
<div class="page-head">
  <div><h1>Revisions</h1><p class="muted"><a href="<?= e($editUrl) ?>">← <?= e($post['title'] ?: '(no title)') ?></a> · <?= count($list) ?> saved version<?= count($list) === 1 ? '' : 's' ?></p></div>
</div>
<?php if (!$list): ?>
  <div class="card empty-state"><?= icon('refresh', 36) ?><h2>No revisions yet</h2><p class="muted">Each time you save changes in the editor, the previous version is kept here.</p></div>
<?php else: ?>
<div class="revisions-layout">
  <nav class="card revisions-list" aria-label="Revisions">
    <ul class="list">
      <?php foreach ($list as $r): ?>
        <li class="<?= $rev && (int)$rev['id'] === (int)$r['id'] ? 'active' : '' ?>">
          <a class="list-main" href="<?= e(admin_url("content/$slug/{$post['id']}/revisions?rev={$r['id']}")) ?>"><span>
            <strong><?= e(local_date($r['created_at'], 'M j, Y g:i a')) ?></strong>
            <small><?= e($r['display_name'] ?: 'Unknown') ?> · <?= e(time_ago($r['created_at'])) ?></small></span></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php if ($rev): ?>
  <section class="card revisions-compare">
    <div class="card-head">
      <h2>Saved <?= e(local_date($rev['created_at'], 'M j, Y g:i a')) ?> <small class="muted">→ current</small></h2>
      <form method="post" action="<?= e(admin_url("content/$slug/{$post['id']}/revisions/{$rev['id']}/restore")) ?>"><?= csrf_field() ?>
        <button class="btn btn-primary" data-confirm="Restore this version? The current version is kept as a revision.">Restore this version</button></form>
    </div>
    <div class="card-pad stack">
      <?php
      $sections = [
          'Title' => [(string)$rev['title'], (string)$post['title']],
          'Content' => [(string)$rev['content'], (string)$post['content']],
          'Excerpt' => [(string)$rev['excerpt'], (string)$post['excerpt']],
      ];
      $flat = static function (array $a): string {
          $out = [];
          foreach ($a as $k => $v) {
              $out[] = $k . ': ' . (is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
          }
          sort($out);
          return implode("\n", $out);
      };
      $sections['Custom fields'] = [$flat($rev['fields']), $flat($post['fields'])];
      $sections['SEO'] = [$flat($rev['seo']), $flat($post['seo'])];
      $same = true;
      foreach ($sections as $label => [$a, $b]):
          if ($a === $b) {
              continue;
          }
          $same = false;
          $d = Revisions::diff($a, $b);
      ?>
        <div><strong class="small-label"><?= e($label) ?></strong>
          <div class="diff"><?php
            // Show changes with two lines of context; collapse long unchanged stretches.
            $keep = [];
            foreach ($d as $i => [$op]) {
                if ($op !== '=') {
                    for ($k = max(0, $i - 2); $k <= min(count($d) - 1, $i + 2); $k++) {
                        $keep[$k] = true;
                    }
                }
            }
            $gap = false;
            foreach ($d as $i => [$op, $line]):
                if (!isset($keep[$i])) {
                    if (!$gap) { echo '<div class="diff-gap">⋯</div>'; }
                    $gap = true;
                    continue;
                }
                $gap = false; ?>
            <div class="diff-<?= $op === '+' ? 'add' : ($op === '-' ? 'del' : 'eq') ?>"><span><?= $op === '=' ? ' ' : e($op) ?></span><?= e($line) ?></div>
          <?php endforeach; ?></div></div>
      <?php endforeach; ?>
      <?php if ($same): ?><p class="muted">This revision is identical to the current version.</p><?php endif; ?>
      <p class="muted small"><span class="diff-legend diff-del">− removed since this revision</span> <span class="diff-legend diff-add">+ added since</span></p>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>
