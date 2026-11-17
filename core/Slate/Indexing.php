<?php
declare(strict_types=1);

namespace Slate;

/**
 * Instant indexing (SEO › Instant indexing): tells search engines about new, changed and deleted URLs.
 *
 *  - Google Indexing API with a service account (JSON key stored encrypted; JWT signed with RS256 through openssl,
 *    access token cached). Google supports it officially for JobPosting and BroadcastEvent pages only.
 *  - IndexNow (Bing, Yandex, Seznam, Naver…): key generated automatically and served at /{key}.txt.
 *
 * Submissions are queued in {{seo_indexing}} and sent after the response has gone out (or by the scheduled task),
 * so saving never waits for a search engine. Google's daily quota is respected, failures are retried with back-off,
 * and the last 30 days are kept as a log. Nothing is ever queued or sent while the site is noindexed, or for items
 * that are noindexed, not public or not published.
 *
 * Endpoints can be pointed elsewhere only from config.php (for tests): 'seo_endpoints' => ['indexnow' => '…', …].
 */
final class Indexing
{
    private const ENDPOINTS = [
        'google_token' => 'https://oauth2.googleapis.com/token',
        'google_publish' => 'https://indexing.googleapis.com/v3/urlNotifications:publish',
        'google_metadata' => 'https://indexing.googleapis.com/v3/urlNotifications/metadata',
        'google_webmasters' => 'https://www.googleapis.com/webmasters/v3',
        'indexnow' => 'https://api.indexnow.org/indexnow',
    ];
    private const GOOGLE_AUD = 'https://oauth2.googleapis.com/token';
    private const SCOPES = 'https://www.googleapis.com/auth/indexing https://www.googleapis.com/auth/webmasters';
    private const BACKOFF = [300, 1800, 7200, 43200];
    private const TIMEOUT = 10;
    private const KEEP_DAYS = 30;

    private static array $queued = [];
    private static bool $flushRegistered = false;

    public static function endpoint(string $name): string
    {
        $over = defined('SLATE_CONFIG') ? (SLATE_CONFIG['seo_endpoints'][$name] ?? null) : null;
        return is_string($over) && $over !== '' ? $over : self::ENDPOINTS[$name];
    }

