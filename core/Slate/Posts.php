<?php
declare(strict_types=1);

namespace Slate;

/** Content repository for all post types. */
final class Posts
{
    public const STATUSES = ['publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending review', 'private' => 'Private', 'scheduled' => 'Scheduled', 'trash' => 'Trash'];
    private const ORDERBY = ['published_at', 'created_at', 'updated_at', 'title', 'menu_order', 'id'];

    private static array $cache = [];

    public static function hydrate(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['fields'] = json_decode((string)($row['fields'] ?? ''), true) ?: [];
        $row['seo'] = json_decode((string)($row['seo'] ?? ''), true) ?: [];
        return self::$cache[$row['id']] = $row;
    }

    /** Drop the in-memory cache (after direct DB updates). */
    public static function flush(?int $id = null): void
    {
        if ($id === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$id]);
        }
    }

    public static function find(int $id): ?array
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        return self::hydrate(DB::row('SELECT * FROM {{posts}} WHERE id = :id', ['id' => $id]));
    }

    public static function findBySlug(string $type, string $slug, bool $publishedOnly = true): ?array
    {
        $sql = 'SELECT * FROM {{posts}} WHERE type = :t AND slug = :s';
        if ($publishedOnly) {
            $sql .= " AND status = 'publish'";
        }
        return self::hydrate(DB::row($sql . ' LIMIT 1', ['t' => $type, 's' => $slug]));
    }

    /** Preload many posts by id in a single query (used for relations, menus, galleries). */
    public static function prime(array $ids): void
    {
        $ids = array_diff(array_unique(array_map('intval', $ids)), array_keys(self::$cache));
        if (!$ids) {
            return;
        }
        [$in, $params] = DB::in($ids);
        foreach (DB::all("SELECT * FROM {{posts}} WHERE id IN ($in)", $params) as $row) {
            self::hydrate($row);
        }
    }

    /**
     * @param array{type?:string|array, status?:string|array|null, limit?:int, page?:int, orderby?:string, order?:string,
     *   search?:string, term?:int|array, taxonomy?:string, parent?:int, author?:int, ids?:array, exclude?:array, with_terms?:bool, count?:bool} $args
     * @return array{items:array, total:int, pages:int, page:int}
     */
    public static function query(array $args = []): array
    {
        $where = [];
        $params = [];
        $joins = '';

        $types = (array)($args['type'] ?? 'post');
        $tp = [];
        foreach ($types as $i => $t) {
            $tp[] = ":type$i";
            $params["type$i"] = $t;
        }
        $where[] = 'p.type IN (' . implode(',', $tp) . ')';

        $status = array_key_exists('status', $args) ? $args['status'] : 'publish';
        if ($status === 'any') {
            $where[] = "p.status <> 'trash'";
        } elseif ($status !== null) {
            $sp = [];
            foreach ((array)$status as $i => $s) {
                $sp[] = ":status$i";
                $params["status$i"] = $s;
            }
            $where[] = 'p.status IN (' . implode(',', $sp) . ')';
        }
        if (!empty($args['search'])) {
            $where[] = '(p.title LIKE :q OR p.content LIKE :q2)';
            $params['q'] = $params['q2'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $args['search']) . '%';
        }
        if (isset($args['parent'])) {
            $where[] = $args['parent'] ? 'p.parent_id = :parent' : 'p.parent_id IS NULL';
            if ($args['parent']) {
                $params['parent'] = (int)$args['parent'];
            }
        }
        if (!empty($args['author'])) {
            $where[] = 'p.author_id = :author';
            $params['author'] = (int)$args['author'];
        }
        if (!empty($args['ids'])) {
            [$in, $p] = DB::in($args['ids'], 'pid');
            $where[] = "p.id IN ($in)";
            $params += $p;
        }
        if (!empty($args['exclude'])) {
            [$in, $p] = DB::in($args['exclude'], 'ex');
            $where[] = "p.id NOT IN ($in)";
            $params += $p;
        }
        if (!empty($args['term'])) {
            $termIds = (array)$args['term'];
            if (!is_numeric(reset($termIds)) && !empty($args['taxonomy'])) {
                $termIds = array_filter(array_map(static fn($s) => Terms::findBySlug($args['taxonomy'], (string)$s)['id'] ?? null, $termIds));
            }
            [$in, $p] = DB::in($termIds ?: [0], 'term');
            $joins .= " INNER JOIN {{term_relationships}} tr ON tr.post_id = p.id AND tr.term_id IN ($in)";
            $params += $p;
        }

        $orderby = in_array($args['orderby'] ?? '', self::ORDERBY, true) ? $args['orderby'] : 'published_at';
        $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min(500, (int)($args['limit'] ?? Options::get('posts_per_page', 10))));
        $page = max(1, (int)($args['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $whereSql = implode(' AND ', $where);
        $distinct = $joins ? 'DISTINCT ' : '';
        $total = 0;
        if (($args['count'] ?? true) !== false) {
            $total = (int)DB::val("SELECT COUNT({$distinct}p.id) FROM {{posts}} p$joins WHERE $whereSql", $params);
        }
        $rows = DB::all(
            "SELECT {$distinct}p.* FROM {{posts}} p$joins WHERE $whereSql ORDER BY p.$orderby $order, p.id DESC LIMIT $limit OFFSET $offset",
            $params
        );
        $items = array_map([self::class, 'hydrate'], $rows);
        if (!empty($args['with_terms']) && $items) {
            $map = Terms::forPosts(array_column($items, 'id'));
            foreach ($items as &$item) {
                $item['terms'] = $map[$item['id']] ?? [];
            }
            unset($item);
        }
        return ['items' => $items, 'total' => $total, 'pages' => $total ? (int)ceil($total / $limit) : 1, 'page' => $page];
    }

    public static function counts(string $type): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (DB::all('SELECT status, COUNT(*) c FROM {{posts}} WHERE type = :t GROUP BY status', ['t' => $type]) as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    public static function uniqueSlug(string $type, string $slug, int $excludeId = 0): string
    {
        $slug = slugify($slug);
        if (in_array($slug, ContentModel::RESERVED, true) && $type === 'page') {
            $slug .= '-page';
        }
        $base = $slug;
        $n = 2;
        while (DB::val('SELECT id FROM {{posts}} WHERE type = :t AND slug = :s AND id <> :id', ['t' => $type, 's' => $slug, 'id' => $excludeId])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /** Create or update. Returns post id. */
    public static function save(array $data, ?int $id = null): int
    {
        $existing = $id ? self::find($id) : null;
        $type = $data['type'] ?? $existing['type'] ?? 'post';
        $title = trim((string)($data['title'] ?? $existing['title'] ?? ''));
        $slugSource = trim((string)($data['slug'] ?? '')) ?: ($existing['slug'] ?? '') ?: ($title ?: 'untitled');
        $status = $data['status'] ?? $existing['status'] ?? 'draft';
        if (!isset(self::STATUSES[$status])) {
            $status = 'draft';
        }

        $publishedAt = $data['published_at'] ?? $existing['published_at'] ?? null;
        if ($status === 'publish' && !$publishedAt) {
            $publishedAt = now();
        }
        if ($status === 'publish' && $publishedAt && strtotime($publishedAt . ' UTC') > time() + 60) {
            $status = 'scheduled';
        }

        $row = [
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'slug' => self::uniqueSlug($type, $slugSource, (int)$id),
            'content' => $data['content'] ?? $existing['content'] ?? '',
            'excerpt' => $data['excerpt'] ?? $existing['excerpt'] ?? '',
            'status' => $status,
            'author_id' => $data['author_id'] ?? $existing['author_id'] ?? (Auth::id() ?: null),
            'parent_id' => array_key_exists('parent_id', $data) ? ($data['parent_id'] ?: null) : ($existing['parent_id'] ?? null),
            'featured_media_id' => array_key_exists('featured_media_id', $data) ? ($data['featured_media_id'] ?: null) : ($existing['featured_media_id'] ?? null),
            'template' => $data['template'] ?? $existing['template'] ?? null,
            'menu_order' => (int)($data['menu_order'] ?? $existing['menu_order'] ?? 0),
            'fields' => json_encode(array_merge($existing['fields'] ?? [], $data['fields'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'seo' => json_encode(array_merge($existing['seo'] ?? [], $data['seo'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published_at' => $publishedAt,
            'updated_at' => now(),
        ];
        if ($row['parent_id'] && (int)$row['parent_id'] === (int)$id) {
            $row['parent_id'] = null;
        }
        $row = Hooks::filter('post_before_save', $row, $id);

        if ($existing) {
            DB::update('posts', $row, ['id' => $id]);
        } else {
            $row['created_at'] = $data['created_at'] ?? now();
            if (!empty($data['updated_at'])) {
                $row['updated_at'] = $data['updated_at'];
            }
            $id = DB::insert('posts', $row);
        }
        unset(self::$cache[$id]);

        if (isset($data['terms']) && is_array($data['terms'])) {
            foreach (ContentModel::taxonomiesFor($type) as $tax => $def) {
                Terms::sync($id, $tax, $data['terms'][$tax] ?? []);
            }
        }
        PageCache::clear();
        Hooks::action('post_saved', $id, $row, (bool)$existing);
        return (int)$id;
    }

    public static function delete(int $id, bool $force = false): void
    {
        $post = self::find($id);
        if (!$post) {
            return;
        }
        if (!$force && $post['status'] !== 'trash') {
            DB::update('posts', ['status' => 'trash', 'updated_at' => now()], ['id' => $id]);
        } else {
            $termIds = DB::col('SELECT term_id FROM {{term_relationships}} WHERE post_id = :id', ['id' => $id]);
            DB::delete('term_relationships', ['post_id' => $id]);
            DB::delete('posts', ['id' => $id]);
            DB::query('UPDATE {{posts}} SET parent_id = NULL WHERE parent_id = :id', ['id' => $id]);
            Terms::recount($termIds);
        }
        unset(self::$cache[$id]);
        PageCache::clear();
        Hooks::action('post_deleted', $id, $force);
    }

    /** Publish scheduled posts whose time has come (cheap; runs at most once per minute). */
    public static function publishScheduled(): void
    {
        $last = (int)Options::get('_scheduled_check', 0);
        if (time() - $last < 60) {
            return;
        }
        Options::set('_scheduled_check', time());
        $n = DB::query("UPDATE {{posts}} SET status = 'publish' WHERE status = 'scheduled' AND published_at <= :now", ['now' => now()])->rowCount();
        if ($n) {
            PageCache::clear();
        }
    }

    public static function permalink(array $post, bool $absolute = false): string
    {
        $type = ContentModel::type($post['type']);
        $path = '';
        if ($post['type'] === 'page') {
            if ((Options::get('front_page') === 'page') && (int)Options::get('front_page_id') === (int)$post['id']) {
                $path = '/';
            } else {
                $segments = [$post['slug']];
                $parent = $post['parent_id'] ? self::find((int)$post['parent_id']) : null;
                $guard = 0;
                while ($parent && $guard++ < 10) {
                    array_unshift($segments, $parent['slug']);
                    $parent = $parent['parent_id'] ? self::find((int)$parent['parent_id']) : null;
                }
                $path = '/' . implode('/', $segments) . '/';
            }
        } elseif ($post['type'] === 'post') {
            $structure = self::postStructure();
            $ts = strtotime(($post['published_at'] ?: $post['created_at'] ?? now()) . ' UTC');
            $category = '';
            if (str_contains($structure, '%category%')) {
                $terms = Terms::forPosts([$post['id']])[$post['id']]['category'] ?? [];
                $category = $terms[0]['slug'] ?? 'uncategorized';
            }
            $path = strtr($structure, [
                '%year%' => date('Y', $ts), '%monthnum%' => date('m', $ts), '%day%' => date('d', $ts),
                '%slug%' => $post['slug'], '%id%' => (string)$post['id'], '%category%' => $category,
            ]);
        } else {
            $base = trim((string)($type['rewrite'] ?? $post['type']), '/');
            // Taxonomy tokens, e.g. "%nationality%/profile" → "/in/profile/slug/".
            if (str_contains($base, '%')) {
                $base = preg_replace_callback('/%([a-z0-9_-]+)%/', static function ($m) use ($post) {
                    $terms = $post['terms'][$m[1]] ?? (Terms::forPosts([(int)$post['id']])[(int)$post['id']][$m[1]] ?? []);
                    return $terms[0]['slug'] ?? 'none';
                }, $base);
            }
            $path = '/' . ($base !== '' ? $base . '/' : '') . $post['slug'] . '/';
        }
        $path = self::trail($path);
        $path = Hooks::filter('permalink', $path, $post);
        return $absolute ? site_url($path) : url($path);
    }

    public static function postStructure(): string
    {
        $s = (string)Options::get('permalink_post', '/%slug%/');
        if ($s === 'custom') {
            $s = (string)Options::get('permalink_custom', '/%slug%/');
        }
        if (!str_contains($s, '%slug%') && !str_contains($s, '%id%')) {
            $s = '/%slug%/';
        }
        return '/' . trim($s, '/') . '/';
    }

    public static function trail(string $path): string
    {
        if ($path === '/' || $path === '') {
            return '/';
        }
        $path = '/' . trim($path, '/');
        return Options::get('permalink_trailing_slash', true) ? $path . '/' : $path;
    }

    public static function excerpt(array $post, int $length = 160): string
    {
        return $post['excerpt'] ? str_limit($post['excerpt'], $length) : str_limit((string)$post['content'], $length);
    }
}
