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
        Cron::maybeRunAfterRequest();

        Modules::boot();
        Theme::boot();
        Hooks::action('init');

        // 4. Route.
        $admin = admin_slug();
        if ($path === "/$admin" || str_starts_with($path, "/$admin/")) {
            // Wrong host or address for the dashboard: indistinguishable from any missing page.
            if (!AdminAccess::dashboardAllowed()) {
                Frontend::notFound();
                return;
            }
            // Old /admin URL 404s when a custom admin slug is set (hides the login).
            require SLATE_ADMIN . '/routes.php';
            admin_dispatch(substr($path, strlen($admin) + 1) ?: '/');
            return;
        }
        // On the private admin host the public site is only shown to signed-in team members.
        if (AdminAccess::onAdminHost()) {
            header('X-Robots-Tag: noindex, nofollow');
            if (!Auth::can('access_admin')) {
                redirect(admin_url('login') . '?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
            }
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            Frontend::notFound();
            return;
        }
        if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
            if (Api::enabled() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
                Api::handle(substr($path, 7));
                return;
            }
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
        PageCache::securityHeaders(PageCache::headerLines());
        if (Options::get('seo_noindex_site')) {
            header('X-Robots-Tag: noindex, nofollow');
        }
    }
}
