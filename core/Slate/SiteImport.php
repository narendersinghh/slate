<?php
declare(strict_types=1);

namespace Slate;

/**
 * Import ready-made HTML/CSS/JS pages (a .zip of a site, a folder, individual files, or page addresses) as Slate pages.
 *
 *   1. receive(): files go to content/uploads/sites/<folder>/ (HTML is parsed, never served from there). Files picked
 *      one by one are linked automatically: a page asking for "css/style.css" gets the uploaded "style.css"; CSS/JS no
 *      page uses are listed so the review step can attach them to the pages.
 *      fromUrls(): the same, fetched from page addresses (RemoteFetch, SSRF-safe) with every asset they use.
 *   2. scan(): the HTML pages found, with titles (and the address each came from).
 *   3. import(): one Slate page per chosen HTML file (HtmlPages format): asset URLs point at the folder, links between
 *      the imported pages point at their new addresses, editable elements and repeating groups are marked, and the
 *      chosen design is stored per page ('keep' the page's own CSS, or 'site' = the site's design system).
 *
 * Per-import facts live in <folder>/.pages/import.json (never served: dot folder): source, pages => [path => [url,
 * base, assets, failed]], assets (URL => file), links (URL => page path), linked, unused, failed, fetched, bytes.
 */
final class SiteImport
{
    public const ASSETS = ['css', 'js', 'mjs', 'map', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'txt', 'xml', 'webmanifest'];
    public const PAGES = ['html', 'htm'];
    private const MAX_FILES = 3000;
    private const MAX_BYTES = 300 * 1024 * 1024;
    /** Import by link: pages followed, assets, bytes per file / in total, seconds. */
    public const URL_MAX_PAGES = 20;
    private const URL_MAX_ASSETS = 600;
    private const URL_MAX_FILE = 15 * 1024 * 1024;
    private const URL_MAX_TOTAL = 150 * 1024 * 1024;
    private const URL_TIME = 240;
    private const MIME_EXT = ['text/css' => 'css', 'text/javascript' => 'js', 'application/javascript' => 'js', 'application/x-javascript' => 'js',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/bmp' => 'bmp', 'font/woff2' => 'woff2', 'font/woff' => 'woff',
        'application/font-woff' => 'woff', 'application/font-woff2' => 'woff2', 'font/ttf' => 'ttf', 'application/x-font-ttf' => 'ttf', 'font/otf' => 'otf',
        'application/vnd.ms-fontobject' => 'eot', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg',
        'application/json' => 'json', 'application/manifest+json' => 'webmanifest', 'application/pdf' => 'pdf'];

    public static function root(): string
    {
        return SLATE_UPLOADS . '/sites';
    }

