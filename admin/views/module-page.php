<?php if (!str_contains(substr($html, 0, 600), 'class="mod-head"')): ?><div class="page-head"><h1><?= e($title) ?></h1></div><?php endif; ?>
<div class="module-page"><?= $html ?></div>
