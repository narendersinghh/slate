<?php
declare(strict_types=1);

namespace Slate;

/** Minimal router: patterns like /content/{type}/{id:\d+}. */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add(['POST'], $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): void
    {
        $this->add(['GET', 'POST'], $pattern, $handler);
    }

    public function add(array $methods, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function ($m) {
            return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
        }, rtrim($pattern, '/') ?: '/');
        $this->routes[] = [$methods, '#^' . $regex . '/?$#', $handler];
    }

    public function dispatch(string $method, string $path): bool
    {
        $path = '/' . trim($path, '/');
        foreach ($this->routes as [$methods, $regex, $handler]) {
            if (in_array($method, $methods, true) && preg_match($regex, $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler(...array_values($params));
                return true;
            }
        }
        return false;
    }
}
