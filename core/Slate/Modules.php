<?php
declare(strict_types=1);

namespace Slate;

/**
 * Modules (Slate's equivalent of plugins).
 * Each module lives in modules/<slug>/ with a module.json manifest and a module.php entry file.
 */
final class Modules
{
    public static function all(): array
    {
        $out = [];
        foreach (glob(SLATE_MODULES . '/*/module.json') ?: [] as $manifest) {
            $slug = basename(dirname($manifest));
            if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
                continue;
            }
            $meta = json_decode((string)file_get_contents($manifest), true) ?: [];
            $out[$slug] = $meta + ['name' => $slug, 'description' => '', 'version' => '1.0.0', 'author' => '', 'requires' => '0.1.0',
                    'slug' => $slug, 'active' => self::isActive($slug)];
        }
        ksort($out);
        return $out;
    }

    public static function active(): array
    {
        return array_values(array_filter((array)Options::get('active_modules', []), 'is_string'));
    }

    public static function isActive(string $slug): bool
    {
        return in_array($slug, self::active(), true);
    }

    public static function boot(): void
    {
        foreach (self::active() as $slug) {
            $file = SLATE_MODULES . '/' . $slug . '/module.php';
            if (preg_match('/^[a-z0-9_-]+$/', $slug) && is_file($file)) {
                try {
                    require_once $file;
                } catch (\Throwable $e) {
                    error_log("Slate module $slug failed: " . $e->getMessage());
                }
            }
        }
        Hooks::action('modules_loaded');
    }

    public static function activate(string $slug): ?string
    {
        $all = self::all();
        if (!isset($all[$slug])) {
            return 'Module not found.';
        }
        if (version_compare(SLATE_VERSION, (string)$all[$slug]['requires'], '<')) {
            return 'This module requires Slate ' . $all[$slug]['requires'] . ' or newer.';
        }
        $active = self::active();
        if (!in_array($slug, $active, true)) {
            $active[] = $slug;
            Options::set('active_modules', $active);
            require_once SLATE_MODULES . '/' . $slug . '/module.php';
            Hooks::action("module_activated_$slug");
        }
        PageCache::clear();
        return null;
    }

    public static function deactivate(string $slug): void
    {
        Hooks::action("module_deactivated_$slug");
        Options::set('active_modules', array_values(array_diff(self::active(), [$slug])));
        PageCache::clear();
    }

    /** Admin menu items registered by modules: Modules::adminPage('My module', 'my-module', fn() => ...) */
    private static array $pages = [];

    public static function adminPage(string $label, string $slug, callable $render, string $cap = 'manage_settings', string $icon = 'puzzle'): void
    {
        self::$pages[$slug] = compact('label', 'slug', 'render', 'cap', 'icon');
    }

    public static function adminPages(): array
    {
        return self::$pages;
    }
}
