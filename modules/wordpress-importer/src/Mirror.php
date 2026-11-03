<?php
declare(strict_types=1);

namespace SlateModules\WpImport;

use Slate\Options;
use SlateModules\AutoCpt\WpClient;

/**
 * Mirrors a WordPress/Elementor site's design into Slate:
 * - downloads theme, plugin and Elementor CSS/JS/fonts into the SAME paths (/wp-content/..., /wp-includes/...),
 *   so every relative URL inside the CSS keeps working;
 * - captures the header & footer markup (Elementor Theme Builder or classic theme), body classes and inline config;
 * - records per-page Elementor CSS (post-123.css) and single-post layouts with title/content placeholders;
 * - extracts brand colours & fonts (Elementor global kit) so the admin can match the website.
 */
final class Mirror
{
    private const ASSET_EXT = ['css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'json', 'map', 'mp4', 'webm'];
    private const SKIP_JS = '#(googletagmanager|gtag\(|fbq\(|clarity\.ms|hotjar|wp-emoji|_wpemojiSettings|admin-bar|wp-embed|connect\.facebook|analytics\.js|cdn-cgi/challenge|comment-reply)#i';
    private string $origin;
    private string $host;
    private WpClient $http;
    private int $downloaded = 0;
    private array $seen = [];

    public function __construct(string $origin)
    {
        $this->origin = rtrim($origin, '/');
        $port = parse_url($this->origin, PHP_URL_PORT);
        $this->host = preg_replace('/^www\./', '', (string)parse_url($this->origin, PHP_URL_HOST)) . ($port ? ':' . $port : '');
        $this->http = new WpClient($this->origin);
    }

    // ------------------------------------------------------------------ DOM helpers

    public static function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        return $doc;
    }

    private static function outer(\DOMNode $n): string
    {
        return (string)$n->ownerDocument->saveHTML($n);
    }

