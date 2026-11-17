<?php
/** Menu builder: search content and terms to add (works with thousands of items). */
use Slate\Auth;
use Slate\ContentModel;
use Slate\DB;
use Slate\Posts;
use Slate\Router;
use Slate\Terms;

return static function (Router $r): void {
    $r->get('/menus/search', static function () {
        Auth::require('manage_menus');
        $q = trim((string)($_GET['q'] ?? ''));
        $kind = ($_GET['kind'] ?? 'post') === 'term' ? 'term' : 'post';
        $filter = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['type'] ?? ''));
        $out = [];
        if ($kind === 'post') {
            $types = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
            if ($filter !== '' && in_array($filter, $types, true)) {
                $types = [$filter];
            }
            $params = [];
            foreach (array_values($types) as $i => $t) {
                $params['t' . $i] = $t;
            }
            $in = implode(',', array_map(static fn($k) => ':' . $k, array_keys($params)));
            $sql = "SELECT * FROM {{posts}} WHERE type IN ($in) AND status IN ('publish', 'private')";
            if ($q !== '') {
                $sql .= ' AND title LIKE :q ORDER BY (title LIKE :qs) DESC, LENGTH(title), title';
                $params['q'] = '%' . $q . '%';
                $params['qs'] = $q . '%';
            } else {
                $sql .= ' ORDER BY updated_at DESC';
            }
            $res = ['items' => DB::all($sql . ' LIMIT 30', $params)];
            foreach ($res['items'] as $p) {
                $out[] = ['id' => (int)$p['id'], 'label' => $p['title'] ?: '(no title)', 'group' => ContentModel::type($p['type'])['singular'] ?? $p['type'],
                    'url' => Posts::permalink($p)];
            }
        } else {
            $taxes = array_keys(ContentModel::taxonomies());
            if ($filter !== '' && in_array($filter, $taxes, true)) {
                $taxes = [$filter];
            }
            [$in, $params] = [implode(',', array_map(static fn($i) => ':t' . $i, array_keys($taxes))), []];
            foreach ($taxes as $i => $t) {
                $params['t' . $i] = $t;
            }
            $sql = "SELECT * FROM {{terms}} WHERE taxonomy IN ($in)" . ($q !== '' ? ' AND name LIKE :q' : '') . ' ORDER BY ' . ($q !== '' ? '(name LIKE :qs) DESC, LENGTH(name), name' : 'count DESC') . ' LIMIT 30';
            if ($q !== '') {
                $params['q'] = '%' . $q . '%';
                $params['qs'] = $q . '%';
            }
            foreach (DB::all($sql, $params) as $t) {
                $t = Terms::hydrate($t);
                $out[] = ['id' => (int)$t['id'], 'label' => $t['name'], 'group' => ContentModel::taxonomy($t['taxonomy'])['singular'] ?? $t['taxonomy'],
                    'url' => Terms::link($t)];
            }
        }
        json_response(['ok' => true, 'items' => $out]);
    });
};
