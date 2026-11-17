<?php
declare(strict_types=1);

namespace Slate;

/**
 * Import ready-made HTML/CSS/JS pages (a .zip of a site, or individual files) as Slate pages that keep their design.
 *
 *   1. receive(): files go to content/uploads/sites/<folder>/ (HTML is parsed, never served from there).
 *   2. scan(): the HTML pages found, with titles.
 *   3. import(): one Slate page per chosen HTML file (HtmlPages format): asset URLs point at the folder, links between
 *      the imported pages point at their new addresses, editable elements and repeating groups are marked.
 */
final class SiteImport
{
    public const ASSETS = ['css', 'js', 'mjs', 'map', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'txt', 'xml', 'webmanifest'];
    public const PAGES = ['html', 'htm'];
    private const MAX_FILES = 3000;
    private const MAX_BYTES = 300 * 1024 * 1024;

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
        return self::scan($folder) ? [true, $folder] : [false, 'No HTML pages were found in what you uploaded.'];
    }

    /** Save one file: assets in the public folder, pages in the private .pages copy. Unknown types are skipped. */
    private static function store(string $dir, string $path, string $data): void
    {
        $path = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== '' && $p !== '.'));
        if (!$parts || in_array('..', $parts, true) || preg_grep('/^(__MACOSX|\..*)$/', $parts)) {
            return;
        }
        $parts = array_map(static fn($p) => preg_replace('/[^\w.@+-]/', '-', $p), $parts);
        $rel = implode('/', $parts);
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
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
            if (!in_array(strtolower($f->getExtension()), self::PAGES, true)) {
                continue;
            }
            $html = (string)file_get_contents($f->getPathname(), false, null, 0, 200000);
            $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $out[] = ['path' => $rel, 'title' => $title ?: self::titleFromPath($rel), 'slug' => self::slugFor($rel)];
        }
        usort($out, static fn($a, $b) => [substr_count($a['path'], '/'), $a['slug'] !== 'home', $a['path']] <=> [substr_count($b['path'], '/'), $b['slug'] !== 'home', $b['path']]);
        return $out;
    }

    /**
     * Create pages. $opts: paths (list to import; default all), status (draft|publish), home (path of the homepage or ''),
     * type (default 'page'). Returns ['ok' => bool, 'pages' => [[id, title, path, url]], 'error' => ?string].
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
        $out = [];
        foreach ($pages as $p) {
            $html = (string)file_get_contents(self::root() . "/$folder/.pages/" . $p['path']);
            $parsed = HtmlPages::fromDocument($html, self::rewriter($folder, $p['path'], $links), 'import');
            $id = $ids[$p['path']];
            // Keep the page's own <title> and description exactly.
            $seo = ['seo' => array_filter(['title' => $parsed['title'], 'description' => $parsed['description']], static fn($v) => $v !== '')];
            HtmlPages::save($id, $parsed['data'] + ['import' => ['folder' => $folder, 'path' => $p['path'], 'at' => now()]], ['status' => $status] + $seo);
            $post = Posts::find($id);
            $out[] = ['id' => $id, 'title' => $post['title'], 'path' => $p['path'], 'url' => Posts::permalink($post), 'edit' => admin_url("content/$type/$id")];
        }
        if (($opts['home'] ?? '') !== '' && isset($ids[$opts['home']]) && $type === 'page') {
            Options::set('front_page', 'page');
            Options::set('front_page_id', $ids[$opts['home']]);
        }
        PageCache::clear();
        log_activity('site_imported', count($out) . " page(s) from $folder");
        return ['ok' => true, 'pages' => $out];
    }

    /** URL rewriting for one page: assets → the import folder, other imported pages → their permalinks. */
    private static function rewriter(string $folder, string $pagePath, array $links): callable
    {
        $assetBase = url('content/uploads/sites/' . $folder);
        $dir = trim(dirname($pagePath), '.');
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
