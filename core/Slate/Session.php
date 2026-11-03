<?php
declare(strict_types=1);

namespace Slate;

/** Hardened PHP session handling. Only started when needed (admin / logged-in users). */
final class Session
{
    public const NAME = 'slate_sid';
    /** Non-sensitive cookie that lets the page cache skip logged-in visitors without starting a session. */
    public const FLAG = 'slate_li';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (DB::connected() ? (int)Options::get('security_session_hours', 12) : 12) * 3600;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Absolute + idle timeout.
        $now = time();
        if (isset($_SESSION['_last']) && $now - $_SESSION['_last'] > $lifetime) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last'] = $now;
    }

    public static function exists(): bool
    {
        return isset($_COOKIE[self::NAME]);
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        setcookie(self::NAME, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        setcookie(self::FLAG, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
