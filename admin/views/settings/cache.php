<?php $st = \Slate\PageCache::stats(); ?>
<div class="card-pad">
  <div class="banner">
    <?= icon('zap', 18) ?>
    <div><strong><?= (int)$st['count'] ?> pages cached</strong> (<?= e(format_bytes($st['size'])) ?>). Cached pages are served without loading PHP classes or touching the database.</div>
    <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url('system/cache/clear')) ?>"><?= icon('refresh', 15) ?> Clear cache</button>
  </div>
</div>
