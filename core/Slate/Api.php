<?php
declare(strict_types=1);

namespace Slate;

/**
 * REST API (JSON) at /api/v1, for headless front-ends, apps and integrations. Off until enabled under
 * Settings → Integrations → REST API. Reads of published, public content can be anonymous; everything else needs an
 * API key (Profile → API keys) sent as "Authorization: Bearer <key>" and acts with that user's permissions.
 *
 *   GET    /api/v1                              site info, content types, taxonomies
 *   GET    /api/v1/content/{type}               ?page ?per_page ?search ?orderby ?order ?{taxonomy}=slug ?status (key) ?render=1
 *   GET    /api/v1/content/{type}/{id|slug}
 *   POST   /api/v1/content/{type}               create   (write key)
 *   PATCH  /api/v1/content/{type}/{id}          update   (write key; PUT works too)
 *   DELETE /api/v1/content/{type}/{id}          move to trash (?force=1 deletes permanently)
 *   GET    /api/v1/terms/{taxonomy}             GET /api/v1/menus/{location}   GET /api/v1/media/{id}   GET /api/v1/me
 *
 * Modules add endpoints with the "api_routes" action (Router, paths relative to /api/v1) and shape output with the
 * "api_post" filter.
 */
final class Api
{
    private static ?array $key = null;

    public static function enabled(): bool
    {
        return (bool)Options::get('api_enabled', false);
    }

    public static function handle(string $path): void
    {
        header_remove('X-Powered-By');
        header('X-Robots-Tag: noindex');
        self::cors();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (!self::enabled()) {
            self::error(404, 'not_found', 'The API is not enabled on this site.');
        }
        self::authenticate();
        if (!self::$key && !Options::get('api_public_read', true)) {
            self::error(401, 'unauthorized', 'An API key is required.');
        }
        $r = new Router();
        $r->get('/', [self::class, 'index']);
        $r->get('/me', [self::class, 'me']);
        $r->get('/content/{type}', [self::class, 'listContent']);
        $r->get('/content/{type}/{ref}', [self::class, 'getContent']);
        $r->post('/content/{type}', [self::class, 'createContent']);
        $r->add(['PATCH', 'PUT', 'POST'], '/content/{type}/{id:\d+}', [self::class, 'updateContent']);
        $r->add(['DELETE'], '/content/{type}/{id:\d+}', [self::class, 'deleteContent']);
        $r->get('/terms/{taxonomy}', [self::class, 'listTerms']);
        $r->get('/menus/{location}', [self::class, 'menu']);
        $r->get('/media/{id:\d+}', [self::class, 'media']);
        Hooks::action('api_routes', $r);
        try {
            if (!$r->dispatch($method === 'HEAD' ? 'GET' : $method, $path === '' ? '/' : $path)) {
                self::error(404, 'not_found', 'No such endpoint.');
            }
        } catch (\Throwable $e) {
            error_log('Slate API: ' . $e->getMessage());
            self::error(500, 'server_error', SLATE_DEBUG ? $e->getMessage() : 'Something went wrong.');
        }
    }

    // ------------------------------------------------------------------ auth & keys

