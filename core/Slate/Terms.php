<?php
declare(strict_types=1);

namespace Slate;

/** Taxonomy terms (categories, tags, custom). */
final class Terms
{
    private static array $byTax = [];

    public static function hydrate(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
        $row['count'] = (int)$row['count'];
        $row['fields'] = json_decode((string)($row['fields'] ?? ''), true) ?: [];
        $row['seo'] = json_decode((string)($row['seo'] ?? ''), true) ?: [];
        return $row;
    }

    /** All terms of a taxonomy (cached per request). */
    public static function all(string $taxonomy): array
    {
        if (!isset(self::$byTax[$taxonomy])) {
            self::$byTax[$taxonomy] = array_map(
                [self::class, 'hydrate'],
                DB::all('SELECT * FROM {{terms}} WHERE taxonomy = :t ORDER BY name', ['t' => $taxonomy])
            );
        }
        return self::$byTax[$taxonomy];
    }

    /** Terms ordered as a tree with a "depth" key (for hierarchical taxonomies). */
    public static function tree(string $taxonomy): array
    {
        $all = self::all($taxonomy);
        $children = [];
        foreach ($all as $t) {
            $children[(int)$t['parent_id']][] = $t;
        }
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $children): void {
            foreach ($children[$parent] ?? [] as $t) {
                $t['depth'] = $depth;
                $out[] = $t;
                if ($depth < 10) {
                    $walk($t['id'], $depth + 1);
                }
            }
        };
        $walk(0, 0);
        return $out;
    }

    public static function flush(): void
    {
        self::$byTax = [];
    }

    public static function find(int $id): ?array
    {
        $row = DB::row('SELECT * FROM {{terms}} WHERE id = :id', ['id' => $id]);
        return $row ? self::hydrate($row) : null;
    }

    public static function findBySlug(string $taxonomy, string $slug): ?array
    {
        foreach (self::$byTax[$taxonomy] ?? [] as $t) {
            if ($t['slug'] === $slug) {
                return $t;
            }
        }
        $row = DB::row('SELECT * FROM {{terms}} WHERE taxonomy = :t AND slug = :s', ['t' => $taxonomy, 's' => $slug]);
        return $row ? self::hydrate($row) : null;
    }

    public static function save(string $taxonomy, array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = slugify(trim((string)($data['slug'] ?? '')) ?: $name);
        $base = $slug;
        $n = 2;
        while (DB::val('SELECT id FROM {{terms}} WHERE taxonomy = :t AND slug = :s AND id <> :id', ['t' => $taxonomy, 's' => $slug, 'id' => (int)$id])) {
            $slug = $base . '-' . $n++;
        }
        $row = [
            'taxonomy' => $taxonomy,
            'name' => mb_substr($name, 0, 191),
            'slug' => $slug,
            'description' => (string)($data['description'] ?? ''),
            'parent_id' => !empty($data['parent_id']) && (int)$data['parent_id'] !== (int)$id ? (int)$data['parent_id'] : null,
            'fields' => json_encode($data['fields'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'seo' => json_encode($data['seo'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($id) {
            DB::update('terms', $row, ['id' => $id]);
        } else {
            $id = DB::insert('terms', $row);
        }
        unset(self::$byTax[$taxonomy]);
        PageCache::clear();
        return (int)$id;
    }

    public static function delete(int $id): void
    {
        $t = self::find($id);
        if (!$t) {
            return;
        }
        DB::delete('term_relationships', ['term_id' => $id]);
        DB::query('UPDATE {{terms}} SET parent_id = :p WHERE parent_id = :id', ['p' => $t['parent_id'], 'id' => $id]);
        DB::delete('terms', ['id' => $id]);
        unset(self::$byTax[$t['taxonomy']]);
        PageCache::clear();
    }

    /**
     * Replace a post's terms in one taxonomy. Accepts ids or names (names are created - handy for tags).
     */
    public static function sync(int $postId, string $taxonomy, array $values): void
    {
        $ids = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $ids[] = (int)$v;
            } elseif (is_string($v) && trim($v) !== '') {
                $existing = self::findBySlug($taxonomy, slugify($v));
                $ids[] = $existing ? $existing['id'] : self::save($taxonomy, ['name' => trim($v)]);
            }
        }
        $ids = array_unique(array_filter($ids));
        $current = DB::col(
            'SELECT tr.term_id FROM {{term_relationships}} tr INNER JOIN {{terms}} t ON t.id = tr.term_id WHERE tr.post_id = :p AND t.taxonomy = :t',
            ['p' => $postId, 't' => $taxonomy]
        );
        $current = array_map('intval', $current);
        $remove = array_diff($current, $ids);
        $add = array_diff($ids, $current);
        if ($remove) {
            [$in, $params] = DB::in($remove);
            DB::query("DELETE FROM {{term_relationships}} WHERE post_id = :post AND term_id IN ($in)", $params + ['post' => $postId]);
        }
        foreach ($add as $tid) {
            DB::insert('term_relationships', ['post_id' => $postId, 'term_id' => $tid]);
        }
        if ($add || $remove) {
            self::recount(array_merge($add, $remove));
        }
    }

    public static function recount(array $termIds): void
    {
        foreach (array_unique($termIds) as $tid) {
            DB::query(
                "UPDATE {{terms}} SET count = (SELECT COUNT(*) FROM {{term_relationships}} tr INNER JOIN {{posts}} p ON p.id = tr.post_id
                 WHERE tr.term_id = :t AND p.status = 'publish') WHERE id = :t2",
                ['t' => (int)$tid, 't2' => (int)$tid]
            );
        }
    }

    /** Terms for many posts in ONE query: [postId => [taxonomy => [terms]]]. */
    public static function forPosts(array $postIds): array
    {
        if (!$postIds) {
            return [];
        }
        [$in, $params] = DB::in($postIds);
        $rows = DB::all(
            "SELECT tr.post_id, t.* FROM {{term_relationships}} tr INNER JOIN {{terms}} t ON t.id = tr.term_id WHERE tr.post_id IN ($in) ORDER BY t.name",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['post_id'];
            unset($r['post_id']);
            $out[$pid][$r['taxonomy']][] = self::hydrate($r);
        }
        return $out;
    }

    public static function link(array $term, bool $absolute = false): string
    {
        $tax = ContentModel::taxonomy($term['taxonomy']);
        $base = trim((string)($tax['rewrite'] ?? $term['taxonomy']), '/');
        // Hierarchical taxonomies nest parent slugs like WordPress: /category/parent/child/
        $slugs = [$term['slug']];
        if (!empty($tax['hierarchical']) && ($tax['nested'] ?? true) && !empty($term['parent_id'])) {
            $byId = array_column(self::all($term['taxonomy']), null, 'id');
            $pid = (int)$term['parent_id'];
            $guard = 0;
            while ($pid && isset($byId[$pid]) && $guard++ < 10) {
                array_unshift($slugs, $byId[$pid]['slug']);
                $pid = (int)$byId[$pid]['parent_id'];
            }
        }
        $path = Posts::trail('/' . ($base !== '' ? $base . '/' : '') . implode('/', $slugs));
        return $absolute ? site_url($path) : url($path);
    }
}
