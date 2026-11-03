<?php
declare(strict_types=1);

namespace Slate;

/**
 * Key/value settings store. All autoloaded options are fetched in ONE query per request.
 * Values are JSON encoded so arrays and booleans round-trip cleanly.
 */
final class Options
{
    private static array $cache = [];
    private static bool $loaded = false;
    private static array $missing = [];

    /** Options stored with autoload = 0 (fetched on demand). */
    public const LAZY = ['update_info'];

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        foreach (DB::all('SELECT name, value FROM {{options}} WHERE autoload = 1') as $row) {
            self::$cache[$row['name']] = json_decode((string)$row['value'], true);
        }
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }
        // Everything is autoloaded except a few large, rarely-read values. Unknown keys fall back to
        // their default WITHOUT a query, so a page view costs exactly one options query.
        if (isset(self::$missing[$name]) || !in_array($name, self::LAZY, true)) {
            return $default ?? Settings::default($name);
        }
        $value = DB::val('SELECT value FROM {{options}} WHERE name = :n', ['n' => $name]);
        if ($value === null) {
            self::$missing[$name] = true;
            return $default ?? Settings::default($name);
        }
        return self::$cache[$name] = json_decode((string)$value, true);
    }

    public static function set(string $name, mixed $value, bool $autoload = true): void
    {
        DB::upsert('options', [
            'name' => $name,
            'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'autoload' => $autoload ? 1 : 0,
        ], 'name');
        self::$cache[$name] = $value;
        unset(self::$missing[$name]);
    }

    public static function delete(string $name): void
    {
        DB::delete('options', ['name' => $name]);
        unset(self::$cache[$name]);
    }
}
