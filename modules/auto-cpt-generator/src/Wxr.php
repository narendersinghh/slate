<?php
declare(strict_types=1);

namespace SlateModules\AutoCpt;

/**
 * Parses a WordPress eXtended RSS export (Tools → Export → All content) into plain arrays.
 * Captures posts of every type, terms, attachments, menus, ACF fields and Yoast / Rank Math SEO meta.
 */
final class Wxr
{
    public static function parse(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Export file not found.');
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($prev);
        if (!$xml || !isset($xml->channel)) {
            throw new \RuntimeException('This does not look like a WordPress export (WXR) file.');
        }
        $ns = $xml->getDocNamespaces(true);
        $wpNs = $ns['wp'] ?? 'http://wordpress.org/export/1.2/';
        $ch = $xml->channel;
        $wp = $ch->children($wpNs);
        $out = [
            'site' => ['title' => (string)$ch->title, 'description' => (string)$ch->description, 'url' => rtrim((string)($wp->base_blog_url ?: $ch->link), '/')],
            'terms' => [], 'media' => [], 'posts' => [], 'menus' => [], 'authors' => [],
        ];
        foreach ($wp->category as $c) {
            $out['terms'][] = ['old_id' => (int)$c->term_id, 'taxonomy' => 'category', 'slug' => (string)$c->category_nicename,
                'name' => (string)$c->cat_name, 'parent_slug' => (string)$c->category_parent, 'description' => (string)$c->category_description];
        }
        foreach ($wp->tag as $t) {
            $out['terms'][] = ['old_id' => (int)$t->term_id, 'taxonomy' => 'post_tag', 'slug' => (string)$t->tag_slug, 'name' => (string)$t->tag_name,
                'parent_slug' => '', 'description' => (string)$t->tag_description];
        }
        foreach ($wp->term as $t) {
            $out['terms'][] = ['old_id' => (int)$t->term_id, 'taxonomy' => (string)$t->term_taxonomy, 'slug' => (string)$t->term_slug, 'name' => (string)$t->term_name,
                'parent_slug' => (string)$t->term_parent, 'description' => (string)$t->term_description];
        }
        foreach ($out['terms'] as &$t) {
            $t['parent'] = $t['parent_slug'] !== '' ? $t['parent_slug'] : null;
        }
        unset($t);

        $menuItems = [];
        foreach ($ch->item as $item) {
            $w = $item->children($wpNs);
            $content = $item->children($ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/');
            $excerpt = $item->children($ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/');
            $type = (string)$w->post_type;
            $meta = [];
            foreach ($w->postmeta as $pm) {
                $meta[(string)$pm->meta_key] = (string)$pm->meta_value;
            }
            $terms = [];
            foreach ($item->category as $cat) {
                $terms[] = ['taxonomy' => (string)$cat['domain'], 'slug' => (string)$cat['nicename'], 'name' => (string)$cat];
            }
            $base = ['old_id' => (int)$w->post_id, 'title' => (string)$item->title, 'slug' => (string)$w->post_name, 'status' => (string)$w->status,
                'date_gmt' => self::date((string)$w->post_date_gmt, (string)$w->post_date), 'parent' => (int)$w->post_parent, 'menu_order' => (int)$w->menu_order,
                'link' => (string)$item->link];
            if ($type === 'attachment') {
                $out['media'][] = $base + ['url' => (string)$w->attachment_url, 'alt' => $meta['_wp_attachment_image_alt'] ?? '',
                    'caption' => (string)$excerpt->encoded, 'description' => (string)$content->encoded];
                continue;
            }
            if ($type === 'nav_menu_item') {
                $menu = '';
                foreach ($terms as $t) {
                    if ($t['taxonomy'] === 'nav_menu') {
                        $menu = $t['name'];
                    }
                }
                $menuItems[$menu][] = ['old_id' => $base['old_id'], 'title' => $base['title'], 'order' => $base['menu_order'],
                    'parent' => (int)($meta['_menu_item_menu_item_parent'] ?? 0), 'type' => $meta['_menu_item_type'] ?? 'custom',
                    'object' => $meta['_menu_item_object'] ?? '', 'object_id' => (int)($meta['_menu_item_object_id'] ?? 0),
                    'url' => $meta['_menu_item_url'] ?? '', 'target' => $meta['_menu_item_target'] ?? ''];
                continue;
            }
            if (in_array($type, Generator::SKIP_TYPES, true)) {
                continue;
            }
            $out['posts'][] = $base + [
                'type' => $type,
                'content' => (string)$content->encoded,
                'excerpt' => (string)$excerpt->encoded,
                'thumbnail' => (int)($meta['_thumbnail_id'] ?? 0),
                'template' => $meta['_wp_page_template'] ?? '',
                'terms' => array_values(array_filter($terms, static fn($t) => $t['taxonomy'] !== 'nav_menu')),
                'fields' => self::acfFields($meta),
                'seo' => self::seoMeta($meta),
                'elementor' => isset($meta['_elementor_data']) && strlen($meta['_elementor_data']) > 2,
            ];
        }
        foreach ($menuItems as $name => $items) {
            usort($items, static fn($a, $b) => $a['order'] <=> $b['order']);
            $out['menus'][] = ['name' => $name ?: 'Menu', 'location' => '', 'items' => $items];
        }
        return $out;
    }

    private static function date(string $gmt, string $local): ?string
    {
        foreach ([$gmt, $local] as $d) {
            if ($d !== '' && !str_starts_with($d, '0000')) {
                return $d;
            }
        }
        return null;
    }

    /** ACF stores "field" + "_field" => "field_xxxxx". Also keep simple public meta. */
    private static function acfFields(array $meta): array
    {
        $fields = [];
        foreach ($meta as $k => $v) {
            if (str_starts_with($k, '_')) {
                continue;
            }
            $isAcf = isset($meta['_' . $k]) && str_starts_with($meta['_' . $k], 'field_');
            if (!$isAcf && preg_match('/^(rank_math|_?yoast|_?elementor|footnotes|ast-|site-|theme-|stick-|footer-|ekit|jet|slide_|spay|jetpack|wp_|_oembed|om_)/i', $k)) {
                continue;
            }
            // Skip ACF repeater sub-rows like "faq_0_question" – the parent holds the row count.
            if (preg_match('/^(.+)_(\d+)_(.+)$/', $k, $m) && isset($meta[$m[1]]) && ctype_digit((string)$meta[$m[1]])) {
                continue;
            }
            $value = self::maybeUnserialize($v);
            if ($isAcf && ctype_digit((string)$v) && self::hasRows($meta, $k)) {
                $value = self::rows($meta, $k, (int)$v);
            }
            $fields[$k] = $value;
        }
        return $fields;
    }

    private static function hasRows(array $meta, string $k): bool
    {
        foreach (array_keys($meta) as $mk) {
            if (str_starts_with($mk, $k . '_0_')) {
                return true;
            }
        }
        return false;
    }

    private static function rows(array $meta, string $k, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < min($count, 200); $i++) {
            $row = [];
            foreach ($meta as $mk => $mv) {
                if (str_starts_with($mk, "{$k}_{$i}_")) {
                    $row[substr($mk, strlen("{$k}_{$i}_"))] = self::maybeUnserialize($mv);
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Safely decode PHP-serialized arrays (no objects allowed). */
    public static function maybeUnserialize(string $v): mixed
    {
        if (preg_match('/^(a|s|i|b|d):\d*[:;]/', $v)) {
            $r = @unserialize($v, ['allowed_classes' => false]);
            return $r === false && $v !== 'b:0;' ? $v : $r;
        }
        return $v;
    }

    /** Yoast SEO + Rank Math meta → Slate SEO fields. */
    public static function seoMeta(array $m): array
    {
        $seo = [];
        $title = $m['_yoast_wpseo_title'] ?? $m['rank_math_title'] ?? '';
        $desc = $m['_yoast_wpseo_metadesc'] ?? $m['rank_math_description'] ?? '';
        if ($title !== '') {
            $seo['title'] = self::vars($title);
        }
        if ($desc !== '') {
            $seo['description'] = self::vars($desc);
        }
        if (($kw = $m['_yoast_wpseo_focuskw'] ?? $m['rank_math_focus_keyword'] ?? '') !== '') {
            $seo['focus_keyword'] = explode(',', $kw)[0];
        }
        if (($c = $m['_yoast_wpseo_canonical'] ?? $m['rank_math_canonical_url'] ?? '') !== '') {
            $seo['canonical'] = $c;
        }
        $robots = self::maybeUnserialize($m['rank_math_robots'] ?? '');
        if (($m['_yoast_wpseo_meta-robots-noindex'] ?? '') === '1' || (is_array($robots) && in_array('noindex', $robots, true))) {
            $seo['robots_index'] = 'noindex';
        }
        if (($m['_yoast_wpseo_meta-robots-nofollow'] ?? '') === '1' || (is_array($robots) && in_array('nofollow', $robots, true))) {
            $seo['nofollow'] = true;
        }
        foreach (['_yoast_wpseo_opengraph-title' => 'og_title', 'rank_math_facebook_title' => 'og_title',
                     '_yoast_wpseo_opengraph-description' => 'og_description', 'rank_math_facebook_description' => 'og_description'] as $k => $to) {
            if (!empty($m[$k])) {
                $seo[$to] = self::vars($m[$k]);
            }
        }
        if (!empty($m['_yoast_wpseo_opengraph-image'])) {
            $seo['og_image_url'] = $m['_yoast_wpseo_opengraph-image'];
        } elseif (!empty($m['rank_math_facebook_image'])) {
            $seo['og_image_url'] = $m['rank_math_facebook_image'];
        }
        return $seo;
    }

    /** Convert Yoast %%var%% / Rank Math %var% placeholders to Slate's. */
    public static function vars(string $s): string
    {
        $s = preg_replace('/%%(\w+)%%/', '%$1%', $s);
        return strtr((string)$s, ['%sitename%' => '%site%', '%sitedesc%' => '%tagline%', '%page%' => '', '%primary_category%' => '%category%',
            '%currentyear%' => '%year%', '%seo_title%' => '%title%', '%excerpt%' => '', '%sep%' => '%sep%']);
    }
}
