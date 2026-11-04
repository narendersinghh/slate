<?php
declare(strict_types=1);

namespace SlateModules\AutoCpt;

use Slate\Ai;
use Slate\ContentModel;
use Slate\PageCache;

/**
 * Builds a "content model spec" from a source and applies it to Slate.
 *
 * Spec format:
 * {
 *   "post_types":   [{"slug","singular","plural","rewrite","hierarchical","has_archive","public","icon","supports":[]}],
 *   "taxonomies":   [{"slug","singular","plural","post_types":[],"hierarchical","rewrite"}],
 *   "field_groups": [{"title","post_types":[],"taxonomies":[],"position","fields":[{"name","label","type","options":[],"sub_fields":[]}]}],
 *   "wp_map":       {"types":{wpSlug:{slug,rest_base}}, "taxonomies":{wpSlug:{slug,rest_base}}}   (WordPress sources only)
 * }
 */
final class Generator
{
    /** WordPress types/taxonomies that are never content. */
    public const SKIP_TYPES = ['attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles',
        'wp_font_family', 'wp_font_face', 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'custom_css', 'elementor_library',
        'e-floating-buttons', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'product_variation', 'shop_order', 'shop_order_refund',
        'shop_coupon', 'shop_order_placehold', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'wpcf7_contact_form',
        'jet-engine', 'jet-menu', 'oceanwp_library', 'astra-advanced-hook', 'wpforms', 'wp_router_page', 'ct_template'];
    public const SKIP_TAXES = ['nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'elementor_library_type',
        'elementor_library_category', 'wp_pattern_category', 'product_visibility', 'product_shipping_class', 'elementor_font_type', 'wp_font_family'];
    /** WordPress → Slate built-in equivalents. */
    public const TYPE_ALIASES = ['post' => 'post', 'page' => 'page', 'e-landing-page' => 'page'];
    public const TAX_ALIASES = ['category' => 'category', 'post_tag' => 'tag'];

    // ------------------------------------------------------------------ sources

    public const ACF_TYPES = ['text' => 'text', 'textarea' => 'textarea', 'wysiwyg' => 'richtext', 'number' => 'number', 'range' => 'number',
        'email' => 'email', 'url' => 'url', 'link' => 'url', 'oembed' => 'url', 'password' => 'text', 'true_false' => 'toggle',
        'date_picker' => 'date', 'date_time_picker' => 'datetime', 'time_picker' => 'text', 'image' => 'image', 'file' => 'file',
        'gallery' => 'gallery', 'relationship' => 'relation', 'post_object' => 'relation', 'page_link' => 'relation',
        'select' => 'select', 'radio' => 'radio', 'button_group' => 'radio', 'checkbox' => 'checkbox', 'color_picker' => 'color',
        'repeater' => 'repeater', 'flexible_content' => 'repeater', 'group' => 'repeater', 'taxonomy' => 'text', 'user' => 'text', 'google_map' => 'text'];

    public static function fromWordPress(WpClient $wp): array
    {
        $ctx = $wp->authenticated() ? 'edit' : 'view';
        $types = $wp->get('/wp/v2/types', ['context' => $ctx]);
        if (!$types['ok'] && $ctx === 'edit') {
            $types = $wp->get('/wp/v2/types');
        }
        if (!$types['ok'] || !is_array($types['data'])) {
            throw new \RuntimeException('Could not read post types from ' . $wp->site . ': ' . $types['error']);
        }
        $taxes = $wp->get('/wp/v2/taxonomies', ['context' => $ctx]);
        if (!$taxes['ok']) {
            $taxes = $wp->get('/wp/v2/taxonomies');
        }
        $taxList = $taxes['ok'] && is_array($taxes['data']) ? $taxes['data'] : [];
        $spec = ['post_types' => [], 'taxonomies' => [], 'field_groups' => [], 'wp_map' => ['types' => [], 'taxonomies' => []]];
        $taxRest = [];
        foreach ($taxList as $wpSlug => $t) {
            $taxRest[(string)($t['slug'] ?? $wpSlug)] = (string)($t['rest_base'] ?? '');
        }
        $termSlugCache = [];
        $termSlugs = function (string $wpTax, array $ids) use ($wp, $taxRest, &$termSlugCache): array {
            $need = array_diff($ids, array_keys($termSlugCache[$wpTax] ?? []));
            if ($need && !empty($taxRest[$wpTax])) {
                $r = $wp->get('/wp/v2/' . $taxRest[$wpTax], ['include' => implode(',', $need), 'per_page' => 100]);
                foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $t) {
                    $termSlugCache[$wpTax][(int)$t['id']] = (string)$t['slug'];
                }
            }
            return array_values(array_filter(array_map(static fn($id) => $termSlugCache[$wpTax][(int)$id] ?? null, $ids)));
        };

