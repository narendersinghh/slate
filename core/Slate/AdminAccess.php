<?php
declare(strict_types=1);

namespace Slate;

/**
 * Where the dashboard can be reached. Set in config.php (not the database), so a lock-out is always fixable over
 * SSH/FTP — or with `php bin/slate admin:host` / `admin:ips` / `admin:url`.
 *
 *   'admin_host' => 'cms.example.com',              the dashboard answers ONLY on this host name; on every other host
 *                                                   the admin URL is a plain 404. On the admin host, the public site
 *                                                   is shown to signed-in team members only (preview & on-page editing).
 *   'admin_ips'  => ['203.0.113.7', '10.0.0.0/8'],  optional: only these addresses reach the dashboard at all.
 *
 * Point the admin host at the same Slate folder (an extra domain/subdomain or alias in your hosting panel), use a name
 * nobody can guess, and keep it out of public DNS listings where you can (no links to it anywhere).
 */
final class AdminAccess
{
    public static function adminHost(): string
    {
        $h = defined('SLATE_CONFIG') ? strtolower(trim((string)(SLATE_CONFIG['admin_host'] ?? ''))) : '';
        return preg_replace('#^https?://|/.*$#', '', $h);
    }

    public static function currentHost(): string
    {
        return strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    }

    /** True when an admin host is configured and this request arrived on it. */
    public static function onAdminHost(): bool
    {
        $a = self::adminHost();
        return $a !== '' && self::currentHost() === $a;
    }

    /** May this request reach the dashboard (host + IP rules)? */
    public static function dashboardAllowed(): bool
    {
        $a = self::adminHost();
        if ($a !== '' && self::currentHost() !== $a) {
            return false;
        }
        return self::ipAllowed(Auth::ip());
    }

    public static function allowedIps(): array
    {
        $list = defined('SLATE_CONFIG') ? (SLATE_CONFIG['admin_ips'] ?? []) : [];
        if (is_string($list)) {
            $list = preg_split('/[\s,]+/', $list) ?: [];
        }
        return array_values(array_filter(array_map('trim', (array)$list)));
    }

    public static function ipAllowed(string $ip): bool
    {
        $list = self::allowedIps();
        if (!$list || PHP_SAPI === 'cli') {
            return true;
        }
        foreach ($list as $rule) {
            if (self::match($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /** IP or CIDR match, IPv4 and IPv6. */
    public static function match(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return @inet_pton($ip) !== false && @inet_pton($ip) === @inet_pton($rule);
        }
        [$net, $bits] = explode('/', $rule, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = max(0, min(strlen($a) * 8, (int)$bits));
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return ($a[$bytes] & $mask) === ($b[$bytes] & $mask);
    }

    /** Absolute base URL of the dashboard host (for emails and links that leave the dashboard). */
    public static function baseUrl(): string
    {
        $a = self::adminHost();
        if ($a === '') {
            return rtrim(site_url(), '/');
        }
        $scheme = parse_url(site_url(), PHP_URL_SCHEME) ?: 'https';
        return $scheme . '://' . $a . rtrim(base_path(), '/');
    }

    /**
     * Change config.php keys safely (CLI recovery). Writes a backup copy first. $value null removes the key.
     * @return string|null error
     */
    public static function setConfig(string $key, mixed $value): ?string
    {
        $file = SLATE_ROOT . '/config.php';
        if (!defined('SLATE_CONFIG') || !is_writable($file)) {
            return 'config.php is missing or not writable.';
        }
        $cfg = SLATE_CONFIG;
        if ($value === null) {
            unset($cfg[$key]);
        } else {
            $cfg[$key] = $value;
        }
        $old = (string)file_get_contents($file);
        @file_put_contents(SLATE_CONTENT . '/backups/config-' . gmdate('Ymd-His') . '.bak', $old, LOCK_EX);
        @chmod(SLATE_CONTENT . '/backups/config-' . gmdate('Ymd-His') . '.bak', 0600);
        $php = "<?php\n// Slate configuration — updated " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
            . 'const SLATE_CONFIG = ' . var_export($cfg, true) . ";\n";
        // Check the result parses before replacing the live file.
        try {
            token_get_all($php, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return 'Generated config did not parse: ' . $e->getMessage();
        }
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            return 'Could not write config.php.';
        }
        @chmod($file, 0600);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return null;
    }
}
