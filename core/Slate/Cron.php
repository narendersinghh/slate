<?php
declare(strict_types=1);

namespace Slate;

/**
 * Scheduled tasks, automatic, no setup needed. Core and modules register jobs with the "cron_jobs" filter:
 *
 *   Hooks::add('cron_jobs', fn(array $jobs) => $jobs + ['shop_sync' => ['every' => 3600, 'label' => 'Sync stock', 'run' => fn() => …]]);
 *
 * Due jobs run right after a page has been sent to a visitor (cached pages included), so nobody waits for them.
 * Where the server cannot finish a response early, Slate triggers itself with a short background request instead.
 * A real server cron (`php bin/slate cron` every few minutes) is optional: it only adds punctuality on quiet sites.
 */
final class Cron
{
    private const LOCK = SLATE_CACHE . '/cron.lock';
    private const MAX_RUNTIME = 120;

    /** @return array<string, array{every:int, label:string, run:callable}> */
    public static function jobs(): array
    {
        $jobs = [
            'publish_scheduled' => ['every' => 60, 'label' => 'Publish scheduled content', 'run' => [Posts::class, 'publishScheduled']],
            'cleanup' => ['every' => 86400, 'label' => 'Clean up old login attempts, activity and temporary files', 'run' => [self::class, 'cleanup']],
            'backup' => ['every' => 3600, 'label' => 'Automatic backups (Settings › System › Backups)', 'run' => [Backup::class, 'scheduled']],
            'module_updates' => ['every' => 43200, 'label' => 'Check for module updates', 'run' => static fn() => ModuleInstaller::updates(true)],
            'cache_warm' => ['every' => 300, 'label' => 'Re-warm the page cache after changes', 'run' => static fn() => PageCache::warm()],
            'cache_sweep' => ['every' => 3600, 'label' => 'Remove outdated cached pages', 'run' => static fn() => PageCache::sweep()],
            'webhooks' => ['every' => 60, 'label' => 'Send queued webhook deliveries and retries', 'run' => [Webhooks::class, 'run']],
            'seo_indexing' => ['every' => 60, 'label' => 'Send search engine indexing requests (SEO › Instant indexing)', 'run' => [Indexing::class, 'cron']],
            'integrity' => ['every' => 86400, 'label' => 'File integrity check (emails administrators about tampering)', 'run' => [Integrity::class, 'scheduled']],
        ];
        $jobs = Hooks::filter('cron_jobs', $jobs);
        return array_filter($jobs, static fn($j) => is_array($j) && isset($j['run']) && is_callable($j['run']) && (int)($j['every'] ?? 0) >= 60);
    }

    private const NEXT = SLATE_CACHE . '/cron-next';

    /** Due according to the marker file (no database needed), and not already claimed by another request. */
    private static function due(): bool
    {
        $next = @file_get_contents(self::NEXT);
        return $next === false || (int)$next <= time();
    }

