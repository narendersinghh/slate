<?php
declare(strict_types=1);

namespace Slate;

/**
 * Webhooks: tell other services (Zapier, Make, n8n, your own apps) when something happens on the site.
 * Settings → Integrations → Webhooks. Deliveries are queued, sent right after the request finishes (or by the
 * scheduled task), signed and retried with back-off; the last 30 days are kept in a delivery log.
 *
 * Request: POST JSON {"id","event","created_at","site","data"} with headers
 *   X-Webhook-Event, X-Webhook-Id, X-Webhook-Timestamp,
 *   X-Webhook-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + body), verify it and reject old timestamps.
 *
 * Modules fire their own events with Webhooks::dispatch('shop.order_paid', $data) and list them with the
 * "webhook_events" filter.
 */
final class Webhooks
{
    private const BACKOFF = [60, 300, 1800, 7200, 43200];
    private const TIMEOUT = 8;
    private static array $queued = [];
    private static bool $flushRegistered = false;

    public static function events(): array
    {
        return (array)Hooks::filter('webhook_events', [
            'content.created' => 'Content created',
            'content.updated' => 'Content updated',
            'content.published' => 'Content published',
            'content.unpublished' => 'Content unpublished',
            'content.deleted' => 'Content trashed or deleted',
            'media.uploaded' => 'Media uploaded',
            'form.submitted' => 'Form submitted (Forms module)',
            'comment.created' => 'Comment posted (Comments module)',
            'backup.created' => 'Backup created',
            'security.alert' => 'Security alert (file integrity)',
        ]);
    }

    /** Wire core events. Called once per request from App. */
    public static function boot(): void
    {
        Hooks::add('post_saved', static function (int $id, array $row, bool $existed): void {
            if (in_array($row['status'], ['draft', 'pending', 'trash'], true) && !$existed) {
                return; // drafts become interesting once they are published
            }
            self::dispatch($existed ? 'content.updated' : 'content.created', self::postData($id));
        });
        Hooks::add('post_status_changed', static function (int $id, ?string $old, string $new): void {
            if ($new === 'publish') {
                self::dispatch('content.published', self::postData($id));
            } elseif ($old === 'publish' && $new !== 'trash') {
                self::dispatch('content.unpublished', self::postData($id));
            }
        });
        Hooks::add('post_deleted', static function (int $id, bool $force): void {
            self::dispatch('content.deleted', ['id' => $id, 'permanent' => $force]);
        });
        Hooks::add('media_uploaded', static function (int $id): void {
            $m = Media::find($id);
            if ($m) {
                self::dispatch('media.uploaded', ['id' => $id, 'url' => Media::url($m), 'mime' => $m['mime'], 'alt' => (string)$m['alt']]);
            }
        });
        Hooks::add('backup_created', static fn(array $files) => self::dispatch('backup.created', ['files' => array_map('basename', $files)]));
    }

    /** @return array<int, array{id:string, url:string, events:array, secret:string, active:bool, created:string}> */
    public static function hooks(): array
    {
        Options::lazy('webhooks');
        return array_values(array_filter((array)Options::get('webhooks', []), 'is_array'));
    }

    private static function save(array $hooks): void
    {
        Options::set('webhooks', array_values($hooks), false);
    }

    /** Add a webhook; returns [hook, plain secret]. */
    public static function add(string $url, array $events): array
    {
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $hook = ['id' => bin2hex(random_bytes(6)), 'url' => $url, 'events' => array_values($events), 'secret' => Crypto::encrypt($secret),
            'active' => true, 'created' => now()];
        $hooks = self::hooks();
        $hooks[] = $hook;
        self::save($hooks);
        log_activity('webhook_added', $url);
        return [$hook, $secret];
    }

    public static function remove(string $id): void
    {
        self::save(array_filter(self::hooks(), static fn($h) => $h['id'] !== $id));
        DB::query("DELETE FROM {{webhook_deliveries}} WHERE hook_id = :h AND status = 'pending'", ['h' => $id]);
        log_activity('webhook_removed', $id);
    }

    public static function toggle(string $id): void
    {
        self::save(array_map(static fn($h) => $h['id'] === $id ? ['active' => !$h['active']] + $h : $h, self::hooks()));
    }

