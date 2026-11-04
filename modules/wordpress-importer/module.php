<?php
/**
 * WordPress Importer — migrate a WordPress / Elementor site to Slate, keep its design and URLs.
 */
declare(strict_types=1);

use Slate\App;
use Slate\Auth;
use Slate\Crypto;
use Slate\DB;
use Slate\Hooks;
use Slate\Media;
use Slate\Modules;
use Slate\Options;
use Slate\Posts;
use Slate\Router;
use SlateModules\WpImport\Importer;

require_once SLATE_MODULES . '/auto-cpt-generator/src/WpClient.php';
require_once SLATE_MODULES . '/auto-cpt-generator/src/Generator.php';
require_once SLATE_MODULES . '/auto-cpt-generator/src/Wxr.php';
require_once __DIR__ . '/src/Importer.php';
require_once __DIR__ . '/src/Mirror.php';

Options::lazy('wpi_job', 'wpi_conn', 'wpi_scan');
Hooks::add('module_activated_wordpress-importer', [Importer::class, 'install']);

Modules::adminPage('WordPress import', 'wp-import', static function (): void {
    Importer::install();
    require __DIR__ . '/view.php';
}, 'manage_settings', 'download');

Modules::adminPage('Form submissions', 'forms', static function (): void {
    Importer::install();
    require __DIR__ . '/forms.php';
}, 'edit_others_posts', 'message');

