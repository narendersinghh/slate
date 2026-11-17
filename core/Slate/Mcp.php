<?php
declare(strict_types=1);

namespace Slate;

/**
 * MCP server (Model Context Protocol) at /api/mcp, so AI agents (Claude Desktop, Claude Code, ChatGPT, any MCP client)
 * can build and manage the site. Streamable HTTP transport, stateless: JSON-RPC 2.0 over POST, single messages or
 * batches, answered with application/json. Off until enabled under Settings → Integrations → AI agents (MCP);
 * independent of the REST API switch.
 *
 * Auth: the REST API keys (Profile → API keys) as "Authorization: Bearer slt_…". A key acts as its user: read-only keys
 * can only read, and every tool checks that user's capabilities. Safety model: new content is a draft unless publishing
 * is asked for and allowed, every change keeps a revision first, HTML is sanitised like in the dashboard and never
 * carries scripts, and every write is in the activity log ("mcp_…").
 *
 * Modules add tools with the "mcp_tools" filter: ['name' => ['description', 'inputSchema', 'handler' => callable(array $args): array, 'write' => bool]].
 */
final class Mcp
{
    /** Newest first. The client's version is used when it is listed here, otherwise the newest. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    private const RATE_PER_MINUTE = 120;
    private const MAX_BODY = 12 * 1024 * 1024;
    private const MAX_HTML = 1024 * 1024;

    private static ?array $key = null;

    public static function enabled(): bool
    {
        return (bool)Options::get('mcp_enabled', false);
    }

    public static function endpoint(): string
    {
        return site_url('api/mcp');
    }

    // ------------------------------------------------------------------ transport

    public static function handle(): never
    {
        header_remove('X-Powered-By');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store, private');
        PageCache::skip();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // DNS-rebinding protection: browsers may only call from this site or an allowed CORS origin.
        if (!self::originAllowed()) {
            self::send(403, self::error(null, -32600, 'Origin not allowed.'));
        }
        self::cors();
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        if (!self::enabled()) {
            self::send(404, self::error(null, -32601, 'The MCP server is not enabled on this site (Settings → Integrations → AI agents).'));
        }
        if ($method !== 'POST') {
            header('Allow: POST, OPTIONS');
            self::send(405, self::error(null, -32600, 'Use POST with a JSON-RPC message. This server does not open event streams.'));
        }
        $pv = (string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '');
        if ($pv !== '' && !in_array($pv, self::VERSIONS, true)) {
            self::send(400, self::error(null, -32600, "Unsupported MCP-Protocol-Version $pv. Supported: " . implode(', ', self::VERSIONS) . '.'));
        }
        if (($sid = (string)($_SERVER['HTTP_MCP_SESSION_ID'] ?? '')) !== '' && preg_match('/^[\x21-\x7E]{1,128}$/', $sid)) {
            header('Mcp-Session-Id: ' . $sid);
        }

        self::authenticate();
        self::rateLimit();

        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            self::send(413, self::error(null, -32600, 'Request too large.'));
        }
        $trim = ltrim($raw);
        $data = json_decode($raw, true, 128);
        if ($trim === '' || json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            self::send(400, self::error(null, -32700, 'Parse error: send a JSON-RPC 2.0 message.'));
        }
        if ($trim[0] === '[') {
            if ($data === []) {
                self::send(400, self::error(null, -32600, 'Invalid Request: empty batch.'));
            }
            $out = [];
            foreach (array_slice($data, 0, 50) as $msg) {
                if (($r = self::message($msg)) !== null) {
                    $out[] = $r;
                }
            }
            if (!$out) {
                self::accepted();
            }
            self::send(200, $out);
        }
        if ($trim[0] !== '{') {
            self::send(400, self::error(null, -32600, 'Invalid Request.'));
        }
        $r = self::message($data);
        if ($r === null) {
            self::accepted();
        }
        self::send(200, $r);
    }

    /** One JSON-RPC message → response, or null for notifications and client responses. */
    private static function message(mixed $msg): ?array
    {
        if (!is_array($msg) || ($msg !== [] && array_is_list($msg))) {
            return self::error(null, -32600, 'Invalid Request: expected an object.');
        }
        $hasId = array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return self::error(null, -32600, 'Invalid Request: id must be a string or an integer.');
        }
        if (!isset($msg['method'])) {
            // A response to a server request (we send none) or garbage.
            return array_key_exists('result', $msg) || array_key_exists('error', $msg) ? null : self::error($id, -32600, 'Invalid Request: method missing.');
        }
        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'])) {
            return self::error($id, -32600, 'Invalid Request: jsonrpc must be "2.0" and method a string.');
        }
        $method = $msg['method'];
        if (!$hasId) {
            return null; // notifications/initialized, notifications/cancelled, … need no answer
        }
        $params = $msg['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return self::error($id, -32602, 'Invalid params: expected an object.');
        }
        try {
            $result = match ($method) {
                'initialize' => self::initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => self::toolList()],
                'tools/call' => self::callTool($params),
                'resources/list' => ['resources' => []],
                'resources/templates/list' => ['resourceTemplates' => []],
                'prompts/list' => ['prompts' => []],
                default => throw new McpError(-32601, "Method not found: $method"),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (McpError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Slate MCP: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return self::error($id, -32603, 'Internal error' . (SLATE_DEBUG ? ': ' . $e->getMessage() : '.'));
        }
    }

    private static function initialize(array $params): array
    {
        $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'Slate', 'title' => 'Slate CMS: ' . Options::get('site_title', 'Slate site'), 'version' => SLATE_VERSION],
            'instructions' => self::instructions(),
        ];
    }

    private static function instructions(): string
    {
        $site = (string)Options::get('site_title', 'Slate site');
        $write = (self::$key['scope'] ?? 'read') === 'write' ? 'read & write' : 'read only';
        return "You manage the Slate CMS website “{$site}” (" . site_url() . "), as user “" . (Auth::user()['display_name'] ?? '') . "” with a $write key.\n"
            . "1. Call get_site first: content types with their custom fields and taxonomies, the active theme and the design system (colour, font and spacing CSS variables, utility classes).\n"
            . "2. Content: list_content / get_content / search, then create_content or update_content. New content is a DRAFT unless you pass publish:true (and the user may publish). Ask the user before publishing. Every update keeps a revision (get_revisions, restore_revision).\n"
            . "3. Designed pages: write_page saves a complete HTML document (its own <head> with styles, and <body>) as a page that keeps exactly that design; people then edit texts, images and repeating parts by clicking on the live page. Use the design-system CSS variables from get_site so the page matches the site. Write responsive CSS, real copy, alt text on images.\n"
            . "4. Images: upload_media_from_url, then use the returned url in <img src> with alt text.\n"
            . "Template tags work in designs and templates: {{site.name}}, {{title}}, {{field:name}}, {{#query type=\"post\" limit=\"3\"}}<a href=\"{{url}}\">{{title}}</a>{{/query}}.";
    }

    // ------------------------------------------------------------------ auth, origin, limits

    private static function authenticate(): void
    {
        $token = Api::bearerToken();
        if ($token === '') {
            header('WWW-Authenticate: Bearer realm="Slate MCP"');
            self::send(401, self::error(null, -32001, 'Authentication required: send an API key as "Authorization: Bearer slt_…" (Profile → API keys).'));
        }
        $res = Api::verifyToken($token);
        if (isset($res['error'])) {
            [$status, , $message] = $res['error'];
            if ($status === 401) {
                header('WWW-Authenticate: Bearer realm="Slate MCP", error="invalid_token"');
            }
            self::send($status, self::error(null, -32001, $message));
        }
        Auth::actAs($res['user']);
        self::$key = $res['key'];
    }

    /** Simple fixed-window limit per key (file based, works on any host). */
    private static function rateLimit(): void
    {
        $dir = SLATE_CACHE . '/mcp';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $limit = max(10, (int)Hooks::filter('mcp_rate_limit', self::RATE_PER_MINUTE));
        $fh = @fopen($dir . '/rate-' . preg_replace('/[^a-f0-9]/', '', (string)self::$key['prefix']), 'c+');
        if (!$fh) {
            return;
        }
        flock($fh, LOCK_EX);
        [$start, $count] = array_map('intval', explode(' ', trim((string)stream_get_contents($fh)) ?: '0 0') + [0, 0]);
        $now = time();
        if ($now - $start >= 60) {
            [$start, $count] = [$now, 0];
        }
        $count++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, "$start $count");
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($count > $limit) {
            header('Retry-After: ' . max(1, 60 - ($now - $start)));
            self::send(429, self::error(null, -32002, "Rate limit reached ($limit requests per minute). Try again shortly."));
        }
    }

    private static function originAllowed(): bool
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return true; // not a browser
        }
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        if (in_array($host, array_filter([strtolower((string)parse_url(site_url(), PHP_URL_HOST)), AdminAccess::adminHost()]), true)) {
            return true;
        }
        return self::corsListed($origin);
    }

    private static function corsListed(string $origin): bool
    {
        $list = preg_split('/[\s,]+/', trim((string)Options::get('api_cors', ''))) ?: [];
        return in_array('*', $list, true) || in_array(rtrim($origin, '/'), array_map(static fn($o) => rtrim($o, '/'), $list), true);
    }

    private static function cors(): void
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || !self::corsListed($origin)) {
            return;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID');
        header('Access-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate');
        header('Access-Control-Max-Age: 600');
    }

    private static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private static function accepted(): never
    {
        http_response_code(202);
        exit;
    }

    // ------------------------------------------------------------------ tools: registry & dispatch

    private static function tools(): array
    {
        static $tools = null;
        if ($tools !== null) {
            return $tools;
        }
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Content item id.'];
        $seo = ['type' => 'object', 'additionalProperties' => false, 'description' => 'SEO overrides (all optional).', 'properties' => [
            'title' => ['type' => 'string', 'maxLength' => 300, 'description' => 'Meta title.'],
            'description' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Meta description (about 150 characters).'],
            'focus_keyword' => ['type' => 'string', 'maxLength' => 100],
            'canonical' => ['type' => 'string', 'maxLength' => 500],
            'robots_index' => ['type' => 'string', 'enum' => ['default', 'index', 'noindex']],
            'og_title' => ['type' => 'string', 'maxLength' => 300],
            'og_description' => ['type' => 'string', 'maxLength' => 500],
        ]];
        $fields = ['type' => 'object', 'additionalProperties' => true, 'description' => 'Custom field values by field name (see get_site → content_types[].fields). Image/file fields take media ids, relation fields post ids, repeaters an array of row objects.'];
        $terms = ['type' => 'object', 'description' => 'Terms per taxonomy slug, replacing the current ones: {"category": [3, "News"]}. Ids, or names (created when missing and the user may manage terms).',
            'additionalProperties' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => ['integer', 'string']]]];
        $common = [
            'content' => ['type' => 'string', 'description' => 'Body HTML (classic editor content). Use semantic HTML: p, h2–h4, ul/ol, a, img, blockquote, figure.'],
            'excerpt' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Short summary (plain text).'],
            'slug' => ['type' => 'string', 'maxLength' => 200, 'description' => 'URL slug; made unique automatically.'],
            'fields' => $fields,
            'terms' => $terms,
            'seo' => $seo,
            'parent_id' => ['type' => ['integer', 'null'], 'description' => 'Parent item id (hierarchical types such as pages), null for none.'],
            'menu_order' => ['type' => 'integer'],
            'featured_media_id' => ['type' => ['integer', 'null'], 'description' => 'Media id of the featured image (list_media / upload_media_from_url), null to remove.'],
        ];
        $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
        $designHelp = 'Create or replace a page that keeps its own design: pass a complete HTML document (<!doctype html><html><head>…styles…</head><body>…</body></html>). '
            . 'Visitors get exactly that page (with Slate\'s SEO tags); texts, images, icons and repeating groups (card lists, sliders) become click-to-edit on the live page. '
            . 'Pass id to replace an existing item\'s page, or title (and type, default "page") to create a new draft. Always saves a revision first; the item stays a draft unless publish:true. '
            . 'Rules: put CSS in <style> in the head (or <link> to public https stylesheets/fonts), make it responsive, use the design-system CSS variables from get_site '
            . '(var(--color-primary), var(--font-heading), …) so it matches the site, real copy (no lorem ipsum), alt text on images. Scripts are kept only for users allowed to use unfiltered HTML. '
            . 'Images: upload_media_from_url first, then <img src="URL" alt="…">. Template tags work for live content: {{site.name}}, {{title}}, {{#query type="post" limit="3"}}<a href="{{url}}">{{title}}</a>{{/query}}.';
        $tools = [
            'get_site' => ['title' => 'Site overview', 'annotations' => $ro,
                'description' => 'Overview of the website: name, URL, active theme, content types with their custom fields and taxonomies (with terms), the design system (colour, font, type-scale and layout CSS variables and the utility classes such as .container .section .btn .btn-outline .eyebrow) and what this key may do. Call this first.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
            'list_content' => ['title' => 'List content', 'annotations' => $ro,
                'description' => 'List items of a content type (newest changes first), with status, URL and whether it has a visual design. Without type, lists all public types.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'type' => ['type' => 'string', 'description' => 'Content type slug, e.g. "page" or "post" (see get_site).'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'publish', 'draft', 'pending', 'private', 'scheduled', 'trash'], 'description' => 'Default "any" (everything except trash).'],
                    'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Words in the title or content.'],
                    'orderby' => ['type' => 'string', 'enum' => ['updated_at', 'published_at', 'created_at', 'title', 'menu_order']],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.'],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                ]]],
            'get_content' => ['title' => 'Get content item', 'annotations' => $ro,
                'description' => 'Everything about one item: title, slug, status, content HTML, excerpt, custom fields, terms, SEO, URL, its visual design (if any) and revision count.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => ['id' => $id]]],
            'create_content' => ['title' => 'Create content', 'annotations' => $rw, 'write' => true,
                'description' => 'Create a new item (post, page or any custom type). It is saved as a DRAFT unless publish is true and the user may publish. For a designed landing page use write_page instead.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'title'], 'properties' => [
                    'type' => ['type' => 'string', 'description' => 'Content type slug (see get_site).'],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                ] + $common + ['publish' => ['type' => 'boolean', 'description' => 'Publish right away (only when the user asked for it). Default false = draft.']]]],
            'update_content' => ['title' => 'Update content', 'annotations' => array_merge($rw, ['idempotentHint' => true]), 'write' => true,
                'description' => 'Change an existing item; send only what changes. The previous version is kept as a revision. Custom fields are merged (fields not sent stay as they are); terms replace the current terms of each taxonomy sent. If the item has a visual design, change its look with write_page (content edits are kept but visitors see the design).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => [
                    'id' => $id,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                ] + $common + ['status' => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish', 'private'], 'description' => 'publish/private need a user who may publish. Only change when the user asked for it.']]]],
            'write_page' => ['title' => 'Write a designed page (full HTML)', 'annotations' => $rw, 'write' => true,
                'description' => $designHelp,
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['html'], 'properties' => [
                    'id' => $id + ['description' => 'Existing item to redesign. Omit to create a new item.'],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Title for a new item (required without id).'],
                    'type' => ['type' => 'string', 'description' => 'Content type for a new item. Default "page".'],
                    'slug' => ['type' => 'string', 'maxLength' => 200, 'description' => 'URL slug for a new item.'],
                    'html' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_HTML, 'description' => 'Complete HTML document (see rules above).'],
                    'publish' => ['type' => 'boolean', 'description' => 'Publish as well (only when the user asked for it). Default false.'],
                ]]],
            'list_templates' => ['title' => 'List templates', 'annotations' => $ro,
                'description' => 'Site-wide templates (Templates screen): which HTML template renders single items, archives, taxonomy archives, 404 and search, and which are active. Read-only.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
            'get_template' => ['title' => 'Get template', 'annotations' => $ro,
                'description' => 'HTML and CSS of one template (template tags such as {{title}}, {{{content}}}, {{field:name}}, {{#query}}). Read-only.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => ['id' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Template id from list_templates.']]]],
            'list_media' => ['title' => 'List media', 'annotations' => $ro,
                'description' => 'Files in the media library (newest first) with URL, size and alt text.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Title, file name or alt text.'],
                    'kind' => ['type' => 'string', 'enum' => ['image', 'video', 'document']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 30.'],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                ]]],
            'upload_media_from_url' => ['title' => 'Upload media from a URL', 'annotations' => array_merge($rw, ['openWorldHint' => true]), 'write' => true,
                'description' => 'Download a public image or file into the media library (optimised automatically: WebP, resized) and return its id and URL. Only public http(s) addresses; allowed file types and size limits of the site apply.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['url'], 'properties' => [
                    'url' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Public http(s) URL of the file.'],
                    'filename' => ['type' => 'string', 'maxLength' => 100, 'description' => 'SEO-friendly file name without extension, e.g. "team-photo-2026".'],
                    'alt' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Alt text describing the image.'],
                    'title' => ['type' => 'string', 'maxLength' => 255],
                    'caption' => ['type' => 'string', 'maxLength' => 1000],
                ]]],
            'search' => ['title' => 'Search the site', 'annotations' => $ro,
                'description' => 'Search all content (titles and text), terms, media and templates at once.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['query'], 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'description' => 'Limit content to these type slugs.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Content results, default 20.'],
                ]]],
            'get_revisions' => ['title' => 'Get revisions', 'annotations' => $ro,
                'description' => 'Saved earlier versions of an item (newest first). With revision_id: that version in full plus the lines that differ from the current content.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => [
                    'id' => $id, 'revision_id' => ['type' => 'integer', 'minimum' => 1]]]],
            'restore_revision' => ['title' => 'Restore a revision', 'annotations' => array_merge($rw, ['destructiveHint' => true]), 'write' => true,
                'description' => 'Put an earlier version of an item back (title, content, excerpt, fields incl. its visual design, SEO). The current version is kept as a revision, so this can be undone.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'revision_id'], 'properties' => [
                    'id' => $id, 'revision_id' => ['type' => 'integer', 'minimum' => 1]]]],
        ];
        $tools = (array)Hooks::filter('mcp_tools', $tools);
        return $tools;
    }

    private static function toolList(): array
    {
        $out = [];
        foreach (self::tools() as $name => $t) {
            $out[] = array_filter(['name' => $name, 'title' => $t['title'] ?? null, 'description' => $t['description'] ?? '',
                'inputSchema' => $t['inputSchema'] ?? ['type' => 'object'], 'annotations' => $t['annotations'] ?? null], static fn($v) => $v !== null);
        }
        return $out;
    }

    private static function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? (self::tools()[$name] ?? null) : null;
        if (!$tool) {
            throw new McpError(-32602, 'Unknown tool: ' . (is_string($name) ? $name : '(none)'));
        }
        $args = $params['arguments'] ?? [];
        if ($args === null) {
            $args = [];
        }
        if ($err = self::validate($args, $tool['inputSchema'] ?? ['type' => 'object'], 'arguments')) {
            throw new McpError(-32602, "Invalid arguments for $name: $err");
        }
        try {
            if (!empty($tool['write'])) {
                self::requireWriteKey();
            }
            $handler = $tool['handler'] ?? [self::class, 'tool' . str_replace('_', '', ucwords($name, '_'))];
            $data = (array)$handler((array)$args);
            return ['content' => [['type' => 'text', 'text' => (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]],
                'structuredContent' => $data ?: new \stdClass(), 'isError' => false];
        } catch (ToolError $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    /** Small JSON Schema check (the subset our tools use). Returns an error message or null. */
    private static function validate(mixed &$v, array $s, string $path): ?string
    {
        $types = (array)($s['type'] ?? []);
        if ($types) {
            $ok = false;
            foreach ($types as $t) {
                if ($t === 'integer' && is_string($v) && preg_match('/^-?\d{1,15}$/', $v)) {
                    $v = (int)$v; // lenient with clients that send numbers as strings
                }
                $ok = $ok || match ($t) {
                    'object' => is_array($v) && ($v === [] || !array_is_list($v)),
                    'array' => is_array($v) && array_is_list($v),
                    'string' => is_string($v),
                    'integer' => is_int($v) || (is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX),
                    'number' => is_int($v) || is_float($v),
                    'boolean' => is_bool($v),
                    'null' => $v === null,
                    default => true,
                };
            }
            if (!$ok) {
                return "$path must be " . implode(' or ', $types) . '.';
            }
            if (is_float($v) && in_array('integer', $types, true) && !in_array('number', $types, true)) {
                $v = (int)$v;
            }
        }
        if (isset($s['enum']) && !in_array($v, $s['enum'], true)) {
            return "$path must be one of: " . implode(', ', array_map('strval', $s['enum'])) . '.';
        }
        if (is_string($v)) {
            if (isset($s['minLength']) && mb_strlen($v) < $s['minLength']) {
                return "$path must not be empty.";
            }
            if (isset($s['maxLength']) && mb_strlen($v) > $s['maxLength']) {
                return "$path is too long (max {$s['maxLength']} characters).";
            }
        }
        if (is_int($v) || is_float($v)) {
            if (isset($s['minimum']) && $v < $s['minimum']) {
                return "$path must be at least {$s['minimum']}.";
            }
            if (isset($s['maximum']) && $v > $s['maximum']) {
                return "$path must be at most {$s['maximum']}.";
            }
        }
        if (is_array($v) && in_array('object', $types, true) && ($v === [] || !array_is_list($v))) {
            foreach ((array)($s['required'] ?? []) as $req) {
                if (!array_key_exists($req, $v)) {
                    return "$path.$req is required.";
                }
            }
            $props = (array)($s['properties'] ?? []);
            $extra = $s['additionalProperties'] ?? true;
            foreach ($v as $k => &$val) {
                if (isset($props[$k])) {
                    if ($err = self::validate($val, (array)$props[$k], "$path.$k")) {
                        return $err;
                    }
                } elseif ($extra === false) {
                    return "$path has an unknown property \"$k\". Allowed: " . (implode(', ', array_keys($props)) ?: 'none') . '.';
                } elseif (is_array($extra) && ($err = self::validate($val, $extra, "$path.$k"))) {
                    return $err;
                }
            }
            unset($val);
        }
        if (is_array($v) && array_is_list($v) && in_array('array', $types, true)) {
            if (isset($s['maxItems']) && count($v) > $s['maxItems']) {
                return "$path has too many items (max {$s['maxItems']}).";
            }
            if (isset($s['items'])) {
                foreach ($v as $i => &$item) {
                    if ($err = self::validate($item, (array)$s['items'], "{$path}[$i]")) {
                        return $err;
                    }
                }
                unset($item);
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ permission helpers

    private static function requireWriteKey(): void
    {
        if ((self::$key['scope'] ?? 'read') !== 'write') {
            throw new ToolError('This API key is read-only. Create a key with “Read & write” access under Profile → API keys to make changes.');
        }
    }

    private static function requireCap(string $cap, string $what): void
    {
        if (!Auth::can($cap)) {
            throw new ToolError("The user of this key is not allowed to $what.");
        }
    }

    private static function canEdit(array $post): bool
    {
        return Auth::can('edit_others_posts') || ((int)$post['author_id'] === Auth::id() && Auth::can('edit_posts'));
    }

    private static function canRead(array $post): bool
    {
        return $post['status'] === 'publish' || self::canEdit($post);
    }

    private static function canPublish(): bool
    {
        return (self::$key['scope'] ?? 'read') === 'write' && Auth::can('publish_posts');
    }

    /** Public content types the server exposes (filter "mcp_types"). */
    private static function types(): array
    {
        return (array)Hooks::filter('mcp_types', array_filter(ContentModel::types(), static fn($t) => ($t['public'] ?? true) !== false));
    }

    private static function type(string $slug): array
    {
        $t = self::types()[$slug] ?? null;
        if (!$t) {
            throw new ToolError("Unknown content type “{$slug}”. Available: " . implode(', ', array_keys(self::types())) . '.');
        }
        return $t;
    }

    private static function findPost(int $id, bool $forEdit): array
    {
        $post = Posts::find($id);
        if (!$post || !isset(self::types()[$post['type']]) || !($forEdit ? self::canEdit($post) : self::canRead($post))) {
            throw new ToolError($post && $forEdit && self::canRead($post) ? "The user of this key cannot edit item $id." : "No content item with id $id.");
        }
        return $post;
    }

    // ------------------------------------------------------------------ output helpers

    private static function absAdmin(string $path): string
    {
        return AdminAccess::baseUrl() . '/' . admin_slug() . '/' . ltrim($path, '/');
    }

    private static function iso(?string $utc): ?string
    {
        return $utc ? gmdate('c', (int)strtotime($utc . ' UTC')) : null;
    }

    private static function summary(array $p): array
    {
        return ['id' => (int)$p['id'], 'type' => $p['type'], 'title' => $p['title'], 'slug' => $p['slug'], 'status' => $p['status'],
            'url' => Posts::permalink($p, true), 'updated_at' => self::iso($p['updated_at']), 'published_at' => self::iso($p['published_at']),
            'parent_id' => $p['parent_id'] ? (int)$p['parent_id'] : null, 'designed_page' => HtmlPages::active($p)];
    }

    private static function full(array $p): array
    {
        $terms = [];
        foreach (Terms::forPosts([(int)$p['id']])[(int)$p['id']] ?? [] as $tax => $list) {
            foreach ((array)$list as $t) {
                $terms[$tax][] = ['id' => (int)$t['id'], 'name' => $t['name'], 'slug' => $t['slug']];
            }
        }
        $fields = (array)$p['fields'];
        unset($fields['_html']);
        $b = HtmlPages::data($p);
        $out = self::summary($p) + [
            'excerpt' => (string)$p['excerpt'],
            'content' => (string)$p['content'],
            'fields' => $fields ?: new \stdClass(),
            'terms' => $terms ?: new \stdClass(),
            'seo' => $p['seo'] ?: new \stdClass(),
            'menu_order' => (int)$p['menu_order'],
            'featured_media' => $p['featured_media_id'] && ($m = Media::find((int)$p['featured_media_id'])) ? self::media($m) : null,
            'template' => $p['template'],
            'author_id' => $p['author_id'] ? (int)$p['author_id'] : null,
            'created_at' => self::iso($p['created_at'] ?? null),
            'revisions' => Revisions::count((int)$p['id']),
            'edit_url' => self::absAdmin("content/{$p['type']}/{$p['id']}"),
        ];
        if ($p['status'] !== 'publish') {
            $out['preview_url'] = Posts::permalink($p, true) . '?preview=' . (int)$p['id'];
        }
        if ($b) {
            $out['design'] = ['source' => $b['source'] ?? 'import', 'head' => (string)($b['head'] ?? ''), 'body' => (string)($b['body'] ?? ''),
                'note' => 'Visitors see this page (head + body). content holds its text. Replace it with write_page; people edit it by clicking on the live page.'];
        }
        return $out;
    }

    private static function media(array $m): array
    {
        return ['id' => (int)$m['id'], 'url' => site_url('content/uploads/' . $m['path']), 'mime' => $m['mime'],
            'width' => $m['width'] ? (int)$m['width'] : null, 'height' => $m['height'] ? (int)$m['height'] : null,
            'alt' => (string)$m['alt'], 'title' => (string)$m['title'], 'filename' => basename((string)$m['path']),
            'size' => (int)$m['size']];
    }

    // ------------------------------------------------------------------ tools: site

    public static function toolGetSite(array $a): array
    {
        $ds = DesignSystem::get();
        $types = [];
        foreach (self::types() as $slug => $t) {
            $counts = Posts::counts($slug);
            $types[] = ['slug' => $slug, 'singular' => $t['singular'] ?? $slug, 'plural' => $t['plural'] ?? $slug,
                'hierarchical' => !empty($t['hierarchical']), 'has_archive' => !empty($t['has_archive']), 'supports' => array_values((array)($t['supports'] ?? [])),
                'taxonomies' => array_keys(ContentModel::taxonomiesFor($slug)),
                'fields' => array_values(array_map([self::class, 'fieldDef'], array_filter(ContentModel::fieldsFor('post', $slug),
                    static fn($f) => !in_array($f['type'] ?? '', ContentModel::LAYOUT_TYPES, true)))),
                'counts' => ['publish' => $counts['publish'], 'draft' => $counts['draft'], 'pending' => $counts['pending']]];
        }
        $taxes = [];
        foreach (ContentModel::taxonomies() as $slug => $t) {
            if (($t['public'] ?? true) === false) {
                continue;
            }
            $all = Terms::all($slug);
            $taxes[] = ['slug' => $slug, 'singular' => $t['singular'] ?? $slug, 'plural' => $t['plural'] ?? $slug, 'hierarchical' => !empty($t['hierarchical']),
                'post_types' => array_values((array)($t['post_types'] ?? [])), 'term_count' => count($all),
                'terms' => array_map(static fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'slug' => $x['slug'], 'parent_id' => $x['parent_id'] ? (int)$x['parent_id'] : null],
                    array_slice(array_values($all), 0, 200))];
        }
        $colors = array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'] ?? $c['id'], 'value' => $c['value'],
            'css_variable' => "var(--color-{$c['id']})", 'classes' => [".text-{$c['id']}", ".bg-{$c['id']}"]], $ds['colors']);
        $typeScale = [];
        foreach ($ds['type'] as $k => $t) {
            $typeScale[$k] = ['size_px' => $t['size'], 'weight' => $t['weight'], 'font' => $t['font'], 'font_shorthand_variable' => "var(--type-$k)", 'size_variable' => "var(--fs-$k)"];
        }
        $u = Auth::user();
        $front = Options::get('front_page', 'posts') === 'page' ? (int)Options::get('front_page_id', 0) : 0;
        return [
            'name' => (string)Options::get('site_title'), 'tagline' => (string)Options::get('tagline'), 'url' => site_url(),
            'language' => (string)Options::get('language', 'en'), 'timezone' => (string)Options::get('timezone', 'UTC'), 'slate_version' => SLATE_VERSION,
            'homepage' => $front ? ['type' => 'page', 'id' => $front] : ['type' => 'latest_posts'],
            'theme' => ['slug' => Theme::active(), 'name' => (string)(Theme::meta()['name'] ?? Theme::active()), 'version' => (string)(Theme::meta()['version'] ?? '')],
            'content_types' => $types,
            'taxonomies' => $taxes,
            'design_system' => [
                'colors' => $colors,
                'fonts' => ['heading' => $ds['fonts']['heading'], 'body' => $ds['fonts']['body'], 'heading_variable' => 'var(--font-heading)', 'body_variable' => 'var(--font-body)'],
                'type_scale' => $typeScale,
                'elements_styled' => !empty($ds['apply_elements']) ? 'h1–h6, body text and small are styled automatically (also as classes .h1–.h6, .small).' : 'Headings are not styled globally; use .h1–.h6 classes or your own CSS.',
                'layout' => ['container_px' => $ds['layout']['container'], 'radius_px' => $ds['layout']['radius'], 'button_radius_px' => $ds['layout']['button_radius'],
                    'section_space_px' => $ds['layout']['section_space'],
                    'variables' => ['var(--container)', 'var(--radius)', 'var(--radius-button)', 'var(--section-space)']],
                'classes' => array_map(static fn($c) => ['class' => $c['name'], 'selector' => '.' . $c['name'], 'css' => str_replace("\n", '', (string)$c['css'])], $ds['classes']),
                'page_structure' => '<section class="section"><div class="container"><p class="eyebrow">Label</p><h2>Heading</h2><p>Text</p><a class="btn" href="/contact/">Action</a></div></section>',
            ],
            'you' => ['name' => $u['display_name'] ?? '', 'role' => $u['role'] ?? '', 'key_scope' => self::$key['scope'] ?? 'read',
                'can' => ['write' => (self::$key['scope'] ?? '') === 'write' && Auth::can('edit_posts'), 'publish' => self::canPublish(),
                    'edit_others' => Auth::can('edit_others_posts'), 'unfiltered_html' => Auth::can('unfiltered_html'),
                    'upload_media' => Auth::can('upload_media'), 'read_templates' => Auth::can('manage_content_model'), 'manage_terms' => Auth::can('manage_terms')]],
        ];
    }

    private static function fieldDef(array $f): array
    {
        $out = ['name' => $f['name'], 'label' => $f['label'] ?? $f['name'], 'type' => $f['type']];
        foreach (['required', 'help', 'default', 'options', 'post_type', 'taxonomy'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== [] && $f[$k] !== false) {
                $out[$k] = $f[$k];
            }
        }
        if (!empty($f['sub_fields'])) {
            $out['sub_fields'] = array_map([self::class, 'fieldDef'], (array)$f['sub_fields']);
        }
        return $out;
    }

    // ------------------------------------------------------------------ tools: content

    public static function toolListContent(array $a): array
    {
        if (isset($a['type'])) {
            self::type((string)$a['type']);
        }
        $types = isset($a['type']) ? [(string)$a['type']] : array_keys(self::types());
        $status = (string)($a['status'] ?? 'any');
        $args = ['type' => $types, 'limit' => (int)($a['limit'] ?? 20), 'page' => (int)($a['page'] ?? 1),
            'orderby' => (string)($a['orderby'] ?? 'updated_at'), 'order' => (string)($a['order'] ?? 'desc')];
        $note = null;
        if (!Auth::can('edit_posts')) {
            $args['status'] = 'publish';
            $note = $status !== 'publish' ? 'Only published items are visible to this user.' : null;
        } else {
            $args['status'] = $status;
            if (!Auth::can('edit_others_posts') && $status !== 'publish') {
                $args['author'] = Auth::id();
                $note = 'Only your own items are listed (the user cannot edit content of others).';
            }
        }
        if (($s = trim((string)($a['search'] ?? ''))) !== '') {
            $args['search'] = $s;
        }
        $res = Posts::query($args);
        return array_filter(['items' => array_map([self::class, 'summary'], $res['items']), 'total' => (int)$res['total'],
            'page' => (int)$res['page'], 'pages' => (int)$res['pages'], 'note' => $note], static fn($v) => $v !== null);
    }

    public static function toolGetContent(array $a): array
    {
        return self::full(self::findPost((int)$a['id'], false));
    }

    public static function toolCreateContent(array $a): array
    {
        $type = (string)$a['type'];
        self::type($type);
        self::requireCap('edit_posts', 'create content');
        if (trim((string)$a['title']) === '') {
            throw new ToolError('Give the item a title.');
        }
        $warnings = [];
        $in = self::prepare($type, $a, null, $warnings);
        $data = Api::writable($type, $in, null);
        if (isset($in['seo_clean'])) {
            $data['seo'] = $in['seo_clean'];
        }
        $data['type'] = $type;
        $data['status'] = 'draft';
        if (!empty($a['publish'])) {
            if (self::canPublish()) {
                $data['status'] = 'publish';
            } else {
                $warnings[] = 'Saved as a draft: the user of this key may not publish.';
            }
        }
        $id = Posts::save($data);
        $post = Posts::find($id);
        log_activity('mcp_content_created', "$type:{$post['title']} ({$post['status']})");
        return ['created' => true] + self::full($post) + ($warnings ? ['warnings' => $warnings] : []);
    }

    public static function toolUpdateContent(array $a): array
    {
        self::requireCap('edit_posts', 'edit content');
        $post = self::findPost((int)$a['id'], true);
        $type = (string)$post['type'];
        if (array_key_exists('title', $a) && trim((string)$a['title']) === '') {
            throw new ToolError('The title cannot be empty.');
        }
        $warnings = [];
        $in = self::prepare($type, $a, $post, $warnings);
        unset($in['status']);
        $data = Api::writable($type, $in, $post);
        unset($data['status']);
        if (isset($in['seo_clean'])) {
            $data['seo'] = $in['seo_clean'];
        }
        if (isset($a['status']) && $a['status'] !== $post['status']) {
            if (in_array($a['status'], ['publish', 'private'], true) && !self::canPublish()) {
                $warnings[] = 'Status not changed: the user of this key may not publish.';
            } else {
                $data['status'] = $a['status'];
            }
        }
        if (isset($data['content']) && HtmlPages::active($post)) {
            $warnings[] = 'This item is shown as a designed page, so the new content is stored but visitors still see the page. Use write_page to change what visitors see.';
        }
        if (!$data) {
            throw new ToolError('Nothing to change: send at least one field to update.');
        }
        $revision = Revisions::snapshot($post, $data);
        Posts::save($data, (int)$post['id']);
        $now = Posts::find((int)$post['id']);
        log_activity('mcp_content_updated', "{$type}:{$now['title']}" . (($data['status'] ?? null) ? " → {$now['status']}" : ''));
        return ['updated' => true, 'revision_id' => $revision] + self::full($now) + ($warnings ? ['warnings' => $warnings] : []);
    }

    /** Tool arguments → the input Api::writable() expects, with terms resolved to ids and fields checked. */
    private static function prepare(string $type, array $a, ?array $existing, array &$warnings): array
    {
        $in = array_intersect_key($a, array_flip(['title', 'slug', 'excerpt', 'parent_id', 'menu_order', 'featured_media_id']));
        if (isset($a['content'])) {
            $in['content'] = Auth::can('unfiltered_html') ? self::cleanHtml((string)$a['content'], $css) : (string)$a['content'];
            if (!empty($css)) {
                $warnings[] = '<style> blocks are not kept in content; use write_page for styled layouts.';
            }
        }
        if (!empty($in['featured_media_id']) && !Media::find((int)$in['featured_media_id'])) {
            throw new ToolError("No media item with id {$in['featured_media_id']}.");
        }
        if (!empty($in['parent_id'])) {
            $parent = Posts::find((int)$in['parent_id']);
            if (!$parent || $parent['type'] !== $type) {
                throw new ToolError("Parent {$in['parent_id']} is not an item of type $type.");
            }
        }
        if (isset($a['fields'])) {
            $defs = ContentModel::fieldsFor('post', $type);
            $unknown = array_diff(array_keys($a['fields']), array_keys($defs));
            if ($unknown) {
                $warnings[] = 'Ignored unknown fields: ' . implode(', ', $unknown) . ' (see get_site for the fields of this type).';
            }
            $in['fields'] = array_intersect_key($a['fields'], $defs);
            // Partial update: fields not sent keep their values (Posts::save merges).
        }
        if (isset($a['seo'])) {
            $seo = [];
            foreach ($a['seo'] as $k => $v) {
                $seo[$k] = $k === 'canonical' ? ContentModel::cleanUrl((string)$v) : trim(strip_tags((string)$v));
            }
            $in['seo_clean'] = $seo;
        }
        if (isset($a['terms'])) {
            $allowed = ContentModel::taxonomiesFor($type);
            $in['terms'] = [];
            foreach ($a['terms'] as $tax => $values) {
                if (!isset($allowed[$tax])) {
                    $warnings[] = "Ignored taxonomy “{$tax}”: not used by $type (" . (implode(', ', array_keys($allowed)) ?: 'none') . ').';
                    continue;
                }
                $ids = [];
                foreach ((array)$values as $v) {
                    if (is_int($v)) {
                        $t = Terms::find($v);
                        $t && $t['taxonomy'] === $tax ? $ids[] = $v : $warnings[] = "Ignored term id $v: not a $tax term.";
                    } elseif (($v = trim((string)$v)) !== '') {
                        $t = Terms::findBySlug($tax, slugify($v));
                        if ($t) {
                            $ids[] = (int)$t['id'];
                        } elseif (Auth::can('manage_terms') && (self::$key['scope'] ?? '') === 'write') {
                            $ids[] = Terms::save($tax, ['name' => mb_substr($v, 0, 100)]);
                        } else {
                            $warnings[] = "Ignored term “{$v}”: it does not exist in $tax and this user may not create terms.";
                        }
                    }
                }
                $in['terms'][$tax] = array_values(array_unique($ids));
            }
        }
        return $in;
    }

    public static function toolWritePage(array $a): array
    {
        self::requireCap('edit_posts', 'edit content');
        $warnings = [];
        $created = false;
        $html = (string)$a['html'];
        if (!preg_match('#<body\b#i', $html)) {
            $html = '<!doctype html><html><head></head><body>' . $html . '</body></html>';
            $warnings[] = 'No <body> found: the HTML was used as the page body.';
        }
        $parsed = HtmlPages::fromDocument($html, null, 'ai');
        $data = $parsed['data'];
        $data['head'] = HtmlPages::stripScripts((string)preg_replace('#<link\b[^>]*href=["\']?(?!https://|/)[^>]*>#i', '', $data['head']));
        if (!Auth::can('unfiltered_html')) {
            $data['body'] = HtmlPages::stripScripts($data['body']);
            $warnings[] = 'Scripts and event handlers were removed (this user may not use unfiltered HTML).';
        }
        if (trim(HtmlPages::text($data['body'])) === '' && !preg_match('#<(img|video|iframe|svg)#i', $data['body'])) {
            throw new ToolError('The HTML has no visible content.');
        }
        if (isset($a['id'])) {
            $post = self::findPost((int)$a['id'], true);
        } else {
            $title = trim((string)($a['title'] ?? '')) ?: $parsed['title'];
            if ($title === '') {
                throw new ToolError('Pass id to replace an item, or title (and type) to create a new one.');
            }
            $type = (string)($a['type'] ?? 'page');
            self::type($type);
            $id = Posts::save(['type' => $type, 'title' => $title, 'slug' => trim((string)($a['slug'] ?? '')), 'status' => 'draft', 'content' => '']);
            $post = Posts::find($id);
            $created = true;
        }
        $before = Revisions::count((int)$post['id']);
        $extra = $parsed['description'] !== '' && empty($post['seo']['description']) ? ['seo' => ['description' => $parsed['description']]] : [];
        HtmlPages::save((int)$post['id'], $data, $extra);
        if (!empty($a['publish'])) {
            if (self::canPublish()) {
                Posts::save(['status' => 'publish'], (int)$post['id']);
            } else {
                $warnings[] = 'Saved, but not published: the user of this key may not publish.';
            }
        }
        $now = Posts::find((int)$post['id']);
        log_activity('mcp_page_written', "{$now['type']}:{$now['title']} ({$now['status']})");
        $out = self::full($now);
        unset($out['content'], $out['design']['body'], $out['design']['head']);
        return ['saved' => true, 'created' => $created, 'revision_saved' => Revisions::count((int)$post['id']) > $before] + $out + ($warnings ? ['warnings' => $warnings] : []);
    }

    /** HTML from an agent: body only, no scripts or event handlers; <style> blocks are handed back in $css. */
    private static function cleanHtml(string $html, ?string &$css = null): string
    {
        $css = '';
        if (preg_match('#<body\b[^>]*>(.*?)(</body>|$)#is', $html, $m)) {
            $html = $m[1];
        }
        $html = (string)preg_replace_callback('#<style\b[^>]*>(.*?)</style\s*>#is', static function ($m) use (&$css) {
            $css .= $m[1] . "\n";
            return '';
        }, $html);
        $html = (string)preg_replace(['#<script\b[^>]*>.*?</script\s*>#is', '#</?(script|html|head|body|meta|link|base|object|embed)\b[^>]*>#i'], '', $html);
        $html = (string)preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = (string)preg_replace('/\b(href|src|action|formaction|xlink:href)\s*=\s*(["\']?)\s*(javascript|vbscript|data:text\/html)\s*:?/i', '$1=$2#blocked:', $html);
        $css = trim($css);
        return trim($html);
    }

    // ------------------------------------------------------------------ tools: templates

    public static function toolListTemplates(array $a): array
    {
        self::requireCap('manage_content_model', 'read templates');
        $items = [];
        foreach (Templates::all() as $t) {
            $items[] = ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'target' => $t['target'],
                'applies_to' => Templates::label($t['kind'], $t['target']), 'active' => !empty($t['enabled']),
                'inside_theme' => !empty($t['wrap']), 'updated_at' => self::iso($t['updated_at'] ?? null)];
        }
        return ['items' => $items, 'total' => count($items),
            'note' => 'Without an active template, items render with the theme. Items with a visual design (write_page) use that design instead of the single template.'];
    }

    public static function toolGetTemplate(array $a): array
    {
        self::requireCap('manage_content_model', 'read templates');
        $t = Templates::find((string)$a['id']);
        if (!$t) {
            throw new ToolError("No template with id “{$a['id']}”.");
        }
        return ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'target' => $t['target'],
            'applies_to' => Templates::label($t['kind'], $t['target']), 'active' => !empty($t['enabled']), 'inside_theme' => !empty($t['wrap']),
            'html' => (string)$t['html'], 'css' => (string)$t['css'], 'updated_at' => self::iso($t['updated_at'] ?? null)];
    }

    // ------------------------------------------------------------------ tools: media

    public static function toolListMedia(array $a): array
    {
        self::requireCap('edit_posts', 'browse the media library');
        $limit = (int)($a['limit'] ?? 30);
        $res = Media::list((int)($a['page'] ?? 1), trim((string)($a['search'] ?? '')), (string)($a['kind'] ?? ''), $limit);
        $items = array_map(static fn($m) => ['id' => $m['id'], 'url' => $m['absolute_url'], 'mime' => $m['mime'], 'width' => $m['width'],
            'height' => $m['height'], 'alt' => (string)$m['alt'], 'title' => (string)$m['title'], 'filename' => $m['filename'] . '.' . $m['ext'],
            'size' => $m['size_h']], $res['items']);
        return ['items' => $items, 'total' => $res['total'], 'pages' => $res['pages']];
    }

    public static function toolUploadMediaFromUrl(array $a): array
    {
        self::requireCap('upload_media', 'upload media');
        $url = trim((string)$a['url']);
        $max = max(1, (int)Options::get('media_max_upload_mb', 20)) * 1024 * 1024;
        $tmp = tempnam(sys_get_temp_dir(), 'slmcp');
        if ($tmp === false) {
            throw new ToolError('Could not create a temporary file.');
        }
        try {
            $dl = self::download($url, $tmp, $max);
            if (isset($dl['error'])) {
                throw new ToolError($dl['error']);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
            $byMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif',
                'application/pdf' => 'pdf', 'video/mp4' => 'mp4', 'video/webm' => 'webm', 'audio/mpeg' => 'mp3', 'text/plain' => 'txt', 'text/csv' => 'csv'];
            $path = (string)parse_url($dl['url'], PHP_URL_PATH);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (isset($byMime[$mime]) && !in_array($ext, $mime === 'image/jpeg' ? ['jpg', 'jpeg'] : [$byMime[$mime]], true)) {
                $ext = $byMime[$mime];
            }
            $name = trim((string)($a['filename'] ?? '')) ?: rawurldecode(pathinfo($path, PATHINFO_FILENAME));
            $base = trim($name) !== '' ? slugify($name) : 'file';
            $res = Media::upload(['name' => mb_substr($base, 0, 80) . ($ext !== '' ? ".$ext" : ''), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], true);
        } finally {
            @unlink($tmp);
        }
        if (!$res['ok']) {
            throw new ToolError((string)$res['error']);
        }
        $m = $res['media'];
        $meta = array_filter(['alt' => $a['alt'] ?? null, 'title' => $a['title'] ?? null, 'caption' => $a['caption'] ?? null], static fn($v) => $v !== null);
        if ($meta) {
            Media::update((int)$m['id'], $meta);
            $m = Media::find((int)$m['id']);
        }
        log_activity('mcp_media_uploaded', basename((string)$m['path']));
        return ['uploaded' => true] + self::media($m) + ['source' => $dl['url']];
    }

    /** Fetch a public URL into $dest: SSRF-safe (public IPs only, pinned per hop, redirects re-checked), size-capped. */
    private static function download(string $url, string $dest, int $max): array
    {
        if (!function_exists('curl_init')) {
            return ['error' => 'The PHP cURL extension is required.'];
        }
        for ($hop = 0; $hop <= 4; $hop++) {
            $check = self::publicTarget($url);
            if (isset($check['error'])) {
                return $check;
            }
            $fh = fopen($dest, 'wb');
            $headers = [];
            $ch = curl_init($url);
            $port = (int)(parse_url($url, PHP_URL_PORT) ?: (str_starts_with(strtolower($url), 'https') ? 443 : 80));
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$check['host'] . ':' . $port . ':' . (str_contains($check['ip'], ':') ? '[' . $check['ip'] . ']' : $check['ip'])],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_MAXFILESIZE => $max,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static fn($c, $dlTotal, $dlNow) => $dlNow > $max ? 1 : 0,
                CURLOPT_HTTPHEADER => ['User-Agent: Slate-CMS/' . SLATE_VERSION . ' (media import)', 'Accept: image/*,video/*,application/pdf,*/*;q=0.5'],
                CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
                    $p = explode(':', $line, 2);
                    if (count($p) === 2) {
                        $headers[strtolower(trim($p[0]))] = trim($p[1]);
                    }
                    return strlen($line);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errNo = curl_errno($ch);
            $err = $errNo ? curl_error($ch) : '';
            unset($ch);
            fclose($fh);
            if (!$ok || $errNo) {
                return ['error' => in_array($errNo, [CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK], true)
                    ? 'The file is larger than the ' . (int)($max / 1048576) . ' MB upload limit.' : "Download failed: $err"];
            }
            if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
                $loc = $headers['location'];
                if (!preg_match('#^https?://#i', $loc)) {
                    $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $url);
                    $loc = str_starts_with($loc, '/') ? $origin . $loc : preg_replace('#/[^/]*$#', '/', $url) . $loc;
                }
                $url = $loc;
                continue;
            }
            if ($status !== 200) {
                return ['error' => "Download failed: the server answered HTTP $status."];
            }
            if (filesize($dest) === 0) {
                return ['error' => 'Download failed: the file is empty.'];
            }
            return ['url' => $url, 'content_type' => $headers['content-type'] ?? ''];
        }
        return ['error' => 'Too many redirects.'];
    }

    /** A URL's host must resolve to public addresses only (no private, loopback or reserved ranges). */
    private static function publicTarget(string $url): array
    {
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Enter a full http(s):// address.'];
        }
        if (parse_url($url, PHP_URL_USER) !== null) {
            return ['error' => 'URLs with user names or passwords are not allowed.'];
        }
        $port = parse_url($url, PHP_URL_PORT);
        if ($port !== null && !in_array((int)$port, [80, 443, 8080, 8443], true)) {
            return ['error' => 'Only the standard web ports (80, 443, 8080, 8443) are allowed.'];
        }
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (!$ips) {
            return ['error' => "Cannot resolve $host."];
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['error' => 'That address points to a private or local network. Only public URLs can be imported.'];
            }
        }
        usort($ips, static fn($x, $y) => (int)str_contains($x, ':') <=> (int)str_contains($y, ':')); // IPv4 first
        return ['host' => $host, 'ip' => $ips[0]];
    }

    // ------------------------------------------------------------------ tools: search & revisions

    public static function toolSearch(array $a): array
    {
        $q = trim((string)$a['query']);
        if ($q === '') {
            throw new ToolError('Enter something to search for.');
        }
        $types = array_keys(self::types());
        if (!empty($a['types'])) {
            $types = array_values(array_intersect($types, $a['types'])) ?: ['-none-'];
        }
        $limit = (int)($a['limit'] ?? 20);
        $args = ['type' => $types, 'search' => $q, 'limit' => $limit, 'orderby' => 'updated_at'];
        $items = Posts::query($args + ['status' => Auth::can('edit_others_posts') ? 'any' : 'publish'])['items'];
        if (Auth::can('edit_posts') && !Auth::can('edit_others_posts')) {
            $seen = array_column($items, 'id');
            foreach (Posts::query($args + ['status' => 'any', 'author' => Auth::id()])['items'] as $p) {
                if (!in_array($p['id'], $seen, true)) {
                    $items[] = $p;
                }
            }
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $taxes = array_keys(array_filter(ContentModel::taxonomies(), static fn($t) => ($t['public'] ?? true) !== false));
        $terms = [];
        if ($taxes) {
            $params = [];
            foreach (array_values($taxes) as $i => $tx) {
                $params["tx$i"] = $tx;
            }
            $in = ':' . implode(',:', array_keys($params));
            foreach (DB::all("SELECT * FROM {{terms}} WHERE taxonomy IN ($in) AND (name LIKE :q OR slug LIKE :q2) ORDER BY name LIMIT 20", $params + ['q' => $like, 'q2' => $like]) as $t) {
                $terms[] = ['id' => (int)$t['id'], 'taxonomy' => $t['taxonomy'], 'name' => $t['name'], 'slug' => $t['slug'], 'url' => Terms::link($t, true)];
            }
        }
        $out = ['query' => $q, 'content' => array_map([self::class, 'summary'], array_slice($items, 0, $limit)), 'terms' => $terms];
        if (Auth::can('edit_posts')) {
            $out['media'] = array_map(static fn($m) => ['id' => $m['id'], 'url' => $m['absolute_url'], 'title' => $m['title'], 'alt' => $m['alt']],
                Media::list(1, $q, '', 10)['items']);
        }
        if (Auth::can('manage_content_model')) {
            $out['templates'] = array_values(array_map(static fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'kind' => $t['kind'], 'target' => $t['target']],
                array_filter(Templates::all(), static fn($t) => stripos((string)$t['name'], $q) !== false || stripos((string)$t['html'], $q) !== false)));
        }
        return $out;
    }

    public static function toolGetRevisions(array $a): array
    {
        $post = self::findPost((int)$a['id'], true);
        if (!empty($a['revision_id'])) {
            $r = Revisions::find((int)$a['revision_id']);
            if (!$r || (int)$r['post_id'] !== (int)$post['id']) {
                throw new ToolError("Revision {$a['revision_id']} does not belong to item {$post['id']}.");
            }
            $fields = (array)$r['fields'];
            $design = isset($fields['_html']) && is_array($fields['_html']) ? ['head' => (string)($fields['_html']['head'] ?? ''), 'body' => (string)($fields['_html']['body'] ?? '')] : null;
            unset($fields['_html']);
            $changes = [];
            foreach (Revisions::diff((string)$r['content'], (string)$post['content']) as [$op, $line]) {
                if ($op !== '=' && count($changes) < 200) {
                    $changes[] = ($op === '-' ? '- ' : '+ ') . mb_substr($line, 0, 500);
                }
            }
            return ['id' => (int)$r['id'], 'item_id' => (int)$post['id'], 'saved_at' => self::iso($r['created_at']), 'title' => $r['title'],
                'excerpt' => (string)$r['excerpt'], 'content' => (string)$r['content'], 'fields' => $fields ?: new \stdClass(), 'seo' => $r['seo'] ?: new \stdClass(),
                'design' => $design, 'diff_to_current' => ['legend' => '"- " only in this revision, "+ " only in the current version', 'lines' => $changes]];
        }
        $items = array_map(static fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'saved_at' => self::iso($r['created_at']),
            'by' => $r['display_name'], 'size' => (int)$r['size']], Revisions::list((int)$post['id']));
        return ['item_id' => (int)$post['id'], 'title' => $post['title'], 'revisions' => $items, 'kept' => Revisions::keep()];
    }

    public static function toolRestoreRevision(array $a): array
    {
        self::requireCap('edit_posts', 'edit content');
        $post = self::findPost((int)$a['id'], true);
        $r = Revisions::find((int)$a['revision_id']);
        if (!$r || (int)$r['post_id'] !== (int)$post['id']) {
            throw new ToolError("Revision {$a['revision_id']} does not belong to item {$post['id']}.");
        }
        if (!Revisions::restore((int)$r['id'])) {
            throw new ToolError('Could not restore that revision.');
        }
        $now = Posts::find((int)$post['id']);
        log_activity('mcp_revision_restored', "{$now['type']}:{$now['title']} (revision {$r['id']})");
        return ['restored' => true, 'revision_id' => (int)$r['id'], 'note' => 'The version you replaced was kept as a revision.'] + self::full($now);
    }
}

/** JSON-RPC protocol error (bad method, bad params). */
final class McpError extends \RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}

/** A tool ran but could not do what was asked: returned to the agent as a result with isError. */
final class ToolError extends \RuntimeException
{
}
