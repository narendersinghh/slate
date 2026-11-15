<?php
declare(strict_types=1);

use Slate\Auth;
use Slate\Csrf;
use Slate\DB;
use Slate\Options;

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Path prefix when Slate is installed in a sub-directory, e.g. "/cms". */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $configured = defined('SLATE_CONFIG') ? (string)(SLATE_CONFIG['url'] ?? '') : '';
        if ($configured !== '') {
            $base = rtrim((string)parse_url($configured, PHP_URL_PATH), '/');
        } else {
            $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $base = str_ends_with($script, '.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
        }
    }
    return $base;
}

function site_url(string $path = ''): string
{
    $configured = defined('SLATE_CONFIG') ? rtrim((string)(SLATE_CONFIG['url'] ?? ''), '/') : '';
    if ($configured === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $configured = (is_https() ? 'https://' : 'http://') . $host . base_path();
    }
    return $configured . '/' . ltrim($path, '/');
}

/** Root-relative URL (no scheme/host) for links inside the site. */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function admin_slug(): string
{
    $slug = DB::connected() ? (string)Options::get('security_admin_slug', 'admin') : 'admin';
    return preg_match('/^[a-z0-9-]{3,40}$/', $slug) ? $slug : 'admin';
}

function admin_url(string $path = ''): string
{
    return url(admin_slug() . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

function asset_url(string $path): string
{
    $file = SLATE_ROOT . '/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : SLATE_VERSION;
    return url($path) . '?v=' . $v;
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function back(): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? admin_url();
    // Only allow redirects to our own host.
    $u = parse_url($ref);
    $host = isset($u['host']) ? strtolower($u['host'] . (isset($u['port']) ? ':' . $u['port'] : '')) : '';
    if ($host !== '' && $host !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) {
        $ref = admin_url();
    }
    redirect($ref);
}

function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read JSON request body once. */
function json_input(): array
{
    static $data = null;
    if ($data === null) {
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
    }
    return $data;
}

function input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? json_input()[$key] ?? $default;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function option(string $key, mixed $default = null): mixed
{
    return Options::get($key, $default);
}

function slugify(string $text, string $sep = '-'): string
{
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: $text;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', $sep, $text);
    return trim((string)$text, $sep) ?: 'item';
}

function csrf_field(): string
{
    return Csrf::field();
}

function local_date(?string $utc, ?string $format = null): string
{
    if (!$utc) {
        return '';
    }
    try {
        $tz = new DateTimeZone((string)option('timezone', 'UTC'));
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone($tz);
    return $d->format($format ?? (string)option('date_format', 'M j, Y'));
}

function time_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - strtotime($utc . ' UTC');
    return match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => floor($diff / 60) . 'm ago',
        $diff < 86400 => floor($diff / 3600) . 'h ago',
        $diff < 604800 => floor($diff / 86400) . 'd ago',
        default => local_date($utc),
    };
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function str_limit(string $text, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
}

function log_activity(string $action, string $object = ''): void
{
    try {
        DB::insert('activity_log', [
            'user_id' => Auth::id() ?: null,
            'action' => $action,
            'object' => mb_substr($object, 0, 191),
            'ip' => Auth::ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable) {
        // Never break a request because logging failed.
    }
}

/** Render an admin view inside the admin layout. */
function admin_view(string $view, array $vars = [], bool $layout = true): void
{
    extract($vars, EXTR_SKIP);
    $__view = SLATE_ADMIN . '/views/' . $view . '.php';
    if (!$layout) {
        require $__view;
        return;
    }
    ob_start();
    require $__view;
    $__content = ob_get_clean();
    require SLATE_ADMIN . '/views/layout.php';
}

/** Inline SVG icon from the admin icon set. */
function icon(string $name, int $size = 18): string
{
    return \Slate\Icons::svg($name, $size);
}

/** Slate wordmark (logo mark + serif name). */
function icon_logo(int $size = 32, string $variant = 'dark'): string
{
    return \Slate\Icons::wordmark($size, $variant);
}

/**
 * Typographic quotes and dashes in text (outside tags, scripts, code), like WordPress's wptexturize():
 * "x" → “x”, it's → it’s, ' - ' → ' – ', '--' → '–', '---' → '—', '...' → '…'.
 */
/** Language code for <html lang>: the site language, adjustable by modules ("html_lang" filter). */
function html_lang(?string $base = null): string
{
    return (string)\Slate\Hooks::filter('html_lang', $base !== null && $base !== '' ? $base : (string)option('language', 'en'));
}

/** Text direction for <html dir>: "ltr" unless a module says otherwise ("html_dir" filter). */
function html_dir(): string
{
    return \Slate\Hooks::filter('html_dir', 'ltr') === 'rtl' ? 'rtl' : 'ltr';
}

/** lang="…" (and dir="rtl" when needed) for the <html> tag. */
function html_attrs(?string $base = null): string
{
    return 'lang="' . e(html_lang($base)) . '"' . (html_dir() === 'rtl' ? ' dir="rtl"' : '');
}

function texturize(string $html): string
{
    if ($html === '' || !preg_match('/[\'"\-.]|&#0?39;|&quot;/', $html)) {
        return $html;
    }
    $parts = preg_split('/(<!--.*?-->|<(?:script|style|pre|code|kbd|textarea)\b.*?<\/(?:script|style|pre|code|kbd|textarea)>|<[^>]*>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $prevEnd = ' ';
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) {
            $out .= $p;
            continue;
        }
        if ($p === '') {
            continue;
        }
        $t = html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(['---', ' -- ', '--', '...'], ['—', ' — ', '–', '…'], $t);
        $t = preg_replace('/(?<=\s)-(?=\s)/u', '–', $t);
        $t = preg_replace("/'(?=\d\d(?!\d))/u", '’', $t);                    // '90, '90s
        $t = preg_replace("/(?<=[\p{L}\p{N}])'(?=[\p{L}\p{N}])/u", '’', $t); // it's, don't
        $t = ($prevEnd . $t);
        $t = preg_replace('/(?<=[\s(\[{"\x{201C}\-—–])\'/u', '‘', $t);       // opening single
        $t = preg_replace('/(?<=[\s(\[{\x{2018}\-—–])"/u', '“', $t);          // opening double
        $t = str_replace(["'", '"'], ['’', '”'], $t);                          // the rest close
        $t = mb_substr($t, mb_strlen($prevEnd));
        $prevEnd = mb_substr($t, -1) ?: ' ';
        $out .= htmlspecialchars($t, ENT_NOQUOTES, 'UTF-8', false);
    }
    return $out;
}