// ---------------------------------------------------------------- admin endpoints
Hooks::add('admin_routes', static function (Router $r): void {
    $respond = static function (callable $fn): void {
        Auth::require('manage_settings');
        @set_time_limit(300);
        try {
            json_response(['ok' => true] + $fn());
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    };
    $r->post('/m/wp-import/connect', static fn() => $respond(static function (): array {
        $in = json_input();
        $conn = ['source' => 'rest', 'url' => trim((string)($in['url'] ?? '')), 'user' => trim((string)($in['user'] ?? '')),
            'pass' => Crypto::encrypt(preg_replace('/\s+/', '', (string)($in['password'] ?? '')))];
        if (!filter_var($conn['url'], FILTER_VALIDATE_URL) && !filter_var('https://' . $conn['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter the WordPress site address, e.g. https://example.com');
        }
        $scan = Importer::scan($conn);
        $conn['url'] = $scan['summary']['url'];
        Options::set('wpi_conn', $conn, false);
        Options::set('wpi_scan', $scan, false);
        return ['scan' => $scan];
    }));
    $r->post('/m/wp-import/upload', static fn() => $respond(static function (): array {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Upload the WordPress export (.xml) file. If it is large, raise upload_max_filesize in PHP settings.');
        }
        Importer::install();
        move_uploaded_file($f['tmp_name'], Importer::WORK . '/export.xml');
        @unlink(Importer::WORK . '/wxr.json');
        $conn = ['source' => 'wxr', 'url' => ''];
        $scan = Importer::scan($conn);
        $conn['url'] = $scan['summary']['url'];
        Options::set('wpi_conn', $conn, false);
        Options::set('wpi_scan', $scan, false);
        return ['scan' => $scan];
    }));
    $r->post('/m/wp-import/start', static fn() => $respond(static function (): array {
        $conn = Options::get('wpi_conn');
        $scan = Options::get('wpi_scan');
        if (!$conn || !$scan) {
            throw new RuntimeException('Connect to a site first.');
        }
        $o = json_input()['options'] ?? [];
        $opts = ['types' => array_values(array_map('strval', (array)($o['types'] ?? [])))];
        foreach (['model', 'media', 'seo', 'menus', 'settings', 'redirects', 'design', 'activate_theme', 'drafts'] as $k) {
            $opts[$k] = !empty($o[$k]);
        }
        if (!empty($o['only'])) {
            $opts['only'] = array_values(array_map('strval', (array)$o['only']));
        }
        $job = Importer::start($conn, $scan, $opts);
        log_activity('wp_import_started', $job['origin']);
        return ['steps' => count($job['steps'])];
    }));
    $r->post('/m/wp-import/step', static fn() => $respond(static fn(): array => Importer::step()));
    $r->post('/m/wp-import/reset', static fn() => $respond(static function (): array {
        Importer::reset(!empty(json_input()['delete']));
        return [];
    }));
    $r->post('/m/forms/{id:\d+}/delete', static function (string $id): void {
        Auth::require('edit_others_posts');
        DB::delete('wpi_forms', ['id' => (int)$id]);
        back();
    });
});

// ---------------------------------------------------------------- frontend compatibility layer
Hooks::add('init', static function (): void {
    $path = App::path();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Elementor (Pro) form submissions → stored in Slate + emailed to the admin.
    if ($path === '/wp-admin/admin-ajax.php') {
        wpi_handle_ajax();
    }

    // Old WordPress media & asset URLs.
    if (preg_match('#^/(wp-content|wp-includes)/#', $path)) {
        wpi_serve_asset($path);
    }

    // ?p=123 / ?page_id=123 links from the old site.
    if ($method === 'GET' && $path === '/' && (isset($_GET['p']) || isset($_GET['page_id']))) {
        $id = Importer::map('post_id', (string)(int)($_GET['p'] ?? $_GET['page_id']));
        if ($id && ($post = Posts::find($id))) {
            redirect(Posts::permalink($post), 301);
        }
    }
}, 1);

function wpi_handle_ajax(): never
{
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($action !== 'elementor_pro_forms_send_form') {
        echo json_encode(['success' => false, 'data' => ['message' => 'Unsupported action.']]);
        exit;
    }
    Importer::install();
    $ip = Auth::ip();
    $recent = (int)DB::val('SELECT COUNT(*) FROM {{wpi_forms}} WHERE ip = :ip AND created_at > :t', ['ip' => $ip, 't' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    $fields = (array)($_POST['form_fields'] ?? []);
    // Honeypot fields (Elementor "honeypot" field or a generic one) must stay empty.
    $spam = !empty($_POST['form_fields']['honeypot']) || !empty($_POST['website_url_hp']);
    if ($recent >= 20 || $spam) {
        echo json_encode(['success' => true, 'data' => ['message' => 'Thank you!', 'data' => []]]);
        exit;
    }
    $clean = [];
    foreach ($fields as $k => $v) {
        $clean[mb_substr(preg_replace('/[^\w-]/', '', (string)$k), 0, 60)] = is_array($v) ? array_map(static fn($x) => mb_substr(strip_tags((string)$x), 0, 5000), $v) : mb_substr(strip_tags((string)$v), 0, 5000);
    }
    $form = mb_substr(strip_tags((string)($_POST['form_name'] ?? $_POST['form_id'] ?? 'Form')), 0, 191);
    $page = mb_substr(strip_tags((string)($_POST['referer_title'] ?? $_SERVER['HTTP_REFERER'] ?? '')), 0, 255);
    DB::insert('wpi_forms', ['form' => $form, 'page' => $page, 'data' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'ip' => $ip, 'created_at' => now()]);
    $to = (string)Options::get('admin_email');
    if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $body = "New submission from “{$form}” on " . site_url() . "\n\n";
        foreach ($clean as $k => $v) {
            $body .= ucfirst($k) . ': ' . (is_array($v) ? implode(', ', $v) : $v) . "\n";
        }
        $replyTo = '';
        foreach ($clean as $v) {
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $replyTo = "\r\nReply-To: " . $v;
                break;
            }
        }
        $host = (string)parse_url(site_url(), PHP_URL_HOST);
        @mail($to, '[' . Options::get('site_title') . '] New form submission: ' . $form, $body, 'From: Slate <noreply@' . $host . '>' . $replyTo);
    }
    echo json_encode(['success' => true, 'data' => ['message' => 'Your submission was successful.', 'data' => []]]);
    exit;
}

function wpi_serve_asset(string $path): void
{
    $file = SLATE_ROOT . $path;
    if (str_contains($path, '..') || preg_match('/\.(php\d?|phtml|phar|sh|py|pl|cgi|htaccess)$/i', $path)) {
        http_response_code(404);
        exit;
    }
    // Imported media → its optimized Slate copy (permanent redirect keeps image SEO).
    if (str_starts_with($path, '/wp-content/uploads/') && !str_contains($path, '/uploads/elementor/')) {
        $id = Importer::mediaByUrl($path);
        if ($id && ($m = Media::find($id))) {
            redirect(Media::url($m), 301);
        }
    }
    if (is_file($file)) {
        return; // the web server normally serves it directly
    }
    // Lazy mirror: fetch missing theme/plugin assets from the original site while it is still reachable.
    $origin = (string)Options::get('wpi_origin');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'json', 'map', 'mp4', 'webm'];
    $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin && in_array($ext, $allowed, true) && strcasecmp((string)parse_url($origin, PHP_URL_HOST), $self) !== 0) {
        @mkdir(dirname($file), 0755, true);
        if ((new \SlateModules\AutoCpt\WpClient($origin))->download($origin . $path, $file . '.part')) {
            rename($file . '.part', $file);
            $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'json' => 'application/json'];
            header('Content-Type: ' . ($types[$ext] ?? (mime_content_type($file) ?: 'application/octet-stream')));
            header('Cache-Control: public, max-age=31536000');
            readfile($file);
            exit;
        }
    }
    http_response_code(404);
    exit;
}
