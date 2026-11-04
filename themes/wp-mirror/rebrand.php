<?php
/**
 * Site Mirror: brand-neutral output.
 *
 * The mirrored design originally came from a page builder whose name is baked into class names,
 * script globals, CSS selectors and asset paths. Everything is renamed consistently to "slatekit"
 * and every theme/plugin asset lives under /content/assets/, so markup, CSS and JS keep matching
 * each other while no third-party product names reach the visitor (or the editor's HTML view).
 *
 * The same transform runs (1) once over stored content and asset files (bin/slate mirror:rebrand)
 * and (2) on every rendered page as a safety net, so newly captured markup is covered as well.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Hooks;
use Slate\Options;

const SMX_ASSETS = '/content/assets/';

/** Old asset path prefix → new one (longest first wins in strtr). */
function smx_path_map(): array
{
    return [
        '/wp-content/plugins/elementor/' => SMX_ASSETS . 'slatekit/',
        '/wp-content/plugins/elementor-pro/' => SMX_ASSETS . 'slatekit-pro/',
        '/wp-content/plugins/pro-elements/' => SMX_ASSETS . 'slatekit-pro/',
        '/wp-content/themes/hello-elementor/' => SMX_ASSETS . 'theme/',
        '/wp-content/uploads/elementor/' => SMX_ASSETS . 'generated/',
        '/wp-content/plugins/' => SMX_ASSETS . 'plugins/',
        '/wp-content/themes/' => SMX_ASSETS . 'themes/',
        '/wp-includes/' => SMX_ASSETS . 'core/',
    ];
}

/** strtr() table for asset paths, plain and JSON-escaped, relative or absolute on the old origin. */
function smx_strtr_table(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    $origins = [''];
    $origin = (string)Options::get('wpi_origin', '');
    if ($host = parse_url($origin, PHP_URL_HOST)) {
        $host = preg_replace('/^www\./', '', (string)$host);
        foreach (["https://$host", "https://www.$host", "http://$host", "http://www.$host", "//$host", "//www.$host"] as $o) {
            $origins[] = $o;
        }
    }
    $table = [];
    foreach (smx_path_map() as $old => $new) {
        foreach ($origins as $o) {
            $table[$o . $old] = $new;
            $table[str_replace('/', '\/', $o . $old)] = str_replace('/', '\/', $new);
        }
    }
    return $table;
}

/** Rename the builder's identifiers, keeping the case pattern (elementor → slatekit, Elementor → SlateKit…). */
function smx_rename(string $s): string
{
    if (stripos($s, 'elementor') === false && stripos($s, 'pro-elements') === false) {
        return $s;
    }
    $s = str_ireplace(['pro-elements', 'pro_elements'], ['slatekit-pro', 'slatekit_pro'], $s);
    return (string)preg_replace_callback('/elementor/i', static function (array $m): string {
        $w = $m[0];
        if ($w === 'elementor') {
            return 'slatekit';
        }
        if ($w === 'ELEMENTOR') {
            return 'SLATEKIT';
        }
        if ($w === 'Elementor') {
            return 'SlateKit';
        }
        return ctype_upper($w[0]) ? 'Slatekit' : 'slatekit';
    }, $s);
}

/** Full transform for HTML, CSS, JS and JSON text. */
function smx_rebrand(string $s): string
{
    if ($s === '') {
        return $s;
    }
    $s = strtr($s, smx_strtr_table());
    // Generator tags of the old stack.
    $s = (string)preg_replace('#<meta\s+name=["\']generator["\'][^>]*>\s*#i', '', $s);
    return smx_rename($s);
}

/** Recursively rebrand strings inside arrays (custom fields, options). */
function smx_rebrand_deep(mixed $v): mixed
{
    if (is_string($v)) {
        return smx_rebrand($v);
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[is_string($k) ? smx_rename($k) : $k] = smx_rebrand_deep($x);
        }
        return $out;
    }
    return $v;
}

/** New asset path → original path on the old site (for lazily fetching a file that was never copied). */
function smx_original_path(string $path): ?string
{
    foreach (['slatekit-pro/' => '/wp-content/plugins/pro-elements/', 'slatekit/' => '/wp-content/plugins/elementor/', 'theme/' => '/wp-content/themes/hello-elementor/',
        'generated/' => '/wp-content/uploads/elementor/', 'plugins/' => '/wp-content/plugins/', 'themes/' => '/wp-content/themes/', 'core/' => '/wp-includes/'] as $new => $old) {
        if (str_starts_with($path, SMX_ASSETS . $new)) {
            $rest = substr($path, strlen(SMX_ASSETS . $new));
            return $old . str_replace(['slatekit-pro', 'slatekit'], ['pro-elements', 'elementor'], $rest);
        }
    }
    return null;
}

// Every rendered page.
Hooks::add('html_output', 'smx_rebrand');

// Old asset URLs → new location; missing new assets → fetched once from the original site and rebranded.
Hooks::add('init', static function (): void {
    $path = App::path();
    if (preg_match('#^/(wp-content/(plugins|themes|uploads/elementor)|wp-includes)/#', $path)) {
        $new = strtr($path, smx_path_map());
        if ($new !== $path && is_file(SLATE_ROOT . smx_rename($new))) {
            redirect(url(ltrim(smx_rename($new), '/')), 301);
        }
        return; // let the importer's compatibility layer handle the rest
    }
    if (!str_starts_with($path, SMX_ASSETS) || str_contains($path, '..')) {
        return;
    }
    $file = SLATE_ROOT . $path;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2',
        'woff' => 'font/woff', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'map' => 'application/json', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];
    $origin = rtrim((string)Options::get('wpi_origin', ''), '/');
    $orig = smx_original_path($path);
    $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!is_file($file) && isset($types[$ext]) && $origin && $orig && strcasecmp((string)parse_url($origin, PHP_URL_HOST), $self) !== 0) {
        $ch = curl_init($origin . $orig);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'Slate']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (is_string($body) && $code === 200) {
            if (in_array($ext, ['css', 'js', 'json', 'svg', 'map'], true)) {
                $body = smx_rebrand($body);
            }
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file . '.part', $body);
            rename($file . '.part', $file);
        }
    }
    if (is_file($file) && isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        header('Cache-Control: public, max-age=31536000');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}, 0);
