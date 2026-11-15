<?php
declare(strict_types=1);

namespace Slate;

/**
 * Modules (Slate's equivalent of plugins).
 *
 * Each module lives in modules/<slug>/ with a module.json manifest and a module.php entry file:
 *
 *   {
 *     "name": "Shop", "description": "…", "version": "1.2.0", "author": "You", "homepage": "https://…",
 *     "requires": "0.6.0",               minimum Slate version
 *     "requires_php": "8.2",             minimum PHP version
 *     "depends": {"payments": ">=1.0"},  other modules (or a plain list of slugs)
 *     "autoload": {"SlateModules\\Shop\\": "src/"},   PSR-4 class loading
 *     "source": "https://github.com/me/slate-shop",    where updates come from (zip URL, GitHub repo or folder)
 *     "settings": "shop"                 admin page slug opened by the "Settings" button
 *   }
 *
 * Optional files: migrations/NNN-name.php (run once, in order, on activation and after updates; each returns a
 * callable or runs its statements directly) and uninstall.php (run when the module is deleted).
 *
 * Safety: a module that fails while loading (syntax error, missing class, fatal error) is switched off automatically and
 * the error is shown on the Modules page, so a broken module never takes the website down. Create content/safe-mode
 * (an empty file) to start without any module, e.g. over FTP.
 */
final class Modules
{
    private const SAFE_MODE_FILE = SLATE_CONTENT . '/safe-mode';
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** Module being loaded right now (for crash attribution). */
    private static ?string $loading = null;
    private static bool $guarded = false;
    private static array $autoload = [];
    private static ?array $all = null;

    // ------------------------------------------------------------------ discovery

    public static function all(bool $fresh = false): array
    {
        if (self::$all !== null && !$fresh) {
            return self::$all;
        }
        $out = [];
        foreach (glob(SLATE_MODULES . '/*/module.json') ?: [] as $manifest) {
            $slug = basename(dirname($manifest));
            if (!self::validSlug($slug)) {
                continue;
            }
            $out[$slug] = self::manifest($slug) + ['slug' => $slug, 'active' => self::isActive($slug)];
        }
        ksort($out);
        return self::$all = $out;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function validSlug(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug);
    }

    /** Normalised manifest of an installed module (or of a module folder anywhere, for installs). */
    public static function manifest(string $slugOrDir): array
    {
        $dir = str_contains($slugOrDir, '/') ? $slugOrDir : SLATE_MODULES . '/' . $slugOrDir;
        $meta = json_decode((string)@file_get_contents($dir . '/module.json'), true);
        $meta = is_array($meta) ? $meta : [];
        $depends = [];
        foreach ((array)($meta['depends'] ?? []) as $k => $v) {
            if (is_int($k)) {
                $depends[(string)$v] = '*';
            } else {
                $depends[(string)$k] = (string)$v ?: '*';
            }
        }
        return [
            'name' => (string)($meta['name'] ?? basename($dir)),
            'description' => (string)($meta['description'] ?? ''),
            'version' => (string)($meta['version'] ?? '1.0.0'),
            'author' => (string)($meta['author'] ?? ''),
            'homepage' => (string)($meta['homepage'] ?? ''),
            'requires' => (string)($meta['requires'] ?? '0.1.0'),
            'requires_php' => (string)($meta['requires_php'] ?? ''),
            'depends' => $depends,
            'autoload' => array_filter((array)($meta['autoload'] ?? []), 'is_string'),
            'source' => (string)($meta['source'] ?? ''),
            'settings' => (string)($meta['settings'] ?? ''),
            'icon' => preg_match('/^[a-z0-9-]{1,40}$/', (string)($meta['icon'] ?? '')) ? (string)$meta['icon'] : '',
            'tags' => array_values(array_filter((array)($meta['tags'] ?? []), 'is_string')),
            'valid' => is_file($dir . '/module.php'),
        ];
    }

    public static function active(): array
    {
        return array_values(array_filter((array)Options::get('active_modules', []), 'is_string'));
    }

    public static function isActive(string $slug): bool
    {
        return in_array($slug, self::active(), true);
    }

    public static function safeMode(): bool
    {
        return is_file(self::SAFE_MODE_FILE);
    }

    // ------------------------------------------------------------------ boot

    /** Rarely-read module bookkeeping is stored with autoload off; tell Options to fetch it on demand. */
    public static function lazyOptions(): void
    {
        Options::lazy('module_errors', 'module_migrations', 'module_versions', 'module_updates', 'module_registries', 'modules_github_token');
    }

