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
        'gallery' => 'Gallery', 'file' => 'File', 'relation' => 'Relationship (posts)', 'taxonomy' => 'Taxonomy terms', 'repeater' => 'Repeater',
        'code' => 'Code / HTML', 'form' => 'Form', 'tab' => 'Tab (groups the fields below it)',
    ];

    /** Field types that only structure the edit screen and store no value. */
    public const LAYOUT_TYPES = ['tab'];

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

    /** Delete a custom post type (built-in types cannot be deleted), optionally with all its items. Returns an error message or null. */
    public static function removeType(string $slug, bool $withContent = false): ?string
    {
        if (isset(self::builtinTypes()[$slug])) {
            return 'Built-in post types cannot be deleted.';
        }
        if ($withContent) {
            foreach (DB::col('SELECT id FROM {{posts}} WHERE type = :t', ['t' => $slug]) as $pid) {
                Posts::delete((int)$pid, true);
            }
        }
        self::deleteType($slug);
        return null;
    }

    /** Delete a custom taxonomy and its terms (content keeps existing). Returns an error message or null. */
    public static function removeTaxonomy(string $slug): ?string
    {
        if (isset(self::builtinTaxonomies()[$slug])) {
            return 'Built-in taxonomies cannot be deleted.';
        }
        foreach (DB::col('SELECT id FROM {{terms}} WHERE taxonomy = :t', ['t' => $slug]) as $tid) {
            Terms::delete((int)$tid);
        }
        self::deleteTaxonomy($slug);
        return null;
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
        return Hooks::filter('field_groups', Options::get('field_groups') ?: []);
    }

    /** Post types a relationship field may point to (stored as "books,events"; empty = any). */
    public static function relationTypes(array $f): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)($f['post_type'] ?? '')))));
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
        // Stored groups only: groups added by modules through the "field_groups" filter must not be persisted.
        $all = Options::get('field_groups') ?: [];
        $all[$group['id']] = $group;
        Options::set('field_groups', $all);
    }

    public static function deleteFieldGroup(string $id): void
    {
        $all = Options::get('field_groups') ?: [];
        unset($all[$id]);
        Options::set('field_groups', $all);
    }

    /**
     * A post type definition from input (the Post types screen, the MCP server). $in keys: slug, singular, plural, icon, public,
     * has_archive, hierarchical, show_in_menu, rewrite, menu_position, supports, description. Returns the clean definition, or an
     * error message. Built-in types keep their slug.
     */
    public static function cleanTypeDef(array $in, ?string $original = null): array|string
    {
        $builtin = $original && (self::builtinTypes()[$original] ?? false);
        $slug = $builtin ? $original : strtolower(trim((string)($in['slug'] ?? '')));
        if (!$builtin && ($err = self::validSlug($slug, 'type', $original))) {
            return $err;
        }
        $rewrite = trim(strtolower((string)($in['rewrite'] ?? $slug)), '/');
        $rewrite = $rewrite === '' ? '' : implode('/', array_map(static fn($seg) => preg_match('/^%[a-z0-9_-]+%$/', $seg) ? $seg : slugify($seg), explode('/', $rewrite)));
        if ($slug !== 'page' && $rewrite === '') {
            $rewrite = $slug;
        }
        $icon = (string)($in['icon'] ?? '');
        return [
            'slug' => $slug,
            'singular' => trim(strip_tags((string)($in['singular'] ?? ''))) ?: ucfirst($slug),
            'plural' => trim(strip_tags((string)($in['plural'] ?? ''))) ?: ucfirst($slug) . 's',
            'icon' => in_array($icon, Icons::names(), true) ? $icon : 'file',
            'public' => (bool)($in['public'] ?? false),
            'has_archive' => (bool)($in['has_archive'] ?? false),
            'hierarchical' => (bool)($in['hierarchical'] ?? false),
            'show_in_menu' => (bool)($in['show_in_menu'] ?? true),
            'rewrite' => $rewrite,
            'menu_position' => (int)($in['menu_position'] ?? 30),
            'supports' => array_values(array_intersect((array)($in['supports'] ?? []), array_keys(self::SUPPORTS))),
            'description' => trim(strip_tags((string)($in['description'] ?? ''))),
        ];
    }

    /** A taxonomy definition from input (slug, singular, plural, post_types, hierarchical, public, rewrite, nested), or an error message. */
    public static function cleanTaxonomyDef(array $in, ?string $original = null): array|string
    {
        $slug = strtolower(trim((string)($in['slug'] ?? '')));
        if ($original && isset(self::builtinTaxonomies()[$original])) {
            $slug = $original;
        } elseif ($err = self::validSlug($slug, 'tax', $original)) {
            return $err;
        }
        return [
            'slug' => $slug,
            'singular' => trim(strip_tags((string)($in['singular'] ?? ''))) ?: ucfirst($slug),
            'plural' => trim(strip_tags((string)($in['plural'] ?? ''))) ?: ucfirst($slug) . 's',
            'post_types' => array_values(array_intersect((array)($in['post_types'] ?? []), array_keys(self::types()))),
            'hierarchical' => (bool)($in['hierarchical'] ?? false),
            'public' => (bool)($in['public'] ?? false),
            'rewrite' => trim(implode('/', array_map('slugify', array_filter(explode('/', (string)($in['rewrite'] ?? '')), 'strlen'))), '/'),
            'nested' => (bool)($in['nested'] ?? true),
        ];
    }

    /** A field group from input ({id, title, position, location: {post_types, taxonomies}, fields: [...]}), cleaned for saving. */
    public static function cleanFieldGroup(array $data): array
    {
        return [
            'id' => preg_replace('/[^a-z0-9]/', '', (string)($data['id'] ?? '')) ?: 'g' . bin2hex(random_bytes(4)),
            'title' => trim(strip_tags((string)($data['title'] ?? 'Fields'))) ?: 'Fields',
            'position' => in_array($data['position'] ?? 'main', ['main', 'side'], true) ? $data['position'] : 'main',
            'location' => [
                'post_types' => array_values(array_intersect((array)($data['location']['post_types'] ?? []), array_keys(self::types()))),
                'taxonomies' => array_values(array_intersect((array)($data['location']['taxonomies'] ?? []), array_keys(self::taxonomies()))),
            ],
            'fields' => self::cleanFields((array)($data['fields'] ?? []), 0),
        ];
    }

    /** Field definitions of a group: known types, unique names, repeaters one level deep. */
    public static function cleanFields(array $fields, int $depth = 0): array
    {
        $out = [];
        $seen = [];
        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $name = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['name'] ?? '')));
            if (($f['type'] ?? '') === 'tab' && $name === '') {
                $name = 'tab_' . (slugify((string)($f['label'] ?? '')) ?: count($out));
                $name = str_replace('-', '_', $name);
            }
            if ($name === '' || isset($seen[$name]) || !isset(self::FIELD_TYPES[$f['type'] ?? ''])) {
                continue;
            }
            $seen[$name] = true;
            $field = [
                'name' => $name,
                'label' => trim(strip_tags((string)($f['label'] ?? $name))),
                'type' => $f['type'],
                'required' => !empty($f['required']),
                'help' => trim(strip_tags((string)($f['help'] ?? ''))),
                'placeholder' => trim(strip_tags((string)($f['placeholder'] ?? ''))),
                'default' => is_scalar($f['default'] ?? '') ? (string)($f['default'] ?? '') : '',
                'width' => in_array((string)($f['width'] ?? '100'), ['25', '33', '50', '66', '75', '100'], true) ? (string)($f['width'] ?? '100') : '100',
                'options' => array_values(array_filter(array_map('trim', is_array($f['options'] ?? null) ? array_map('strval', $f['options']) : explode("\n", (string)($f['options'] ?? ''))))),
                'post_type' => implode(',', array_filter(array_map(static fn($t) => preg_replace('/[^a-z0-9_-]/', '', (string)$t),
                    is_array($f['post_type'] ?? null) ? $f['post_type'] : explode(',', (string)($f['post_type'] ?? ''))))),
            ];
            if ($depth > 0) {
                // Width inside a repeater row: "auto" (several per line, the default) or a share of the row.
                $rw = (string)($f['row_width'] ?? 'auto');
                $field['row_width'] = in_array($rw, ['auto', '25', '33', '50', '66', '75', '100'], true) ? $rw : 'auto';
            }
            if ($f['type'] === 'taxonomy') {
                $field['taxonomy'] = self::taxonomy((string)($f['taxonomy'] ?? '')) ? (string)$f['taxonomy'] : '';
            }
            if ($f['type'] === 'repeater' && $depth < 1) {
                $field['sub_fields'] = self::cleanFields((array)($f['sub_fields'] ?? []), $depth + 1);
            }
            $out[] = $field;
        }
        return $out;
    }

    public static function validSlug(string $slug, string $kind, ?string $original = null): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,38}$/', $slug)) {
            return 'Slug must be 2–39 lowercase letters, numbers, dashes or underscores, starting with a letter.';
        }
        if (in_array($slug, self::RESERVED, true)) {
            return "“{$slug}” is reserved.";
        }
        $exists = $kind === 'type' ? isset(self::types()[$slug]) : isset(self::taxonomies()[$slug]);
        if ($exists && $slug !== $original) {
            return "“{$slug}” already exists.";
        }
        return null;
    }

    /** Sanitise posted custom field values according to their definitions. */
    public static function sanitizeFields(array $defs, array $input, bool $unfiltered): array
    {
        $out = [];
        foreach ($defs as $name => $f) {
            if (in_array($f['type'] ?? '', self::LAYOUT_TYPES, true)) {
                continue;
            }
            $out[$name] = self::sanitizeValue($f, $input[$name] ?? null, $unfiltered);
        }
        return $out;
    }

    public static function sanitizeValue(array $f, mixed $v, bool $unfiltered): mixed
    {
        return match ($f['type']) {
            'number' => $v === '' || $v === null ? null : (is_numeric($v) ? $v + 0 : null),
            'toggle' => !empty($v),
            'image', 'file', 'form' => $v ? (int)$v : null,
            'gallery', 'relation', 'taxonomy' => array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string)$v)))),
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