    /** Mark the next minute as taken so concurrent visitors do not all start a run. */
    private static function claim(): bool
    {
        @mkdir(SLATE_CACHE, 0755, true);
        $fp = @fopen(self::NEXT, 'c+');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $next = (int)stream_get_contents($fp);
        $ok = $next <= time();
        if ($ok) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)(time() + 60));
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $ok;
    }

    private static function automatic(): bool
    {
        return PHP_SAPI !== 'cli' && (!DB::connected() || Options::get('cron_visitor_trigger', true));
    }

    /** Send the response to the visitor now and keep working. False when the server cannot do that. */
    private static function finishResponse(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return @fastcgi_finish_request();
        }
        if (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            return true;
        }
        return false;
    }

    /** Called at the end of a normal (uncached) request. */
    public static function maybeRunAfterRequest(): void
    {
        if (!self::automatic() || !self::due()) {
            return;
        }
        register_shutdown_function(static function (): void {
            if (!self::claim()) {
                return;
            }
            if (!self::finishResponse()) {
                self::spawn();
                return;
            }
            ignore_user_abort(true);
            try {
                self::run(false, 'visit');
            } catch (\Throwable $e) {
                error_log('Slate cron: ' . $e->getMessage());
            }
        });
    }

    /** Called after a cached page was sent (no database connection yet). */
    public static function afterCachedPage(): void
    {
        if (PHP_SAPI === 'cli' || !self::due() || !defined('SLATE_CONFIG') || !self::claim()) {
            return;
        }
        if (!self::finishResponse()) {
            self::spawn();
            return;
        }
        ignore_user_abort(true);
        try {
            DB::connect(SLATE_CONFIG['db']);
            Options::load();
            if (!Options::get('cron_visitor_trigger', true)) {
                return;
            }
            Modules::boot();
            self::run(false, 'visit');
        } catch (\Throwable $e) {
            error_log('Slate cron: ' . $e->getMessage());
        }
    }

    /** Secret path that runs due jobs (used by the self-trigger; also usable by external uptime/cron services). */
    public static function webPath(): string
    {
        return '/__tasks/' . substr(Crypto::hmac('scheduled-tasks'), 0, 24);
    }

    /** Fire-and-forget request to webPath() for servers that cannot finish a response early. */
    private static function spawn(): void
    {
        if (!function_exists('curl_init') || !defined('SLATE_CONFIG')) {
            return;
        }
        $ch = curl_init(rtrim((string)(SLATE_CONFIG['url'] ?? site_url()), '/') . self::webPath());
        curl_setopt_array($ch, [CURLOPT_TIMEOUT_MS => 300, CURLOPT_CONNECTTIMEOUT_MS => 300, CURLOPT_NOSIGNAL => true,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'tasks']);
        @curl_exec($ch);
        unset($ch);
    }

    /** Handler for webPath(). */
    public static function handleWeb(): void
    {
        ignore_user_abort(true);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        http_response_code(204);
        self::finishResponse();
        try {
            self::run(false, 'visit');
        } catch (\Throwable $e) {
            error_log('Slate cron: ' . $e->getMessage());
        }
        exit;
    }

    /**
     * Run every due job (or all with $force). Returns [job => 'ok' | 'skipped' | error message].
     * A file lock makes sure only one runner works at a time.
     */
    public static function run(bool $force = false, string $trigger = 'server', ?string $only = null): array
    {
        if ($trigger === 'server') {
            Options::set('cron_server_seen', time());
        }
        @mkdir(SLATE_CACHE, 0755, true);
        $lock = @fopen(self::LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['_' => 'Another run is in progress.'];
        }
        @set_time_limit(self::MAX_RUNTIME + 60);
        Options::lazy('cron_state');
        $state = (array)Options::get('cron_state', []);
        $results = [];
        $start = time();
        $next = PHP_INT_MAX;
        try {
            foreach (self::jobs() as $name => $job) {
                $every = (int)$job['every'];
                $last = (int)($state[$name]['last'] ?? 0);
                $due = $force || $last + $every <= time();
                if ($only !== null && $name !== $only) {
                    $due = false;
                }
                if (!$due || time() - $start > self::MAX_RUNTIME) {
                    $results[$name] = 'skipped';
                    $next = min($next, $last + $every);
                    continue;
                }
                $t = microtime(true);
                try {
                    ($job['run'])();
                    $state[$name] = ['last' => time(), 'ok' => true, 'ms' => (int)((microtime(true) - $t) * 1000)];
                    $results[$name] = 'ok';
                } catch (\Throwable $e) {
                    $state[$name] = ['last' => time(), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'ms' => (int)((microtime(true) - $t) * 1000)];
                    $results[$name] = $e->getMessage();
                    error_log("Slate cron job $name failed: " . $e->getMessage());
                }
                $next = min($next, time() + $every);
            }
            Options::set('cron_state', $state, false);
            $nextAt = $next === PHP_INT_MAX ? time() + 60 : max(time() + 60, $next);
            Options::set('cron_next', $nextAt);
            @file_put_contents(self::NEXT, (string)$nextAt, LOCK_EX);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $results;
    }

    public static function state(): array
    {
        Options::lazy('cron_state');
        return (array)Options::get('cron_state', []);
    }

    /** Daily housekeeping. */
    public static function cleanup(): void
    {
        $days = max(7, (int)Options::get('activity_keep_days', 180));
        DB::query('DELETE FROM {{login_attempts}} WHERE attempted_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        DB::query('DELETE FROM {{activity_log}} WHERE created_at < :t', ['t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        // Leftover temporary folders from interrupted installs/uploads (older than a day).
        foreach (glob(SLATE_CACHE . '/{module-,module-scan-,update-}*', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 86400) {
                Updater::rrmdir($dir);
            }
        }
        // Drafts from the AI module builder nobody installed within a week.
        foreach (DB::col("SELECT name FROM {{options}} WHERE name LIKE 'module!_draft!_%' ESCAPE '!'") as $name) {
            Options::lazy($name);
            $d = Options::get($name);
            if (!is_array($d) || (int)($d['created'] ?? 0) < time() - 7 * 86400) {
                Options::delete($name);
            }
        }
    }
}
