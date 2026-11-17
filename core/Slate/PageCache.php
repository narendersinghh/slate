<?php
declare(strict_types=1);

namespace Slate;

/**
 * Optional full-page cache for anonymous visitors (Settings › Performance › Page cache). It is not static-site
 * generation: pages render dynamically and the cache simply keeps the result, so dynamic sites keep working, * signed-in users, forms, query strings, excluded paths and "bypass" cookies (e.g. a shop cart) always get fresh pages,
 * and modules can opt a request out with PageCache::skip().
 *
 * serve() runs BEFORE the database connection, so cached pages cost zero queries.
 *
 * Invalidation is O(1), however large the site: every cached file carries the "generation" it was built in, and a
 * purge only increments a counter, stale files are ignored on the next visit and swept up in the background.
 *   - global generation: settings, menus, templates, modules… purge every page
 *   - listing generation: home, archives, taxonomy pages, search, feeds: pages that list content
 *   - "smart" purge mode (large sites): editing one item refreshes only that item's page plus the listing pages,
 *     leaving thousands of other pages warm. "Everything" mode (default) refreshes all pages.
 * A background task re-warms the most important pages after a purge, so visitors rarely hit a cold page.
 */
final class PageCache
{
    private const DIR = SLATE_CACHE . '/pages';
    private const GEN = SLATE_CACHE . '/pages/.gen';
    private static bool $skip = false;

    /** Called by modules (or themes) when the current response must not be cached (personalised output, carts…). */
    public static function skip(): void
    {
        self::$skip = true;
    }

    private static function keyFor(string $host, string $path, bool $https): string
    {
        $host = strtolower(preg_replace('/[^a-z0-9.:-]/i', '', $host));
        return self::DIR . '/' . md5(($https ? 's' : '') . $host . $path) . '.html';
    }

