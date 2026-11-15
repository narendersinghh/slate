<?php
declare(strict_types=1);

namespace Slate;

/**
 * Static full-page cache for anonymous visitors.
 * serve() runs BEFORE the database connection, so cached pages cost zero queries.
 */
final class PageCache
{
    private const DIR = SLATE_CACHE . '/pages';

    private static function key(): string
    {
        $host = strtolower(preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? ''));
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::DIR . '/' . md5((is_https() ? 's' : '') . $host . $path) . '.html';
    }

    private static function cacheable(): bool
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
        return true;
    }

    public static function serve(): void
    {
        if (!is_file(self::DIR . '/.enabled') || !self::cacheable()) {
            return;
        }
        $file = self::key();
        if (!is_file($file)) {
            return;
        }
        $ttl = (int)@file_get_contents(self::DIR . '/.enabled') ?: 43200;
        if (filemtime($file) + $ttl < time()) {
            @unlink($file);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        if (SLATE_DEBUG) {
            header('X-Cache: HIT');
        }
        header('Cache-Control: public, max-age=0, must-revalidate');
        self::securityHeaders();
        readfile($file);
        exit;
    }

    public static function store(string $html): void
    {
        if (!Options::get('cache_pages', true) || !self::cacheable() || http_response_code() !== 200
            || session_status() === PHP_SESSION_ACTIVE || Options::get('maintenance_mode')) {
            return;
        }
        if (!is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        self::writeFlag();
        @file_put_contents(self::key(), $html, LOCK_EX);
    }

    private static function writeFlag(): void
    {
        $flag = self::DIR . '/.enabled';
        $ttl = (string)((int)Options::get('cache_ttl', 720) * 60);
        if (!is_file($flag) || file_get_contents($flag) !== $ttl) {
            @file_put_contents($flag, $ttl);
        }
    }

    public static function clear(): int
    {
        $n = 0;
        foreach (glob(self::DIR . '/*.html') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        if (!Options::get('cache_pages', true)) {
            @unlink(self::DIR . '/.enabled');
        }
        @unlink(self::DIR . '/.headers');
        return $n;
    }

    public static function stats(): array
    {
        $files = glob(self::DIR . '/*.html') ?: [];
        return ['count' => count($files), 'size' => array_sum(array_map('filesize', $files))];
    }

    /** Default Content-Security-Policy: blocks clickjacking, <base> hijacking and plugins without restricting scripts. */
    public const DEFAULT_CSP = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'";

    /** Security headers from the Settings → Security options (needs the database). */
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
