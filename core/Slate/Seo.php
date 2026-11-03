<?php
declare(strict_types=1);

namespace Slate;

/**
 * Built-in SEO: titles, meta description, robots, canonical, Open Graph / Twitter,
 * verification tags, JSON-LD schema graph, analytics integrations and custom code.
 */
final class Seo
{
    public const SCHEMA_TYPES = ['WebPage' => 'Web page', 'Article' => 'Article', 'BlogPosting' => 'Blog post', 'NewsArticle' => 'News article',
        'Product' => 'Product', 'Service' => 'Service', 'Event' => 'Event', 'Recipe' => 'Recipe', 'FAQPage' => 'FAQ page',
        'AboutPage' => 'About page', 'ContactPage' => 'Contact page', 'CollectionPage' => 'Collection page', 'None' => 'No schema'];

    /** Request context set by the frontend router. */
    public static array $ctx = ['kind' => 'home'];

    public static function typeDefaults(string $type): array
    {
        $all = Options::get('seo_types') ?: [];
        return ($all[$type] ?? []) + [
            'title_template' => '',
            'robots' => 'index',
            'schema' => $type === 'post' ? 'BlogPosting' : ($type === 'page' ? 'WebPage' : 'Article'),
            'sitemap' => true,
        ];
    }

    public static function taxDefaults(string $tax): array
    {
        $all = Options::get('seo_taxonomies') ?: [];
        return ($all[$tax] ?? []) + ['title_template' => '', 'robots' => $tax === 'tag' ? 'noindex' : 'index', 'sitemap' => $tax !== 'tag'];
    }

    public static function vars(string $template, array $extra = []): string
    {
        $out = strtr($template, $extra + [
            '%site%' => (string)Options::get('site_title'),
            '%sitename%' => (string)Options::get('site_title'),
            '%tagline%' => (string)Options::get('tagline'),
            '%sep%' => (string)Options::get('seo_separator', '–'),
            '%year%' => date('Y'),
            '%page%' => (self::$ctx['page'] ?? 1) > 1 ? 'Page ' . self::$ctx['page'] : '',
            '%title%' => '',
            '%category%' => '',
        ]);
        $sep = preg_quote((string)Options::get('seo_separator', '–'), '/');
        $out = preg_replace('/\s+/', ' ', $out);
        $out = preg_replace("/^(\s*$sep\s*)+|(\s*$sep\s*)+$/u", '', trim($out));
        return trim((string)$out);
    }

    public static function title(): string
    {
        $c = self::$ctx;
        $global = (string)Options::get('seo_title_template', '%title% %sep% %site%');
        $title = match ($c['kind']) {
            'home' => self::vars((string)Options::get('seo_home_title', '%site% %sep% %tagline%')),
            'single' => self::postTitle($c['post']),
            'term' => self::vars(($c['term']['seo']['title'] ?? '') ?: (self::taxDefaults($c['term']['taxonomy'])['title_template'] ?: $global), ['%title%' => $c['term']['name']]),
            'archive' => self::vars($global, ['%title%' => $c['title'] ?? '']),
            'search' => self::vars($global, ['%title%' => 'Search results for “' . ($c['query'] ?? '') . '”']),
            '404' => self::vars($global, ['%title%' => 'Page not found']),
            default => (string)Options::get('site_title'),
        };
        if (($c['page'] ?? 1) > 1 && $c['kind'] !== 'single') {
            $title .= ' ' . Options::get('seo_separator', '–') . ' Page ' . $c['page'];
        }
        return Hooks::filter('seo_title', $title, $c);
    }

    public static function postTitle(array $post): string
    {
        if (!empty($post['seo']['title'])) {
            return self::vars($post['seo']['title'], ['%title%' => $post['title']]);
        }
        if (self::isFrontPage($post)) {
            return self::vars((string)Options::get('seo_home_title', '%site% %sep% %tagline%'), ['%title%' => $post['title']]);
        }
        $tpl = self::typeDefaults($post['type'])['title_template'] ?: (string)Options::get('seo_title_template', '%title% %sep% %site%');
        return self::vars($tpl, ['%title%' => $post['title']]);
    }