    /** Problem with a target URL (scheme, private networks), or null. */
    public static function urlProblem(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Enter a full http(s):// address.';
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if (Options::get('webhooks_allow_private')) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (!$ips) {
            return "Cannot resolve $host.";
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address points to a private or local network. Webhooks only go to public servers.';
            }
        }
        return null;
    }

    /** Queue an event for every active webhook that wants it. */
    public static function dispatch(string $event, array $data): void
    {
        $hooks = array_filter(self::hooks(), static fn($h) => !empty($h['active'])
            && (in_array('*', (array)$h['events'], true) || in_array($event, (array)$h['events'], true)));
        if (!$hooks || !DB::connected()) {
            return;
        }
        foreach ($hooks as $h) {
            $id = 'evt_' . bin2hex(random_bytes(8));
            $payload = json_encode(['id' => $id, 'event' => $event, 'created_at' => gmdate('c'), 'site' => site_url(), 'data' => $data],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            self::$queued[] = DB::insert('webhook_deliveries', ['hook_id' => $h['id'], 'event' => $event, 'payload' => (string)$payload,
                'status' => 'pending', 'attempts' => 0, 'next_at' => now(), 'created_at' => now()]);
        }
        if (!self::$flushRegistered && PHP_SAPI !== 'cli') {
            self::$flushRegistered = true;
            // Send after the response has gone out, so editors never wait for a slow receiver.
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                ignore_user_abort(true);
                try {
                    self::deliver(self::$queued);
                } catch (\Throwable $e) {
                    error_log('Slate webhooks: ' . $e->getMessage());
                }
            });
        }
    }

    /** Scheduled task: send everything due (retries included). */
    public static function run(): void
    {
        $ids = DB::col("SELECT id FROM {{webhook_deliveries}} WHERE status = 'pending' AND next_at <= :now ORDER BY id LIMIT 50", ['now' => now()]);
        self::deliver(array_map('intval', $ids));
        DB::query('DELETE FROM {{webhook_deliveries}} WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }

    /** @param int[] $ids */
    public static function deliver(array $ids): void
    {
        $hooks = [];
        foreach (self::hooks() as $h) {
            $hooks[$h['id']] = $h;
        }
        foreach ($ids as $id) {
            $d = DB::row("SELECT * FROM {{webhook_deliveries}} WHERE id = :id AND status = 'pending'", ['id' => $id]);
            if (!$d) {
                continue;
            }
            $h = $hooks[$d['hook_id']] ?? null;
            if (!$h) {
                DB::update('webhook_deliveries', ['status' => 'failed', 'response' => 'Webhook was removed.'], ['id' => $id]);
                continue;
            }
            self::send($d, $h);
        }
    }

    private static function send(array $d, array $h): void
    {
        $ts = (string)time();
        $body = (string)$d['payload'];
        $sig = hash_hmac('sha256', $ts . '.' . $body, Crypto::decrypt((string)$h['secret']));
        $evtId = (string)(json_decode($body, true)['id'] ?? '');
        $problem = self::urlProblem((string)$h['url']);
        $r = $problem ? ['status' => 0, 'error' => $problem, 'body' => ''] : Http::request('POST', (string)$h['url'], [
            'Content-Type: application/json',
            'X-Webhook-Event: ' . $d['event'],
            'X-Webhook-Id: ' . $evtId,
            'X-Webhook-Timestamp: ' . $ts,
            'X-Webhook-Signature: sha256=' . $sig,
        ], $body, self::TIMEOUT);
        $attempts = (int)$d['attempts'] + 1;
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        $status = $ok ? 'delivered' : ($attempts > count(self::BACKOFF) || $problem ? 'failed' : 'pending');
        DB::update('webhook_deliveries', [
            'status' => $status, 'attempts' => $attempts, 'response_code' => $r['status'] ?: null,
            'response' => mb_substr($r['error'] ?: (string)$r['body'], 0, 500),
            'next_at' => gmdate('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts - 1] ?? 0)),
        ], ['id' => $d['id']]);
    }

    /** Send a test event to one webhook right away. Returns [ok, message]. */
    public static function ping(string $hookId): array
    {
        $h = null;
        foreach (self::hooks() as $x) {
            if ($x['id'] === $hookId) {
                $h = $x;
            }
        }
        if (!$h) {
            return [false, 'Webhook not found.'];
        }
        $payload = json_encode(['id' => 'evt_test_' . bin2hex(random_bytes(4)), 'event' => 'ping', 'created_at' => gmdate('c'), 'site' => site_url(),
            'data' => ['message' => 'Test delivery from ' . Options::get('site_title', 'your site')]], JSON_UNESCAPED_SLASHES);
        $id = DB::insert('webhook_deliveries', ['hook_id' => $hookId, 'event' => 'ping', 'payload' => (string)$payload, 'status' => 'pending',
            'attempts' => count(self::BACKOFF), 'next_at' => now(), 'created_at' => now()]);
        self::deliver([$id]);
        $row = DB::row('SELECT * FROM {{webhook_deliveries}} WHERE id = :id', ['id' => $id]);
        return [$row['status'] === 'delivered', $row['status'] === 'delivered'
            ? 'Delivered (HTTP ' . $row['response_code'] . ').' : 'Failed: ' . ($row['response_code'] ? 'HTTP ' . $row['response_code'] . ' ' : '') . $row['response']];
    }

    public static function retry(int $deliveryId): void
    {
        DB::update('webhook_deliveries', ['status' => 'pending', 'attempts' => 0, 'next_at' => now()], ['id' => $deliveryId]);
        self::deliver([$deliveryId]);
    }

    public static function recent(int $limit = 30): array
    {
        return DB::all('SELECT id, hook_id, event, status, attempts, response_code, response, created_at, next_at FROM {{webhook_deliveries}} ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    private static function postData(int $id): array
    {
        $p = Posts::find($id);
        if (!$p) {
            return ['id' => $id];
        }
        return ['id' => (int)$p['id'], 'type' => $p['type'], 'title' => $p['title'], 'slug' => $p['slug'], 'status' => $p['status'],
            'url' => Posts::permalink($p, true), 'published_at' => $p['published_at'], 'updated_at' => $p['updated_at'], 'author_id' => $p['author_id'] ? (int)$p['author_id'] : null];
    }
}
