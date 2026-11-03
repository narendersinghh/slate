<?php $cur = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH); ?>
<nav class="tabs-line" aria-label="Content model">
  <a href="<?= e(admin_url('model/types')) ?>" class="<?= str_contains($cur, '/model/types') ? 'active' : '' ?>"><?= icon('box', 15) ?> Post types</a>
  <a href="<?= e(admin_url('model/taxonomies')) ?>" class="<?= str_contains($cur, '/model/taxonomies') ? 'active' : '' ?>"><?= icon('tag', 15) ?> Taxonomies</a>
  <a href="<?= e(admin_url('model/fields')) ?>" class="<?= str_contains($cur, '/model/fields') ? 'active' : '' ?>"><?= icon('list', 15) ?> Custom fields</a>
</nav>