    public static function boot(): void
    {
        self::lazyOptions();
        self::guard();
        if (self::safeMode()) {
            Hooks::action('modules_loaded');
            return;
        }
        $autoload = Options::get('module_autoload', []);
        if (is_array($autoload) && $autoload) {
            self::registerAutoload($autoload);
        }
        foreach (self::active() as $slug) {
            $file = SLATE_MODULES . '/' . $slug . '/module.php';
            if (self::validSlug($slug) && is_file($file)) {
                self::load($slug, $file);
            }
        }
        self::$loading = null;
        Hooks::action('modules_loaded');
    }

    private static function load(string $slug, string $file): bool
    {
        self::$loading = $slug;
        try {
            require_once $file;
            return true;
        } catch (\Error $e) {
            // Syntax errors, missing classes/functions: the module cannot work, switch it off, // unless the broken file is core code the module merely loaded (not the module's fault).
            if ($e instanceof \ParseError && self::slugFromPath($e->getFile()) !== $slug) {
                throw $e;
            }
            self::fail($slug, $e->getMessage(), $e->getFile(), $e->getLine());
        } catch (\Throwable $e) {
            error_log("Slate module $slug failed: " . $e->getMessage());
        } finally {
            self::$loading = null;
        }
        return false;
    }

    /** Turns a fatal error raised while a module loads into an automatic deactivation instead of a dead site. */
    private static function guard(): void
    {
        if (self::$guarded) {
            return;
        }
        self::$guarded = true;
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (!$err || !($err['type'] & self::FATAL)) {
                return;
            }
            $slug = self::$loading;
            $owner = self::slugFromPath((string)$err['file']);
            if ($err['type'] & (E_PARSE | E_COMPILE_ERROR)) {
                // A broken file belongs to whoever owns it: never blame a module for a broken core file.
                $slug = $owner;
            }
            if ($slug === null) {
                return;
            }
            try {
                self::fail($slug, (string)$err['message'], (string)$err['file'], (int)$err['line']);
            } catch (\Throwable) {
                // The database may be what failed; nothing more we can do.
            }
            if (PHP_SAPI !== 'cli') {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/html; charset=utf-8');
                    header('Cache-Control: no-store');
                }
                $team = false;
                try {
                    $team = (bool)Auth::user();
                } catch (\Throwable) {
                }
                $body = $team
                    ? '<h1 style="font-size:22px">A module stopped working</h1><p>The module <strong>' . e($slug) . '</strong> caused an error and was switched off '
                        . 'automatically. Reload the page, the site works without it.</p><p><a href="' . e(admin_url('modules')) . '">Back to Modules</a></p>'
                    : '<h1 style="font-size:22px">Something went wrong</h1><p>Please reload the page.</p>';
                echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Error</title>'
                    . '<div style="font:15px/1.5 system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px">' . $body . '</div>';
            }
        });
    }

    public static function slugFromPath(string $file): ?string
    {
        $base = rtrim(str_replace('\\', '/', SLATE_MODULES), '/') . '/';
        $file = str_replace('\\', '/', $file);
        if (!str_starts_with($file, $base)) {
            return null;
        }
        $slug = explode('/', substr($file, strlen($base)))[0];
        return self::validSlug($slug) ? $slug : null;
    }

    /** Switch a broken module off and remember why. */
    public static function fail(string $slug, string $message, string $file = '', int $line = 0): void
    {
        $short = str_replace(SLATE_ROOT, '', $file);
        error_log("Slate module $slug was deactivated: $message in $short:$line");
        if (!DB::connected()) {
            return;
        }
        Options::set('active_modules', array_values(array_diff(self::active(), [$slug])));
        $errors = (array)Options::get('module_errors', []);
        $errors[$slug] = ['message' => mb_substr($message, 0, 500), 'file' => $short, 'line' => $line, 'time' => now()];
        Options::set('module_errors', $errors, false);
        self::syncAutoload();
        PageCache::clear();
    }

    public static function errors(): array
    {
        return (array)Options::get('module_errors', []);
    }

    public static function clearError(string $slug): void
    {
        $errors = self::errors();
        if (isset($errors[$slug])) {
            unset($errors[$slug]);
            Options::set('module_errors', $errors, false);
        }
    }

    // ------------------------------------------------------------------ class autoloading

    private static function registerAutoload(array $map): void
    {
        if (!self::$autoload) {
            spl_autoload_register(static function (string $class): void {
                foreach (self::$autoload as $prefix => $dir) {
                    if (str_starts_with($class, $prefix)) {
                        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            require $file;
                            return;
                        }
                    }
                }
            });
        }
        foreach ($map as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir)) {
                self::$autoload[$prefix] = SLATE_MODULES . '/' . ltrim(str_replace(SLATE_MODULES . '/', '', $dir), '/');
            }
        }
    }

    /** Store the PSR-4 map of every active module, so boot needs no manifest reads. */
    private static function syncAutoload(): void
    {
        $map = [];
        foreach (self::active() as $slug) {
            foreach (self::manifest($slug)['autoload'] as $prefix => $path) {
                $prefix = trim((string)$prefix, '\\') . '\\';
                $path = trim(str_replace('\\', '/', $path), '/');
                if ($prefix !== '\\' && !str_contains($path, '..') && str_starts_with($prefix, 'SlateModules\\')) {
                    $map[$prefix] = "$slug/" . ($path === '' ? '' : "$path/"); // relative to modules/, so the site can move
                }
            }
        }
        if ($map !== (array)Options::get('module_autoload', [])) {
            Options::set('module_autoload', $map);
        }
    }

    // ------------------------------------------------------------------ requirements & dependencies

    /** Why a module cannot run here, or null when it can. */
    public static function requirementError(string $slug, ?array $m = null): ?string
    {
        $m ??= self::get($slug);
        if (!$m) {
            return 'Module not found.';
        }
        if (empty($m['valid'])) {
            return 'This module has no module.php file.';
        }
        if (version_compare(SLATE_VERSION, $m['requires'], '<')) {
            return "This module requires Slate {$m['requires']} or newer.";
        }
        if ($m['requires_php'] !== '' && version_compare(PHP_VERSION, $m['requires_php'], '<')) {
            return "This module requires PHP {$m['requires_php']} or newer (this server runs " . PHP_VERSION . ').';
        }
        return null;
    }

    /** Is version $v allowed by a constraint like ">=1.2", "^1.0", "~2.1", "1.*", "*"? */
    public static function satisfies(string $v, string $constraint): bool
    {
        foreach (preg_split('/\s*\|\|\s*/', trim($constraint)) as $alt) {
            $ok = true;
            foreach (preg_split('/[\s,]+/', trim($alt)) ?: [] as $c) {
                if ($c === '' || $c === '*') {
                    continue;
                }
                if (preg_match('/^(>=|<=|>|<|==|=|!=)\s*(.+)$/', $c, $m)) {
                    $ok = $ok && version_compare($v, $m[2], $m[1] === '=' ? '==' : $m[1]);
                } elseif ($c[0] === '^') {
                    $base = substr($c, 1);
                    $major = (int)$base;
                    $ok = $ok && version_compare($v, $base, '>=') && version_compare($v, ($major + 1) . '.0.0', '<');
                } elseif ($c[0] === '~') {
                    $base = substr($c, 1);
                    $parts = explode('.', $base);
                    $next = count($parts) > 1 ? $parts[0] . '.' . ((int)$parts[1] + 1) : ((int)$parts[0] + 1) . '.0';
                    $ok = $ok && version_compare($v, $base, '>=') && version_compare($v, $next, '<');
                } elseif (str_contains($c, '*')) {
                    $ok = $ok && (bool)preg_match('/^' . str_replace('\*', '.*', preg_quote($c, '/')) . '$/', $v);
                } else {
                    $ok = $ok && version_compare($v, $c, '==');
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** Active modules that need $slug. */
    public static function dependents(string $slug): array
    {
        $out = [];
        foreach (self::all() as $s => $m) {
            if ($m['active'] && isset($m['depends'][$slug])) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Modules (in load order) that must be active before $slug, or an error. */
    private static function resolve(string $slug, array &$order, array $stack = []): ?string
    {
        if (in_array($slug, $stack, true)) {
            return 'Circular module dependency: ' . implode(' → ', [...$stack, $slug]) . '.';
        }
        $m = self::get($slug);
        if (!$m) {
            $by = $stack ? ' (needed by ' . end($stack) . ')' : '';
            return "Module “{$slug}” is not installed$by. Install it first under Modules → Add new.";
        }
        if ($err = self::requirementError($slug, $m)) {
            return "{$m['name']}: $err";
        }
        foreach ($m['depends'] as $dep => $constraint) {
            $dm = self::get($dep);
            if ($dm && !self::satisfies($dm['version'], $constraint)) {
                return "{$m['name']} needs {$dm['name']} $constraint (installed: {$dm['version']}).";
            }
            if ($err = self::resolve($dep, $order, [...$stack, $slug])) {
                return $err;
            }
        }
        if (!in_array($slug, $order, true)) {
            $order[] = $slug;
        }
        return null;
    }

    // ------------------------------------------------------------------ activate / deactivate

    /**
     * Activate a module and, automatically, the installed modules it depends on.
     * Returns an error message or null on success.
     */
    public static function activate(string $slug): ?string
    {
        if (!self::get($slug)) {
            return 'Module not found.';
        }
        $order = [];
        if ($err = self::resolve($slug, $order)) {
            return $err;
        }
        foreach ($order as $s) {
            if (self::isActive($s)) {
                continue;
            }
            $check = ModuleScanner::lint(SLATE_MODULES . '/' . $s);
            if ($check) {
                return self::get($s)['name'] . ' has a syntax error and was not activated: ' . $check[0];
            }
            self::clearError($s);
            // Mark active first: if loading kills the request, the shutdown guard switches it off again.
            Options::set('active_modules', [...self::active(), $s]);
            self::syncAutoload();
            $autoload = (array)Options::get('module_autoload', []);
            self::registerAutoload($autoload);
            if (!self::load($s, SLATE_MODULES . "/$s/module.php")) {
                $e = self::errors()[$s]['message'] ?? 'it failed to load';
                return self::get($s)['name'] . " could not be activated: $e";
            }
            self::$loading = $s;
            try {
                if ($err = self::migrate($s)) {
                    self::fail($s, $err);
                    return self::get($s)['name'] . " could not be activated: $err";
                }
                Hooks::action("module_activated_$s");
            } catch (\Error $e) {
                self::fail($s, $e->getMessage(), $e->getFile(), $e->getLine());
                return self::get($s)['name'] . ' could not be activated: ' . $e->getMessage();
            } finally {
                self::$loading = null;
            }
            self::rememberVersion($s);
        }
        self::$all = null;
        PageCache::clear();
        return null;
    }

    /** Deactivate a module. Refuses while other active modules depend on it (unless $force). */
    public static function deactivate(string $slug, bool $force = false): ?string
    {
        if (!$force && ($deps = self::dependents($slug))) {
            $names = array_map(static fn($s) => self::get($s)['name'] ?? $s, $deps);
            return 'Deactivate ' . implode(', ', $names) . ' first, ' . (count($deps) > 1 ? 'they need' : 'it needs') . ' this module.';
        }
        Hooks::action("module_deactivated_$slug");
        Options::set('active_modules', array_values(array_diff(self::active(), [$slug])));
        self::syncAutoload();
        self::$all = null;
        PageCache::clear();
        return null;
    }

    // ------------------------------------------------------------------ migrations

    /** Run the module's pending migrations/NNN-*.php files in order. Returns an error message or null. */
    public static function migrate(string $slug): ?string
    {
        $files = glob(SLATE_MODULES . "/$slug/migrations/*.php") ?: [];
        if (!$files) {
            return null;
        }
        natsort($files);
        $done = (array)Options::get('module_migrations', []);
        $applied = (array)($done[$slug] ?? []);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            try {
                $result = (static fn(string $__file) => require $__file)($file);
                if (is_callable($result)) {
                    $result();
                }
            } catch (\Throwable $e) {
                return "migration $name failed: " . $e->getMessage();
            }
            $applied[] = $name;
            $done[$slug] = $applied;
            Options::set('module_migrations', $done, false);
            log_activity('module_migrated', "$slug/$name");
        }
        return null;
    }

    private static function rememberVersion(string $slug): void
    {
        $versions = (array)Options::get('module_versions', []);
        $v = self::manifest($slug)['version'];
        if (($versions[$slug] ?? null) !== $v) {
            $versions[$slug] = $v;
            Options::set('module_versions', $versions, false);
        }
    }

    /**
     * Admin-side upkeep: when an active module's files changed version (uploaded over FTP, git pull, update),
     * run its new migrations and fire "module_updated_<slug>". Cheap: one small option + one manifest per module.
     */
    public static function maintain(): void
    {
        if (self::safeMode()) {
            return;
        }
        $versions = (array)Options::get('module_versions', []);
        $changed = false;
        foreach (self::active() as $slug) {
            if (!is_file(SLATE_MODULES . "/$slug/module.json")) {
                continue;
            }
            $v = self::manifest($slug)['version'];
            if (($versions[$slug] ?? null) === $v) {
                continue;
            }
            if (isset($versions[$slug])) {
                self::$loading = $slug;
                if ($err = self::migrate($slug)) {
                    self::$loading = null;
                    self::fail($slug, $err);
                    continue;
                }
                Hooks::action("module_updated_$slug", $versions[$slug], $v);
                self::$loading = null;
            }
            $versions[$slug] = $v;
            $changed = true;
        }
        if ($changed) {
            Options::set('module_versions', $versions, false);
            self::syncAutoload();
        }
    }

    // ------------------------------------------------------------------ admin pages

    /** Admin menu items registered by modules: Modules::adminPage('My module', 'my-module', fn() => ...) */
    private static array $pages = [];

    /**
     * Add a dashboard page. Pages are grouped under their module in the sidebar's "Modules" section.
     *
     * @param string        $section "main" (default; listed under the module), "settings" (the module's settings page,
     *                               shown last as "Settings") or "hidden" (reachable by URL only). The old values
     *                               "build" and "content" mean "main".
     * @param callable|null $badge   returns a number shown next to the label (e.g. unread items), 0 = none
     */
    public static function adminPage(string $label, string $slug, callable $render, string $cap = 'manage_settings', string $icon = 'puzzle',
        string $section = 'main', ?callable $badge = null): void
    {
        $section = in_array($section, ['settings', 'hidden'], true) ? $section : 'main';
        $module = self::$loading ?? self::callerModule();
        self::$pages[$slug] = compact('label', 'slug', 'render', 'cap', 'icon', 'section', 'badge', 'module');
    }

    /**
     * Declarative settings for a module, rendered by the standard settings screen (Settings → Modules → <name>) and
     * linked as "Settings" in the module's sidebar group. Same field format as core settings:
     *   Modules::settings([['key' => 'shop_currency', 'type' => 'select', 'label' => 'Currency', 'options' => [...], 'default' => 'USD'], ...]);
     * Optional: 'label' (defaults to the module name), 'help' (intro text), 'render' => callable printing extra HTML below the fields.
     */
    public static function settings(array $fields, array $opts = []): void
    {
        $module = self::$loading ?? self::callerModule();
        if (!$module) {
            return;
        }
        self::$settings[$module] = ['fields' => $fields, 'label' => (string)($opts['label'] ?? (self::all()[$module]['name'] ?? $module)),
            'intro' => (string)($opts['help'] ?? ''), 'callback' => $opts['render'] ?? null, 'cap' => (string)($opts['cap'] ?? 'manage_settings')];
    }

    private static array $settings = [];

    /** @return array<string, array> module slug => settings definition */
    public static function settingsSections(): array
    {
        return self::$settings;
    }

    public static function adminPages(): array
    {
        return self::$pages;
    }

    /**
     * Sidebar groups: one per module that has pages or settings, in module-name order.
     * @return array<string, array{name:string, icon:string, pages:array, settings:?string}>
     */
    public static function adminGroups(): array
    {
        $groups = [];
        $all = self::all();
        foreach (self::$pages as $p) {
            $m = $p['module'] ?? '' ?: 'other';
            $groups[$m] ??= ['name' => $all[$m]['name'] ?? 'Other', 'icon' => ($all[$m]['icon'] ?? '') ?: $p['icon'], 'pages' => [], 'settings' => null];
            if ($p['section'] === 'settings') {
                $groups[$m]['settings'] = $p;
            } elseif ($p['section'] === 'main') {
                $groups[$m]['pages'][] = $p;
            }
        }
        foreach (self::$settings as $m => $s) {
            $groups[$m] ??= ['name' => $all[$m]['name'] ?? $m, 'icon' => ($all[$m]['icon'] ?? '') ?: 'puzzle', 'pages' => [], 'settings' => null];
            $groups[$m]['settings'] ??= ['label' => 'Settings', 'url' => 'settings/modules/' . $m, 'cap' => $s['cap'], 'icon' => 'settings', 'slug' => ''];
        }
        uasort($groups, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $groups;
    }

    /** Which module's file called us (pages registered from an init hook rather than while loading). */
    private static function callerModule(): ?string
    {
        $root = rtrim(str_replace('\\', '/', SLATE_MODULES), '/') . '/';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
            $file = str_replace('\\', '/', (string)($f['file'] ?? ''));
            if (str_starts_with($file, $root)) {
                return explode('/', substr($file, strlen($root)), 2)[0];
            }
        }
        return null;
    }
}
