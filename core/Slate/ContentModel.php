<?php
declare(strict_types=1);

namespace Slate;

/**
 * Post types, taxonomies and custom field groups.
 * Stored as autoloaded options => zero extra queries to know the content model.
 */
final class ContentModel
{
    public const RESERVED = ['admin', 'api', 'content', 'core', 'modules', 'themes', 'install', 'sitemap', 'robots', 'feed',
        'search', 'page', 'author', 'login', 'logout', 'media', 'uploads', 'type', 'id', 'slug', 'title', 'status'];

    public const SUPPORTS = [
        'title' => 'Title', 'editor' => 'Content editor', 'excerpt' => 'Excerpt', 'featured_image' => 'Featured image',
        'author' => 'Author', 'page_attributes' => 'Parent & order', 'seo' => 'SEO panel', 'frontend_edit' => 'Frontend editing',
    ];

    public const FIELD_TYPES = [
        'text' => 'Text', 'textarea' => 'Text area', 'richtext' => 'Rich text', 'number' => 'Number', 'email' => 'Email',
        'url' => 'URL', 'tel' => 'Phone', 'select' => 'Select', 'radio' => 'Radio buttons', 'checkbox' => 'Checkboxes',
        'toggle' => 'True / false', 'date' => 'Date', 'datetime' => 'Date & time', 'color' => 'Colour', 'image' => 'Image',
        'gallery' => 'Gallery', 'file' => 'File', 'relation' => 'Relationship (posts)', 'repeater' => 'Repeater', 'code' => 'Code / HTML',
    ];

    private static ?array $types = null;
    private static ?array $taxes = null;

    public static function builtinTypes(): array
    {
        $base = ['public' => true, 'has_archive' => true, 'builtin' => true, 'show_in_menu' => true];
        return [
            'post' => $base + ['slug' => 'post', 'singular' => 'Post', 'plural' => 'Posts', 'icon' => 'file-text', 'hierarchical' => false,
                'rewrite' => 'blog', 'menu_position' => 10, 'supports' => ['title', 'editor', 'excerpt', 'featured_image', 'author', 'seo', 'frontend_edit']],
            'page' => $base + ['slug' => 'page', 'singular' => 'Page', 'plural' => 'Pages', 'icon' => 'file', 'hierarchical' => true,
                'has_archive' => false, 'rewrite' => '', 'menu_position' => 20, 'supports' => ['title', 'editor', 'featured_image', 'page_attributes', 'seo', 'frontend_edit']],
        ];
    }

    public static function builtinTaxonomies(): array
    {
        return [
            'category' => ['slug' => 'category', 'singular' => 'Category', 'plural' => 'Categories', 'post_types' => ['post'],
                'hierarchical' => true, 'public' => true, 'rewrite' => 'category', 'builtin' => true],
            'tag' => ['slug' => 'tag', 'singular' => 'Tag', 'plural' => 'Tags', 'post_types' => ['post'],
                'hierarchical' => false, 'public' => true, 'rewrite' => 'tag', 'builtin' => true],
        ];
    }

    public static function types(): array
    {
        if (self::$types === null) {
            $stored = Options::get('post_types') ?: [];
            $types = self::builtinTypes();
            foreach ($stored as $slug => $def) {
                $types[$slug] = isset($types[$slug]) ? array_merge($types[$slug], $def, ['builtin' => true]) : $def + ['builtin' => false];
            }
            uasort($types, static fn($a, $b) => ($a['menu_position'] ?? 50) <=> ($b['menu_position'] ?? 50));
            self::$types = Hooks::filter('post_types', $types);
        }
        return self::$types;
    }

    public static function type(string $slug): ?array
    {
        return self::types()[$slug] ?? null;
    }

    public static function supports(string $type, string $feature): bool
    {
        return in_array($feature, self::type($type)['supports'] ?? [], true);
    }

    public static function taxonomies(): array
    {
        if (self::$taxes === null) {
            $stored = Options::get('taxonomies') ?: [];
            $taxes = self::builtinTaxonomies();
            foreach ($stored as $slug => $def) {
                $taxes[$slug] = isset($taxes[$slug]) ? array_merge($taxes[$slug], $def, ['builtin' => true]) : $def + ['builtin' => false];
            }
            self::$taxes = Hooks::filter('taxonomies', $taxes);
        }
        return self::$taxes;
    }

    public static function taxonomy(string $slug): ?array
    {
        return self::taxonomies()[$slug] ?? null;
    }

    /** Taxonomies attached to a post type. */
    public static function taxonomiesFor(string $type): array
    {
        return array_filter(self::taxonomies(), static fn($t) => in_array($type, $t['post_types'] ?? [], true));
    }

