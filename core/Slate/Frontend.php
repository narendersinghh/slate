<?php
declare(strict_types=1);

namespace Slate;

/** Resolves public URLs to content and renders them through the active theme. */
final class Frontend
{
    /** Globals available to templates. */
    public static array $data = [];
    /** True while core is working out which content a URL points to (see resolving()). */
    private static bool $resolving = false;

    /**
     * True while core resolves the request URL (comparing permalinks with the requested path). Permalink filters
     * that decorate URLs for visitors (e.g. a language prefix already stripped by "route_path") can return the plain
     * path while this is true.
     */
    public static function resolving(): bool
    {
        return self::$resolving;
    }

    public static function handle(string $path): void
    {
        // Modules may map their URL schemes onto Slate's (e.g. "/x/2" → "/x/page/2").
        $path = (string)Hooks::filter('route_path', '/' . trim($path, '/'));

        // Module routes: Hooks::add('routes', fn(Router $r) => $r->get('/cart', fn() => ...)).
        if (Hooks::has('routes')) {
            $router = new Router();
            Hooks::action('routes', $router);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($router->dispatch($method === 'HEAD' ? 'GET' : $method, $path)) {
                return;
            }
        }
        self::$resolving = true;
        $page = 1;
        if (preg_match('#^(.*?)/page/(\d+)$#', $path, $m)) {
            $path = $m[1] ?: '/';
            $page = max(1, (int)$m[2]);
        }

        // Virtual files.
        if ($path === '/robots.txt') {
            self::send(Seo::robotsTxt(), 'text/plain');
        }
        if (preg_match('#^/([a-zA-Z0-9-]{8,128})\.txt$#', $path, $m) && ($key = Indexing::indexNowKey()) !== '' && hash_equals($key, $m[1])) {
            self::send($key, 'text/plain'); // IndexNow key file
        }
        if (Options::get('seo_sitemap_enabled', true)) {
            if ($path === '/sitemap.xml' || $path === '/sitemap_index.xml') {
                self::send(Sitemap::index(), 'application/xml');
            }
            if (preg_match('#^/sitemap-tax-([a-z0-9_-]+)\.xml$#', $path, $m)) {
                self::send(Sitemap::taxonomy($m[1]) ?? self::notFoundXml(), 'application/xml');
            }
            if (preg_match('#^/sitemap-([a-z0-9_-]+)-(\d+)\.xml$#', $path, $m)) {
                self::send(Sitemap::posts($m[1], (int)$m[2]) ?? self::notFoundXml(), 'application/xml');
            }
        }

        self::redirects($path);

        // Maintenance mode.
        if (Options::get('maintenance_mode') && !Auth::can('access_admin')) {
            http_response_code(503);
            header('Retry-After: 3600');
            Seo::$ctx = ['kind' => '404'];
            self::output('maintenance', ['message' => Options::get('maintenance_message')]);
            return;
        }

        Posts::publishScheduled();

        // Canonical trailing-slash redirect (GET only, no file extensions).
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rel = '/' . ltrim(substr($requestPath, strlen(base_path())), '/');
        if ($rel !== '/' && !str_contains(basename($rel), '.') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $want = Options::get('permalink_trailing_slash', true);
            $has = str_ends_with($rel, '/');
            if ($want !== $has) {
                $target = url($want ? rtrim($rel, '/') . '/' : rtrim($rel, '/'));
                $qs = $_SERVER['QUERY_STRING'] ?? '';
                redirect($target . ($qs ? '?' . $qs : ''), 301);
            }
        }

        // Search.
        if (isset($_GET['s']) && is_string($_GET['s'])) {
            $q = mb_substr(trim($_GET['s']), 0, 100);
            $types = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
            $result = Posts::query(['type' => $types, 'search' => $q, 'page' => $page]);
            Seo::$ctx = ['kind' => 'search', 'query' => $q, 'page' => $page];
            self::output('search', ['query' => $q, 'result' => $result]);
            return;
        }

        // Homepage.
        if ($path === '/') {
            if (Options::get('front_page') === 'page' && ($fp = Posts::find((int)Options::get('front_page_id'))) && $fp['status'] === 'publish') {
                self::single($fp);
                return;
            }
            $result = Posts::query(['type' => 'post', 'page' => $page, 'with_terms' => true]);
            Seo::$ctx = ['kind' => 'home', 'page' => $page];
            self::output('home', ['result' => $result, 'title' => (string)Options::get('site_title')], ['home', 'index']);
            return;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        // Preview of drafts by editors: /?preview=ID handled via query param on any URL.
        if (isset($_GET['preview']) && Auth::can('edit_posts') && ($p = Posts::find((int)$_GET['preview']))) {
            header('X-Robots-Tag: noindex');
            self::single($p);
            return;
        }

        // Posts page (when homepage is static).
        if (Options::get('front_page') === 'page' && ($pp = (int)Options::get('posts_page_id')) && ($postsPage = Posts::find($pp))
            && rtrim(parse_url(Posts::permalink($postsPage), PHP_URL_PATH), '/') === rtrim(url($path), '/')) {
            $result = Posts::query(['type' => 'post', 'page' => $page, 'with_terms' => true]);
            Seo::$ctx = ['kind' => 'archive', 'title' => $postsPage['title'], 'url' => Posts::permalink($postsPage, true), 'page' => $page, 'type' => 'post'];
            self::output('archive', ['result' => $result, 'title' => $postsPage['title'], 'type' => ContentModel::type('post')], ['archive-post', 'home', 'archive']);
            return;
        }

        // Built-in "post" permalink structure.
        if ($post = self::matchPostStructure($path)) {
            self::single($post);
            return;
        }

        // Custom post types by rewrite pattern (supports multi-segment bases and %taxonomy% tokens).
        $rel = '/' . implode('/', $segments);
        foreach (ContentModel::types() as $slug => $type) {
            if (empty($type['public']) || $slug === 'page' || $slug === 'post') {
                continue;
            }
            $base = trim((string)($type['rewrite'] ?? $slug), '/');
            if ($base === '') {
                continue;
            }
            $re = self::rewriteRegex($base);
            if (!str_contains($base, '%') && !empty($type['has_archive']) && preg_match('#^/' . $re . '$#', $rel)) {
                $result = Posts::query(['type' => $slug, 'page' => $page, 'with_terms' => true,
                    'orderby' => !empty($type['hierarchical']) ? 'menu_order' : 'published_at', 'order' => !empty($type['hierarchical']) ? 'ASC' : 'DESC']);
                Seo::$ctx = ['kind' => 'archive', 'title' => $type['plural'], 'url' => site_url(Posts::trail('/' . $base)), 'page' => $page, 'type' => $slug];
                self::output('archive', ['result' => $result, 'title' => $type['plural'], 'type' => $type], ["archive-$slug", 'archive']);
                return;
            }
            if (preg_match('#^/' . $re . '/([^/]+)$#', $rel, $m) && ($p = Posts::findBySlug($slug, end($m)))) {
                $canonical = (string)parse_url(Posts::permalink($p), PHP_URL_PATH);
                if (rtrim($canonical, '/') !== rtrim(url($rel), '/') && str_contains($base, '%')) {
                    redirect($canonical, 301); // right item, wrong term segment
                }
                self::single($p);
                return;
            }
        }
        $termRoute = function (bool $root) use ($rel, $segments, $page): bool {
            foreach (ContentModel::taxonomies() as $slug => $tax) {
                $base = trim((string)($tax['rewrite'] ?? $slug), '/');
                if (empty($tax['public']) || ($base === '') !== $root) {
                    continue;
                }
                $termSlug = null;
                if ($root) {
                    if (count($segments) === 1) {
                        $termSlug = $segments[0];
                    }
                } elseif (preg_match('#^/' . self::rewriteRegex($base) . '/(.+)$#', $rel, $m)) {
                    $parts = explode('/', end($m));
                    $termSlug = end($parts);
                }
                if ($termSlug === null || !($term = Terms::findBySlug($slug, $termSlug))) {
                    continue;
                }
                $result = Posts::query(['type' => $tax['post_types'] ?: ['post'], 'term' => [$term['id']], 'page' => $page, 'with_terms' => true]);
                Seo::$ctx = ['kind' => 'term', 'term' => $term, 'page' => $page, 'items' => $result['items'], 'per_page' => (int)Options::get('posts_per_page', 10)];
                self::output('taxonomy', ['result' => $result, 'term' => $term, 'taxonomy' => $tax, 'title' => $term['name']],
                    ["taxonomy-$slug-{$term['slug']}", "taxonomy-$slug", 'taxonomy', 'archive']);
                return true;
            }
            return false;
        };
        if ($termRoute(false)) {
            return;
        }

        // Pages (hierarchical path).
        if ($segments && ($p = Posts::findBySlug('page', end($segments)))) {
            if (rtrim(parse_url(Posts::permalink($p), PHP_URL_PATH), '/') === rtrim(url($path), '/')) {
                self::single($p);
                return;
            }
        }

        // Taxonomies whose archives live at the site root (e.g. /in/ for a "nationality" term).
        if ($termRoute(true)) {
            return;
        }

        self::notFound();
    }

    /** Regex for a rewrite base: literal segments, %taxonomy% tokens match any single segment. */
    private static function rewriteRegex(string $base): string
    {
        return implode('/', array_map(static fn($seg) => preg_match('/^%[a-z0-9_-]+%$/', $seg) ? '[^/]+' : preg_quote($seg, '#'), explode('/', $base)));
    }

    private static function matchPostStructure(string $path): ?array
    {
        $structure = Posts::postStructure();
        $regex = preg_quote(rtrim($structure, '/'), '#');
        $regex = strtr($regex, [
            '%year%' => '(?P<year>\d{4})', '%monthnum%' => '(?P<month>\d{2})', '%day%' => '(?P<day>\d{2})',
            '%slug%' => '(?P<slug>[a-z0-9_-]+)', '%id%' => '(?P<id>\d+)', '%category%' => '(?P<category>[a-z0-9_-]+)',
        ]);
        if (!preg_match('#^' . $regex . '$#', rtrim($path, '/'), $m)) {
            return null;
        }
        $post = !empty($m['id']) ? Posts::find((int)$m['id']) : (!empty($m['slug']) ? Posts::findBySlug('post', $m['slug']) : null);
        if (!$post || $post['type'] !== 'post' || $post['status'] !== 'publish') {
            return null;
        }
        // Ensure the full URL matches (date/category parts).
        return rtrim(parse_url(Posts::permalink($post), PHP_URL_PATH), '/') === rtrim(url($path), '/') ? $post : null;
    }

    public static function single(array $post): void
    {
        $viewable = $post['status'] === 'publish' || ($post['status'] === 'private' && Auth::can('edit_others_posts'))
            || (isset($_GET['preview']) && Auth::can('edit_posts'));
        $type = ContentModel::type($post['type']);
        if (!$viewable || !$type || empty($type['public'])) {
            self::notFound();
            return;
        }
        $terms = Terms::forPosts([$post['id']]);
        $post['terms'] = $terms[$post['id']] ?? [];
        if ($post['featured_media_id']) {
            Media::prime([(int)$post['featured_media_id']]);
        }
        Seo::$ctx = ['kind' => 'single', 'post' => $post];
        self::$data['post'] = $post;
        $candidates = [];
        if ($post['template']) {
            $candidates[] = 'template-' . $post['template'];
        }
        if (Seo::isFrontPage($post)) {
            $candidates[] = 'front-page';
        }
        $candidates[] = "single-{$post['type']}-{$post['slug']}";
        $candidates[] = $post['type'] === 'page' ? 'page' : "single-{$post['type']}";
        $candidates[] = 'single';
        self::output('single', ['post' => $post, 'type' => $type], $candidates);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        Seo::$ctx = ['kind' => '404'];
        self::output('404', [], ['404']);
    }

    /**
     * Render a full page through the active theme (or an admin Template), for modules that add their own views.
     * $kind is free-form (e.g. "shop-cart"); $candidates are theme template names to try. Set Seo::$ctx first.
     */
    public static function page(string $kind, array $vars = [], array $candidates = []): void
    {
        self::output($kind, $vars, $candidates);
    }

    /**
     * Render module-provided HTML inside the theme's header and footer, through the normal output pipeline
     * (minification, "html_output", page cache, call PageCache::skip() first for personal pages).
     */
    public static function content(string $kind, string $html, array $vars = []): void
    {
        self::$resolving = false;
        self::$data = array_merge(self::$data, $vars, ['kind' => $kind]);
        Hooks::action('route_resolved', $kind, $vars);
        ob_start();
        Theme::wrap($html);
        self::finish((string)ob_get_clean());
    }

    private static function output(string $kind, array $vars, array $candidates = []): void
    {
        self::$resolving = false;
        // The request is resolved: modules may redirect (e.g. to the right language) before anything renders…
        Hooks::action('route_resolved', $kind, $vars);
        // …or adjust what the view receives.
        $vars = (array)Hooks::filter('frontend_vars', $vars, $kind);
        self::$data = array_merge(self::$data, $vars, ['kind' => $kind]);
        $tpl = self::template($kind, $vars);
        PageEditor::begin($tpl); // a team member editing this item on the page (never for visitors)
        if ($kind === 'single' && isset($vars['post']) && HtmlPages::active($vars['post'])) {
            // Imported or AI-written page: served as its own document with its own design.
            $html = HtmlPages::render($vars['post'], self::templateContext($kind, $vars));
        } elseif ($tpl) {
            $html = Templates::renderPage($tpl, self::templateContext($kind, $vars));
        } else {
            ob_start();
            Theme::render($candidates ?: [$kind], $vars);
            $html = (string)ob_get_clean();
        }
        self::finish($html);
    }

    private static function finish(string $html): void
    {
        if (Options::get('html_minify', true)) {
            $html = self::minify($html);
        }
        $html = Hooks::filter('html_output', $html);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        PageCache::store($html);
    }

    /** Template from Templates (admin) for this view, if one is enabled. */
    private static function template(string $kind, array $vars): ?array
    {
        if (Theme::active() === '' || ($kind === 'maintenance')) {
            return null;
        }
        return match ($kind) {
            'single' => isset($vars['post']) ? Templates::resolve('single', $vars['post']['type'] . '__' . $vars['post']['slug']) : null,
            'archive' => Templates::resolve('archive', (string)($vars['type']['slug'] ?? 'post')),
            'home' => Templates::resolve('archive', 'post'),
            'taxonomy' => isset($vars['term']) ? Templates::resolve('term', (string)$vars['term']['taxonomy']) : null,
            'search' => Templates::resolve('special', 'search'),
            '404' => Templates::resolve('special', '404'),
            default => null,
        };
    }

    private static function templateContext(string $kind, array $vars): array
    {
        $result = $vars['result'] ?? null;
        $archive = ['title' => $vars['title'] ?? '', 'description' => '', 'count' => $result['total'] ?? 0];
        if (isset($vars['term'])) {
            $archive['title'] = $vars['term']['name'];
            $archive['description'] = e((string)$vars['term']['description']);
        }
        if ($kind === 'search') {
            $archive['title'] = 'Search: ' . ($vars['query'] ?? '');
        }
        return ['post' => $vars['post'] ?? null, 'result' => $result, 'term' => $vars['term'] ?? null, 'archive' => $archive, 'kind' => $kind,
            'page' => (int)($result['page'] ?? 1)];
    }

    private static function redirects(string $path): void
    {
        $rules = Options::get('redirects') ?: [];
        if (!$rules) {
            return;
        }
        $full = '/' . trim($path, '/');
        foreach ($rules as $r) {
            $from = '/' . trim((string)$r['from'], '/');
            $to = (string)$r['to'];
            $code = in_array((int)($r['code'] ?? 301), [301, 302, 307, 308, 410], true) ? (int)$r['code'] : 301;
            if (!empty($r['regex'])) {
                if (@preg_match('#^' . str_replace('#', '\#', trim((string)$r['from'])) . '$#', $full, $m)) {
                    $to = preg_replace_callback('/\$(\d)/', static fn($x) => $m[(int)$x[1]] ?? '', $to);
                } else {
                    continue;
                }
            } elseif (strcasecmp($from, $full) !== 0) {
                continue;
            }
            if ($code === 410) {
                http_response_code(410);
                exit('Gone');
            }
            redirect(preg_match('#^https?://#', $to) ? $to : url($to), $code);
        }
    }

    private static function send(string $body, string $type): never
    {
        header("Content-Type: $type; charset=utf-8");
        header('X-Robots-Tag: noindex, follow');
        echo $body;
        exit;
    }

    private static function notFoundXml(): string
    {
        http_response_code(404);
        return '<?xml version="1.0" encoding="UTF-8"?><error>Not found</error>';
    }

    /** Conservative HTML minifier that leaves <pre>, <textarea>, <script> and <style> intact. */
    public static function minify(string $html): string
    {
        $keep = [];
        $html = preg_replace_callback('#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is', static function ($m) use (&$keep) {
            $keep[] = $m[0];
            return '%%SLATE' . (count($keep) - 1) . '%%';
        }, $html);
        $html = preg_replace('/<!--(?!\[if|\s*cached|\/?se:).*?-->/s', '', (string)$html); // se: marks parts for the page editor (team members only)
        $html = preg_replace('/>\s+</', '> <', (string)$html);
        $html = preg_replace('/\s{2,}/', ' ', (string)$html);
        return preg_replace_callback('/%%SLATE(\d+)%%/', static fn($m) => $keep[(int)$m[1]], trim((string)$html));
    }
}