        foreach ($types['data'] as $wpSlug => $t) {
            $wpSlug = (string)($t['slug'] ?? $wpSlug);
            if (in_array($wpSlug, self::SKIP_TYPES, true) || empty($t['rest_base'])) {
                continue;
            }
            if (isset($t['viewable']) && !$t['viewable'] && !isset(self::TYPE_ALIASES[$wpSlug])) {
                continue;
            }
            $restBase = (string)$t['rest_base'];
            $sample = $wp->get('/wp/v2/' . $restBase, ['per_page' => 10, 'context' => 'view']);
            $items = $sample['ok'] && is_array($sample['data']) ? $sample['data'] : [];
            $slug = self::TYPE_ALIASES[$wpSlug] ?? self::slug($wpSlug);
            $spec['wp_map']['types'][$wpSlug] = ['slug' => $slug, 'rest_base' => $restBase, 'total' => $sample['total'], 'taxonomies' => $t['taxonomies'] ?? []];
            if (!isset(self::TYPE_ALIASES[$wpSlug])) {
                $spec['post_types'][] = [
                    'slug' => $slug,
                    'singular' => html_entity_decode((string)($t['labels']['singular_name'] ?? self::singular((string)($t['name'] ?? $wpSlug))), ENT_QUOTES),
                    'plural' => html_entity_decode((string)($t['name'] ?? ucfirst($wpSlug)), ENT_QUOTES),
                    'rewrite' => self::rewriteWithTokens($items, $wp->site, (array)($t['taxonomies'] ?? []), $termSlugs) ?? $slug,
                    'hierarchical' => (bool)($t['hierarchical'] ?? false),
                    'has_archive' => true,
                    'public' => true,
                    'icon' => self::guessIcon($wpSlug . ' ' . ($t['name'] ?? '')),
                    'supports' => self::supportsFrom($items, (bool)($t['hierarchical'] ?? false)),
                    'description' => trim(strip_tags((string)($t['description'] ?? ''))),
                ];
            }
            // Custom fields: ACF ("acf", with optional "<name>_source" type metadata) and registered meta.
            $fieldValues = [];
            $meta = [];
            foreach ($items as $it) {
                foreach (['acf', 'meta'] as $bucket) {
                    if (empty($it[$bucket]) || !is_array($it[$bucket]) || array_is_list($it[$bucket])) {
                        continue;
                    }
                    foreach ($it[$bucket] as $k => $v) {
                        $k = (string)$k;
                        if (str_ends_with($k, '_source') && is_array($v) && isset($v['type'])) {
                            $meta[substr($k, 0, -7)] = $v;
                            continue;
                        }
                        if (!str_starts_with($k, '_') && !self::isPluginMeta($k)) {
                            $fieldValues[$k][] = $v;
                        }
                    }
                }
            }
            if ($fieldValues) {
                $fields = self::inferFields($fieldValues);
                foreach ($fields as &$f) {
                    $m = $meta[$f['name']] ?? null;
                    if (!$m) {
                        continue;
                    }
                    $f['label'] = html_entity_decode((string)($m['label'] ?? $f['label']), ENT_QUOTES);
                    $mapped = self::ACF_TYPES[$m['type']] ?? null;
                    if ($mapped === 'relation') {
                        $fv = $m['formatted_value'] ?? null;
                        $first = is_array($fv) ? ($fv[0] ?? null) : null;
                        if (is_array($first) && ($first['post_type'] ?? '') === 'attachment') {
                            $mapped = 'gallery';
                        } elseif (is_array($first) && !empty($first['post_type'])) {
                            $f['post_type'] = self::TYPE_ALIASES[$first['post_type']] ?? self::slug((string)$first['post_type']);
                        }
                    }
                    if ($mapped) {
                        if ($mapped === 'repeater' && empty($f['sub_fields'])) {
                            $f['sub_fields'] = self::inferSub(array_filter([$m['formatted_value'] ?? null]));
                        }
                        $f['type'] = $mapped;
                    }
                }
                unset($f);
                $spec['field_groups'][] = ['title' => html_entity_decode((string)($t['labels']['singular_name'] ?? self::singular((string)($t['name'] ?? $wpSlug))), ENT_QUOTES) . ' fields',
                    'post_types' => [$slug], 'taxonomies' => [], 'position' => 'main', 'fields' => $fields];
            }
        }

