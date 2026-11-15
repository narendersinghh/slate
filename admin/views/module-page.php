<?php if (!str_contains(substr($html, 0, 600), 'class="mod-head"')): ?>
<div class="page-head"><div><?php if (!empty($module)): ?><div class="crumbs"><span><?= e($module) ?></span></div><?php endif; ?><h1><?= e($title) ?></h1></div></div>
<?php endif; ?>
<div class="module-page"><?= $html ?></div>
