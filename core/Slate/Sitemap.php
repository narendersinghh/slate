<?php
declare(strict_types=1);

namespace Slate;

/** XML sitemaps: /sitemap.xml (index) → /sitemap-{type}-{n}.xml, /sitemap-tax-{taxonomy}.xml */
final class Sitemap
{
    public static function includedTypes(): array
    {
        return array_keys(array_filter(ContentModel::types(), static fn($t, $slug) => !empty($t['public']) && Seo::typeDefaults($slug)['sitemap']
            && Seo::typeDefaults($slug)['robots'] !== 'noindex', ARRAY_FILTER_USE_BOTH));
    }

    public static function includedTaxonomies(): array
    {
        return array_keys(array_filter(ContentModel::taxonomies(), static fn($t, $slug) => !empty($t['public']) && Seo::taxDefaults($slug)['sitemap']
            && Seo::taxDefaults($slug)['robots'] !== 'noindex', ARRAY_FILTER_USE_BOTH));
    }

    public static function index(): string
    {
        $per = (int)Options::get('seo_sitemap_per_page', 1000);
        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<?xml-stylesheet type="text/xsl" href="' . e(url('core/assets/sitemap.xsl')) . '"?>',
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (self::includedTypes() as $type) {
            $row = DB::row("SELECT COUNT(*) c, MAX(updated_at) m FROM {{posts}} WHERE type = :t AND status = 'publish'", ['t' => $type]);
            $pages = (int)ceil(((int)$row['c']) / $per);
            for ($i = 1; $i <= $pages; $i++) {
                $x[] = '<sitemap><loc>' . e(site_url("sitemap-$type-$i.xml")) . '</loc><lastmod>' . gmdate('c', strtotime($row['m'] . ' UTC')) . '</lastmod></sitemap>';
            }
        }
        foreach (self::includedTaxonomies() as $tax) {
            if (DB::val('SELECT COUNT(*) FROM {{terms}} WHERE taxonomy = :t AND count > 0', ['t' => $tax])) {
                $x[] = '<sitemap><loc>' . e(site_url("sitemap-tax-$tax.xml")) . '</loc></sitemap>';
            }
        }
        $x[] = '</sitemapindex>';
        return implode("\n", $x);
    }

    public static function posts(string $type, int $page): ?string
    {
        if (!in_array($type, self::includedTypes(), true)) {
            return null;
        }
        $per = (int)Options::get('seo_sitemap_per_page', 1000);
        $offset = (max(1, $page) - 1) * $per;
        $rows = DB::all(
            "SELECT * FROM {{posts}} WHERE type = :t AND status = 'publish' ORDER BY published_at DESC LIMIT $per OFFSET $offset",
            ['t' => $type]
        );
        if (!$rows && $page > 1) {
            return null;
        }
        $posts = array_map([Posts::class, 'hydrate'], $rows);
        $withImages = Options::get('seo_sitemap_images', true);
        if ($withImages) {
            Media::prime(array_column($posts, 'featured_media_id'));
        }
        $parents = array_filter(array_column($posts, 'parent_id'));
        Posts::prime($parents);
        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<?xml-stylesheet type="text/xsl" href="' . e(url('core/assets/sitemap.xsl')) . '"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'];
        foreach ($posts as $p) {
            $robots = $p['seo']['robots_index'] ?? 'default';
            if ($robots === 'noindex' || !empty($p['seo']['canonical'])) {
                continue;
            }
            $x[] = '<url><loc>' . e(Posts::permalink($p, true)) . '</loc><lastmod>' . gmdate('c', strtotime($p['updated_at'] . ' UTC')) . '</lastmod>';
            if ($withImages && $p['featured_media_id'] && ($m = Media::find((int)$p['featured_media_id']))) {
                $x[] = '<image:image><image:loc>' . e(site_url('content/uploads/' . $m['path'])) . '</image:loc></image:image>';
            }
            $x[] = '</url>';
        }
        $x[] = '</urlset>';
        return implode("\n", $x);
    }

    public static function taxonomy(string $tax): ?string
    {
        if (!in_array($tax, self::includedTaxonomies(), true)) {
            return null;
        }
        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<?xml-stylesheet type="text/xsl" href="' . e(url('core/assets/sitemap.xsl')) . '"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (Terms::all($tax) as $t) {
            if ($t['count'] > 0 && ($t['seo']['robots_index'] ?? 'default') !== 'noindex') {
                $x[] = '<url><loc>' . e(Terms::link($t, true)) . '</loc></url>';
            }
        }
        $x[] = '</urlset>';
        return implode("\n", $x);
    }
}