        foreach ($taxList as $wpSlug => $t) {
            $wpSlug = (string)($t['slug'] ?? $wpSlug);
            if (in_array($wpSlug, self::SKIP_TAXES, true) || empty($t['rest_base'])) {
                continue;
            }
            $slug = self::TAX_ALIASES[$wpSlug] ?? self::slug($wpSlug, 'tax');
            $types = [];
            foreach ((array)($t['types'] ?? []) as $wt) {
                if (isset($spec['wp_map']['types'][$wt])) {
                    $types[] = $spec['wp_map']['types'][$wt]['slug'];
                }
            }
            $sample = $wp->get('/wp/v2/' . $t['rest_base'], ['per_page' => 50, 'orderby' => 'count', 'order' => 'desc']);
            $spec['wp_map']['taxonomies'][$wpSlug] = ['slug' => $slug, 'rest_base' => (string)$t['rest_base'], 'total' => $sample['total']];
            if (!$types) {
                continue;
            }
            $terms = $sample['ok'] && is_array($sample['data']) ? $sample['data'] : [];
            [$rewrite, $nested] = self::termRewrite($terms, $wp->site);
            $spec['taxonomies'][] = [
                'slug' => $slug,
                'singular' => html_entity_decode((string)($t['labels']['singular_name'] ?? self::singular((string)($t['name'] ?? $wpSlug))), ENT_QUOTES),
                'plural' => html_entity_decode((string)($t['name'] ?? ucfirst($wpSlug)), ENT_QUOTES),
                'post_types' => array_values(array_unique($types)),
                'hierarchical' => (bool)($t['hierarchical'] ?? false),
                'rewrite' => $rewrite ?? $slug,
                'nested' => $nested,
                'builtin' => isset(self::TAX_ALIASES[$wpSlug]),
            ];
        }
        return $spec;
    }

    /** CPT URL base from sample links; segments that equal an item's term slug become %taxonomy% tokens. */
    private static function rewriteWithTokens(array $items, string $site, array $wpTaxes, callable $termSlugs): ?string
    {
        $bases = [];
        foreach ($items as $it) {
            $link = (string)($it['link'] ?? '');
            if ($link === '' || str_contains($link, '?')) {
                continue;
            }
            $base = self::rewriteFromLink($link, $site, (string)($it['slug'] ?? ''));
            if ($base === null) {
                continue;
            }
            $segs = explode('/', $base);
            foreach ($segs as $i => $seg) {
                foreach ($wpTaxes as $wpTax) {
                    $ids = array_map('intval', (array)($it[$wpTax] ?? []));
                    if ($ids && in_array($seg, $termSlugs($wpTax, $ids), true)) {
                        $segs[$i] = '%' . (Generator::TAX_ALIASES[$wpTax] ?? self::slug($wpTax, 'tax')) . '%';
                        break;
                    }
                }
            }
            $bases[] = implode('/', $segs);
        }
        if (!$bases) {
            return null;
        }
        $counts = array_count_values($bases);
        arsort($counts);
        return (string)array_key_first($counts);
    }

    /** Term URL base ('' = site root) and whether child terms nest their parent's slug. */
    private static function termRewrite(array $terms, string $site): array
    {
        $base = null;
        $nested = true;
        foreach ($terms as $t) {
            $path = trim((string)parse_url((string)($t['link'] ?? ''), PHP_URL_PATH), '/');
            $sitePath = trim((string)parse_url($site, PHP_URL_PATH), '/');
            if ($sitePath !== '' && str_starts_with($path, $sitePath)) {
                $path = trim(substr($path, strlen($sitePath)), '/');
            }
            if ($path === '' || str_contains((string)($t['link'] ?? ''), '?')) {
                continue;
            }
            $parts = explode('/', $path);
            if (end($parts) !== (string)$t['slug']) {
                continue;
            }
            array_pop($parts);
            if (!empty($t['parent'])) {
                // A child whose URL has no extra segment compared to the base → flat URLs.
                if ($base !== null) {
                    $nested = count($parts) > count(array_filter(explode('/', $base), 'strlen'));
                }
                continue;
            }
            $base ??= implode('/', $parts);
        }
        if ($base !== null) {
            foreach ($terms as $t) {
                if (!empty($t['parent'])) {
                    $path = trim((string)parse_url((string)($t['link'] ?? ''), PHP_URL_PATH), '/');
                    $nested = substr_count($path, '/') > substr_count(trim($base . '/' . $t['slug'], '/'), '/');
                    break;
                }
            }
        }
        return [$base, $nested];
    }

    /** Build a spec from a WordPress export (WXR) parsed by Wxr::parse(). */
    public static function fromWxr(array $wxr): array
    {
        $spec = ['post_types' => [], 'taxonomies' => [], 'field_groups' => [], 'wp_map' => ['types' => [], 'taxonomies' => []]];
        $byType = [];
        foreach ($wxr['posts'] as $p) {
            $byType[$p['type']][] = $p;
        }
        foreach ($byType as $wpSlug => $items) {
            if (in_array($wpSlug, self::SKIP_TYPES, true)) {
                continue;
            }
            $slug = self::TYPE_ALIASES[$wpSlug] ?? self::slug($wpSlug);
            $spec['wp_map']['types'][$wpSlug] = ['slug' => $slug, 'total' => count($items)];
            if (!isset(self::TYPE_ALIASES[$wpSlug])) {
                $first = $items[0];
                $hier = (bool)array_filter($items, static fn($i) => !empty($i['parent']));
                $spec['post_types'][] = ['slug' => $slug, 'singular' => self::humanize(self::singular($wpSlug)), 'plural' => self::humanize(self::plural($wpSlug)),
                    'rewrite' => self::rewriteFromLink($first['link'], $wxr['site']['url'], $first['slug']) ?? $slug, 'hierarchical' => $hier,
                    'has_archive' => true, 'public' => true, 'icon' => self::guessIcon($wpSlug),
                    'supports' => ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit'] + ($hier ? [6 => 'page_attributes'] : [])];
            }
            $fieldValues = [];
            foreach (array_slice($items, 0, 50) as $it) {
                foreach ($it['fields'] as $k => $v) {
                    $fieldValues[$k][] = $v;
                }
            }
            if ($fieldValues) {
                $spec['field_groups'][] = ['title' => self::humanize(self::singular($wpSlug)) . ' fields', 'post_types' => [$slug], 'taxonomies' => [],
                    'position' => 'main', 'fields' => self::inferFields($fieldValues)];
            }
        }
        $taxTypes = [];
        foreach ($wxr['posts'] as $p) {
            foreach ($p['terms'] as $t) {
                $taxTypes[$t['taxonomy']][$p['type']] = true;
            }
        }
        foreach ($wxr['terms'] as $t) {
            $taxTypes[$t['taxonomy']] ??= [];
        }
        foreach ($taxTypes as $wpSlug => $types) {
            if (in_array($wpSlug, self::SKIP_TAXES, true)) {
                continue;
            }
            $slug = self::TAX_ALIASES[$wpSlug] ?? self::slug($wpSlug, 'tax');
            $spec['wp_map']['taxonomies'][$wpSlug] = ['slug' => $slug];
            $pts = [];
            foreach (array_keys($types) as $wt) {
                if (isset($spec['wp_map']['types'][$wt])) {
                    $pts[] = $spec['wp_map']['types'][$wt]['slug'];
                }
            }
            if (!$pts) {
                continue;
            }
            $hier = (bool)array_filter($wxr['terms'], static fn($x) => $x['taxonomy'] === $wpSlug && !empty($x['parent']));
            $spec['taxonomies'][] = ['slug' => $slug, 'singular' => self::humanize(self::singular($wpSlug)), 'plural' => self::humanize(self::plural($wpSlug)),
                'post_types' => array_values(array_unique($pts)), 'hierarchical' => $hier || $wpSlug === 'category', 'rewrite' => $slug, 'builtin' => isset(self::TAX_ALIASES[$wpSlug])];
        }
        return $spec;
    }

    /** Ask the configured AI provider to design a content model from a description. */
    public static function fromDescription(string $description): array
    {
        if (!Ai::enabled()) {
            throw new \RuntimeException('Enable and configure AI under Settings → AI first.');
        }
        $types = implode(', ', array_keys(ContentModel::FIELD_TYPES));
        $prompt = "Design a CMS content model for this website:\n\"\"\"\n$description\n\"\"\"\n\n"
            . "Return ONLY JSON with this shape:\n"
            . '{"post_types":[{"slug":"project","singular":"Project","plural":"Projects","rewrite":"projects","hierarchical":false,"icon":"briefcase"}],'
            . '"taxonomies":[{"slug":"project_type","singular":"Project type","plural":"Project types","post_types":["project"],"hierarchical":true,"rewrite":"project-type"}],'
            . '"field_groups":[{"title":"Project details","post_types":["project"],"fields":[{"name":"client","label":"Client","type":"text"},{"name":"status","label":"Status","type":"select","options":["active:Active","done:Completed"]}]}]}'
            . "\nRules: slugs lowercase with underscores, max 30 chars; do not recreate built-in \"post\" or \"page\" (but you may add field groups to them); "
            . "field types must be one of: $types; icons one of: " . implode(', ', ['box', 'briefcase', 'shopping', 'calendar', 'users', 'user', 'star', 'book', 'image', 'map-pin', 'message', 'globe', 'tag', 'home', 'monitor', 'file-text']) . '.';
        $r = Ai::complete($prompt, 'You are a senior information architect. Output strictly valid JSON.', ['max_tokens' => 4000, 'temperature' => 0.3]);
        if (!$r['ok']) {
            throw new \RuntimeException($r['error']);
        }
        if (!preg_match('/\{.*\}/s', $r['text'], $m) || !is_array($spec = json_decode($m[0], true))) {
            throw new \RuntimeException('The AI response was not valid JSON. Try again or simplify the description.');
        }
        return self::normalize($spec);
    }

    public static function normalize(array $spec): array
    {
        $spec += ['post_types' => [], 'taxonomies' => [], 'field_groups' => []];
        foreach ($spec['post_types'] as &$t) {
            $t['slug'] = self::slug((string)($t['slug'] ?? $t['singular'] ?? 'item'));
            $t += ['singular' => self::humanize($t['slug']), 'plural' => self::humanize(self::plural($t['slug'])), 'rewrite' => str_replace('_', '-', $t['slug']),
                'hierarchical' => false, 'has_archive' => true, 'public' => true, 'icon' => self::guessIcon($t['slug']),
                'supports' => ['title', 'editor', 'excerpt', 'featured_image', 'seo', 'frontend_edit']];
        }
        unset($t);
        foreach ($spec['taxonomies'] as &$x) {
            $x['slug'] = self::TAX_ALIASES[$x['slug'] ?? ''] ?? self::slug((string)($x['slug'] ?? 'group'), 'tax');
            $x += ['singular' => self::humanize($x['slug']), 'plural' => self::humanize(self::plural($x['slug'])), 'post_types' => [], 'hierarchical' => true];
            if (!array_key_exists('rewrite', $x) || $x['rewrite'] === null) {
                $x['rewrite'] = str_replace('_', '-', $x['slug']);
            }
        }
        unset($x);
        foreach ($spec['field_groups'] as &$g) {
            $g += ['title' => 'Fields', 'post_types' => [], 'taxonomies' => [], 'position' => 'main', 'fields' => []];
            foreach ($g['fields'] as &$f) {
                $f['name'] = preg_replace('/[^a-z0-9_]/', '_', strtolower((string)($f['name'] ?? $f['label'] ?? 'field')));
                $f += ['label' => self::humanize($f['name']), 'type' => 'text'];
                if (!isset(ContentModel::FIELD_TYPES[$f['type']])) {
                    $f['type'] = 'text';
                }
            }
            unset($f);
        }
        unset($g);
        return $spec;
    }

    // ------------------------------------------------------------------ apply

    /** Create / merge everything in the spec. Returns human-readable log lines. */
    public static function apply(array $spec): array
    {
        $spec = self::normalize($spec);
        $log = [];
        $types = ContentModel::types();
        foreach ($spec['post_types'] as $t) {
            $slug = $t['slug'];
            if (isset(ContentModel::builtinTypes()[$slug])) {
                continue;
            }
            $existing = $types[$slug] ?? null;
            $def = [
                'slug' => $slug,
                'singular' => mb_substr(strip_tags($t['singular']), 0, 80),
                'plural' => mb_substr(strip_tags($t['plural']), 0, 80),
                'icon' => in_array($t['icon'], \Slate\Icons::names(), true) ? $t['icon'] : 'box',
                'public' => (bool)$t['public'],
                'has_archive' => (bool)$t['has_archive'],
                'hierarchical' => (bool)$t['hierarchical'],
                'show_in_menu' => true,
                'rewrite' => self::cleanRewrite((string)$t['rewrite']) ?: $slug,
                'menu_position' => (int)($existing['menu_position'] ?? 30),
                'supports' => array_values(array_intersect((array)$t['supports'], array_keys(ContentModel::SUPPORTS))),
                'description' => (string)($t['description'] ?? ''),
            ];
            ContentModel::saveType($existing ? array_merge($existing, $def, ['builtin' => false]) : $def);
            $log[] = ($existing ? 'Updated' : 'Created') . " post type “{$def['plural']}” (/{$def['rewrite']}/)";
        }
        foreach ($spec['taxonomies'] as $x) {
            $slug = $x['slug'];
            $pts = array_values(array_filter((array)$x['post_types'], static fn($p) => ContentModel::type($p) !== null));
            $existing = ContentModel::taxonomy($slug);
            $rewrite = array_key_exists('rewrite', $x) && $x['rewrite'] === '' ? '' : (self::cleanRewrite((string)($x['rewrite'] ?? '')) ?: $slug);
            if ($existing) {
                $merged = array_values(array_unique(array_merge($existing['post_types'], $pts)));
                ContentModel::saveTaxonomy(array_merge($existing, ['post_types' => $merged, 'rewrite' => $rewrite, 'nested' => (bool)($x['nested'] ?? ($existing['nested'] ?? true))]));
                $log[] = "Updated taxonomy “{$existing['plural']}” → " . implode(', ', $merged) . ' (/' . $rewrite . ($rewrite !== '' ? '/' : '') . '…)';
                continue;
            }
            ContentModel::saveTaxonomy(['slug' => $slug, 'singular' => strip_tags($x['singular']), 'plural' => strip_tags($x['plural']), 'post_types' => $pts,
                'hierarchical' => (bool)$x['hierarchical'], 'public' => true, 'rewrite' => $rewrite, 'nested' => (bool)($x['nested'] ?? true)]);
            $log[] = "Created taxonomy “{$x['plural']}” for " . (implode(', ', $pts) ?: '—');
        }
        $groups = ContentModel::fieldGroups();
        foreach ($spec['field_groups'] as $g) {
            $id = 'auto' . substr(md5(strtolower($g['title'])), 0, 10);
            $existing = $groups[$id] ?? null;
            $fields = [];
            foreach (array_merge($existing['fields'] ?? [], $g['fields']) as $f) {
                $fields[$f['name']] = self::cleanField($f);
            }
            ContentModel::saveFieldGroup([
                'id' => $id,
                'title' => strip_tags($g['title']),
                'position' => in_array($g['position'], ['main', 'side'], true) ? $g['position'] : 'main',
                'location' => [
                    'post_types' => array_values(array_unique(array_merge($existing['location']['post_types'] ?? [], array_filter((array)$g['post_types'], static fn($p) => ContentModel::type($p) !== null)))),
                    'taxonomies' => array_values(array_unique(array_merge($existing['location']['taxonomies'] ?? [], array_filter((array)$g['taxonomies'], static fn($p) => ContentModel::taxonomy($p) !== null)))),
                ],
                'fields' => array_values($fields),
            ]);
            $log[] = ($existing ? 'Updated' : 'Created') . " field group “{$g['title']}” (" . count($fields) . ' fields)';
        }
        PageCache::clear();
        return $log ?: ['Nothing to create — the content model already matches.'];
    }

    /** Slugify each URL segment but keep %taxonomy% tokens. */
    public static function cleanRewrite(string $r): string
    {
        $segs = array_filter(explode('/', trim($r, '/')), 'strlen');
        return implode('/', array_map(static fn($seg) => preg_match('/^%[a-z0-9_-]+%$/', $seg) ? $seg : slugify($seg), $segs));
    }

    private static function cleanField(array $f): array
    {
        $out = [
            'name' => preg_replace('/[^a-z0-9_]/', '_', strtolower((string)$f['name'])),
            'label' => strip_tags((string)($f['label'] ?? $f['name'])),
            'type' => isset(ContentModel::FIELD_TYPES[$f['type'] ?? '']) ? $f['type'] : 'text',
            'required' => !empty($f['required']),
            'help' => strip_tags((string)($f['help'] ?? '')),
            'placeholder' => '',
            'default' => '',
            'width' => '100',
            'options' => array_values(array_map('strval', (array)($f['options'] ?? []))),
            'post_type' => (string)($f['post_type'] ?? ''),
        ];
        if ($out['type'] === 'repeater') {
            $out['sub_fields'] = array_map([self::class, 'cleanField'], (array)($f['sub_fields'] ?? []));
        }
        return $out;
    }

    // ------------------------------------------------------------------ inference helpers

    /** @param array<string, array> $values field name => sample values */
    public static function inferFields(array $values): array
    {
        $fields = [];
        foreach ($values as $name => $samples) {
            $fields[] = ['name' => preg_replace('/[^a-z0-9_]/', '_', strtolower((string)$name)), 'label' => self::humanize((string)$name),
                'type' => self::inferType((string)$name, $samples)] + (self::inferType((string)$name, $samples) === 'repeater' ? ['sub_fields' => self::inferSub($samples)] : []);
        }
        return $fields;
    }

    public static function inferSub(array $samples): array
    {
        $vals = [];
        foreach ($samples as $s) {
            foreach ((array)$s as $row) {
                if (is_array($row)) {
                    foreach ($row as $k => $v) {
                        $vals[$k][] = $v;
                    }
                }
            }
        }
        return array_map(static fn($f) => $f['type'] === 'repeater' ? array_merge($f, ['type' => 'textarea']) : $f, self::inferFields($vals));
    }

    public static function inferType(string $name, array $samples): string
    {
        $samples = array_values(array_filter($samples, static fn($v) => $v !== null && $v !== '' && $v !== false && $v !== []));
        $n = strtolower($name);
        if (!$samples) {
            return preg_match('/image|photo|logo|thumb|avatar|banner/', $n) ? 'image' : (preg_match('/url|link|website/', $n) ? 'url' : 'text');
        }
        $v = $samples[0];
        if (is_bool($v)) {
            return 'toggle';
        }
        if (is_array($v)) {
            if (isset($v['url']) && (isset($v['mime_type']) || isset($v['sizes']) || preg_match('/\.(jpe?g|png|gif|webp|avif|svg)$/i', (string)$v['url']))) {
                return 'image';
            }
            if (isset($v['url'], $v['title'])) {
                return 'url';
            }
            if (array_is_list($v)) {
                $first = $v[0] ?? null;
                if (is_array($first) && isset($first['url'], $first['sizes'])) {
                    return 'gallery';
                }
                if (is_array($first) && isset($first['ID'], $first['post_type'])) {
                    return 'relation';
                }
                if (is_int($first) || (is_string($first) && ctype_digit($first))) {
                    return preg_match('/gallery|images|photos/', $n) ? 'gallery' : 'relation';
                }
                if (is_array($first)) {
                    return 'repeater';
                }
                return 'checkbox';
            }
            return 'repeater';
        }
        $s = (string)$v;
        if (is_int($v) || is_float($v) || (is_numeric($s) && strlen($s) < 15)) {
            return preg_match('/image|photo|logo|thumb|avatar|banner|icon/', $n) ? 'image' : 'number';
        }
        if (filter_var($s, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        if (preg_match('#^https?://\S+\.(jpe?g|png|gif|webp|avif)(\?.*)?$#i', $s)) {
            return 'image';
        }
        if (preg_match('#^(https?://|/)\S+$#', $s)) {
            return 'url';
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $s)) {
            return 'color';
        }
        if (preg_match('/^\d{4}-?\d{2}-?\d{2}$/', $s)) {
            return 'date';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $s)) {
            return 'datetime';
        }
        if (preg_match('/^\+?[\d\s().-]{7,20}$/', $s) && preg_match('/phone|tel|mobile/', $n)) {
            return 'tel';
        }
        if ($s !== strip_tags($s)) {
            return 'richtext';
        }
        return mb_strlen($s) > 120 || str_contains($s, "\n") ? 'textarea' : 'text';
    }

    private static function supportsFrom(array $items, bool $hier): array
    {
        $s = ['title', 'editor', 'seo', 'frontend_edit'];
        $first = $items[0] ?? [];
        if (array_key_exists('excerpt', $first)) {
            $s[] = 'excerpt';
        }
        if (array_key_exists('featured_media', $first)) {
            $s[] = 'featured_image';
        }
        if (array_key_exists('author', $first)) {
            $s[] = 'author';
        }
        if ($hier || array_key_exists('menu_order', $first)) {
            $s[] = 'page_attributes';
        }
        return $s;
    }

    public static function rewriteFromLink(string $link, string $site, string $itemSlug): ?string
    {
        if ($link === '') {
            return null;
        }
        $path = trim((string)parse_url($link, PHP_URL_PATH), '/');
        $sitePath = trim((string)parse_url($site, PHP_URL_PATH), '/');
        if ($sitePath !== '' && str_starts_with($path, $sitePath)) {
            $path = trim(substr($path, strlen($sitePath)), '/');
        }
        $parts = explode('/', $path);
        if ($itemSlug !== '' && end($parts) === rawurlencode($itemSlug) || end($parts) === $itemSlug) {
            array_pop($parts);
        }
        $base = implode('/', $parts);
        return $base !== '' && !str_contains($base, '?') ? $base : null;
    }

    private static function isPluginMeta(string $k): bool
    {
        return (bool)preg_match('/^(footnotes|inline_featured_image|rank_math|yoast|_yoast|_elementor|elementor|_wp_|wp_|ast-|site-|theme-transparent|stick-header|footer-sml|ekit|jet_|_jet|slide_template|_oembed|om_disable|spay|jetpack|_edit_|_thumbnail_id|sfwd)/i', $k);
    }

    public static function slug(string $s, string $kind = 'type'): string
    {
        $s = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '_', $s));
        $s = trim($s, '_-');
        if ($s === '' || !preg_match('/^[a-z]/', $s)) {
            $s = ($kind === 'tax' ? 'tax_' : 'type_') . $s;
        }
        $s = substr($s, 0, 36);
        if (in_array($s, ContentModel::RESERVED, true)) {
            $s .= $kind === 'tax' ? '_tax' : '_item';
        }
        return $s;
    }

    public static function humanize(string $s): string
    {
        return ucfirst(trim(preg_replace('/[_-]+/', ' ', $s)));
    }

    public static function singular(string $s): string
    {
        $s = trim($s);
        return match (true) {
            (bool)preg_match('/ies$/i', $s) => preg_replace('/ies$/i', 'y', $s),
            (bool)preg_match('/(ss|us)$/i', $s) => $s,
            (bool)preg_match('/(x|ch|sh)es$/i', $s) => substr($s, 0, -2),
            (bool)preg_match('/s$/i', $s) => substr($s, 0, -1),
            default => $s,
        };
    }

    public static function plural(string $s): string
    {
        return match (true) {
            (bool)preg_match('/[^aeiou]y$/i', $s) => substr($s, 0, -1) . 'ies',
            (bool)preg_match('/(s|x|ch|sh)$/i', $s) => $s . 'es',
            default => $s . 's',
        };
    }

    public static function guessIcon(string $s): string
    {
        $s = strtolower($s);
        $map = ['portfolio|project|work|case' => 'briefcase', 'product|shop|store|item' => 'shopping', 'event|webinar|session' => 'calendar',
            'team|member|staff|people|author' => 'users', 'testimonial|review|quote' => 'message', 'service' => 'star', 'course|lesson|book|guide|doc' => 'book',
            'gallery|photo' => 'image', 'location|place|property|listing|store' => 'map-pin', 'job|career' => 'briefcase', 'faq' => 'message'];
        foreach ($map as $re => $icon) {
            if (preg_match('/' . $re . '/', $s)) {
                return $icon;
            }
        }
        return 'box';
    }
}
