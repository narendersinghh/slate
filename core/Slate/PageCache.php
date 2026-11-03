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
        header('X-Slate-Cache: HIT');
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
        @file_put_contents(self::key(), $html . "\n<!-- cached by Slate " . gmdate('c') . ' -->', LOCK_EX);
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
        return $n;
    }

    public static function stats(): array
    {
        $files = glob(self::DIR . '/*.html') ?: [];
        return ['count' => count($files), 'size' => array_sum(array_map('filesize', $files))];
    }

    public static function securityHeaders(): void
    {
        if (is_file(self::DIR . '/.noheaders')) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    }
}