    private static function authenticate(): void
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($token === '') {
            return;
        }
        if (Auth::isLockedOut()) {
            self::error(429, 'locked_out', 'Too many failed attempts. Try again later.');
        }
        $key = self::findKey($token);
        if (!$key) {
            DB::insert('login_attempts', ['ip' => Auth::ip(), 'username' => 'api-key', 'attempted_at' => now()]);
            self::error(401, 'invalid_key', 'Invalid API key.');
        }
        $user = DB::row('SELECT * FROM {{users}} WHERE id = :id', ['id' => $key['user_id']]);
        if (!$user || $user['status'] !== 'active') {
            self::error(401, 'invalid_key', 'The user of this key is not active.');
        }
        Auth::actAs($user);
        self::$key = $key;
        if (!$key['last_used'] || strtotime($key['last_used'] . ' UTC') < time() - 300) {
            DB::update('api_keys', ['last_used' => now()], ['id' => $key['id']]);
        }
    }

    private static function findKey(string $token): ?array
    {
        if (!preg_match('/^slt_([a-f0-9]{10})_[A-Za-z0-9]{40}$/', $token, $m)) {
            return null;
        }
        $key = DB::row('SELECT * FROM {{api_keys}} WHERE prefix = :p', ['p' => $m[1]]);
        return $key && hash_equals((string)$key['token_hash'], hash('sha256', $token)) ? $key : null;
    }

    /** Create a key for a user. Returns the full token, shown once, only its hash is stored. */
    public static function createKey(int $userId, string $name, string $scope): string
    {
        $prefix = bin2hex(random_bytes(5));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $secret = '';
        for ($i = 0; $i < 40; $i++) {
            $secret .= $alphabet[random_int(0, 61)];
        }
        $token = "slt_{$prefix}_{$secret}";
        DB::insert('api_keys', ['user_id' => $userId, 'name' => mb_substr(trim($name) ?: 'API key', 0, 100), 'prefix' => $prefix,
            'token_hash' => hash('sha256', $token), 'scope' => $scope === 'write' ? 'write' : 'read', 'created_at' => now()]);
        log_activity('api_key_created', $name);
        return $token;
    }

    public static function keys(int $userId): array
    {
        return DB::all('SELECT id, name, prefix, scope, last_used, created_at FROM {{api_keys}} WHERE user_id = :u ORDER BY id DESC', ['u' => $userId]);
    }

    public static function revokeKey(int $id, int $userId): void
    {
        DB::query('DELETE FROM {{api_keys}} WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
        log_activity('api_key_revoked', (string)$id);
    }

    private static function requireWrite(string $cap): void
    {
        if (!self::$key) {
            self::error(401, 'unauthorized', 'An API key is required.');
        }
        if (self::$key['scope'] !== 'write') {
            self::error(403, 'read_only_key', 'This API key can only read.');
        }
        if (!Auth::can($cap)) {
            self::error(403, 'forbidden', 'The user of this key is not allowed to do that.');
        }
    }

    // ------------------------------------------------------------------ endpoints

    public static function index(): void
    {
        $types = [];
        foreach (self::types() as $slug => $t) {
            $types[] = ['slug' => $slug, 'name' => $t['plural'] ?? $slug, 'singular' => $t['singular'] ?? $slug,
                'taxonomies' => array_keys(ContentModel::taxonomiesFor($slug)), 'href' => site_url("api/v1/content/$slug")];
        }
        $taxes = [];
        foreach (ContentModel::taxonomies() as $slug => $t) {
            if (($t['public'] ?? true) !== false) {
                $taxes[] = ['slug' => $slug, 'name' => $t['plural'] ?? $slug, 'href' => site_url("api/v1/terms/$slug")];
            }
        }
        self::json(['name' => Options::get('site_title'), 'description' => Options::get('tagline'), 'url' => site_url(),
            'language' => Options::get('language', 'en'), 'timezone' => Options::get('timezone', 'UTC'), 'api' => 'v1',
            'types' => $types, 'taxonomies' => $taxes, 'authenticated' => (bool)self::$key]);
    }

    public static function me(): void
    {
        if (!self::$key) {
            self::error(401, 'unauthorized', 'An API key is required.');
        }
        $u = Auth::user();
        self::json(['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['display_name'], 'role' => $u['role'],
            'key' => ['name' => self::$key['name'], 'scope' => self::$key['scope']]]);
    }

    public static function listContent(string $type): void
    {
        $def = self::type($type);
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $args = ['type' => $type, 'limit' => $perPage, 'page' => $page, 'status' => 'publish', 'with_terms' => true,
            'orderby' => (string)($_GET['orderby'] ?? 'published_at'), 'order' => strtolower((string)($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc'];
        if (($s = trim((string)($_GET['search'] ?? ''))) !== '') {
            $args['search'] = mb_substr($s, 0, 100);
        }
        if (isset($_GET['status']) && self::$key && Auth::can('edit_posts')) {
            $st = (string)$_GET['status'];
            $args['status'] = $st === 'any' ? 'any' : (isset(Posts::STATUSES[$st]) ? $st : 'publish');
            if (!Auth::can('edit_others_posts')) {
                $args['author'] = Auth::id();
            }
        }
        foreach (ContentModel::taxonomiesFor($type) as $tax => $t) {
            if (isset($_GET[$tax]) && is_string($_GET[$tax])) {
                $term = ctype_digit($_GET[$tax]) ? Terms::find((int)$_GET[$tax]) : Terms::findBySlug($tax, $_GET[$tax]);
                $args['term'] = $term ? (int)$term['id'] : -1;
                $args['taxonomy'] = $tax;
            }
        }
        $res = Posts::query($args);
        $items = array_map(static fn($p) => self::post($p, false), $res['items']);
        header('X-Total-Count: ' . (int)$res['total']);
        self::json(['items' => $items, 'total' => (int)$res['total'], 'page' => (int)$res['page'], 'pages' => (int)$res['pages'], 'per_page' => $perPage]);
    }

    public static function getContent(string $type, string $ref): void
    {
        self::type($type);
        $post = ctype_digit($ref) ? Posts::find((int)$ref) : Posts::findBySlug($type, $ref, false);
        if (!$post || $post['type'] !== $type || !self::canRead($post)) {
            self::error(404, 'not_found', 'Not found.');
        }
        self::json(self::post($post, true));
    }

    public static function createContent(string $type): void
    {
        self::type($type);
        self::requireWrite('edit_posts');
        $in = self::input();
        $data = self::writable($type, $in, null);
        $data['type'] = $type;
        if (empty($data['title']) && empty($data['content'])) {
            self::error(422, 'invalid', 'Send at least a title or content.');
        }
        $id = Posts::save($data);
        log_activity('content_created', "$type:" . ($data['title'] ?? $id) . ' (API)');
        http_response_code(201);
        self::json(self::post(Posts::find($id), true));
    }

    public static function updateContent(string $type, string $id): void
    {
        self::type($type);
        self::requireWrite('edit_posts');
        $post = Posts::find((int)$id);
        if (!$post || $post['type'] !== $type || $post['status'] === 'trash' && !isset(self::input()['status'])) {
            self::error(404, 'not_found', 'Not found.');
        }
        if ((int)$post['author_id'] !== Auth::id() && !Auth::can('edit_others_posts')) {
            self::error(403, 'forbidden', 'The user of this key cannot edit this item.');
        }
        $data = self::writable($type, self::input(), $post);
        Revisions::snapshot($post, $data);
        Posts::save($data, (int)$id);
        log_activity('content_updated', "$type:{$post['title']} (API)");
        self::json(self::post(Posts::find((int)$id), true));
    }

    public static function deleteContent(string $type, string $id): void
    {
        self::type($type);
        self::requireWrite('delete_posts');
        $post = Posts::find((int)$id);
        if (!$post || $post['type'] !== $type) {
            self::error(404, 'not_found', 'Not found.');
        }
        if ((int)$post['author_id'] !== Auth::id() && !Auth::can('delete_others_posts')) {
            self::error(403, 'forbidden', 'The user of this key cannot delete this item.');
        }
        $force = !empty($_GET['force']);
        Posts::delete((int)$id, $force);
        log_activity($force ? 'content_deleted' : 'content_trashed', "$type:{$post['title']} (API)");
        self::json(['ok' => true, 'id' => (int)$id, 'deleted' => $force, 'status' => $force ? null : 'trash']);
    }

    public static function listTerms(string $taxonomy): void
    {
        $t = ContentModel::taxonomy($taxonomy);
        if (!$t || ($t['public'] ?? true) === false) {
            self::error(404, 'not_found', 'Unknown taxonomy.');
        }
        $items = array_map(static fn($term) => ['id' => (int)$term['id'], 'name' => $term['name'], 'slug' => $term['slug'],
            'description' => (string)($term['description'] ?? ''), 'parent' => $term['parent_id'] ? (int)$term['parent_id'] : null,
            'count' => (int)$term['count'], 'url' => Terms::link($term, true), 'fields' => $term['fields'] ?? []], Terms::all($taxonomy));
        self::json(['items' => array_values($items), 'total' => count($items)]);
    }

    public static function menu(string $location): void
    {
        $menu = Menus::byLocation($location);
        if (!$menu) {
            self::error(404, 'not_found', 'No menu in that location.');
        }
        self::json(['location' => $location, 'name' => $menu['name'] ?? $location, 'items' => Menus::resolve($menu['items'] ?? [])]);
    }

    public static function media(string $id): void
    {
        $m = Media::find((int)$id);
        if (!$m) {
            self::error(404, 'not_found', 'Not found.');
        }
        self::json(self::mediaJson($m));
    }

    // ------------------------------------------------------------------ helpers

    /** Public content types exposed by the API (filter "api_types"). */
    private static function types(): array
    {
        $types = array_filter(ContentModel::types(), static fn($t) => ($t['public'] ?? true) !== false);
        return (array)Hooks::filter('api_types', $types);
    }

    private static function type(string $type): array
    {
        $t = self::types()[$type] ?? null;
        if (!$t) {
            self::error(404, 'not_found', 'Unknown content type.');
        }
        return $t;
    }

    private static function canRead(array $post): bool
    {
        if ($post['status'] === 'publish') {
            return true;
        }
        if (!self::$key || !Auth::can('edit_posts')) {
            return false;
        }
        return (int)$post['author_id'] === Auth::id() || Auth::can('edit_others_posts');
    }

    private static function post(array $p, bool $full): array
    {
        $terms = [];
        $byTax = $p['terms'] ?? Terms::forPosts([(int)$p['id']])[(int)$p['id']] ?? [];
        foreach ($byTax as $tax => $list) {
            foreach ((array)$list as $t) {
                $terms[$tax][] = ['id' => (int)$t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'url' => Terms::link($t, true)];
            }
        }
        $author = $p['author_id'] ? DB::row('SELECT id, display_name FROM {{users}} WHERE id = :id', ['id' => $p['author_id']]) : null;
        $out = [
            'id' => (int)$p['id'], 'type' => $p['type'], 'slug' => $p['slug'], 'status' => $p['status'], 'title' => $p['title'],
            'url' => Posts::permalink($p, true), 'excerpt' => (string)$p['excerpt'],
            'published_at' => self::iso($p['published_at']), 'updated_at' => self::iso($p['updated_at']),
            'author' => $author ? ['id' => (int)$author['id'], 'name' => $author['display_name']] : null,
            'parent' => $p['parent_id'] ? (int)$p['parent_id'] : null, 'menu_order' => (int)$p['menu_order'],
            'featured_media' => $p['featured_media_id'] && ($m = Media::find((int)$p['featured_media_id'])) ? self::mediaJson($m) : null,
            'terms' => $terms, 'fields' => $p['fields'],
            'seo' => ['title' => Seo::postTitle($p), 'description' => (string)($p['seo']['description'] ?? '') ?: str_limit(strip_tags((string)$p['excerpt'] ?: (string)$p['content']), 160)],
        ];
        if ($full) {
            $out['content'] = (string)$p['content'];
            if (!empty($_GET['render'])) {
                try {
                    $out['content_rendered'] = (string)Hooks::filter('the_content', (string)$p['content'], $p);
                } catch (\Throwable) {
                    $out['content_rendered'] = (string)$p['content'];
                }
            }
            $out['template'] = $p['template'];
        }
        return (array)Hooks::filter('api_post', $out, $p, $full);
    }

    private static function mediaJson(array $m): array
    {
        return ['id' => (int)$m['id'], 'url' => Media::url($m), 'thumb' => Media::url($m, 'thumb'), 'mime' => $m['mime'],
            'width' => $m['width'] ? (int)$m['width'] : null, 'height' => $m['height'] ? (int)$m['height'] : null,
            'alt' => (string)$m['alt'], 'title' => (string)$m['title'], 'caption' => (string)($m['caption'] ?? '')];
    }

    /** Turn request JSON into Posts::save() data, with the same checks as the editor. */
    private static function writable(string $type, array $in, ?array $existing): array
    {
        $unfiltered = Auth::can('unfiltered_html');
        $data = [];
        foreach (['title', 'slug'] as $k) {
            if (isset($in[$k]) && is_scalar($in[$k])) {
                $data[$k] = trim((string)$in[$k]);
            }
        }
        if (isset($in['content']) && is_string($in['content'])) {
            $data['content'] = $unfiltered ? $in['content'] : Sanitizer::html($in['content']);
        }
        if (isset($in['excerpt']) && is_string($in['excerpt'])) {
            $data['excerpt'] = trim(strip_tags($in['excerpt']));
        }
        if (isset($in['status']) && is_string($in['status'])) {
            $st = isset(Posts::STATUSES[$in['status']]) ? $in['status'] : 'draft';
            if (in_array($st, ['publish', 'scheduled', 'private'], true) && !Auth::can('publish_posts')) {
                $st = 'pending';
            }
            $data['status'] = $st;
        } elseif (!$existing) {
            $data['status'] = 'draft';
        }
        foreach (['featured_media_id', 'parent_id', 'menu_order'] as $k) {
            if (array_key_exists($k, $in)) {
                $data[$k] = $in[$k] === null ? null : (int)$in[$k];
            }
        }
        if (!empty($in['published_at']) && is_string($in['published_at']) && ($ts = strtotime($in['published_at'])) !== false) {
            $data['published_at'] = gmdate('Y-m-d H:i:s', $ts);
        }
        if (isset($in['fields']) && is_array($in['fields'])) {
            $defs = ContentModel::fieldsFor('post', $type);
            $data['fields'] = [];
            foreach ($in['fields'] as $name => $value) {
                if (isset($defs[$name])) {
                    $data['fields'][$name] = ContentModel::sanitizeValue($defs[$name], $value, $unfiltered);
                }
            }
        }
        if (isset($in['seo']) && is_array($in['seo'])) {
            $data['seo'] = array_map(static fn($v) => is_scalar($v) ? trim(strip_tags((string)$v)) : null,
                array_intersect_key($in['seo'], array_flip(['title', 'description', 'canonical', 'robots', 'og_title', 'og_description'])));
        }
        if (isset($in['terms']) && is_array($in['terms'])) {
            $data['terms'] = [];
            foreach (ContentModel::taxonomiesFor($type) as $tax => $t) {
                $current = [];
                if ($existing) {
                    foreach (Terms::forPosts([(int)$existing['id']])[(int)$existing['id']][$tax] ?? [] as $term) {
                        $current[] = (int)$term['id'];
                    }
                }
                $data['terms'][$tax] = array_key_exists($tax, $in['terms']) ? array_values(array_filter((array)$in['terms'][$tax], 'is_scalar')) : $current;
            }
        }
        return $data;
    }

    private static function input(): array
    {
        static $in = null;
        if ($in === null) {
            $raw = (string)file_get_contents('php://input');
            $in = $raw !== '' ? json_decode($raw, true) : $_POST;
            if (!is_array($in)) {
                self::error(400, 'invalid_json', 'Send a JSON object.');
            }
        }
        return $in;
    }

    private static function iso(?string $utc): ?string
    {
        return $utc ? gmdate('c', (int)strtotime($utc . ' UTC')) : null;
    }

    private static function cors(): void
    {
        $allowed = trim((string)Options::get('api_cors', ''));
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($allowed === '' || $origin === '') {
            return;
        }
        $list = preg_split('/[\s,]+/', $allowed) ?: [];
        if (in_array('*', $list, true) || in_array(rtrim($origin, '/'), array_map(static fn($o) => rtrim($o, '/'), $list), true)) {
            header('Access-Control-Allow-Origin: ' . (in_array('*', $list, true) ? '*' : $origin));
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
            header('Access-Control-Max-Age: 600');
        }
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . (self::$key || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' ? 'no-store, private' : 'public, max-age=60'));
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (isset($_GET['pretty']) ? JSON_PRETTY_PRINT : 0));
        exit;
    }

    public static function error(int $status, string $code, string $message): never
    {
        self::json(['error' => ['code' => $code, 'message' => $message, 'status' => $status]], $status);
    }
}