    /** host[:port] of the configured site URL, the way browsers send it in the Host header. */
    private static function siteHost(): string
    {
        $u = parse_url(site_url());
        return (string)($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    private static function key(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::keyFor((string)($_SERVER['HTTP_HOST'] ?? ''), $path, is_https());
    }

    /** Runtime settings mirrored to a file so serve() needs no database. */
    private static function config(): ?array
    {
        $raw = @file_get_contents(self::DIR . '/.enabled');
        if ($raw === false) {
            return null;
        }
        if (ctype_digit(trim($raw))) {
            return ['ttl' => (int)$raw, 'exclude' => [], 'cookies' => []]; // file from an older version
        }
        $c = json_decode($raw, true);
        return is_array($c) ? $c + ['ttl' => 43200, 'exclude' => [], 'cookies' => []] : null;
    }

    /** [global, listing] generations. */
    private static function gens(): array
    {
        $g = @file_get_contents(self::GEN);
        $p = $g ? explode(' ', trim($g)) : [];
        return [(int)($p[0] ?? 0), (int)($p[1] ?? 0)];
    }

    private static function bump(bool $global): void
    {
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        $fp = @fopen(self::GEN, 'c+');
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_EX);
        $p = explode(' ', trim((string)stream_get_contents($fp)));
        $g = (int)($p[0] ?? 0);
        $l = (int)($p[1] ?? 0);
        $global ? $g++ : $l++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, "$g $l");
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function cacheable(?array $cfg = null): bool
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return false;
        }
        if (isset($_COOKIE[Session::NAME]) || isset($_COOKIE[Session::FLAG])) {
            return false;
        }
        $query = $_SERVER['QUERY_STRING'] ?? '';
        // Ignore pure marketing parameters, everything else bypasses the cache.
        if ($query !== '') {
            parse_str($query, $q);
            foreach (array_keys($q) as $k) {
                if (!preg_match('/^(utm_\w+|gclid|fbclid|msclkid|ref)$/', (string)$k)) {
                    return false;
                }
            }
        }
        $cfg ??= self::config() ?? [];
        foreach ((array)($cfg['cookies'] ?? []) as $c) {
            if ($c !== '' && (isset($_COOKIE[$c]) || (str_ends_with($c, '*') && self::cookiePrefix(substr($c, 0, -1))))) {
                return false;
            }
        }
        $path = '/' . ltrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
        foreach ((array)($cfg['exclude'] ?? []) as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $path)) {
                return false;
            }
        }
        return true;
    }

    private static function cookiePrefix(string $prefix): bool
    {
        foreach (array_keys($_COOKIE) as $name) {
            if (str_starts_with((string)$name, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function serve(): void
    {
        $cfg = self::config();
        if (!$cfg || !self::cacheable($cfg)) {
            return;
        }
        $file = self::key();
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return;
        }
        $head = (string)fgets($fp, 200);
        [$gen, $lgen] = self::gens();
        $valid = false;
        $kind = 'listing';
        if (preg_match('/^<!--sc (\d+) (\d+) (single|listing)-->\n$/', $head, $m)) {
            $kind = $m[3];
            $valid = (int)$m[1] === $gen && ($kind === 'single' || (int)$m[2] === $lgen);
        }
        if (!$valid || filemtime($file) + (int)$cfg['ttl'] < time()) {
            fclose($fp);
            @unlink($file);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        if (SLATE_DEBUG) {
            header('X-Cache: HIT');
        }
        header('Cache-Control: public, max-age=0, must-revalidate');
        self::securityHeaders();
        fpassthru($fp);
        fclose($fp);
        // Keep scheduled tasks running even when every visitor gets cached pages.
        Cron::afterCachedPage();
        exit;
    }

    public static function store(string $html): void
    {
        if (self::$skip || !Options::get('cache_pages', true) || !self::cacheable() || http_response_code() !== 200
            || session_status() === PHP_SESSION_ACTIVE || Options::get('maintenance_mode') || !Hooks::filter('page_cacheable', true)) {
            return;
        }
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        self::writeFlag();
        [$gen, $lgen] = self::gens();
        // Only plain item pages may stay cached when other content changes; the home page always lists content.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $kind = (Seo::$ctx['kind'] ?? '') === 'single' && $path !== '/' && rtrim($path, '/') !== rtrim(base_path(), '/') ? 'single' : 'listing';
        $kind = Hooks::filter('page_cache_kind', $kind, Seo::$ctx) === 'single' ? 'single' : 'listing';
        $file = self::key();
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, "<!--sc $gen $lgen $kind-->\n" . $html) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /** Mirror cache settings to the file serve() reads. */
    public static function writeFlag(): void
    {
        $flag = self::DIR . '/.enabled';
        if (!Options::get('cache_pages', true)) {
            @unlink($flag);
            return;
        }
        $lines = static fn(string $k): array => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)Options::get($k, '')) ?: [])));
        $json = json_encode(['ttl' => (int)Options::get('cache_ttl', 720) * 60, 'exclude' => $lines('cache_exclude'), 'cookies' => $lines('cache_bypass_cookies')],
            JSON_UNESCAPED_SLASHES);
        if (@file_get_contents($flag) !== $json) {
            if (!is_dir(self::DIR)) {
                @mkdir(self::DIR, 0755, true);
            }
            @file_put_contents($flag, $json, LOCK_EX);
        }
    }

    /** Refresh every page. Instant at any size: bumps the generation; old files are swept in the background. */
    public static function clear(): int
    {
        self::bump(true);
        self::writeFlag();
        @unlink(self::DIR . '/.headers');
        Options::lazy('cache_purged_at');
        if (DB::connected()) {
            Options::set('cache_purged_at', time(), false);
        }
        return 0;
    }

    /**
     * Content changed: in "smart" mode refresh only this item's page(s) and the listing pages; otherwise everything.
     * @param string[] $paths extra paths to refresh (e.g. the item's old URL after a slug change)
     */
    public static function purgeContent(?array $post, array $paths = []): void
    {
        if (Options::get('cache_purge', 'all') !== 'smart' || !$post) {
            self::clear();
            return;
        }
        $paths[] = parse_url(Posts::permalink($post, true), PHP_URL_PATH) ?: '/';
        $hosts = array_unique(array_filter([self::siteHost(), (string)($_SERVER['HTTP_HOST'] ?? '')]));
        foreach (array_unique($paths) as $path) {
            foreach ($hosts as $host) {
                foreach ([true, false] as $https) {
                    @unlink(self::keyFor($host, $path, $https));
                }
            }
        }
        self::bump(false);
        Options::lazy('cache_purged_at');
        Options::set('cache_purged_at', time(), false);
        Hooks::action('page_cache_purged', $post, $paths);
    }

    /** Background task: delete files from older generations (bounded per run). */
    public static function sweep(int $max = 3000): int
    {
        [$gen, $lgen] = self::gens();
        $n = 0;
        foreach (glob(self::DIR . '/*.html') ?: [] as $f) {
            $fp = @fopen($f, 'rb');
            $head = $fp ? (string)fgets($fp, 200) : '';
            if ($fp) {
                fclose($fp);
            }
            $stale = !preg_match('/^<!--sc (\d+) (\d+) (single|listing)-->/', $head, $m)
                || (int)$m[1] !== $gen || ($m[3] === 'listing' && (int)$m[2] !== $lgen);
            if ($stale && @unlink($f) && ++$n >= $max) {
                break;
            }
        }
        foreach (glob(self::DIR . '/*.tmp') ?: [] as $f) {
            if (filemtime($f) < time() - 600) {
                @unlink($f);
            }
        }
        return $n;
    }

    /** Is a fresh cached copy of $path available? */
    public static function isWarm(string $path): bool
    {
        $file = self::keyFor(self::siteHost(), $path, str_starts_with(site_url(), 'https://'));
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return false;
        }
        $head = (string)fgets($fp, 200);
        fclose($fp);
        [$gen, $lgen] = self::gens();
        return (bool)preg_match('/^<!--sc (\d+) (\d+) (single|listing)-->/', $head, $m)
            && (int)$m[1] === $gen && ($m[3] === 'single' || (int)$m[2] === $lgen);
    }

    /**
     * Background task: after a purge, rebuild the most visited kinds of pages (home, recently changed items, archives)
     * by requesting them, so real visitors find them warm. Bounded per run.
     */
    public static function warm(int $budgetSeconds = 40): int
    {
        if (!Options::get('cache_pages', true) || !Options::get('cache_warm', true) || !function_exists('curl_init')) {
            return 0;
        }
        $paths = ['/'];
        foreach (ContentModel::types() as $slug => $t) {
            if (($t['public'] ?? true) !== false && !empty($t['has_archive'])) {
                $paths[] = parse_url(site_url(Posts::trail('/' . ($t['rewrite'] ?? $slug))), PHP_URL_PATH) ?: '/';
            }
        }
        foreach (Posts::query(['type' => array_keys(ContentModel::types()), 'status' => 'publish', 'limit' => 40, 'orderby' => 'updated_at', 'count' => false])['items'] as $p) {
            $paths[] = parse_url(Posts::permalink($p, true), PHP_URL_PATH) ?: '/';
        }
        $paths = array_slice(array_values(array_unique((array)Hooks::filter('cache_warm_paths', $paths))), 0, 120);
        $start = time();
        $n = 0;
        foreach ($paths as $path) {
            if (time() - $start > $budgetSeconds) {
                break;
            }
            if (self::isWarm($path)) {
                continue;
            }
            Http::request('GET', rtrim(site_url(), '/') . $path, ['Accept: text/html', 'X-Cache-Warm: 1'], null, 15);
            $n++;
            usleep(150000); // be gentle with shared hosting
        }
        return $n;
    }

    public static function stats(): array
    {
        $files = glob(self::DIR . '/*.html') ?: [];
        return ['count' => count($files), 'size' => array_sum(array_map(static fn($f) => (int)@filesize($f), $files))];
    }

    /** Default Content-Security-Policy: blocks clickjacking, <base> hijacking and plugins without restricting scripts. */
    public const DEFAULT_CSP = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'";

    /** Security headers from the Settings › Security options (needs the database). */
    public static function headerLines(): array
    {
        $lines = [];
        if (Options::get('security_headers', true)) {
            $lines[] = 'X-Content-Type-Options: nosniff';
            $lines[] = 'X-Frame-Options: SAMEORIGIN';
            $lines[] = 'Referrer-Policy: strict-origin-when-cross-origin';
            $lines[] = 'Permissions-Policy: camera=(), microphone=(), geolocation=(), browsing-topics=()';
            $lines[] = 'Cross-Origin-Opener-Policy: same-origin-allow-popups';
            $csp = trim(preg_replace('/\s+/', ' ', (string)Options::get('security_csp', self::DEFAULT_CSP)));
            if ($csp !== '') {
                $lines[] = 'Content-Security-Policy: ' . $csp;
            }
        }
        if (Options::get('security_hsts') && is_https()) {
            $lines[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        }
        return $lines;
    }

    /**
     * Send the security headers. With the database loaded they come from the settings and are
     * remembered in a small file, so cached pages (served before the database connects) send the same ones.
     */
    public static function securityHeaders(?array $lines = null): void
    {
        $file = self::DIR . '/.headers';
        if ($lines === null) {
            $lines = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
            if (!is_array($lines)) {
                return; // Not known yet: the next uncached request writes it.
            }
        } else {
            $json = json_encode(array_values($lines));
            if (!is_file($file) || @file_get_contents($file) !== $json) {
                if (!is_dir(self::DIR)) {
                    @mkdir(self::DIR, 0755, true);
                }
                @file_put_contents($file, $json, LOCK_EX);
            }
        }
        header_remove('X-Powered-By');
        foreach ($lines as $line) {
            if (is_string($line) && !preg_match('/[\r\n]/', $line)) {
                header($line);
            }
        }
    }
}