    public static function saveType(array $def, ?string $original = null): void
    {
        $all = Options::get('post_types') ?: [];
        if ($original && $original !== $def['slug']) {
            unset($all[$original]);
            DB::query('UPDATE {{posts}} SET type = :n WHERE type = :o', ['n' => $def['slug'], 'o' => $original]);
        }
        $all[$def['slug']] = $def;
        Options::set('post_types', $all);
        self::$types = null;
    }

    public static function deleteType(string $slug): void
    {
        $all = Options::get('post_types') ?: [];
        unset($all[$slug]);
        Options::set('post_types', $all);
        self::$types = null;
    }

    public static function saveTaxonomy(array $def, ?string $original = null): void
    {
        $all = Options::get('taxonomies') ?: [];
        if ($original && $original !== $def['slug']) {
            unset($all[$original]);
            DB::query('UPDATE {{terms}} SET taxonomy = :n WHERE taxonomy = :o', ['n' => $def['slug'], 'o' => $original]);
        }
        $all[$def['slug']] = $def;
        Options::set('taxonomies', $all);
        self::$taxes = null;
    }

    public static function deleteTaxonomy(string $slug): void
    {
        $all = Options::get('taxonomies') ?: [];
        unset($all[$slug]);
        Options::set('taxonomies', $all);
        self::$taxes = null;
    }

    public static function fieldGroups(): array
    {
        return Options::get('field_groups') ?: [];
    }

    /** Fields that apply to a post type ('post') or taxonomy ('term'), grouped. */
    public static function groupsFor(string $kind, string $slug): array
    {
        $key = $kind === 'term' ? 'taxonomies' : 'post_types';
        return array_values(array_filter(self::fieldGroups(), static fn($g) => in_array($slug, $g['location'][$key] ?? [], true)));
    }

    public static function fieldsFor(string $kind, string $slug): array
    {
        $fields = [];
        foreach (self::groupsFor($kind, $slug) as $g) {
            foreach ($g['fields'] ?? [] as $f) {
                $fields[$f['name']] = $f;
            }
        }
        return $fields;
    }

    public static function saveFieldGroup(array $group): void
    {
        $all = self::fieldGroups();
        $all[$group['id']] = $group;
        Options::set('field_groups', $all);
    }

    public static function deleteFieldGroup(string $id): void
    {
        $all = self::fieldGroups();
        unset($all[$id]);
        Options::set('field_groups', $all);
    }

    public static function validSlug(string $slug, string $kind, ?string $original = null): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,38}$/', $slug)) {
            return 'Slug must be 2–39 lowercase letters, numbers, dashes or underscores, starting with a letter.';
        }
        if (in_array($slug, self::RESERVED, true)) {
            return "“$slug” is reserved.";
        }
        $exists = $kind === 'type' ? isset(self::types()[$slug]) : isset(self::taxonomies()[$slug]);
        if ($exists && $slug !== $original) {
            return "“$slug” already exists.";
        }
        return null;
    }

    /** Sanitise posted custom field values according to their definitions. */
    public static function sanitizeFields(array $defs, array $input, bool $unfiltered): array
    {
        $out = [];
        foreach ($defs as $name => $f) {
            $out[$name] = self::sanitizeValue($f, $input[$name] ?? null, $unfiltered);
        }
        return $out;
    }

    public static function sanitizeValue(array $f, mixed $v, bool $unfiltered): mixed
    {
        return match ($f['type']) {
            'number' => $v === '' || $v === null ? null : (is_numeric($v) ? $v + 0 : null),
            'toggle' => !empty($v),
            'image', 'file' => $v ? (int)$v : null,
            'gallery', 'relation' => array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string)$v)))),
            'checkbox' => array_values(array_filter(array_map('strval', (array)($v ?? [])), 'strlen')),
            'email' => filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL) ?: '',
            'url' => self::cleanUrl((string)$v),
            'richtext' => $unfiltered ? (string)$v : Sanitizer::html((string)$v),
            'code' => $unfiltered ? (string)$v : '',
            'repeater' => self::sanitizeRepeater($f, $v, $unfiltered),
            'color' => preg_match('/^#[0-9a-f]{3,8}$/i', (string)$v) ? (string)$v : '',
            default => trim(strip_tags((string)$v)),
        };
    }

    private static function sanitizeRepeater(array $f, mixed $v, bool $unfiltered): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        if (!is_array($v)) {
            return [];
        }
        $sub = [];
        foreach ($f['sub_fields'] ?? [] as $sf) {
            $sub[$sf['name']] = $sf;
        }
        $rows = [];
        foreach ($v as $row) {
            if (is_array($row)) {
                $rows[] = self::sanitizeFields($sub, $row, $unfiltered);
            }
        }
        return $rows;
    }

    public static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : '';
    }
}