    /** True when config.php points the endpoints elsewhere (test sites). */
    public static function testEndpoints(): bool
    {
        return defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['seo_endpoints']);
    }

    // ------------------------------------------------------------------ configuration

    public static function indexNowEnabled(): bool
    {
        return (bool)Options::get('seo_indexnow_enabled');
    }

    public static function googleEnabled(): bool
    {
        return Options::get('seo_google_indexing') && self::googleEmail() !== '';
    }

    public static function enabled(): bool
    {
        return self::indexNowEnabled() || self::googleEnabled();
    }

    /** The IndexNow key (created on first use), or '' while IndexNow is off. */
    public static function indexNowKey(): string
    {
        if (!self::indexNowEnabled()) {
            return '';
        }
        $key = (string)Options::get('seo_indexnow_key', '');
        if (!preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            Options::set('seo_indexnow_key', $key);
        }
        return $key;
    }

    public static function regenerateIndexNowKey(): void
    {
        Options::set('seo_indexnow_key', bin2hex(random_bytes(16)));
    }

    /** Post types submitted automatically; null when never chosen (= every public type). */
    public static function autoTypes(): array
    {
        $saved = Options::get('seo_indexing_types');
        $public = array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])));
        return is_array($saved) ? array_values(array_intersect($public, $saved)) : $public;
    }

    public static function googleEmail(): string
    {
        return (string)Options::get('seo_google_sa_email', '');
    }

    /** The decrypted service account key, or null. */
    private static function googleKey(): ?array
    {
        Options::lazy('seo_google_sa');
        $json = Crypto::decrypt((string)Options::get('seo_google_sa', ''));
        $key = $json !== '' ? json_decode($json, true) : null;
        return is_array($key) && !empty($key['client_email']) && !empty($key['private_key']) ? $key : null;
    }

    /** Store a service account JSON key. Returns an error message or null. */
    public static function setGoogleKey(string $json): ?string
    {
        $key = json_decode(trim($json), true);
        if (!is_array($key)) {
            return 'That is not valid JSON. Paste the whole key file you downloaded from Google Cloud.';
        }
        if (($key['type'] ?? '') !== 'service_account' || empty($key['client_email']) || empty($key['private_key'])) {
            return 'That JSON is not a service account key (it needs "type": "service_account", "client_email" and "private_key").';
        }
        if (!function_exists('openssl_pkey_get_private') || !openssl_pkey_get_private((string)$key['private_key'])) {
            return 'The private key in that file cannot be read.';
        }
        $keep = array_intersect_key($key, array_flip(['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id']));
        Options::set('seo_google_sa', Crypto::encrypt((string)json_encode($keep, JSON_UNESCAPED_SLASHES)), false);
        Options::set('seo_google_sa_email', (string)$key['client_email']);
        Options::set('seo_google_token', '', false);
        log_activity('seo_google_key_saved', (string)$key['client_email']);
        return null;
    }

    public static function removeGoogleKey(): void
    {
        Options::set('seo_google_sa', '', false);
        Options::set('seo_google_sa_email', '');
        Options::set('seo_google_token', '', false);
        log_activity('seo_google_key_removed');
    }

    // ------------------------------------------------------------------ rules

    /** Why a post must not be submitted, or null when it may. Without a post only the site-wide rules apply. */
    public static function blocked(?array $post): ?string
    {
        if (Options::get('seo_noindex_site')) {
            return 'The website is set to noindex, so nothing is sent to search engines.';
        }
        if (!$post) {
            return null;
        }
        $type = ContentModel::type((string)$post['type']);
        if (!$type || empty($type['public'])) {
            return 'This content type is not public.';
        }
        if ($post['status'] !== 'publish') {
            return 'Only published items can be submitted.';
        }
        $robots = ($post['seo']['robots_index'] ?? 'default') === 'default' ? Seo::typeDefaults((string)$post['type'])['robots'] : $post['seo']['robots_index'];
        if ($robots === 'noindex') {
            return ($post['seo']['robots_index'] ?? 'default') === 'noindex' ? 'This item is set to noindex.' : 'This content type is set to noindex.';
        }
        $canonical = (string)($post['seo']['canonical'] ?? '');
        if ($canonical !== '' && rtrim($canonical, '/') !== rtrim(Posts::permalink($post, true), '/')) {
            return 'This item\'s canonical URL points to another address.';
        }
        return null;
    }

    /** May this URL be submitted (http(s) on this website's host)? */
    public static function ownUrl(string $url): bool
    {
        $host = strtolower((string)parse_url(site_url(), PHP_URL_HOST));
        return (bool)preg_match('#^https?://#i', $url) && strtolower((string)parse_url($url, PHP_URL_HOST)) === $host && filter_var($url, FILTER_VALIDATE_URL);
    }

    // ------------------------------------------------------------------ automatic submissions

    /** Wire content events. Called once per request from App. */
    public static function boot(): void
    {
        if (!self::enabled() || !Options::get('seo_indexing_auto', true)) {
            return;
        }
        Hooks::add('post_saved', static function (int $id): void {
            $p = Posts::find($id);
            if ($p && $p['status'] === 'publish') {
                self::auto($p, 'URL_UPDATED');
            }
        });
        Hooks::add('post_status_changed', static function (int $id, ?string $old, string $new): void {
            if ($old === 'publish' && $new !== 'publish' && ($p = Posts::find($id))) {
                self::auto($p, 'URL_DELETED');
            }
        });
        Hooks::add('post_deleted', static function (int $id, bool $force): void {
            // Moved to the trash (status changed without a status event). Permanent deletes of trashed items were sent then.
            $p = $force ? null : Posts::find($id);
            if ($p && $p['status'] === 'trash' && !empty($p['published_at']) && strtotime($p['published_at'] . ' UTC') <= time()) {
                self::auto($p, 'URL_DELETED');
            }
        });
        Hooks::add('scheduled_published', static function (array $ids): void {
            Posts::prime(array_map('intval', $ids));
            foreach ($ids as $id) {
                if (($p = Posts::find((int)$id)) && $p['status'] === 'publish') {
                    self::auto($p, 'URL_UPDATED');
                }
            }
        });
    }

    private static function auto(array $post, string $action): void
    {
        if (!in_array($post['type'], self::autoTypes(), true)) {
            return;
        }
        if ($action === 'URL_UPDATED') {
            if (self::blocked($post) !== null) {
                return;
            }
        } else {
            // A removed URL is reported unless the site, the type or the item asked search engines to stay away.
            $type = ContentModel::type((string)$post['type']);
            $robots = ($post['seo']['robots_index'] ?? 'default') === 'default' ? Seo::typeDefaults((string)$post['type'])['robots'] : $post['seo']['robots_index'];
            if (Options::get('seo_noindex_site') || !$type || empty($type['public']) || $robots === 'noindex') {
                return;
            }
        }
        $url = Posts::permalink($post, true);
        if (self::ownUrl($url)) {
            self::queue([$url], $action, (int)$post['id'], 'auto');
        }
    }

    // ------------------------------------------------------------------ queue

    /**
     * Queue URLs for every enabled engine. Returns the queue row ids.
     * @param string[] $urls
     */
    public static function queue(array $urls, string $action = 'URL_UPDATED', ?int $postId = null, string $source = 'auto'): array
    {
        if (!DB::connected() || Options::get('seo_noindex_site')) {
            return [];
        }
        $action = $action === 'URL_DELETED' ? 'URL_DELETED' : 'URL_UPDATED';
        $engines = array_keys(array_filter(['google' => self::googleEnabled(), 'indexnow' => self::indexNowEnabled()]));
        $ids = [];
        foreach (array_unique($urls) as $url) {
            foreach ($engines as $engine) {
                $existing = DB::val("SELECT id FROM {{seo_indexing}} WHERE status = 'pending' AND engine = :e AND url = :u", ['e' => $engine, 'u' => $url]);
                if ($existing) {
                    DB::update('seo_indexing', ['action' => $action, 'source' => $source, 'next_at' => now(), 'attempts' => 0, 'post_id' => $postId], ['id' => (int)$existing]);
                    $ids[] = (int)$existing;
                    continue;
                }
                $ids[] = DB::insert('seo_indexing', ['engine' => $engine, 'action' => $action, 'url' => mb_substr($url, 0, 2048), 'post_id' => $postId,
                    'source' => $source, 'status' => 'pending', 'attempts' => 0, 'next_at' => now(), 'created_at' => now()]);
            }
        }
        if ($ids && $source === 'auto') {
            self::$queued = array_merge(self::$queued, $ids);
            self::flushAfterResponse();
        }
        return $ids;
    }

    /** Send what was queued in this request after the response has gone out. */
    private static function flushAfterResponse(): void
    {
        if (self::$flushRegistered || PHP_SAPI === 'cli') {
            return;
        }
        self::$flushRegistered = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            ignore_user_abort(true);
            try {
                self::process(self::$queued, 20);
            } catch (\Throwable $e) {
                error_log('Slate indexing: ' . $e->getMessage());
            }
        });
    }

    /** Scheduled task: send everything due, retries included, and forget old log entries. */
    public static function cron(): void
    {
        if (DB::val("SELECT COUNT(*) FROM {{seo_indexing}} WHERE status = 'pending'")) {
            self::process(null, 100);
        }
        DB::query('DELETE FROM {{seo_indexing}} WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
    }

    /**
     * Send due queue entries (all of them, or only $ids). At most $budget Google requests per run.
     * @param int[]|null $ids
     */
    public static function process(?array $ids = null, int $budget = 25): void
    {
        @mkdir(SLATE_CACHE, 0755, true);
        $lock = @fopen(SLATE_CACHE . '/seo-indexing.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        try {
            if (Options::get('seo_noindex_site')) {
                DB::query("UPDATE {{seo_indexing}} SET status = 'skipped', response = :r WHERE status = 'pending'", ['r' => 'The website is set to noindex.']);
                return;
            }
            foreach (['google' => self::googleEnabled(), 'indexnow' => self::indexNowEnabled()] as $engine => $on) {
                if (!$on) {
                    DB::query("UPDATE {{seo_indexing}} SET status = 'skipped', response = :r WHERE status = 'pending' AND engine = :e",
                        ['r' => 'This search engine was turned off.', 'e' => $engine]);
                }
            }
            $where = "status = 'pending' AND next_at <= :now";
            $params = ['now' => now()];
            if ($ids !== null) {
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!$ids) {
                    return;
                }
                [$in, $p] = DB::in($ids);
                $where .= " AND id IN ($in)";
                $params += $p;
            }
            if (self::googleEnabled()) {
                $left = min($budget, self::googleRemaining());
                if ($left > 0) {
                    $rows = DB::all("SELECT * FROM {{seo_indexing}} WHERE $where AND engine = 'google' ORDER BY id LIMIT $left", $params);
                    self::sendGoogle(self::recheck($rows));
                }
            }
            if (self::indexNowEnabled()) {
                $rows = DB::all("SELECT * FROM {{seo_indexing}} WHERE $where AND engine = 'indexnow' ORDER BY id LIMIT 5000", $params);
                self::sendIndexNow(self::recheck($rows));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Drop entries whose item can no longer be submitted (unpublished, noindexed…) since they were queued. */
    private static function recheck(array $rows): array
    {
        Posts::prime(array_filter(array_map('intval', array_column($rows, 'post_id'))));
        $keep = [];
        foreach ($rows as $r) {
            if ($r['action'] === 'URL_UPDATED' && $r['post_id']) {
                $p = Posts::find((int)$r['post_id']);
                $why = $p ? self::blocked($p) : 'The item was deleted.';
                if ($why !== null) {
                    DB::update('seo_indexing', ['status' => 'skipped', 'response' => $why], ['id' => (int)$r['id']]);
                    continue;
                }
            }
            $keep[] = $r;
        }
        return $keep;
    }

    /** Google requests made in the last 24 hours. */
    public static function googleUsed(): int
    {
        return (int)DB::val("SELECT COUNT(*) FROM {{seo_indexing}} WHERE engine = 'google' AND sent_at >= :t", ['t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    public static function googleRemaining(): int
    {
        return max(0, (int)Options::get('seo_google_quota', 200) - self::googleUsed());
    }

    /** Record the outcome of an attempt. */
    private static function result(array $row, bool $ok, int $code, string $message, bool $retry): void
    {
        $attempts = (int)$row['attempts'] + 1;
        $status = $ok ? 'sent' : ($retry && $attempts <= count(self::BACKOFF) ? 'pending' : 'failed');
        DB::update('seo_indexing', ['status' => $status, 'attempts' => $attempts, 'response_code' => $code ?: null,
            'response' => mb_substr($message, 0, 500), 'sent_at' => now(),
            'next_at' => gmdate('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts - 1] ?? 0))], ['id' => (int)$row['id']]);
    }

    private static function errorText(array $r): string
    {
        $j = json_decode((string)$r['body'], true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? $j['error_description'] ?? $j['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '')) : '';
        return $msg !== '' ? $msg : ($r['error'] ?: trim(strip_tags((string)$r['body'])) ?: 'HTTP ' . $r['status']);
    }

    // ------------------------------------------------------------------ Google

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Signed JWT assertion for the service account (RS256). */
    public static function jwt(array $key, int $now): string
    {
        $head = self::b64url((string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url((string)json_encode(['iss' => $key['client_email'], 'scope' => self::SCOPES, 'aud' => self::GOOGLE_AUD,
            'iat' => $now, 'exp' => $now + 3600], JSON_UNESCAPED_SLASHES));
        $pk = openssl_pkey_get_private((string)$key['private_key']);
        if (!$pk || !openssl_sign("$head.$claims", $sig, $pk, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Cannot sign with the service account key.');
        }
        return "$head.$claims." . self::b64url($sig);
    }

    /** Access token (cached until shortly before it expires). Returns [token, error]. */
    private static function googleToken(bool $fresh = false): array
    {
        $key = self::googleKey();
        if (!$key) {
            return [null, 'No service account key is saved.'];
        }
        Options::lazy('seo_google_token');
        $cached = Options::get('seo_google_token');
        if (!$fresh && is_array($cached) && (int)($cached['exp'] ?? 0) > time() + 120 && ($cached['for'] ?? '') === $key['client_email']) {
            $t = Crypto::decrypt((string)$cached['token']);
            if ($t !== '') {
                return [$t, null];
            }
        }
        try {
            $jwt = self::jwt($key, time());
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
        $r = Http::request('POST', self::endpoint('google_token'), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]), self::TIMEOUT);
        $j = json_decode((string)$r['body'], true);
        if ($r['status'] !== 200 || empty($j['access_token'])) {
            return [null, 'Google sign-in failed: ' . self::errorText($r)];
        }
        Options::set('seo_google_token', ['token' => Crypto::encrypt((string)$j['access_token']), 'exp' => time() + (int)($j['expires_in'] ?? 3600),
            'for' => $key['client_email']], false);
        return [(string)$j['access_token'], null];
    }

    private static function googleCall(string $method, string $url, ?array $payload = null): array
    {
        [$token, $err] = self::googleToken();
        if (!$token) {
            return ['status' => 0, 'body' => '', 'error' => $err, 'headers' => []];
        }
        $r = Http::json($method, $url, ['Authorization: Bearer ' . $token], $payload, self::TIMEOUT);
        if ($r['status'] === 401) {
            [$token, $err] = self::googleToken(true);
            if (!$token) {
                return ['status' => 0, 'body' => '', 'error' => $err, 'headers' => []];
            }
            $r = Http::json($method, $url, ['Authorization: Bearer ' . $token], $payload, self::TIMEOUT);
        }
        return $r;
    }

    private static function sendGoogle(array $rows): void
    {
        foreach ($rows as $row) {
            $r = self::googleCall('POST', self::endpoint('google_publish'), ['url' => $row['url'], 'type' => $row['action']]);
            $code = (int)$r['status'];
            if ($code === 200) {
                self::result($row, true, $code, 'Accepted by Google.', false);
            } elseif ($code === 429) {
                // Quota exhausted: try again in an hour, stop for now.
                DB::update('seo_indexing', ['response_code' => 429, 'response' => 'Google quota reached; waiting.', 'sent_at' => now(),
                    'next_at' => gmdate('Y-m-d H:i:s', time() + 3600)], ['id' => (int)$row['id']]);
                return;
            } elseif ($code === 403) {
                self::result($row, false, $code, 'Permission denied: add ' . self::googleEmail() . ' as an Owner of this site in Search Console (Settings, Users and permissions). ' . self::errorText($r), false);
            } elseif ($code >= 400 && $code < 500) {
                self::result($row, false, $code, self::errorText($r), false);
            } else {
                self::result($row, false, $code, self::errorText($r), true);
            }
        }
    }

    /** Check the key and access. Returns [ok, message]. */
    public static function testGoogle(): array
    {
        [$token, $err] = self::googleToken(true);
        if (!$token) {
            return [false, (string)$err];
        }
        $r = Http::request('GET', self::endpoint('google_metadata') . '?url=' . rawurlencode(site_url()), ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, self::TIMEOUT);
        return match (true) {
            $r['status'] === 200, $r['status'] === 404 => [true, 'Connected as ' . self::googleEmail() . '. Google accepts indexing requests for this site.'],
            $r['status'] === 403 => [false, 'Signed in as ' . self::googleEmail() . ', but it may not submit this site. In Search Console, open Settings, Users and permissions, and add that address as an Owner. Also make sure the Web Search Indexing API is enabled in Google Cloud. (' . self::errorText($r) . ')'],
            default => [false, 'Google answered: ' . self::errorText($r)],
        };
    }

    /** Search Console property used for the sitemap. */
    public static function gscProperty(): string
    {
        $p = trim((string)Options::get('seo_gsc_property', ''));
        return $p !== '' ? $p : site_url();
    }

    /** Submit the XML sitemap to Search Console. Returns [ok, message]. */
    public static function submitSitemap(): array
    {
        if (self::blocked(null) !== null) {
            return [false, (string)self::blocked(null)];
        }
        if (!self::googleKey()) {
            return [false, 'Save a Google service account key first.'];
        }
        if (!Options::get('seo_sitemap_enabled', true)) {
            return [false, 'The XML sitemap is turned off (SEO, XML sitemap).'];
        }
        $url = self::endpoint('google_webmasters') . '/sites/' . rawurlencode(self::gscProperty()) . '/sitemaps/' . rawurlencode(site_url('sitemap.xml'));
        $r = self::googleCall('PUT', $url);
        if ($r['status'] >= 200 && $r['status'] < 300) {
            Options::set('seo_gsc_sitemap_sent', now());
            return [true, 'Sitemap submitted to Search Console (' . self::gscProperty() . ').'];
        }
        return [false, $r['status'] === 403
            ? 'Search Console refused: add ' . self::googleEmail() . ' as an Owner of ' . self::gscProperty() . ' and enable the Search Console API in Google Cloud.'
            : 'Search Console answered: ' . self::errorText($r)];
    }

    // ------------------------------------------------------------------ IndexNow

    private static function sendIndexNow(array $rows): void
    {
        $key = self::indexNowKey();
        if (!$rows || $key === '') {
            return;
        }
        $host = (string)parse_url(site_url(), PHP_URL_HOST);
        foreach (array_chunk($rows, 10000) as $chunk) {
            $payload = ['host' => $host, 'key' => $key, 'keyLocation' => site_url($key . '.txt'), 'urlList' => array_values(array_unique(array_column($chunk, 'url')))];
            $r = Http::json('POST', self::endpoint('indexnow'), [], $payload, self::TIMEOUT);
            $code = (int)$r['status'];
            [$ok, $msg, $retry] = match (true) {
                $code === 200, $code === 202 => [true, $code === 202 ? 'Received; the key is being checked.' : 'Accepted.', false],
                $code === 403 => [false, 'Key not accepted: ' . site_url($key . '.txt') . ' must be reachable.', true],
                $code === 422 => [false, 'Rejected: the addresses do not belong to ' . $host . '.', false],
                $code === 400 => [false, 'Bad request: ' . self::errorText($r), false],
                default => [false, self::errorText($r), true],
            };
            foreach ($chunk as $row) {
                self::result($row, $ok, $code, $msg, $retry);
            }
        }
    }

    // ------------------------------------------------------------------ on demand

    /**
     * Submit now (editor button, list of addresses). Returns [ok, message].
     * @param string[] $urls
     */
    public static function request(array $urls, ?array $post = null, string $action = 'URL_UPDATED'): array
    {
        if (!self::enabled()) {
            return [false, 'Turn on IndexNow or Google under SEO, Instant indexing first.'];
        }
        if ($why = self::blocked($post)) {
            return [false, $why];
        }
        $urls = array_values(array_unique(array_filter($urls, [self::class, 'ownUrl'])));
        if (!$urls) {
            return [false, 'No valid addresses on this website.'];
        }
        $ids = self::queue($urls, $action, $post ? (int)$post['id'] : null, 'manual');
        self::process($ids, 100);
        $parts = [];
        $ok = false;
        foreach (['google' => 'Google', 'indexnow' => 'IndexNow'] as $engine => $label) {
            [$in, $p] = DB::in($ids ?: [0]);
            $rows = DB::all("SELECT status, response FROM {{seo_indexing}} WHERE engine = :e AND id IN ($in)", ['e' => $engine] + $p);
            if (!$rows) {
                continue;
            }
            $by = array_count_values(array_column($rows, 'status'));
            $sent = (int)($by['sent'] ?? 0);
            $ok = $ok || $sent > 0;
            $msg = $sent === count($rows) ? ($sent > 1 ? "$sent sent" : 'sent') : ($sent ? "$sent of " . count($rows) . ' sent' : '');
            if (!empty($by['pending'])) {
                $msg .= ($msg ? ', ' : '') . $by['pending'] . ' waiting (quota or retry)';
            }
            if (!empty($by['failed']) || !empty($by['skipped'])) {
                $last = '';
                foreach ($rows as $r) {
                    if (in_array($r['status'], ['failed', 'skipped'], true)) {
                        $last = (string)$r['response'];
                    }
                }
                $msg .= ($msg ? ', ' : '') . (($by['failed'] ?? 0) + ($by['skipped'] ?? 0)) . ' not sent: ' . $last;
            }
            $parts[] = "$label: $msg";
            $ok = $ok || !empty($by['pending']);
        }
        return [$ok, implode(' ', array_map(static fn($s) => rtrim($s, '.') . '.', $parts))];
    }

    // ------------------------------------------------------------------ log

    public static function recent(int $limit = 50): array
    {
        return DB::all('SELECT * FROM {{seo_indexing}} ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    /** Latest entry for a post (editor panel). */
    public static function lastFor(int $postId): ?array
    {
        return DB::row('SELECT * FROM {{seo_indexing}} WHERE post_id = :p ORDER BY id DESC LIMIT 1', ['p' => $postId]);
    }

    public static function counts(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (DB::all('SELECT status, COUNT(*) c FROM {{seo_indexing}} GROUP BY status') as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    public static function clearPending(): int
    {
        return DB::query("UPDATE {{seo_indexing}} SET status = 'skipped', response = 'Removed from the queue.' WHERE status = 'pending'")->rowCount();
    }

    public static function retryFailed(): int
    {
        return DB::query("UPDATE {{seo_indexing}} SET status = 'pending', attempts = 0, next_at = :n WHERE status = 'failed' AND created_at >= :t",
            ['n' => now(), 't' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->rowCount();
    }

    /** Save the custom part of the settings screen (key, post types). Returns an error or null. */
    public static function saveAdmin(array $in): ?string
    {
        $types = array_values(array_intersect(array_map('strval', (array)($in['types'] ?? [])),
            array_keys(array_filter(ContentModel::types(), static fn($t) => !empty($t['public'])))));
        if (isset($in['types_present'])) {
            Options::set('seo_indexing_types', $types);
        }
        $json = trim((string)($in['google_key'] ?? ''));
        return $json !== '' ? self::setGoogleKey($json) : null;
    }
}