    public static function description(): string
    {
        $c = self::$ctx;
        $auto = (bool)Options::get('seo_auto_description', true);
        $d = match ($c['kind']) {
            'home' => (string)Options::get('seo_home_description') ?: (string)Options::get('tagline'),
            'single' => ($c['post']['seo']['description'] ?? '') ?: (self::isFrontPage($c['post']) && Options::get('seo_home_description')
                ? (string)Options::get('seo_home_description')
                : ($auto ? Posts::excerpt($c['post'], 158) : '')),
            'term' => ($c['term']['seo']['description'] ?? '') ?: ($auto ? str_limit((string)$c['term']['description'], 158) : ''),
            default => '',
        };
        return Hooks::filter('seo_description', self::vars($d), $c);
    }

    public static function robots(): string
    {
        $c = self::$ctx;
        if (Options::get('seo_noindex_site')) {
            return 'noindex, nofollow';
        }
        $index = 'index';
        $follow = 'follow';
        switch ($c['kind']) {
            case 'single':
                $seo = $c['post']['seo'];
                $index = ($seo['robots_index'] ?? 'default') === 'default' ? self::typeDefaults($c['post']['type'])['robots'] : $seo['robots_index'];
                $follow = !empty($seo['nofollow']) ? 'nofollow' : 'follow';
                if ($c['post']['status'] !== 'publish') {
                    $index = 'noindex';
                }
                break;
            case 'term':
                $seo = $c['term']['seo'];
                $index = ($seo['robots_index'] ?? 'default') === 'default' ? self::taxDefaults($c['term']['taxonomy'])['robots'] : $seo['robots_index'];
                break;
            case 'search':
                $index = Options::get('seo_noindex_search', true) ? 'noindex' : 'index';
                break;
            case '404':
                $index = 'noindex';
                break;
        }
        if (($c['page'] ?? 1) > 1 && Options::get('seo_noindex_paged')) {
            $index = 'noindex';
        }
        $out = "$index, $follow";
        if ($index === 'index' && Options::get('seo_max_snippet', true)) {
            $out .= ', max-snippet:-1, max-image-preview:large, max-video-preview:-1';
        }
        return Hooks::filter('seo_robots', $out, $c);
    }

    public static function canonical(): string
    {
        $c = self::$ctx;
        $url = match ($c['kind']) {
            'home' => site_url(),
            'single' => ($c['post']['seo']['canonical'] ?? '') ?: Posts::permalink($c['post'], true),
            'term' => Terms::link($c['term'], true),
            'archive' => $c['url'] ?? site_url(),
            default => '',
        };
        if ($url && ($c['page'] ?? 1) > 1 && $c['kind'] !== 'single') {
            $url = rtrim($url, '/') . '/page/' . $c['page'] . '/';
        }
        return $url;
    }

    public static function image(): ?array
    {
        $c = self::$ctx;
        $id = 0;
        if ($c['kind'] === 'single') {
            $id = (int)($c['post']['seo']['og_image'] ?? 0) ?: (int)($c['post']['featured_media_id'] ?? 0);
        }
        $id = $id ?: (int)Options::get('seo_og_default_image');
        $m = $id ? Media::find($id) : null;
        return $m ? ['url' => site_url('content/uploads/' . $m['path']), 'width' => $m['width'], 'height' => $m['height'], 'alt' => $m['alt'], 'mime' => $m['mime']] : null;
    }

    public static function isFrontPage(array $post): bool
    {
        return Options::get('front_page') === 'page' && (int)Options::get('front_page_id') === (int)$post['id'];
    }

