<?php
declare(strict_types=1);

namespace Slate;

/** Theme loading and template hierarchy. */
final class Theme
{
    private static ?array $meta = null;

    public static function active(): string
    {
        $t = (string)Options::get('theme', 'default');
        return preg_match('/^[a-z0-9_-]+$/', $t) && is_dir(SLATE_THEMES . '/' . $t) ? $t : 'default';
    }

    public static function path(string $file = ''): string
    {
        return SLATE_THEMES . '/' . self::active() . ($file !== '' ? '/' . ltrim($file, '/') : '');
    }

    public static function url(string $file = ''): string
    {
        return asset_url('themes/' . self::active() . '/' . ltrim($file, '/'));
    }

    public static function meta(): array
    {
        if (self::$meta === null) {
            $f = self::path('theme.json');
            self::$meta = (is_file($f) ? json_decode((string)file_get_contents($f), true) : null) ?: [];
            self::$meta += ['name' => self::active(), 'menus' => ['primary' => 'Primary', 'footer' => 'Footer'], 'templates' => []];
        }
        return self::$meta;
    }

    public static function available(): array
    {
        $out = [];
        foreach (glob(SLATE_THEMES . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            $meta = is_file("$dir/theme.json") ? json_decode((string)file_get_contents("$dir/theme.json"), true) : [];
            $out[$slug] = $meta['name'] ?? $slug;
        }
        return $out;
    }

    public static function boot(): void
    {
        $fn = self::path('functions.php');
        if (is_file($fn)) {
            require_once $fn;
        }
    }

    /** Render the first template that exists from a hierarchy list. */
    public static function render(array $candidates, array $vars = []): void
    {
        $candidates[] = 'index';
        foreach ($candidates as $c) {
            $c = preg_replace('/[^a-z0-9_-]/i', '', (string)$c);
            $file = self::path('templates/' . $c . '.php');
            if ($c !== '' && is_file($file)) {
                self::include($file, $vars);
                return;
            }
        }
        throw new \RuntimeException('Theme "' . self::active() . '" has no index.php template.');
    }

    public static function partial(string $name, array $vars = []): void
    {
        $file = self::path('partials/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.php');
        if (is_file($file)) {
            self::include($file, $vars);
        }
    }

    private static function include(string $__file, array $__vars): void
    {
        extract($__vars, EXTR_SKIP);
        require $__file;
    }
}