    private static function first(\DOMXPath $x, array $queries): ?\DOMElement
    {
        foreach ($queries as $q) {
            $list = $x->query($q);
            if ($list && $list->length) {
                $el = $list->item(0);
                return $el instanceof \DOMElement ? $el : null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ URL helpers

    private function isOwn(string $url): bool
    {
        $port = parse_url($url, PHP_URL_PORT);
        $h = preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST)) . ($port ? ':' . $port : '');
        return $h === '' || strcasecmp($h, $this->host) === 0;
    }

    private function abs(string $url, string $base = ''): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES);
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $this->origin . $url;
        }
        $baseDir = $base !== '' ? preg_replace('#/[^/]*$#', '/', $base) : $this->origin . '/';
        $abs = $baseDir . $url;
        // Normalise ../ and ./ segments.
        $parts = parse_url($abs);
        $segs = [];
        foreach (explode('/', (string)($parts['path'] ?? '/')) as $s) {
            if ($s === '..') {
                array_pop($segs);
            } elseif ($s !== '.') {
                $segs[] = $s;
            }
        }
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? $this->host) . (isset($parts['port']) ? ':' . $parts['port'] : '') . implode('/', $segs) . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /** Local root-relative path for an own asset, e.g. /wp-content/themes/x/style.css */
    private function localPath(string $abs): ?string
    {
        if (!$this->isOwn($abs)) {
            return null;
        }
        $path = rawurldecode((string)parse_url($abs, PHP_URL_PATH));
        if (!preg_match('#^/(wp-content|wp-includes)/#', $path) || str_contains($path, '..')) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::ASSET_EXT, true) ? $path : null;
    }

    /** Download an own asset to the same path under the Slate root. Returns the root-relative URL. */
    public function asset(string $url, string $base = '', int $depth = 0): string
    {
        $abs = $this->abs($url, $base);
        $path = $this->localPath($abs);
        if ($path === null) {
            return $abs;
        }
        $rel = url(ltrim($path, '/'));
        $dest = SLATE_ROOT . $path;
        if (isset($this->seen[$path]) || (is_file($dest) && filesize($dest) > 0 && $depth > 0)) {
            return $rel;
        }
        $this->seen[$path] = true;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true) && !is_dir(dirname($dest))) {
            return $abs;
        }
        $tmp = $dest . '.part';
        if (!$this->http->download($abs, $tmp)) {
            return $abs;
        }
        if (str_ends_with($path, '.css')) {
            $css = (string)file_get_contents($tmp);
            $css = $this->rewriteCss($css, $abs, $depth);
            file_put_contents($tmp, $css);
        }
        @rename($tmp, $dest);
        $this->downloaded++;
        return $rel;
    }

    /** Download assets referenced by CSS; make absolute own URLs root-relative. */
    private function rewriteCss(string $css, string $cssUrl, int $depth): string
    {
        $css = preg_replace_callback('#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i', function ($m) use ($cssUrl, $depth) {
            $u = trim($m[2]);
            if (str_starts_with($u, 'data:') || str_starts_with($u, '#')) {
                return $m[0];
            }
            $abs = $this->abs($u, $cssUrl);
            if (!$this->isOwn($abs)) {
                return $m[0];
            }
            if (str_contains((string)parse_url($abs, PHP_URL_PATH), '/wp-content/uploads/') && !str_contains($abs, '/uploads/elementor/')) {
                // Media library files are served by the compatibility route (redirects to the imported copy).
                return 'url(' . $m[1] . (string)parse_url($abs, PHP_URL_PATH) . $m[1] . ')';
            }
            return $depth < 2 ? 'url(' . $m[1] . $this->asset($abs, '', $depth + 1) . $m[1] . ')' : $m[0];
        }, $css);
        $css = preg_replace_callback('#@import\s+(?:url\()?\s*[\'"]([^\'"]+)[\'"]\s*\)?#i', function ($m) use ($cssUrl, $depth) {
            return '@import url("' . ($depth < 2 ? $this->asset($m[1], $cssUrl, $depth + 1) : $m[1]) . '")';
        }, (string)$css);
        return (string)$css;
    }

    /** Make own links relative inside captured markup. */
    public function rewriteMarkup(string $html): string
    {
        $h = preg_quote($this->host, '#');
        $h = preg_quote($this->host, '~');
        $base = rtrim(base_path(), '/');
        $html = preg_replace('~(?:https?:)?//(?:www\.)?' . $h . '(?=[/"\'\s?#\\\\]|$)~i', $base, $html);
        // JSON-escaped URLs inside inline config (https:\/\/example.com\/wp-content\/…).
        $html = preg_replace('~(?:https?:)?\\\\/\\\\/(?:www\.)?' . $h . '(?=\\\\/|["\'])~i', str_replace('/', '\\/', $base), (string)$html);
        return (string)$html;
    }

    // ------------------------------------------------------------------ site capture

    public function captureSite(string $html): array
    {
        $doc = self::dom($html);
        $x = new \DOMXPath($doc);
        $head = [];
        $foot = [];
        $keys = [];
        $css = 0;
        $js = 0;
        $headEl = $doc->getElementsByTagName('head')->item(0);
        $bodyEl = $doc->getElementsByTagName('body')->item(0);

        $collect = function (\DOMNode $scope, array &$into) use (&$keys, &$css, &$js, $x, $doc): void {
            foreach ($x->query('.//link[@rel="stylesheet"] | .//link[@rel="preload"][@as="style"] | .//style | .//script', $scope) as $n) {
                /** @var \DOMElement $n */
                if ($n->parentNode && in_array(strtolower($n->parentNode->nodeName), ['noscript', 'template'], true)) {
                    continue;
                }
                $tag = strtolower($n->nodeName);
                if ($tag === 'link') {
                    $href = $n->getAttribute('href');
                    if ($href === '' || isset($keys[$href]) || preg_match('#admin-bar|dashicons#', $href)) {
                        continue;
                    }
                    $keys[$href] = true;
                    if (preg_match('#/uploads/elementor/css/post-\d+\.css#', $href) && !preg_match('#post-' . $this->kitId($doc) . '\.css#', $href)) {
                        continue; // page-specific, captured per page
                    }
                    $into[] = ['t' => 'css', 'href' => $this->asset($href), 'media' => $n->getAttribute('media') ?: 'all', 'id' => $n->getAttribute('id')];
                    $css++;
                } elseif ($tag === 'style') {
                    $text = (string)$n->textContent;
                    if (trim($text) === '' || preg_match('#wp-emoji|admin-bar#', $text)) {
                        continue;
                    }
                    $into[] = ['t' => 'style', 'css' => $this->rewriteMarkup($this->rewriteCss($text, $this->origin . '/', 1)), 'id' => $n->getAttribute('id')];
                } else {
                    $src = $n->getAttribute('src');
                    $type = strtolower($n->getAttribute('type'));
                    if ($type === 'application/ld+json' || $type === 'text/template' || str_contains($type, 'template')) {
                        continue;
                    }
                    if ($src !== '') {
                        if (isset($keys[$src]) || preg_match(self::SKIP_JS, $src)) {
                            continue;
                        }
                        $keys[$src] = true;
                        $into[] = ['t' => 'js', 'src' => $this->asset($src), 'id' => $n->getAttribute('id'), 'defer' => $n->hasAttribute('defer'), 'async' => $n->hasAttribute('async'), 'module' => $type === 'module'];
                        $js++;
                    } else {
                        $code = (string)$n->textContent;
                        if (trim($code) === '' || preg_match(self::SKIP_JS, $code)) {
                            continue;
                        }
                        $into[] = ['t' => 'inline', 'js' => $this->rewriteMarkup($code), 'id' => $n->getAttribute('id')];
                    }
                }
            }
        };
        if ($headEl) {
            $collect($headEl, $head);
        }

        // Header / footer: Elementor Theme Builder first, then common theme markup.
        $header = self::first($x, ['//*[@data-elementor-type="header"]', '//header[contains(@class,"site-header")]', '//header[@id="masthead"]', '//body//header']);
        $footer = self::first($x, ['//*[@data-elementor-type="footer"]', '//footer[contains(@class,"site-footer")]', '//footer[@id="colophon"]', '(//body//footer)[last()]']);
        $popups = [];
        foreach ($x->query('//*[@data-elementor-type="popup"]') as $p) {
            $popups[] = $this->rewriteMarkup(self::outer($p));
        }
        $headerHtml = $header ? $this->rewriteMarkup($this->fixMedia(self::outer($header))) : '';
        $footerHtml = $footer ? $this->rewriteMarkup($this->fixMedia(self::outer($footer))) : '';
        // Wrapper elements that hold page content in classic themes (e.g. <div id="page" class="site">).
        $wrapOpen = '';
        $wrapClose = '';
        if ($header && $header->parentNode instanceof \DOMElement && strtolower($header->parentNode->nodeName) !== 'body') {
            $p = $header->parentNode;
            $attrs = '';
            foreach ($p->attributes as $a) {
                $attrs .= ' ' . $a->nodeName . '="' . htmlspecialchars($a->nodeValue, ENT_QUOTES) . '"';
            }
            $wrapOpen = '<' . $p->nodeName . $attrs . '>';
            $wrapClose = '</' . $p->nodeName . '>';
        }
        // Scripts in <body> (outside header/footer) belong at the end of the page.
        if ($bodyEl) {
            $collect($bodyEl, $foot);
        }
        $bodyClass = $bodyEl instanceof \DOMElement ? $bodyEl->getAttribute('class') : '';
        $bodyClass = trim(preg_replace('/\b(home|blog|page-id-\d+|postid-\d+|page-template[\w-]*|elementor-page(-\d+)?|single[\w-]*|page|wp-singular|logged-in|admin-bar|customize-support|privacy-policy|post-template[\w-]*|wp-theme-[\w-]+|wp-child-theme-[\w-]+)\b/', ' ', $bodyClass));
        $htmlEl = $doc->documentElement;
        $site = [
            'head' => $head, 'foot' => $foot, 'header' => $headerHtml, 'footer' => $footerHtml, 'popups' => $popups,
            'wrap_open' => $wrapOpen, 'wrap_close' => $wrapClose, 'body_class' => preg_replace('/\s+/', ' ', $bodyClass),
            'lang' => $htmlEl instanceof \DOMElement ? ($htmlEl->getAttribute('lang') ?: 'en') : 'en', 'keys' => array_keys($keys),
            'origin' => $this->origin, 'captured_at' => gmdate('c'),
        ];
        Options::set('wpm_site', $site);
        Options::set('wpm_brand', $this->brand($html));
        return ['css' => $css, 'js' => $js, 'header' => $headerHtml !== '', 'footer' => $footerHtml !== '', 'files' => $this->downloaded];
    }

    private function kitId(\DOMDocument $doc): string
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement && preg_match('/elementor-kit-(\d+)/', $body->getAttribute('class'), $m)) {
            return $m[1];
        }
        return 'x';
    }

    /** Media library URLs inside captured header/footer → imported copies when known. */
    private function fixMedia(string $html): string
    {
        $h = preg_quote($this->host, '#');
        $html = preg_replace_callback('#(?:https?:)?//(?:www\.)?' . $h . '(/wp-content/uploads/[^"\'\s\)<>,]+)#i', static function ($m) {
            $id = Importer::mediaByUrl($m[1]);
            return $id ? \Slate\Media::url($id) : $m[1];
        }, $html);
        return (string)preg_replace('#\s(srcset|sizes)="[^"]*"#i', '', (string)$html);
    }

    /** All global CSS captured from the site (inline styles + Elementor kit / global files). */
    public static function globalCss(): string
    {
        $css = '';
        foreach ((array)(Options::get('wpm_site')['head'] ?? []) as $a) {
            if ($a['t'] === 'style') {
                $css .= $a['css'] . "\n";
            } elseif ($a['t'] === 'css') {
                $path = (string)parse_url((string)$a['href'], PHP_URL_PATH);
                $f = SLATE_ROOT . '/' . ltrim(substr($path, strlen(base_path())), '/');
                if (preg_match('#/(uploads/elementor/css/|themes/)#', $path) && is_file($f) && filesize($f) < 3000000) {
                    $css .= file_get_contents($f) . "\n";
                }
            }
        }
        return $css;
    }

    /** Elementor global colours & fonts (kit), with sensible fallbacks from :root variables. */
    public function brand(string $html): array
    {
        $css = '';
        foreach ((array)(Options::get('wpm_site')['head'] ?? []) as $a) {
            if ($a['t'] === 'style') {
                $css .= $a['css'];
            } elseif ($a['t'] === 'css' && preg_match('#/(elementor/css/post-\d+|global|kit)[^/]*\.css#', (string)$a['href'])) {
                $f = SLATE_ROOT . '/' . ltrim(substr((string)parse_url($a['href'], PHP_URL_PATH), strlen(base_path())), '/');
                if (is_file($f)) {
                    $css .= file_get_contents($f);
                }
            }
        }
        $var = static function (string $name) use ($css): ?string {
            return preg_match('/--' . preg_quote($name, '/') . '\s*:\s*([^;}]+)/i', $css, $m) ? trim($m[1]) : null;
        };
        $font = static function (?string $f): ?string {
            return $f ? trim(explode(',', trim($f, '"\' '))[0], '"\' ') : null;
        };
        $brand = [
            'primary' => $var('e-global-color-primary') ?? $var('e-global-color-accent') ?? $var('wp--preset--color--primary'),
            'secondary' => $var('e-global-color-secondary'),
            'text' => $var('e-global-color-text'),
            'accent' => $var('e-global-color-accent'),
            'font_heading' => $font($var('e-global-typography-primary-font-family')),
            'font_body' => $font($var('e-global-typography-text-font-family')),
        ];
        // Google Fonts link, so the admin can load the same families.
        if (preg_match('#href=["\'](https://fonts\.googleapis\.com/css2?\?[^"\']+)#i', $html, $m)) {
            $brand['fonts_url'] = html_entity_decode($m[1], ENT_QUOTES);
        }
        return array_filter($brand);
    }

    // ------------------------------------------------------------------ per page

    /** Per-page CSS (Elementor post-123.css, inline styles) and body classes, stored on the post. */
    public static function pageFields(string $html, string $origin, Mirror $m): array
    {
        $site = Options::get('wpm_site') ?: [];
        $known = array_flip($site['keys'] ?? []);
        $doc = self::dom($html);
        $x = new \DOMXPath($doc);
        $css = [];
        $styles = [];
        foreach ($x->query('//link[@rel="stylesheet"]') as $n) {
            $href = $n->getAttribute('href');
            if ($href !== '' && (!isset($known[$href]) || preg_match('#/uploads/elementor/css/post-\d+\.css#', $href))) {
                $css[] = $m->asset($href);
            }
        }
        foreach ($x->query('//style') as $n) {
            $id = $n instanceof \DOMElement ? $n->getAttribute('id') : '';
            $t = (string)$n->textContent;
            if (preg_match('/^elementor-post-|^elementor-frontend-inline|-inline-css$/', $id) && !self::inSite($site, $t)) {
                $styles[] = $m->rewriteMarkup($t);
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $classes = $body instanceof \DOMElement ? $body->getAttribute('class') : '';
        preg_match_all('/\b(elementor-page(?:-\d+)?|elementor-template-[\w-]+|page-template[\w-]*|elementor-default|single-[\w-]+|page)\b/', $classes, $mm);
        return array_filter(['_wpm_css' => array_values(array_unique($css)), '_wpm_style' => implode("\n", $styles), '_wpm_body_class' => implode(' ', array_unique($mm[0]))]);
    }

    private static function inSite(array $site, string $css): bool
    {
        foreach ($site['head'] ?? [] as $a) {
            if ($a['t'] === 'style' && trim($a['css']) === trim($css)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Capture the single-post layout (e.g. an Elementor "Single Post" template) with placeholders
     * {{SLATE_TITLE}}, {{SLATE_CONTENT}}, {{SLATE_IMAGE}}, {{SLATE_DATE}} so imported posts stay editable.
     */
    public static function captureSingle(string $html, string $title, string $origin): ?string
    {
        $doc = self::dom($html);
        $x = new \DOMXPath($doc);
        $main = self::first($x, ['//*[@data-elementor-type="single-post"]', '//*[@data-elementor-type="single"]', '//main', '//*[@id="primary"]', '//*[@id="content"]']);
        if (!$main) {
            return null;
        }
        $content = self::first($x, ['.//*[contains(@class,"elementor-widget-theme-post-content")]//*[contains(@class,"elementor-widget-container")]',
            './/*[contains(@class,"elementor-widget-theme-post-content")]', './/*[contains(@class,"entry-content")]', './/article']);
        if (!$content || !$main->contains($content) && $main !== $content) {
            return null;
        }
        while ($content->firstChild) {
            $content->removeChild($content->firstChild);
        }
        $content->appendChild($doc->createTextNode('{{SLATE_CONTENT}}'));
        $titleEl = self::first($x, ['.//*[contains(@class,"elementor-widget-theme-post-title")]//*[self::h1 or self::h2]', './/h1[contains(@class,"entry-title")]', './/h1']);
        if ($titleEl && $main->contains($titleEl)) {
            while ($titleEl->firstChild) {
                $titleEl->removeChild($titleEl->firstChild);
            }
            $titleEl->appendChild($doc->createTextNode('{{SLATE_TITLE}}'));
        }
        $img = self::first($x, ['.//*[contains(@class,"elementor-widget-theme-post-featured-image")]//img', './/*[contains(@class,"post-thumbnail")]//img']);
        if ($img && $main->contains($img)) {
            $img->setAttribute('src', '{{SLATE_IMAGE}}');
            $img->removeAttribute('srcset');
            $img->removeAttribute('sizes');
        }
        $date = self::first($x, ['.//*[contains(@class,"elementor-post-info__item--type-date")]', './/time[contains(@class,"entry-date")]']);
        if ($date && $main->contains($date)) {
            while ($date->firstChild) {
                $date->removeChild($date->firstChild);
            }
            $date->appendChild($doc->createTextNode('{{SLATE_DATE}}'));
        }
        $out = self::outer($main);
        $out = str_replace(htmlspecialchars($title, ENT_QUOTES), '{{SLATE_TITLE}}', $out);
        return (new self($origin))->rewriteMarkup($out);
    }

    /** Elementor-rendered body of a page (for WXR imports). */
    public static function extractElementor(string $html, int $postId): ?string
    {
        $x = new \DOMXPath(self::dom($html));
        $el = self::first($x, ['//*[@data-elementor-id="' . $postId . '"]', '//*[@data-elementor-type="wp-page"]', '//*[@data-elementor-type="wp-post"]']);
        return $el ? self::outer($el) : null;
    }

    /** Build a menu from the first navigation inside the site header. */
    public static function navFromHtml(string $html): array
    {
        $x = new \DOMXPath(self::dom($html));
        $ul = self::first($x, ['//*[@data-elementor-type="header"]//nav//ul', '//header//nav//ul', '//nav//ul']);
        if (!$ul) {
            return [];
        }
        $items = [];
        $id = 1;
        $walk = function (\DOMElement $list, int $parent) use (&$walk, &$items, &$id, $x): void {
            foreach ($x->query('./li', $list) as $li) {
                $a = $x->query('./a', $li)->item(0);
                if (!$a instanceof \DOMElement) {
                    continue;
                }
                $my = $id++;
                $items[] = ['old_id' => $my, 'title' => trim($a->textContent), 'order' => $my, 'parent' => $parent, 'type' => 'custom',
                    'object' => '', 'object_id' => 0, 'url' => $a->getAttribute('href'), 'target' => $a->getAttribute('target')];
                $sub = $x->query('./ul', $li)->item(0);
                if ($sub instanceof \DOMElement) {
                    $walk($sub, $my);
                }
            }
        };
        $walk($ul, 0);
        return $items;
    }
}
