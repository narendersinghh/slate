<?php
declare(strict_types=1);

namespace Slate;

/**
 * Navigation menus stored in a single autoloaded option.
 * Item: {id, label, type: custom|post|term, url?, object_id?, target?, children: []}
 */
final class Menus
{
    public static function all(): array
    {
        return Options::get('menus') ?: [];
    }

    public static function byLocation(string $location): ?array
    {
        foreach (self::all() as $menu) {
            if (($menu['location'] ?? '') === $location) {
                return $menu;
            }
        }
        return null;
    }

    public static function save(array $menus): void
    {
        $clean = [];
        foreach ($menus as $m) {
            $id = preg_replace('/[^a-z0-9_-]/', '', (string)($m['id'] ?? '')) ?: 'menu-' . bin2hex(random_bytes(3));
            $clean[$id] = [
                'id' => $id,
                'name' => mb_substr(trim(strip_tags((string)($m['name'] ?? 'Menu'))), 0, 100),
                'location' => preg_replace('/[^a-z0-9_-]/', '', (string)($m['location'] ?? '')),
                'items' => self::cleanItems($m['items'] ?? [], 0),
            ];
        }
        Options::set('menus', $clean);
        PageCache::clear();
    }

    private static function cleanItems(array $items, int $depth): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = in_array($it['type'] ?? '', ['custom', 'post', 'term'], true) ? $it['type'] : 'custom';
            $out[] = [
                'id' => preg_replace('/[^a-z0-9]/', '', (string)($it['id'] ?? '')) ?: bin2hex(random_bytes(4)),
                'label' => mb_substr(trim(strip_tags((string)($it['label'] ?? ''))), 0, 120),
                'type' => $type,
                'url' => $type === 'custom' ? ContentModel::cleanUrl((string)($it['url'] ?? '')) : '',
                'object_id' => $type !== 'custom' ? (int)($it['object_id'] ?? 0) : 0,
                'target' => !empty($it['target']) ? '_blank' : '',
                'class' => preg_replace('/[^a-z0-9 _-]/i', '', (string)($it['class'] ?? '')),
                'children' => $depth < 3 ? self::cleanItems($it['children'] ?? [], $depth + 1) : [],
            ];
        }
        return $out;
    }

    /** Resolve URLs with one query for all linked posts. */
    public static function resolve(array $items): array
    {
        $postIds = [];
        $walk = static function (array $items) use (&$walk, &$postIds): void {
            foreach ($items as $it) {
                if ($it['type'] === 'post') {
                    $postIds[] = $it['object_id'];
                }
                $walk($it['children'] ?? []);
            }
        };
        $walk($items);
        Posts::prime($postIds);
        $map = static function (array $items) use (&$map): array {
            foreach ($items as &$it) {
                if ($it['type'] === 'post' && ($p = Posts::find((int)$it['object_id']))) {
                    $it['url'] = Posts::permalink($p);
                    $it['label'] = $it['label'] ?: $p['title'];
                } elseif ($it['type'] === 'term' && ($t = Terms::find((int)$it['object_id']))) {
                    $it['url'] = Terms::link($t);
                    $it['label'] = $it['label'] ?: $t['name'];
                }
                $it['children'] = $map($it['children'] ?? []);
            }
            return $items;
        };
        return $map($items);
    }

    public static function render(string $location, string $class = 'menu'): string
    {
        $menu = self::byLocation($location);
        if (!$menu || empty($menu['items'])) {
            return '';
        }
        $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $html = static function (array $items, int $depth) use (&$html, $current, $class): string {
            $out = '<ul class="' . ($depth ? 'sub-menu' : e($class)) . '">';
            foreach ($items as $it) {
                $active = $it['url'] === $current ? ' current' : '';
                $has = $it['children'] ? ' has-children' : '';
                $out .= '<li class="menu-item' . $active . $has . ($it['class'] ? ' ' . e($it['class']) : '') . '">';
                $out .= '<a href="' . e($it['url']) . '"' . ($it['target'] ? ' target="_blank" rel="noopener"' : '') . ($active ? ' aria-current="page"' : '') . '>' . e($it['label']) . '</a>';
                if ($it['children']) {
                    $out .= $html($it['children'], $depth + 1);
                }
                $out .= '</li>';
            }
            return $out . '</ul>';
        };
        return $html(self::resolve($menu['items']), 0);
    }
}
