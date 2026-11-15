<?php
use Slate\Posts;

$slug = $type['slug'];
$base = admin_url("content/$slug");
?>
<div class="page-head">
  <div>
    <h1><?= e($type['plural']) ?></h1>
    <?php if (!empty($type['description'])): ?><p class="muted"><?= e($type['description']) ?></p><?php endif; ?>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="<?= e($base . '/new') ?>"><?= icon('plus', 16) ?> Add <?= e(strtolower($type['singular'])) ?></a>
  </div>
</div>

<?= $table->render($result['items'], $result['total']) ?>

<template id="quick-edit-tpl">
  <form method="post" class="stack quick-edit">
    <?= csrf_field() ?>
    <label class="field"><span>Title</span><input class="input" name="title" required></label>
    <label class="field"><span>Slug</span><input class="input" name="slug" spellcheck="false"></label>
    <div class="grid-2-tight">
      <label class="field"><span>Status</span><select class="input" name="status">
        <?php foreach (Posts::STATUSES as $k => $label): if ($k === 'trash') continue; ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span>Publish date</span><input class="input" type="datetime-local" name="published_at"></label>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</template>
