<?php
/** Menu builder: menus are edited in the browser (admin/assets/menus.js) and saved as JSON with the settings form. */
use Slate\ContentModel;
use Slate\DB;
use Slate\Menus;
use Slate\Theme;

// Labels and links of the items already in menus (only those, not the whole site).
$postIds = [];
$termIds = [];
$walk = static function (array $items) use (&$walk, &$postIds, &$termIds): void {
    foreach ($items as $it) {
        if (($it['type'] ?? '') === 'post') {
            $postIds[] = (int)$it['object_id'];
        } elseif (($it['type'] ?? '') === 'term') {
            $termIds[] = (int)$it['object_id'];
        }
        $walk((array)($it['children'] ?? []));
    }
};
foreach (Menus::all() as $m) {
    $walk((array)($m['items'] ?? []));
}
$refs = ['post' => [], 'term' => []];
if ($postIds) {
    [$in, $params] = DB::in($postIds);
    foreach (DB::all("SELECT id, type, title, slug, status, parent_id, published_at FROM {{posts}} WHERE id IN ($in)", $params) as $p) {
        $refs['post'][$p['id']] = ['label' => $p['title'], 'group' => ContentModel::type($p['type'])['singular'] ?? $p['type'], 'missing' => $p['status'] === 'trash'];
    }
}
if ($termIds) {
    [$in, $params] = DB::in($termIds);
    foreach (DB::all("SELECT id, taxonomy, name FROM {{terms}} WHERE id IN ($in)", $params) as $t) {
        $refs['term'][$t['id']] = ['label' => $t['name'], 'group' => ContentModel::taxonomy($t['taxonomy'])['singular'] ?? $t['taxonomy']];
    }
}
$types = array_map(static fn($t) => $t['plural'], array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
$taxes = array_map(static fn($t) => $t['plural'], ContentModel::taxonomies());
?>
<input type="hidden" name="menus_json" data-menus-json>
<script type="application/json" data-menu-config><?= json_encode([
    'menus' => array_values(Menus::all()),
    'locations' => (object)(Theme::meta()['menus'] ?? []),
    'refs' => $refs,
    'types' => (object)$types,
    'taxes' => (object)$taxes,
    'search' => admin_url('menus/search'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<div class="mb" data-menu-builder></div>
