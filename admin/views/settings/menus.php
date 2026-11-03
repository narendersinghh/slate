<?php
use Slate\ContentModel;
use Slate\Menus;
use Slate\Theme;
$pages = \Slate\Posts::query(['type' => array_keys(array_filter(ContentModel::types(), fn($t) => !empty($t['public']))), 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC', 'count' => false])['items'];
$termsList = [];
foreach (ContentModel::taxonomies() as $tx => $t) { foreach (\Slate\Terms::all($tx) as $term) { $termsList[] = ['id' => $term['id'], 'label' => $term['name'], 'group' => $t['plural']]; } }
?>
<input type="hidden" name="menus_json" data-menus-json>
<script type="application/json" data-menus><?= json_encode(array_values(Menus::all()), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" data-locations><?= json_encode(Theme::meta()['menus'], JSON_HEX_TAG) ?></script>
<script type="application/json" data-menu-sources><?= json_encode([
    'posts' => array_map(fn($p) => ['id' => $p['id'], 'label' => $p['title'], 'group' => ContentModel::type($p['type'])['plural'] ?? $p['type']], $pages),
    'terms' => $termsList,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
<div class="card-pad menu-builder" data-menu-builder></div>