    /** Everything printed in <head>. */
    public static function head(): string
    {
        $h = [];
        $title = self::title();
        $desc = self::description();
        $h[] = '<title>' . e($title) . '</title>';
        if ($desc !== '') {
            $h[] = '<meta name="description" content="' . e($desc) . '">';
        }
        $h[] = '<meta name="robots" content="' . e(self::robots()) . '">';
        if (Options::get('seo_canonical', true) && ($canonical = self::canonical())) {
            $h[] = '<link rel="canonical" href="' . e($canonical) . '">';
        }
        foreach (['gsc_verification' => 'google-site-verification', 'bing_verification' => 'msvalidate.01',
                     'yandex_verification' => 'yandex-verification', 'pinterest_verification' => 'p:domain_verify'] as $opt => $name) {
            if ($v = trim((string)Options::get($opt))) {
                // Accept either the value or the full meta tag pasted by the user.
                if (preg_match('/content=["\']([^"\']+)/', $v, $m)) {
                    $v = $m[1];
                }
                $h[] = '<meta name="' . $name . '" content="' . e($v) . '">';
            }
        }
        if ($icon = (int)Options::get('site_icon')) {
            $h[] = '<link rel="icon" href="' . e(Media::url($icon)) . '">';
            $h[] = '<link rel="apple-touch-icon" href="' . e(Media::url($icon)) . '">';
        }
        if (Options::get('seo_sitemap_enabled', true)) {
            $h[] = '<link rel="sitemap" type="application/xml" href="' . e(url('sitemap.xml')) . '">';
        }
        foreach (array_filter(array_map('trim', explode("\n", (string)Options::get('perf_preconnect')))) as $origin) {
            $h[] = '<link rel="preconnect" href="' . e($origin) . '" crossorigin>';
        }
        if (Options::get('seo_og_enabled', true)) {
            $h = array_merge($h, self::openGraph($title, $desc));
        }
        if (Options::get('seo_schema_enabled', true) && self::$ctx['kind'] !== '404') {
            $graph = self::schema();
            if ($graph) {
                $h[] = '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
            }
        }
        $h[] = self::trackingHead();
        $h[] = (string)Options::get('code_head');
        return Hooks::filter('seo_head', implode("\n", array_filter($h)));
    }

    private static function openGraph(string $title, string $desc): array
    {
        $c = self::$ctx;
        $isArticle = $c['kind'] === 'single' && $c['post']['type'] !== 'page';
        $ogTitle = $c['kind'] === 'single' ? (($c['post']['seo']['og_title'] ?? '') ?: $title) : $title;
        $ogDesc = $c['kind'] === 'single' ? (($c['post']['seo']['og_description'] ?? '') ?: $desc) : $desc;
        $o = [
            '<meta property="og:locale" content="' . e(str_replace('-', '_', (string)Options::get('language', 'en'))) . '">',
            '<meta property="og:type" content="' . ($isArticle ? 'article' : 'website') . '">',
            '<meta property="og:title" content="' . e($ogTitle) . '">',
            '<meta property="og:site_name" content="' . e((string)Options::get('site_title')) . '">',
        ];
        if ($ogDesc) {
            $o[] = '<meta property="og:description" content="' . e($ogDesc) . '">';
        }
        if ($canonical = self::canonical()) {
            $o[] = '<meta property="og:url" content="' . e($canonical) . '">';
        }
        if ($img = self::image()) {
            $o[] = '<meta property="og:image" content="' . e($img['url']) . '">';
            if ($img['width']) {
                $o[] = '<meta property="og:image:width" content="' . (int)$img['width'] . '">';
                $o[] = '<meta property="og:image:height" content="' . (int)$img['height'] . '">';
            }
            if ($img['alt']) {
                $o[] = '<meta property="og:image:alt" content="' . e($img['alt']) . '">';
            }
            $o[] = '<meta property="og:image:type" content="' . e($img['mime']) . '">';
        }
        if ($isArticle) {
            $o[] = '<meta property="article:published_time" content="' . e(gmdate('c', strtotime($c['post']['published_at'] . ' UTC'))) . '">';
            $o[] = '<meta property="article:modified_time" content="' . e(gmdate('c', strtotime($c['post']['updated_at'] . ' UTC'))) . '">';
        }
        if ($fb = Options::get('seo_facebook_app_id')) {
            $o[] = '<meta property="fb:app_id" content="' . e($fb) . '">';
        }
        $o[] = '<meta name="twitter:card" content="' . e((string)Options::get('seo_twitter_card', 'summary_large_image')) . '">';
        if ($tw = Options::get('seo_twitter_site')) {
            $o[] = '<meta name="twitter:site" content="' . e('@' . ltrim($tw, '@')) . '">';
        }
        return $o;
    }

