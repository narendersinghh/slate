<?php
declare(strict_types=1);

namespace Slate;

/**
 * Content revisions: every save from the editor keeps the previous version (title, content, excerpt, custom fields, SEO),
 * so any change can be compared and undone. Settings → General → Writing: how many to keep per item (0 = off).
 */
final class Revisions
{
    public static function keep(): int
    {
        return max(0, (int)Options::get('revisions_keep', 25));
    }

    /**
     * Store $existing as a revision when $data changes something worth tracking.
     * Call it before Posts::save($data, $existing['id']).
     */
    public static function snapshot(array $existing, array $data): ?int
    {
        if (self::keep() === 0 || empty($existing['id'])) {
            return null;
        }
        $changed = false;
        $text = static fn($v): string => trim(str_replace("\r\n", "\n", (string)$v));
        foreach (['title', 'content', 'excerpt'] as $k) {
            if (array_key_exists($k, $data) && $text($data[$k]) !== $text($existing[$k] ?? '')) {
                $changed = true;
            }
        }
        foreach (['fields', 'seo'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $merged = array_merge((array)($existing[$k] ?? []), $data[$k]);
                if (self::json(self::compact($merged)) !== self::json(self::compact((array)($existing[$k] ?? [])))) {
                    $changed = true;
                }
            }
        }
        if (!$changed) {
            return null;
        }
        $id = DB::insert('revisions', [
            'post_id' => (int)$existing['id'],
            'user_id' => Auth::id() ?: null,
            'title' => mb_substr((string)$existing['title'], 0, 255),
            'content' => (string)($existing['content'] ?? ''),
            'excerpt' => (string)($existing['excerpt'] ?? ''),
            'fields' => self::json((array)($existing['fields'] ?? [])),
            'seo' => self::json((array)($existing['seo'] ?? [])),
            'created_at' => (string)($existing['updated_at'] ?? now()),
        ]);
        self::prune((int)$existing['id']);
        return $id;
    }

    public static function count(int $postId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM {{revisions}} WHERE post_id = :p', ['p' => $postId]);
    }

    /** Newest first, without the heavy columns. */
    public static function list(int $postId): array
    {
        return DB::all('SELECT r.id, r.post_id, r.user_id, r.title, r.created_at, LENGTH(r.content) AS size, u.display_name
            FROM {{revisions}} r LEFT JOIN {{users}} u ON u.id = r.user_id WHERE r.post_id = :p ORDER BY r.id DESC', ['p' => $postId]);
    }

    public static function find(int $id): ?array
    {
        $r = DB::row('SELECT * FROM {{revisions}} WHERE id = :id', ['id' => $id]);
        if ($r) {
            $r['fields'] = json_decode((string)$r['fields'], true) ?: [];
            $r['seo'] = json_decode((string)$r['seo'], true) ?: [];
        }
        return $r;
    }

    /** Put a revision back. The current version becomes a revision itself, so restoring can be undone too. */
    public static function restore(int $revisionId): ?int
    {
        $rev = self::find($revisionId);
        $post = $rev ? Posts::find((int)$rev['post_id']) : null;
        if (!$rev || !$post) {
            return null;
        }
        $data = ['title' => $rev['title'], 'content' => $rev['content'], 'excerpt' => $rev['excerpt'],
            'fields' => $rev['fields'], 'seo' => $rev['seo']];
        self::snapshot($post, $data);
        // Fields/SEO merge in Posts::save, so start from the revision's full sets.
        DB::update('posts', ['fields' => self::json($rev['fields']), 'seo' => self::json($rev['seo'])], ['id' => $post['id']]);
        Posts::flush((int)$post['id']);
        return Posts::save($data, (int)$post['id']);
    }

    public static function deleteFor(int $postId): void
    {
        DB::delete('revisions', ['post_id' => $postId]);
    }

    private static function prune(int $postId): void
    {
        $keep = self::keep();
        $ids = DB::col('SELECT id FROM {{revisions}} WHERE post_id = :p ORDER BY id DESC', ['p' => $postId]);
        foreach (array_slice($ids, $keep) as $old) {
            DB::delete('revisions', ['id' => (int)$old]);
        }
    }

    /** Drop empty values (and sort keys) so "missing" and "empty" compare equal. */
    private static function compact(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $v = self::compact($v);
            } elseif (is_string($v)) {
                $v = trim(str_replace("\r\n", "\n", $v));
            }
            if ($v === '' || $v === null || $v === [] || $v === false) {
                continue;
            }
            $out[$k] = $v;
        }
        ksort($out);
        return $out;
    }

    private static function json(array $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Line diff of two texts (HTML is split at block tags so changes line up with paragraphs).
     * @return array<int, array{0:string, 1:string}> [op, line] with op "=", "-" (only in $a) or "+" (only in $b)
     */
    public static function diff(string $a, string $b): array
    {
        $split = static function (string $s): array {
            $s = preg_replace('#(</(?:p|h[1-6]|li|div|blockquote|tr|section|figure)>|<br\s*/?>)#i', "$1\n", str_replace("\r\n", "\n", $s));
            return array_values(array_filter(array_map('rtrim', explode("\n", (string)$s)), static fn($l) => trim($l) !== ''));
        };
        $x = array_slice($split($a), 0, 1500);
        $y = array_slice($split($b), 0, 1500);
        // Trim the common head and tail, then LCS on the rest.
        $pre = 0;
        while ($pre < count($x) && $pre < count($y) && $x[$pre] === $y[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < count($x) - $pre && $suf < count($y) - $pre && $x[count($x) - 1 - $suf] === $y[count($y) - 1 - $suf]) {
            $suf++;
        }
        $xs = array_slice($x, $pre, count($x) - $pre - $suf);
        $ys = array_slice($y, $pre, count($y) - $pre - $suf);
        $n = count($xs);
        $m = count($ys);
        $out = [];
        foreach (array_slice($x, 0, $pre) as $l) {
            $out[] = ['=', $l];
        }
        if ($n * $m > 400000) {
            foreach ($xs as $l) {
                $out[] = ['-', $l];
            }
            foreach ($ys as $l) {
                $out[] = ['+', $l];
            }
        } else {
            $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; $i--) {
                for ($j = $m - 1; $j >= 0; $j--) {
                    $L[$i][$j] = $xs[$i] === $ys[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
                }
            }
            $i = $j = 0;
            while ($i < $n && $j < $m) {
                if ($xs[$i] === $ys[$j]) {
                    $out[] = ['=', $xs[$i]];
                    $i++;
                    $j++;
                } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                    $out[] = ['-', $xs[$i++]];
                } else {
                    $out[] = ['+', $ys[$j++]];
                }
            }
            for (; $i < $n; $i++) {
                $out[] = ['-', $xs[$i]];
            }
            for (; $j < $m; $j++) {
                $out[] = ['+', $ys[$j]];
            }
        }
        foreach (array_slice($x, count($x) - $suf) as $l) {
            $out[] = ['=', $l];
        }
        return $out;
    }
}
