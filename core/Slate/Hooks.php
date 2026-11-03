<?php
declare(strict_types=1);

namespace Slate;

/** Actions & filters used by themes and modules to extend Slate. */
final class Hooks
{
    private static array $hooks = [];

    public static function add(string $name, callable $cb, int $priority = 10): void
    {
        self::$hooks[$name][$priority][] = $cb;
    }

    public static function has(string $name): bool
    {
        return !empty(self::$hooks[$name]);
    }

    public static function action(string $name, mixed ...$args): void
    {
        if (empty(self::$hooks[$name])) {
            return;
        }
        ksort(self::$hooks[$name]);
        foreach (self::$hooks[$name] as $cbs) {
            foreach ($cbs as $cb) {
                $cb(...$args);
            }
        }
    }

    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        if (empty(self::$hooks[$name])) {
            return $value;
        }
        ksort(self::$hooks[$name]);
        foreach (self::$hooks[$name] as $cbs) {
            foreach ($cbs as $cb) {
                $value = $cb($value, ...$args);
            }
        }
        return $value;
    }
}