    /**
     * Store uploaded files (a zip, or several files with their relative paths) in a new import folder.
     * $files: [['name' => relative path, 'tmp' => path on disk]]. Returns [ok, folder|error].
     */
    public static function receive(array $files): array
    {
        if (!$files) {
            return [false, 'Choose a .zip file or the files of your site.'];
        }
        $folder = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir = self::root() . '/' . $folder;
        $pages = $dir . '/.pages';
        if (!@mkdir($pages, 0755, true)) {
            return [false, 'Could not create the import folder (check that content/uploads is writable).'];
        }
        $count = 0;
        $bytes = 0;
        foreach ($files as $f) {
            $name = (string)$f['name'];
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip' && class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open((string)$f['tmp']) !== true) {
                    return [false, 'That zip file could not be opened.'];
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $st = $zip->statIndex($i);
                    if (!$st || str_ends_with($st['name'], '/')) {
                        continue;
                    }
                    if (++$count > self::MAX_FILES || ($bytes += (int)$st['size']) > self::MAX_BYTES) {
                        $zip->close();
                        return [false, 'The site is too large to import (max ' . self::MAX_FILES . ' files, ' . (self::MAX_BYTES >> 20) . ' MB).'];
                    }
                    self::store($dir, $st['name'], (string)$zip->getFromIndex($i));
                }
                $zip->close();
                continue;
            }
            if (++$count > self::MAX_FILES || ($bytes += (int)@filesize((string)$f['tmp'])) > self::MAX_BYTES) {
                return [false, 'Too many or too large files.'];
            }
            self::store($dir, $name, (string)file_get_contents((string)$f['tmp']));
        }
        self::flattenSingleRoot($dir);
        if (!self::scan($folder)) {
            return [false, 'No HTML pages were found in what you uploaded.'];
        }
        self::saveMeta($folder, ['source' => 'upload'] + self::autoLink($dir));
        return [true, $folder];
    }

    /**
     * Files picked one by one lose their folders: a page asking for "css/style.css" or a stylesheet asking for
     * "../img/bg.png" gets the uploaded file with that name (copied to where it is asked for). Returns
     * ['linked' => [[asked path, uploaded file]], 'unused' => [CSS/JS files no page or stylesheet uses]].
     */
    private static function autoLink(string $dir): array
    {
        $assets = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
            if (!str_starts_with($rel, '.pages/')) {
                $assets[$rel] = true;
            }
        }
        $byName = [];
        foreach (array_keys($assets) as $rel) {
            $byName[strtolower(basename($rel))][] = $rel;
        }
        $used = [];
        $linked = [];
        $visit = static function (string $from, string $text, bool $css) use ($dir, &$assets, $byName, &$used, &$linked): array {
            $found = [];
            foreach (self::refsIn($text, $css) as $ref) {
                if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|\{\{)#i', $ref)) {
                    continue;
                }
                $path = rawurldecode((string)preg_replace('/[?#].*$/', '', $ref));
                if ($path === '') {
                    continue;
                }
                $resolved = self::resolve(str_starts_with($path, '/') ? '' : trim(dirname($from), '.'), $path);
                if ($resolved === '' || str_ends_with($resolved, '/') || in_array(strtolower(pathinfo($resolved, PATHINFO_EXTENSION)), self::PAGES, true)) {
                    continue;
                }
                if (isset($assets[$resolved])) {
                    $used[$resolved] = true;
                    continue;
                }
                $cands = $byName[strtolower(basename($resolved))] ?? [];
                if (count($cands) === 1) {
                    $target = self::cleanRel($resolved);
                    if ($target !== null && !is_file($dir . '/' . $target)) {
                        @mkdir(dirname($dir . '/' . $target), 0755, true);
                        @copy($dir . '/' . $cands[0], $dir . '/' . $target);
                        $assets[$target] = true;
                        $found[] = $target;
                    }
                    $used[$cands[0]] = true;
                    $used[$target ?? $cands[0]] = true;
                    $linked[] = [$resolved, $cands[0]];
                }
            }
            return $found;
        };
        $pagesDir = $dir . '/.pages';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pagesDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array(strtolower($f->getExtension()), self::PAGES, true)) {
                $visit(ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($pagesDir))), '/'), (string)file_get_contents($f->getPathname()), false);
            }
        }
        // Stylesheets (also the ones just copied) ask for fonts, images and other stylesheets.
        $queue = array_values(array_filter(array_keys($assets), static fn($r) => strtolower(pathinfo($r, PATHINFO_EXTENSION)) === 'css'));
        $seen = [];
        while ($queue && count($seen) < 500) {
            $css = array_shift($queue);
            if (isset($seen[$css])) {
                continue;
            }
            $seen[$css] = true;
            foreach ($visit($css, (string)file_get_contents($dir . '/' . $css), true) as $new) {
                if (str_ends_with(strtolower($new), '.css')) {
                    $queue[] = $new;
                }
            }
        }
        $unused = [];
        foreach (array_keys($assets) as $rel) {
            if (!isset($used[$rel]) && in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), ['css', 'js', 'mjs'], true)) {
                $unused[] = $rel;
            }
        }
        sort($unused);
        return ['linked' => $linked, 'unused' => $unused];
    }

    /** URLs a page (src, href, srcset, poster, url() in styles) or a stylesheet (url(), @import) asks for. */
    private static function refsIn(string $text, bool $css): array
    {
        $out = [];
        if (!$css && preg_match_all('#\s(?:src|href|poster|data-src|data-bg|data-background)\s*=\s*["\']([^"\']+)["\']#i', $text, $m)) {
            $out = $m[1];
        }
        if (!$css && preg_match_all('#\s(?:srcset|data-srcset)\s*=\s*["\']([^"\']+)["\']#i', $text, $m)) {
            foreach ($m[1] as $set) {
                foreach (explode(',', $set) as $part) {
                    $out[] = preg_split('/\s+/', trim($part))[0] ?? '';
                }
            }
        }
        if (preg_match_all('#url\(\s*([\'"]?)([^\'")]+)\1\s*\)|@import\s+([\'"])([^\'"]+)\3#i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = ($x[2] ?? '') !== '' ? $x[2] : ($x[4] ?? '');
            }
        }
        return array_values(array_unique(array_filter(array_map(static fn($u) => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $out))));
    }

    // ------------------------------------------------------------------ import by link

    /**
     * Fetch pages by address (one per line) with everything they use: stylesheets (and their @import and url():
     * fonts, background images), images (src, srcset), scripts, icons, video posters. $follow: also the pages they
     * link to on the same site (depth 1, at most URL_MAX_PAGES pages). Returns [ok, folder|error].
     */
    public static function fromUrls(array $urls, bool $follow = false): array
    {
        @set_time_limit(self::URL_TIME + 60);
        $start = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '') {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) {
                $u = 'https://' . $u;
            }
            [$ok, $err] = RemoteFetch::check($u);
            if (!$ok) {
                return [false, $u . ': ' . $err . '.'];
            }
            $start[self::urlKey($u)] = $u;
        }
        if (!$start) {
            return [false, 'Paste the address of a page, for example https://example.com/'];
        }
        $start = array_slice(array_values($start), 0, self::URL_MAX_PAGES);
        $folder = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir = self::root() . '/' . $folder;
        if (!@mkdir($dir . '/.pages', 0755, true)) {
            return [false, 'Could not create the import folder (check that content/uploads is writable).'];
        }
        $began = time();
        // Files are kept relative to the folder of the first address ("https://x.com/shop/" -> "index.html", "css/a.css").
        $sp = (string)(parse_url($start[0], PHP_URL_PATH) ?: '/');
        $primary = self::origin($start[0]) . substr($sp, 0, (int)strrpos($sp, '/') + 1);
        $meta = ['source' => 'url', 'pages' => [], 'assets' => [], 'links' => [], 'failed' => [], 'fetched' => 0, 'bytes' => 0, 'follow' => $follow];
        $owners = [];   // asset URL => [page paths]
        $queue = [];    // asset URLs to fetch: [url, hint 'css'|'']
        $pageQueue = array_map(static fn($u) => [$u, true], $start);
        $pageFail = [];
        while ($pageQueue) {
            [$url, $isStart] = array_shift($pageQueue);
            if (count($meta['pages']) >= self::URL_MAX_PAGES || isset($meta['links'][self::urlKey($url)])) {
                continue;
            }
            if (isset($meta['pages'][$known = self::pagePath($url, $primary)])) {
                $meta['links'][self::urlKey($url)] = $known; // "/" and "/index.html" are the same page
                continue;
            }
            if (time() - $began > self::URL_TIME) {
                $pageFail[] = [$url, 'Time limit reached'];
                continue;
            }
            $r = RemoteFetch::get($url, 5 * 1024 * 1024);
            if (!$r['ok'] || !in_array($r['type'], ['text/html', 'application/xhtml+xml', ''], true)) {
                if ($isStart) {
                    $pageFail[] = [$url, $r['ok'] ? 'Not an HTML page (' . $r['type'] . ')' : (string)$r['error']];
                }
                continue;
            }
            $final = $r['url'];
            $html = $r['body'];
            if (isset($meta['links'][self::urlKey($final)])) {
                $meta['links'][self::urlKey($url)] = $meta['links'][self::urlKey($final)];
                continue;
            }
            $path = self::pagePath($final, $primary);
            if (isset($meta['pages'][$path])) {
                $meta['links'][self::urlKey($url)] = $path;
                continue;
            }
            $base = preg_match('#<base\b[^>]*\bhref=["\']([^"\']+)#i', $html, $bm) ? (RemoteFetch::absolute($bm[1], $final) ?? $final) : $final;
            self::store($dir, $path, $html);
            $meta['bytes'] += strlen($html);
            $meta['links'][self::urlKey($url)] = $path;
            $meta['links'][self::urlKey($final)] = $path;
            $meta['pages'][$path] = ['url' => $final, 'base' => $base, 'assets' => 0, 'failed' => []];
            foreach (self::pageAssets($html, $base) as [$asset, $hint]) {
                $owners[$asset][$path] = true;
                $queue[] = [$asset, $hint];
            }
            if ($follow && $isStart) {
                foreach (self::pageLinks($html, $base) as $link) {
                    if (self::origin($link) === self::origin($final)) {
                        $pageQueue[] = [$link, false];
                    }
                }
            }
        }
        if (!$meta['pages']) {
            self::removeDir($dir);
            return [false, 'The page could not be fetched: ' . ($pageFail ? $pageFail[0][1] : 'no HTML found') . '.'];
        }
        // Assets, breadth first; stylesheets add the files they use.
        $done = [];
        $cssFiles = [];
        while ($queue) {
            [$url, $hint] = array_shift($queue);
            $key = self::urlKey($url);
            if (isset($done[$key])) {
                continue;
            }
            $done[$key] = true;
            $fail = null;
            if (count($done) > self::URL_MAX_ASSETS) {
                $fail = 'Too many files (max ' . self::URL_MAX_ASSETS . ')';
            } elseif (time() - $began > self::URL_TIME) {
                $fail = 'Time limit reached';
            } elseif ($meta['bytes'] > self::URL_MAX_TOTAL) {
                $fail = 'Total size limit reached (' . (self::URL_MAX_TOTAL >> 20) . ' MB)';
            }
            $r = $fail === null ? RemoteFetch::get($url, min(self::URL_MAX_FILE, self::URL_MAX_TOTAL - $meta['bytes'] + 1)) : null; // checks the address (SSRF) on every hop
            if ($r && !$r['ok']) {
                $fail = (string)$r['error'];
            }
            $local = $r && $fail === null ? self::assetPath($url, $r['type'], $primary, $hint) : null;
            if ($r && $fail === null && $local === null) {
                $fail = 'File type not imported (' . ($r['type'] ?: 'unknown') . ')';
            }
            if ($fail !== null) {
                $meta['failed'][] = [$url, $fail];
                foreach (array_keys($owners[$url] ?? []) as $p) {
                    if (count($meta['pages'][$p]['failed']) < 50) {
                        $meta['pages'][$p]['failed'][] = [$url, $fail];
                    }
                }
                continue;
            }
            self::store($dir, $local, $r['body']);
            if (!is_file($dir . '/' . $local)) {
                $meta['failed'][] = [$url, 'Could not be saved'];
                continue;
            }
            $meta['assets'][$key] = $local;
            $meta['fetched']++;
            $meta['bytes'] += strlen($r['body']);
            foreach (array_keys($owners[$url] ?? []) as $p) {
                $meta['pages'][$p]['assets']++;
            }
            if (str_ends_with($local, '.css')) {
                $cssFiles[$local] = $r['url'];
                foreach (self::refsIn($r['body'], true) as $ref) {
                    if (($abs = RemoteFetch::absolute($ref, $r['url'])) !== null) {
                        $abs = self::urlKey($abs);
                        $owners[$abs] = ($owners[$abs] ?? []) + ($owners[$url] ?? []);
                        $queue[] = [$abs, preg_match('/@import/i', $ref) ? 'css' : ''];
                    }
                }
            }
        }
        // Stylesheets: point their url() and @import at the downloaded copies (relative, they are served as files).
        foreach ($cssFiles as $local => $cssUrl) {
            $css = (string)file_get_contents($dir . '/' . $local);
            $css = (string)preg_replace_callback('#(url\(\s*)([\'"]?)([^\'")]+)\2(\s*\))|(@import\s+)([\'"])([^\'"]+)\6#i',
                static function ($m) use ($meta, $local, $cssUrl) {
                    $isUrl = ($m[3] ?? '') !== '';
                    $ref = $isUrl ? $m[3] : $m[7];
                    $abs = RemoteFetch::absolute($ref, $cssUrl);
                    if ($abs === null) {
                        return $m[0];
                    }
                    $frag = str_contains($abs, '#') ? '#' . explode('#', $abs, 2)[1] : '';
                    $to = isset($meta['assets'][self::urlKey($abs)]) ? self::relativePath($local, $meta['assets'][self::urlKey($abs)]) . $frag : $abs;
                    return $isUrl ? $m[1] . $m[2] . $to . $m[2] . $m[4] : $m[5] . $m[6] . $to . $m[6];
                }, $css);
            file_put_contents($dir . '/' . $local, $css);
        }
        $meta['failed'] = array_merge($pageFail, array_slice($meta['failed'], 0, 200));
        self::saveMeta($folder, $meta);
        log_activity('site_fetched', count($meta['pages']) . ' page(s), ' . $meta['fetched'] . ' file(s) from ' . $primary);
        return [true, $folder];
    }

    /** Files a fetched page uses: [[absolute URL, 'css'|'']]. */
    private static function pageAssets(string $html, string $base): array
    {
        $doc = HtmlPages::load($html);
        $out = [];
        $add = static function (string $ref, string $hint = '') use (&$out, $base): void {
            if (($abs = RemoteFetch::absolute($ref, $base)) !== null) {
                $out[self::urlKey($abs)] = [preg_replace('/#.*$/', '', $abs), $hint];
            }
        };
        foreach ($doc->getElementsByTagName('*') as $el) {
            /** @var \DOMElement $el */
            $tag = strtolower($el->tagName);
            if ($tag === 'link') {
                $rel = ' ' . strtolower($el->getAttribute('rel')) . ' ';
                $as = strtolower($el->getAttribute('as'));
                if (preg_match('/ (stylesheet|icon|shortcut|apple-touch-icon|apple-touch-icon-precomposed|mask-icon|manifest) /', $rel)
                    || (str_contains($rel, 'preload') && in_array($as, ['style', 'font', 'image', 'script'], true))) {
                    $add($el->getAttribute('href'), str_contains($rel, 'stylesheet') || $as === 'style' ? 'css' : '');
                }
                continue;
            }
            foreach (['src', 'poster', 'data-src', 'data-bg', 'data-background'] as $at) {
                if ($el->hasAttribute($at) && !in_array($tag, ['iframe', 'a', 'frame'], true)) {
                    $add($el->getAttribute($at));
                }
            }
            foreach (['srcset', 'data-srcset'] as $at) {
                if ($el->hasAttribute($at)) {
                    foreach (explode(',', $el->getAttribute($at)) as $part) {
                        $add(preg_split('/\s+/', trim($part))[0] ?? '');
                    }
                }
            }
            if (in_array($tag, ['image', 'use'], true)) {
                foreach (['href', 'xlink:href'] as $at) {
                    if ($el->hasAttribute($at) && !str_starts_with($el->getAttribute($at), '#')) {
                        $add($el->getAttribute($at));
                    }
                }
            }
            if ($el->hasAttribute('style')) {
                foreach (self::refsIn($el->getAttribute('style'), true) as $ref) {
                    $add($ref);
                }
            }
            if ($tag === 'style') {
                foreach (self::refsIn($el->textContent, true) as $ref) {
                    $add($ref, preg_match('#@import\s+[\'"]?' . preg_quote($ref, '#') . '#i', $el->textContent) ? 'css' : '');
                }
            }
        }
        return array_values($out);
    }

    /** Links to other pages: absolute URLs without fragments, files (images, PDFs...) left out. */
    private static function pageLinks(string $html, string $base): array
    {
        $out = [];
        if (preg_match_all('#<a\b[^>]*\bhref=["\']([^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $href) {
                $abs = RemoteFetch::absolute($href, $base);
                if ($abs === null) {
                    continue;
                }
                $abs = (string)preg_replace('/#.*$/', '', $abs);
                $ext = strtolower(pathinfo((string)parse_url($abs, PHP_URL_PATH), PATHINFO_EXTENSION));
                if ($ext !== '' && (in_array($ext, self::ASSETS, true) || in_array($ext, ['zip', 'rar', 'gz', 'exe', 'dmg', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true))) {
                    continue;
                }
                $out[self::urlKey($abs)] = $abs;
            }
        }
        return array_values($out);
    }

    /** Where a fetched page is kept: "/" -> index.html, "/about/" -> about/index.html, "/about" -> about.html. */
    private static function pagePath(string $url, string $primary): string
    {
        $p = parse_url($url);
        $path = rawurldecode((string)($p['path'] ?? '/'));
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index.html';
        } elseif (!preg_match('/\.html?$/i', $path)) {
            $path = preg_replace('/\.(php\d?|aspx?|jsp|cfm|shtml)$/i', '', $path) . '.html';
        }
        if (!empty($p['query'])) {
            $path = preg_replace('/(\.html?)$/i', '-' . substr(md5($p['query']), 0, 6) . '$1', $path);
        }
        return self::cleanRel(self::under($url, $path, $primary, '')) ?? 'page-' . substr(md5($url), 0, 8) . '.html';
    }

    /** Where a fetched asset is kept: its own path on the main site, _ext/<host>/<path> for other servers. */
    private static function assetPath(string $url, string $type, string $primary, string $hint): ?string
    {
        $p = parse_url($url);
        $path = rawurldecode((string)($p['path'] ?? '/'));
        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $want = self::MIME_EXT[$type] ?? ($hint === 'css' && ($type === '' || $type === 'text/plain') ? 'css' : null);
        if ($type === 'text/css' || $hint === 'css' && $want === 'css') {
            $want = 'css';
        }
        if (!in_array($ext, self::ASSETS, true) || ($want !== null && $want !== $ext && in_array($want, ['css', 'js', 'svg'], true))) {
            if ($want === null) {
                return null;
            }
            $path .= '.' . $want;
            $ext = $want;
        }
        if (!empty($p['query'])) {
            $path = substr($path, 0, -strlen($ext) - 1) . '-' . substr(md5($p['query']), 0, 8) . '.' . $ext;
        }
        return self::cleanRel(self::under($url, $path, $primary, '_ext/'));
    }

    /**
     * A fetched file's place: relative to the folder of the first address ($primary = origin + folder), "_root/..."
     * for other folders of that site, "<prefix><host>/..." for other servers.
     */
    private static function under(string $url, string $path, string $primary, string $prefix): string
    {
        $origin = self::origin($url);
        $basePath = substr($primary, strlen($origin));
        if (!str_starts_with($primary, $origin . '/')) {
            return $prefix . self::hostDir($url) . '/' . ltrim($path, '/');
        }
        if (str_starts_with($path, $basePath)) {
            return substr($path, strlen($basePath));
        }
        return '_root/' . ltrim($path, '/');
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return strtolower(($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : ''));
    }

    private static function hostDir(string $url): string
    {
        $p = parse_url($url);
        return preg_replace('/[^a-z0-9.-]/', '-', strtolower(($p['host'] ?? 'site') . (isset($p['port']) ? '-' . $p['port'] : '')));
    }

    /** Map key of a URL: without fragment. */
    private static function urlKey(string $url): string
    {
        return (string)preg_replace('/#.*$/', '', $url);
    }

    /** Relative URL from one stored file to another ("css/a.css" -> "fonts/x.woff2" = "../fonts/x.woff2"). */
    private static function relativePath(string $from, string $to): string
    {
        $a = explode('/', $from);
        array_pop($a);
        $b = explode('/', $to);
        while ($a && $b && $a[0] === $b[0] && count($b) > 1) {
            array_shift($a);
            array_shift($b);
        }
        return str_repeat('../', count($a)) . implode('/', array_map('rawurlencode', $b));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ per-import facts

    public static function meta(string $folder): array
    {
        $file = self::root() . '/' . self::cleanFolder($folder) . '/.pages/import.json';
        $m = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return (is_array($m) ? $m : []) + ['source' => 'upload', 'pages' => [], 'assets' => [], 'links' => [], 'failed' => [], 'linked' => [], 'unused' => [], 'fetched' => 0];
    }

    private static function saveMeta(string $folder, array $meta): void
    {
        file_put_contents(self::root() . '/' . self::cleanFolder($folder) . '/.pages/import.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Save one file: assets in the public folder, pages in the private .pages copy. Unknown types are skipped. */
    private static function store(string $dir, string $path, string $data): void
    {
        $rel = self::cleanRel($path);
        if ($rel === null) {
            return;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, self::PAGES, true)) {
            $target = $dir . '/.pages/' . $rel;
        } elseif (in_array($ext, self::ASSETS, true)) {
            if ($ext === 'svg') {
                $data = HtmlPages::stripScripts((string)preg_replace('#<foreignObject\b.*?</foreignObject>#is', '', $data));
            }
            $target = $dir . '/' . $rel;
        } else {
            return;
        }
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, $data);
    }

    /** A safe relative path inside an import folder (no "..", no hidden parts, plain characters), or null. */
    private static function cleanRel(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== '' && $p !== '.'));
        if (!$parts || in_array('..', $parts, true) || preg_grep('/^(__MACOSX|\..*)$/', $parts)) {
            return null;
        }
        return implode('/', array_map(static fn($p) => preg_replace('/[^\w.@+-]/', '-', $p), $parts));
    }

    /** A zip that holds one folder ("mysite/index.html") is treated as if the files were at the top. */
    private static function flattenSingleRoot(string $dir): void
    {
        $tops = array_values(array_diff(scandir($dir . '/.pages') ?: [], ['.', '..']));
        if (count($tops) !== 1 || !is_dir($dir . '/.pages/' . $tops[0])) {
            return;
        }
        $top = $tops[0];
        foreach ([$dir . '/.pages', $dir] as $base) {
            if (is_dir("$base/$top")) {
                foreach (array_diff(scandir("$base/$top") ?: [], ['.', '..']) as $item) {
                    @rename("$base/$top/$item", "$base/$item");
                }
                @rmdir("$base/$top");
            }
        }
    }

    /** HTML pages in an import folder: [['path' => 'about/index.html', 'title' => 'About', 'slug' => 'about']]. */
    public static function scan(string $folder): array
    {
        $base = self::root() . '/' . self::cleanFolder($folder) . '/.pages';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        $meta = self::meta($folder);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
            if (!in_array(strtolower($f->getExtension()), self::PAGES, true)) {
                continue;
            }
            $html = (string)file_get_contents($f->getPathname(), false, null, 0, 200000);
            $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $out[] = ['path' => $rel, 'title' => $title ?: self::titleFromPath($rel), 'slug' => self::slugFor($rel), 'url' => (string)($meta['pages'][$rel]['url'] ?? '')];
        }
        usort($out, static fn($a, $b) => [substr_count($a['path'], '/'), $a['slug'] !== 'home', $a['path']] <=> [substr_count($b['path'], '/'), $b['slug'] !== 'home', $b['path']]);
        return $out;
    }

    /**
     * Create pages. $opts: paths (list to import; default all), status (draft|publish), home (path of the homepage or ''),
     * type (default 'page'), design ('keep' the page's own CSS, default | 'site' the site's styles), attach (CSS/JS
     * files of the upload to add to every page). Returns ['ok' => bool, 'pages' => [[id, title, path, url, report,
     * design, source, fetched, failed]], 'error' => ?string].
     */
    public static function import(string $folder, array $opts = []): array
    {
        $folder = self::cleanFolder($folder);
        $all = self::scan($folder);
        $wanted = $opts['paths'] ?? array_column($all, 'path');
        $type = ContentModel::type((string)($opts['type'] ?? 'page')) ? (string)($opts['type'] ?? 'page') : 'page';
        $status = ($opts['status'] ?? 'draft') === 'publish' && Auth::can('publish_posts') ? 'publish' : 'draft';
        $pages = array_values(array_filter($all, static fn($p) => in_array($p['path'], $wanted, true)));
        if (!$pages) {
            return ['ok' => false, 'error' => 'Choose at least one page.', 'pages' => []];
        }
        // Pass 1: create the items so links between pages can point at their new addresses.
        $ids = [];
        foreach ($pages as $p) {
            $ids[$p['path']] = Posts::save(['type' => $type, 'title' => $p['title'], 'slug' => $p['slug'] === 'home' ? 'home' : $p['slug'],
                'status' => 'draft', 'content' => '', 'author_id' => Auth::id() ?: null]);
        }
        $links = [];
        foreach ($ids as $path => $id) {
            $links[$path] = Posts::permalink(Posts::find($id));
        }
        // Pass 2: parse, rewrite URLs, annotate, save.
        $meta = self::meta($folder);
        $design = ($opts['design'] ?? 'keep') === 'site' ? 'site' : 'keep';
        $attach = array_values(array_intersect(array_map('strval', (array)($opts['attach'] ?? [])), (array)$meta['unused']));
        $out = [];
        foreach ($pages as $p) {
            $html = self::withAttached((string)file_get_contents(self::root() . "/$folder/.pages/" . $p['path']), $attach);
            $src = (array)($meta['pages'][$p['path']] ?? []);
            $parsed = HtmlPages::fromDocument($html, self::rewriter($folder, $p['path'], $links, $meta), 'import');
            $id = $ids[$p['path']];
            // Keep the page's own <title> and description exactly.
            $seo = ['seo' => array_filter(['title' => $parsed['title'], 'description' => $parsed['description']], static fn($v) => $v !== '')];
            $data = HtmlPages::withDesign($parsed['data'] + ['import' => array_filter(['folder' => $folder, 'path' => $p['path'], 'at' => now(),
                'url' => (string)($src['url'] ?? '')], static fn($v) => $v !== '')], $design);
            HtmlPages::save($id, $data, ['status' => $status] + $seo);
            $post = Posts::find($id);
            $report = self::check($html, $parsed['data'], $folder, (string)($src['url'] ?? ''));
            if ($attach) {
                $report['issues'][] = ['info', count($attach) . ' file(s) attached to the page.', $attach];
            }
            if ($meta['linked'] ?? []) {
                $report['issues'][] = ['info', 'Files linked by name: the page asks for them in a folder, the uploaded file with the same name is used.',
                    array_map(static fn($l) => $l[0] . ' = ' . $l[1], array_slice((array)$meta['linked'], 0, 12))];
            }
            if ($src['failed'] ?? []) {
                // Files that failed are reported once, below, not again as "links to the old website".
                $failedUrls = array_column($src['failed'], 0);
                foreach ($report['issues'] as $k => $issue) {
                    if (str_starts_with($issue[1], 'Links still point at the old website')) {
                        $report['issues'][$k][2] = array_values(array_diff($issue[2], $failedUrls));
                        if (!$report['issues'][$k][2]) {
                            unset($report['issues'][$k]);
                        }
                    }
                }
                $report['issues'] = array_values($report['issues']);
                $report['issues'][] = ['warn', count($src['failed']) . ' file(s) could not be fetched. The page loads them from the original site, or they are missing.',
                    array_map(static fn($f) => $f[0] . ' (' . $f[1] . ')', $src['failed'])];
            }
            $out[] = ['id' => $id, 'title' => $post['title'], 'path' => $p['path'], 'url' => Posts::permalink($post), 'edit' => admin_url("content/$type/$id"),
                'report' => $report, 'design' => $design, 'source' => (string)($src['url'] ?? ''), 'fetched' => (int)($src['assets'] ?? 0)];
        }
        if (($opts['home'] ?? '') !== '' && isset($ids[$opts['home']]) && $type === 'page') {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $ids[$opts['home']]);
        }
        PageCache::clear();
        log_activity('site_imported', count($out) . " page(s) from $folder");
        return ['ok' => true, 'pages' => $out];
    }

    /**
     * Built-in migration check of one imported page (no AI): what was found and what may need attention.
     * Returns ['editable' => n, 'loops' => n, 'issues' => [[level, message, detail[]]]].
     */
    public static function check(string $original, array $data, string $folder, string $sourceUrl = ''): array
    {
        $issues = [];
        $add = static function (string $level, string $msg, array $detail = []) use (&$issues): void {
            $issues[] = [$level, $msg, array_values(array_unique(array_slice($detail, 0, 12)))];
        };
        $html = (string)$data['head'] . (string)$data['body'];
        $base = url('content/uploads/sites/' . $folder) . '/';
        $dir = self::root() . '/' . $folder . '/';

        // Files the page refers to that were not in the upload.
        $missing = [];
        if (preg_match_all('#(?:src|href|poster|srcset|data-src)=["\']([^"\']+)["\']|url\(\s*[\'"]?([^\'")]+)#i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                foreach (preg_split('/\s*,\s*/', $x[1] !== '' ? $x[1] : ($x[2] ?? '')) as $u) {
                    $u = preg_split('/\s+/', trim($u))[0] ?? '';
                    if ($u !== '' && str_starts_with($u, $base)) {
                        $rel = rawurldecode(preg_replace('/[?#].*$/', '', substr($u, strlen($base))));
                        if ($rel !== '' && !is_file($dir . $rel)) {
                            $missing[] = $rel;
                        }
                    }
                }
            }
        }
        if ($missing) {
            $add('warn', count(array_unique($missing)) . ' file(s) the page uses were not in the upload. Upload them again with the site, or replace them on the page.', $missing);
        }

        // Links that still point at the old website.
        $hosts = [];
        if (preg_match_all('#<(?:link[^>]+rel=["\']canonical["\'][^>]*href|meta[^>]+property=["\']og:url["\'][^>]*content)=["\']https?://([^/"\']+)#i', $original, $hm)) {
            $hosts = array_unique(array_map('strtolower', $hm[1]));
        }
        if ($sourceUrl !== '' && ($h = parse_url($sourceUrl, PHP_URL_HOST))) {
            $hosts[] = strtolower((string)$h . (($port = parse_url($sourceUrl, PHP_URL_PORT)) ? ':' . $port : ''));
            $hosts = array_unique($hosts);
        }
        foreach ($hosts as $h) {
            if (preg_match_all('#(?:href|src|action)=["\'](https?://' . preg_quote($h, '#') . '[^"\']*)#i', $html, $lm)) {
                $add('warn', 'Links still point at the old website (' . $h . '). They keep working only while that site is online.', $lm[1]);
            }
        }

        // Forms that send their data somewhere else.
        if (preg_match_all('#<form\b[^>]*>#i', $html, $fm)) {
            $ext = [];
            foreach ($fm[0] as $tag) {
                $action = preg_match('#action=["\']([^"\']*)#i', $tag, $am) ? trim($am[1]) : '';
                if ($action !== '' && $action !== '#') {
                    $ext[] = $action;
                }
            }
            $add($ext ? 'warn' : 'info', count($fm[0]) . ' form(s) on this page.' . ($ext ? ' Some send their data to another address; check that it still works, or rebuild them with Slate Forms.' : ' They do not send anywhere yet; connect them with Slate Forms if they should.'), $ext);
        }

        // Images without a description.
        $noAlt = [];
        if (preg_match_all('#<img\b[^>]*>#i', (string)$data['body'], $im)) {
            foreach ($im[0] as $tag) {
                if (!preg_match('#\balt=["\'][^"\']+["\']#i', $tag)) {
                    $noAlt[] = preg_match('#src=["\']([^"\']+)#i', $tag, $sm) ? basename($sm[1]) : 'image';
                }
            }
        }
        if ($noAlt) {
            $add('info', count($noAlt) . ' image(s) have no alt text (needed for accessibility and search). Click an image on the page to add it.', $noAlt);
        }

        // Things loaded from other servers.
        $ext = (string)$data['head'] . (string)$data['body'];
        $xm = [[], []];
        foreach ([preg_match_all('#<script\b[^>]*\bsrc=["\'](https?://[^"\']+)#i', $ext, $a) ? $a[1] : [],
            preg_match_all('#<link\b(?=[^>]*\brel=["\'](?:stylesheet|preload|modulepreload|icon|preconnect)["\'])[^>]*\bhref=["\'](https?://[^"\']+)#i', $ext, $b) ? $b[1] : []] as $list) {
            $xm[1] = array_merge($xm[1], $list);
        }
        if ($xm[1]) {
            $add('info', 'Loads ' . count(array_unique($xm[1])) . ' file(s) from other servers (fonts, libraries, analytics).', array_map(static fn($u) => (string)parse_url($u, PHP_URL_HOST), $xm[1]));
        }
        if (!preg_match('#<title\b#i', $original)) {
            $add('info', 'The page had no <title>; Slate uses the page name. Set an SEO title in the page settings.');
        }
        $editable = substr_count((string)$data['body'], 'data-se="');
        $loops = substr_count((string)$data['body'], 'data-se-loop="');
        return ['editable' => $editable, 'loops' => $loops, 'issues' => $issues];
    }

    /** The CSS/JS files chosen on the review step, added to a page (stylesheets in <head>, scripts at the end of <body>). */
    private static function withAttached(string $html, array $files): string
    {
        $css = '';
        $js = '';
        foreach ($files as $f) {
            $href = '/' . implode('/', array_map('rawurlencode', explode('/', $f)));
            str_ends_with(strtolower($f), '.css') ? $css .= '<link rel="stylesheet" href="' . e($href) . '">' . "\n"
                : $js .= '<script src="' . e($href) . '"' . (str_ends_with(strtolower($f), '.mjs') ? ' type="module"' : '') . '></script>' . "\n";
        }
        if ($css !== '') {
            $html = preg_match('#</head>#i', $html) ? (string)preg_replace('#</head>#i', $css . '</head>', $html, 1) : $css . $html;
        }
        if ($js !== '') {
            $html = preg_match('#</body>#i', $html) ? (string)preg_replace('#</body>(?!.*</body>)#is', $js . '</body>', $html, 1) : $html . $js;
        }
        return $html;
    }

    /**
     * A standalone preview of one page in a design ('keep' | 'site'), for the review step. Shown in a sandboxed frame
     * without scripts; links between pages are not rewritten yet.
     */
    public static function preview(string $folder, string $path, string $design): ?string
    {
        $folder = self::cleanFolder($folder);
        if (!in_array($path, array_column(self::scan($folder), 'path'), true)) {
            return null;
        }
        $meta = self::meta($folder);
        $html = self::withAttached((string)file_get_contents(self::root() . "/$folder/.pages/$path"), (array)$meta['unused']);
        $parsed = HtmlPages::fromDocument($html, self::rewriter($folder, $path, [], $meta), 'import');
        $d = HtmlPages::withDesign($parsed['data'], $design);
        return '<!doctype html><html ' . ($d['html_attrs'] !== '' ? $d['html_attrs'] : 'lang="en"') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($parsed['title']) . '</title>' . HtmlPages::designHead($d) . '</head><body ' . HtmlPages::bodyAttrs($d) . '>'
            . HtmlPages::designBody($d, (string)$d['body']) . '</body></html>';
    }

    /**
     * URL rewriting for one page: assets → the import folder, other imported pages → their permalinks. Pages fetched
     * by link resolve every URL against their address: downloaded files and pages are used, anything else keeps
     * pointing at the original site.
     */
    private static function rewriter(string $folder, string $pagePath, array $links, array $meta = []): callable
    {
        $assetBase = url('content/uploads/sites/' . $folder);
        $dir = trim(dirname($pagePath), '.');
        $base = (string)($meta['pages'][$pagePath]['base'] ?? '');
        if ($base !== '') {
            $pageLinks = [];
            foreach ((array)($meta['links'] ?? []) as $u => $path) {
                if (isset($links[$path])) {
                    $pageLinks[$u] = $links[$path];
                }
            }
            $assets = (array)($meta['assets'] ?? []);
            return static function (string $u) use ($assetBase, $base, $pageLinks, $assets): string {
                $u = trim($u);
                if ($u === '' || preg_match('#^(\#|\{\{|data:|mailto:|tel:|javascript:)#i', $u)) {
                    return $u;
                }
                $abs = RemoteFetch::absolute($u, $base);
                if ($abs === null) {
                    return $u;
                }
                [$key, $frag] = array_pad(explode('#', $abs, 2), 2, null);
                if (isset($pageLinks[$key])) {
                    return $pageLinks[$key] . ($frag !== null ? '#' . $frag : '');
                }
                if (isset($assets[$key])) {
                    return $assetBase . '/' . implode('/', array_map('rawurlencode', explode('/', $assets[$key]))) . ($frag !== null ? '#' . $frag : '');
                }
                return $abs;
            };
        }
        return static function (string $u) use ($assetBase, $dir, $links): string {
            $u = trim($u);
            if ($u === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|\{\{)#i', $u)) {
                return $u; // absolute, protocol-relative, anchors, mailto/tel/data, template tags
            }
            [$path, $suffix] = array_pad(preg_split('/(?=[?#])/', $u, 2), 2, '');
            $resolved = self::resolve(str_starts_with($path, '/') ? '' : $dir, $path);
            $page = $resolved === '' || str_ends_with($resolved, '/') ? $resolved . 'index.html' : $resolved;
            foreach ([$page, $page . '.html', rtrim($page, '/') . '/index.html'] as $candidate) {
                if (isset($links[$candidate])) {
                    return $links[$candidate] . (str_starts_with($suffix, '#') ? $suffix : '');
                }
            }
            return $assetBase . '/' . $resolved . $suffix;
        };
    }

    /** Join and normalise a relative path ("css/../img/a.png" → "img/a.png"). */
    private static function resolve(string $dir, string $path): string
    {
        $parts = [];
        foreach (explode('/', ($dir !== '' ? $dir . '/' : '') . ltrim($path, '/')) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '.' && $seg !== '') {
                $parts[] = $seg;
            }
        }
        return implode('/', $parts) . (str_ends_with($path, '/') && $parts ? '/' : '');
    }

    private static function slugFor(string $path): string
    {
        $p = preg_replace('/\.html?$/i', '', $path);
        $p = preg_replace('#(^|/)index$#', '', $p);
        return $p === '' ? 'home' : slugify(str_replace('/', '-', $p));
    }

    private static function titleFromPath(string $path): string
    {
        $s = self::slugFor($path);
        return $s === 'home' ? 'Home' : ucwords(str_replace('-', ' ', $s));
    }

    private static function cleanFolder(string $f): string
    {
        return preg_replace('/[^0-9a-f-]/i', '', $f);
    }

    /** Earlier imports (folders), newest first. */
    public static function folders(): array
    {
        $out = [];
        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $out[] = basename($d);
        }
        rsort($out);
        return $out;
    }
}
