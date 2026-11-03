<?php
declare(strict_types=1);

namespace Slate;

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(?string $token): bool
    {
        Session::start();
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    /** Validate POST field or X-CSRF-Token header; abort on failure. */
    public static function check(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::valid($token)) {
            if (is_ajax()) {
                json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
            }
            http_response_code(419);
            exit('Security token expired. Go back, reload the page and try again.');
        }
    }
}
