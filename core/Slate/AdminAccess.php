<?php
declare(strict_types=1);

namespace Slate;

/**
 * Where the dashboard can be reached. Set in config.php (not the database), so a lock-out is always fixable over
 * SSH/FTP, or with `php bin/slate admin:host` / `admin:ips` / `admin:url`.
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
        $php = "<?php\n// Slate configuration, updated " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
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
        if ($key === 'admin_host') {
            self::writeAssetRules(is_string($value) ? $value : '');
        }
        return null;
    }

    /**
     * Dashboard scripts/styles live in admin/assets and are served directly by the web server. With a private admin
     * host, hide them on every other host too (they would reveal the CMS). Apache/LiteSpeed only; nginx: see docs.
     */
    public static function writeAssetRules(string $host): void
    {
        $file = SLATE_ADMIN . '/.htaccess';
        if ($host === '') {
            @unlink($file);
        } else {
            $rule = "# Written by Slate (admin_host). Dashboard files only on the private admin host.\n"
                . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
                . "    # Internal dashboard code is never requested directly (the front controller loads it).\n"
                . "    RewriteRule \\.(php\\d?|phtml|phar|inc)$ - [R=404,L,NC]\n"
                . "    RewriteCond %{HTTP_HOST} !^" . preg_quote($host, '/') . "(:\\d+)?$ [NC]\n"
                . "    RewriteRule ^ - [R=404,L]\n</IfModule>\n";
            @file_put_contents($file, $rule, LOCK_EX);
        }
        if (DB::connected()) {
            Integrity::trust('admin/.htaccess');
        }
    }

    // ------------------------------------------------------------------ guided switch to a private dashboard domain

    public const PROBE_PATH = '/__slate-probe/';

    /**
     * Check that $host already reaches THIS installation (DNS + hosting set up) before switching, so a typo or a
     * missing subdomain can never lock anyone out. Returns [ok, message].
     */
    public static function probe(string $host): array
    {
        $host = strtolower(trim(preg_replace('#^https?://|/.*$#i', '', $host)));
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) && !preg_match('/^[a-z0-9-]+\.localhost$/', $host)) {
            return [false, 'Enter a host name such as cms.example.com (no https://, no path).'];
        }
        $token = bin2hex(random_bytes(16));
        Options::set('admin_probe', ['token' => $token, 'expires' => time() + 300], false);
        $scheme = parse_url(site_url(), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url(site_url(), PHP_URL_PORT);
        $url = $scheme . '://' . $host . ($port && str_ends_with($host, '.localhost') ? ':' . $port : '') . rtrim(base_path(), '/') . self::PROBE_PATH . $token;
        $r = Http::request('GET', $url, [], null, 10);
        if (($r['status'] ?? 0) === 200 && trim((string)$r['body']) === hash_hmac('sha256', $token, self::secret())) {
            return [true, "$host reaches this website. You can switch now."];
        }
        if (($r['status'] ?? 0) === 0) {
            return [false, "Could not connect to $host (" . ($r['error'] ?: 'no answer') . '). Check that the subdomain exists in your hosting panel, its DNS points to this server and it has an SSL certificate.'];
        }
        return [false, "$host answered (HTTP {$r['status']}) but it is not this website. Point the subdomain to this site's folder, or upload the bridge kit into the subdomain's folder."];
    }

    /** Answer a probe request (any host). Prints the proof and exits, or 404s. */
    public static function answerProbe(string $token): void
    {
        Options::lazy('admin_probe');
        $p = Options::get('admin_probe');
        if (!is_array($p) || ($p['expires'] ?? 0) < time() || !hash_equals((string)($p['token'] ?? ''), $token)) {
            return;
        }
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo hash_hmac('sha256', $token, self::secret());
        exit;
    }

    /** One-time link that signs the current user in on another host (cookies never cross domains). */
    public static function handoffUrl(string $host, int $userId, string $then = ''): string
    {
        $token = bin2hex(random_bytes(24));
        Options::set('admin_handoff', ['hash' => hash('sha256', $token), 'user' => $userId, 'expires' => time() + 120, 'then' => $then], false);
        $scheme = parse_url(site_url(), PHP_URL_SCHEME) ?: 'https';
        $port = parse_url(site_url(), PHP_URL_PORT);
        return $scheme . '://' . $host . ($port && str_ends_with($host, 'localhost') ? ':' . $port : '') . rtrim(base_path(), '/') . '/' . admin_slug() . '/handoff/' . $token;
    }

    /** Redeem a handoff token: returns [user row, path to open] or null. Single use, 2 minutes. */
    public static function redeemHandoff(string $token): ?array
    {
        Options::lazy('admin_handoff');
        $h = Options::get('admin_handoff');
        Options::set('admin_handoff', null, false);
        if (!is_array($h) || ($h['expires'] ?? 0) < time() || !hash_equals((string)($h['hash'] ?? ''), hash('sha256', $token))) {
            return null;
        }
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => (int)$h['user']]);
        return $user ? [$user, (string)($h['then'] ?? '')] : null;
    }

    /**
     * Files for hosts that give a subdomain its own folder: an index.php that runs this installation and serves its
     * public files, plus an .htaccess. Returns [filename => contents].
     */
    public static function bridgeFiles(string $host): array
    {
        $root = var_export(SLATE_ROOT, true);
        $hostExport = var_export($host, true);
        $index = <<<'PHP'
<?php
// Slate dashboard bridge for __HOST__. Runs the Slate installation in __ROOT__
// on this (sub)domain. Generated by Slate; safe to download again any time.
declare(strict_types=1);
$host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
if ($host !== __HOSTX__) {
    http_response_code(404);
    exit;
}
define('SLATE_BRIDGE', true);
chdir(__ROOTX__);
require __ROOTX__ . '/index.php';

PHP;
        $index = strtr($index, ['__HOSTX__' => $hostExport, '__ROOTX__' => $root, '__HOST__' => $host, '__ROOT__' => SLATE_ROOT]);
        $ht = "# Slate dashboard bridge: send every request to index.php (it serves the site's files itself).\n"
            . "Options -Indexes\nDirectoryIndex index.php\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
            . "    RewriteCond %{REQUEST_URI} !^/index\\.php$\n    RewriteRule ^ index.php [L]\n</IfModule>\n";
        return ['index.php' => $index, '.htaccess' => $ht];
    }

    /**
     * Requests arriving through the bridge: serve public static files (dashboard assets, theme and module assets,
     * uploads) that the web server cannot reach from the subdomain's own folder.
     */
    public static function serveBridgeStatic(string $path): void
    {
        if (!defined('SLATE_BRIDGE') || !preg_match('#^/(admin/assets|themes/[\w-]+/assets|modules/[\w-]+/assets|content/uploads)/[\w./@-]+$#', $path)
            || str_contains($path, '..')) {
            return;
        }
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'json' => 'application/json', 'map' => 'application/json',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'pdf' => 'application/pdf'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $file = SLATE_ROOT . $path;
        if (!isset($types[$ext]) || !is_file($file)) {
            return;
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? 31536000 : 3600));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function secret(): string
    {
        return hash('sha256', 'admin-probe|' . (defined('SLATE_CONFIG') ? (string)(SLATE_CONFIG['secret'] ?? SLATE_CONFIG['key'] ?? '') : '') . '|' . SLATE_ROOT);
    }
}
