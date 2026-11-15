<?php $st = \Slate\PageCache::stats(); ?>
<div class="card-pad">
  <div class="banner">
    <?= icon('zap', 18) ?>
    <div><strong><?= (int)$st['count'] ?> cached files</strong> (<?= e(format_bytes($st['size'])) ?>). Cached pages are served without touching the database; refreshing is instant at any size.</div>
    <button class="btn btn-sm" type="submit" formaction="<?= e(admin_url('system/cache/clear')) ?>"><?= icon('refresh', 15) ?> Clear cache</button>
  </div>
</div>