    public static function schema(): array
    {
        $c = self::$ctx;
        $home = site_url();
        $entityType = (string)Options::get('seo_schema_entity', 'Organization');
        $entityId = $home . '#' . ($entityType === 'Person' ? 'person' : 'organization');
        $graph = [];

        $entity = ['@type' => $entityType, '@id' => $entityId, 'name' => Options::get('seo_schema_name') ?: Options::get('site_title'), 'url' => $home];
        if ($logo = (int)(Options::get('seo_schema_logo') ?: Options::get('site_logo'))) {
            $m = Media::find($logo);
            if ($m) {
                $entity[$entityType === 'Person' ? 'image' : 'logo'] = ['@type' => 'ImageObject', 'url' => site_url('content/uploads/' . $m['path']),
                    'width' => (int)$m['width'], 'height' => (int)$m['height']];
            }
        }
        if ($phone = Options::get('seo_schema_phone')) {
            $entity['telephone'] = $phone;
        }
        if ($addr = trim((string)Options::get('seo_schema_address'))) {
            $entity['address'] = preg_replace('/\s*\n\s*/', ', ', $addr);
        }
        $sameAs = array_values(array_filter(array_map('trim', explode("\n", (string)Options::get('seo_schema_same_as')))));
        if ($sameAs) {
            $entity['sameAs'] = $sameAs;
        }
        $graph[] = $entity;

        $website = ['@type' => 'WebSite', '@id' => $home . '#website', 'url' => $home, 'name' => Options::get('site_title'),
            'description' => Options::get('tagline'), 'publisher' => ['@id' => $entityId], 'inLanguage' => Options::get('language', 'en')];
        if (Options::get('seo_schema_sitelinks_search', true)) {
            $website['potentialAction'] = ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $home . '?s={search_term_string}'],
                'query-input' => 'required name=search_term_string'];
        }
        $graph[] = $website;

        $canonical = self::canonical() ?: $home;
        $page = ['@type' => 'WebPage', '@id' => $canonical . '#webpage', 'url' => $canonical, 'name' => self::title(),
            'isPartOf' => ['@id' => $home . '#website'], 'inLanguage' => Options::get('language', 'en')];
        if ($d = self::description()) {
            $page['description'] = $d;
        }
        if ($c['kind'] === 'term' || $c['kind'] === 'archive') {
            $page['@type'] = 'CollectionPage';
        }

        if ($c['kind'] === 'single') {
            $post = $c['post'];
            $type = ($post['seo']['schema_type'] ?? '') ?: self::typeDefaults($post['type'])['schema'];
            $img = self::image();
            if ($img) {
                $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $img['url']];
            }
            $page['datePublished'] = $post['published_at'] ? gmdate('c', strtotime($post['published_at'] . ' UTC')) : null;
            $page['dateModified'] = gmdate('c', strtotime($post['updated_at'] . ' UTC'));
            if (in_array($type, ['AboutPage', 'ContactPage', 'FAQPage', 'CollectionPage'], true)) {
                $page['@type'] = $type;
            } elseif ($type !== 'WebPage' && $type !== 'None') {
                $node = ['@type' => $type, '@id' => $canonical . '#' . strtolower($type), 'mainEntityOfPage' => ['@id' => $canonical . '#webpage']];
                if (in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
                    $author = $post['author_id'] ? DB::row('SELECT display_name, username FROM {{users}} WHERE id = :id', ['id' => (int)$post['author_id']]) : null;
                    $node += ['headline' => mb_substr($post['title'], 0, 110), 'datePublished' => $page['datePublished'], 'dateModified' => $page['dateModified'],
                        'publisher' => ['@id' => $entityId], 'author' => ['@type' => 'Person', 'name' => $author['display_name'] ?? $author['username'] ?? (string)Options::get('site_title')]];
                } else {
                    $node['name'] = $post['title'];
                }
                if ($d = self::description()) {
                    $node['description'] = $d;
                }
                if ($img) {
                    $node['image'] = $img['url'];
                }
                $graph[] = Hooks::filter('seo_schema_node', $node, $post);
            }
            if ($type === 'None') {
                $page = null;
            }
        }
        if ($page) {
            $graph[] = array_filter($page, static fn($v) => $v !== null);
        }

        if (Options::get('seo_schema_breadcrumbs', true) && $c['kind'] !== 'home') {
            $crumbs = self::breadcrumbs();
            if (count($crumbs) > 1) {
                $items = [];
                foreach ($crumbs as $i => $cr) {
                    $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $cr['label'], 'item' => $cr['url']];
                }
                $graph[] = ['@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'itemListElement' => $items];
            }
        }

        // Custom JSON-LD entered on the post.
        if ($c['kind'] === 'single' && !empty($c['post']['seo']['schema_custom'])) {
            $custom = json_decode($c['post']['seo']['schema_custom'], true);
            if (is_array($custom)) {
                $custom = isset($custom['@graph']) ? $custom['@graph'] : (array_is_list($custom) ? $custom : [$custom]);
                foreach ($custom as $node) {
                    if (is_array($node)) {
                        unset($node['@context']);
                        $graph[] = $node;
                    }
                }
            }
        }
        return Hooks::filter('seo_schema', $graph, $c);
    }

    /** Breadcrumb trail for the current request: [['label','url']]. */
    public static function breadcrumbs(): array
    {
        $c = self::$ctx;
        $crumbs = [['label' => 'Home', 'url' => site_url()]];
        if ($c['kind'] === 'single') {
            $post = $c['post'];
            $type = ContentModel::type($post['type']);
            if ($post['type'] === 'page') {
                $chain = [];
                $p = $post['parent_id'] ? Posts::find((int)$post['parent_id']) : null;
                $guard = 0;
                while ($p && $guard++ < 10) {
                    array_unshift($chain, ['label' => $p['title'], 'url' => Posts::permalink($p, true)]);
                    $p = $p['parent_id'] ? Posts::find((int)$p['parent_id']) : null;
                }
                $crumbs = array_merge($crumbs, $chain);
            } elseif (!empty($type['has_archive']) && $post['type'] !== 'post' && $type['rewrite'] !== '') {
                $crumbs[] = ['label' => $type['plural'], 'url' => site_url(Posts::trail('/' . $type['rewrite']))];
            }
            if (!self::isFrontPage($post)) {
                $crumbs[] = ['label' => $post['title'], 'url' => Posts::permalink($post, true)];
            }
        } elseif ($c['kind'] === 'term') {
            $crumbs[] = ['label' => $c['term']['name'], 'url' => Terms::link($c['term'], true)];
        } elseif ($c['kind'] === 'archive') {
            $crumbs[] = ['label' => $c['title'] ?? '', 'url' => $c['url'] ?? ''];
        }
        return $crumbs;
    }

    private static function trackingAllowed(): bool
    {
        return !(Options::get('tracking_skip_logged_in', true) && isset($_COOKIE[Session::FLAG]));
    }

    public static function trackingHead(): string
    {
        if (!self::trackingAllowed()) {
            return '<!-- Tracking disabled for logged-in users -->';
        }
        $o = [];
        if ($gtm = Options::get('gtm_id')) {
            $o[] = "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . e($gtm) . "');</script>";
        }
        if ($ga = Options::get('ga4_id')) {
            $o[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . e($ga) . '"></script>';
            $o[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . e($ga) . "');</script>";
        }
        if ($ads = Options::get('adsense_client')) {
            $o[] = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . e($ads) . '" crossorigin="anonymous"></script>';
        }
        if ($px = Options::get('meta_pixel_id')) {
            $o[] = "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . e($px) . "');fbq('track','PageView');</script>";
        }
        if ($cl = Options::get('clarity_id')) {
            $o[] = '<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","' . e($cl) . '");</script>';
        }
        return implode("\n", $o);
    }

    public static function bodyOpen(): string
    {
        $o = [];
        if (self::trackingAllowed() && ($gtm = Options::get('gtm_id'))) {
            $o[] = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . e($gtm) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
        }
        $o[] = (string)Options::get('code_body');
        return implode("\n", array_filter($o));
    }

    public static function footer(): string
    {
        $o = [];
        if (self::trackingAllowed() && ($li = Options::get('linkedin_partner_id'))) {
            $o[] = '<script>_linkedin_partner_id="' . e($li) . '";window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName("script")[0];var b=document.createElement("script");b.type="text/javascript";b.async=true;b.src="https://snap.licdn.com/li.lms-analytics/insight.min.js";s.parentNode.insertBefore(b,s);})(window.lintrk);</script>';
        }
        $o[] = (string)Options::get('code_footer');
        return implode("\n", array_filter($o));
    }

    public static function robotsTxt(): string
    {
        if (Options::get('seo_noindex_site')) {
            return "User-agent: *\nDisallow: /\n";
        }
        $txt = (string)Options::get('seo_robots_txt');
        $txt = str_replace('/admin/', '/' . admin_slug() . '/', $txt);
        $sitemap = Options::get('seo_sitemap_enabled', true) ? site_url('sitemap.xml') : '';
        $txt = str_replace('{sitemap}', $sitemap, $txt);
        if (!$sitemap) {
            $txt = preg_replace('/^Sitemap:\s*$/m', '', $txt);
        }
        return rtrim((string)$txt) . "\n";
    }
}
