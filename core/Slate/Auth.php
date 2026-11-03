<?php
declare(strict_types=1);

namespace Slate;

/** Login, logout, brute-force protection, 2FA and capability checks. */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        if (!Session::exists()) {
            return null;
        }
        Session::start();
        $id = (int)($_SESSION['uid'] ?? 0);
        if (!$id) {
            return null;
        }
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $id]);
        // Invalidate session if the password changed or the user was suspended.
        if (!$user || $user['status'] !== 'active' || ($_SESSION['pwh'] ?? '') !== self::passwordFingerprint($user)) {
            Session::destroy();
            return null;
        }
        unset($user['password'], $user['totp_secret']);
        $user['meta'] = json_decode((string)$user['meta'], true) ?: [];
        return self::$user = $user;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function can(string $cap): bool
    {
        $u = self::user();
        return $u && Roles::can($u['role'], $cap);
    }

    public static function require(string $cap): void
    {
        if (!self::user()) {
            redirect(admin_url('login') . '?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
        }
        if (!self::can($cap)) {
            if (is_ajax()) {
                json_response(['ok' => false, 'error' => 'You do not have permission to do that.'], 403);
            }
            http_response_code(403);
            admin_view('error', ['title' => 'Access denied', 'message' => 'You do not have permission to view this page.']);
            exit;
        }
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (Options::get('security_trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public static function isLockedOut(): bool
    {
        $max = (int)Options::get('security_max_attempts', 5);
        $minutes = (int)Options::get('security_lockout_minutes', 15);
        $since = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $count = (int)DB::val(
            'SELECT COUNT(*) FROM {{login_attempts}} WHERE ip = :ip AND attempted_at > :since',
            ['ip' => self::ip(), 'since' => $since]
        );
        return $count >= $max;
    }

    /**
     * @return array{ok:bool, error?:string, needs_2fa?:bool}
     */
    public static function attempt(string $login, string $password, ?string $totp = null): array
    {
        if (self::isLockedOut()) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . (int)Options::get('security_lockout_minutes', 15) . ' minutes.'];
        }
        $user = DB::row(
            'SELECT * FROM {{users}} WHERE username = :l OR email = :l2 LIMIT 1',
            ['l' => $login, 'l2' => strtolower($login)]
        );
        // Always verify against a hash to keep timing uniform when the user does not exist.
        $hash = $user['password'] ?? self::hash(bin2hex(random_bytes(8)));
        $valid = password_verify($password, $hash) && $user && $user['status'] === 'active';

        if (!$valid) {
            self::recordFailure($login);
            return ['ok' => false, 'error' => 'Incorrect username or password.'];
        }
        if (!empty($user['totp_secret'])) {
            if ($totp === null || $totp === '') {
                return ['ok' => false, 'needs_2fa' => true];
            }
            if (!Totp::verify(Crypto::decrypt($user['totp_secret']), $totp)) {
                self::recordFailure($login);
                return ['ok' => false, 'needs_2fa' => true, 'error' => 'Invalid authentication code.'];
            }
        }
        if (password_needs_rehash($user['password'], self::algo())) {
            DB::update('users', ['password' => self::hash($password)], ['id' => $user['id']]);
            $user['password'] = self::hash($password);
        }
        self::loginUser($user);
        return ['ok' => true];
    }

    public static function loginUser(array $user): void
    {
        Session::start();
        Session::regenerate();
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['pwh'] = self::passwordFingerprint($user);
        setcookie(Session::FLAG, '1', ['expires' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        DB::update('users', ['last_login' => now()], ['id' => $user['id']]);
        DB::query('DELETE FROM {{login_attempts}} WHERE ip = :ip', ['ip' => self::ip()]);
        self::$resolved = false;
        log_activity('login', $user['username']);
    }

    public static function logout(): void
    {
        Session::start();
        Session::destroy();
        self::$user = null;
    }

    private static function recordFailure(string $login): void
    {
        DB::insert('login_attempts', ['ip' => self::ip(), 'username' => mb_substr($login, 0, 191), 'attempted_at' => now()]);
        // Small random delay slows down automated guessing.
        usleep(random_int(200000, 500000));
    }

    private static function passwordFingerprint(array $user): string
    {
        return substr(hash('sha256', $user['password'] . ($user['totp_secret'] ?? '')), 0, 24);
    }

    public static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function passwordProblems(string $password): ?string
    {
        $min = (int)Options::get('security_min_password', 10);
        if (mb_strlen($password) < $min) {
            return "Password must be at least $min characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d|[^A-Za-z]/', $password)) {
            return 'Use a mix of letters and numbers or symbols.';
        }
        return null;
    }
}
