<?php
declare(strict_types=1);

namespace Slate;

/** Request kernel. */
final class App
{
    public static function run(): void
    {
        // 1. Static page cache: no DB connection at all for cached anonymous hits.
        PageCache::serve();

        $path = self::path();

        // 2. Not installed yet → installer.
        if (!defined('SLATE_CONFIG')) {
            require SLATE_ADMIN . '/install.php';
            return;
        }

        // 3. Boot.
        try {
            DB::connect(SLATE_CONFIG['db']);
        } catch (\Throwable $e) {
            error_log('Slate DB: ' . $e->getMessage());
            http_response_code(503);
            exit(SLATE_DEBUG ? 'Database error: ' . e($e->getMessage()) : 'The site is temporarily unavailable. Please try again shortly.');
        }
        Options::load();
        Schema::migrate();
        if (function_exists('date_default_timezone_set')) {
            date_default_timezone_set('UTC');
        }
        self::headers();

        Modules::boot();
        Theme::boot();
        Hooks::action('init');

        // 4. Route.
        $admin = admin_slug();
        if ($path === "/$admin" || str_starts_with($path, "/$admin/")) {
            // Old /admin URL 404s when a custom admin slug is set (hides the login).
            require SLATE_ADMIN . '/routes.php';
            admin_dispatch(substr($path, strlen($admin) + 1) ?: '/');
            return;
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            Frontend::notFound();
            return;
        }
        Frontend::handle($path);
    }

    /** Request path relative to the install directory. */
    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);
        $base = base_path();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        if (str_contains($uri, "\0") || str_contains($uri, '..')) {
            $uri = '/';
        }
        return $uri;
    }

    private static function headers(): void
    {
        header_remove('X-Powered-By');
        if (Options::get('security_headers', true)) {
            PageCache::securityHeaders();
        }
        if (Options::get('security_hsts') && is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        if (Options::get('seo_noindex_site')) {
            header('X-Robots-Tag: noindex, nofollow');
        }
    }
}
