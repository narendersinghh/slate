<?php
declare(strict_types=1);

namespace Slate;

/**
 * Scheduled tasks. Core and modules register jobs with the "cron_jobs" filter:
 *
 *   Hooks::add('cron_jobs', fn(array $jobs) => $jobs + ['shop_sync' => ['every' => 3600, 'label' => 'Sync stock', 'run' => fn() => …]]);
 *
 * Jobs run from a real server cron (recommended: `* * * * * php /path/to/slate/bin/slate cron`) or, when no server cron
 * has been seen recently, after visitor requests (once a minute at most, after the page has been sent).
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
            'backup' => ['every' => 3600, 'label' => 'Automatic backups (Settings → System → Backups)', 'run' => [Backup::class, 'scheduled']],
            'module_updates' => ['every' => 43200, 'label' => 'Check for module updates', 'run' => static fn() => ModuleInstaller::updates(true)],
            'integrity' => ['every' => 86400, 'label' => 'File integrity check (emails administrators about tampering)', 'run' => [Integrity::class, 'scheduled']],
        ];
        $jobs = Hooks::filter('cron_jobs', $jobs);
        return array_filter($jobs, static fn($j) => is_array($j) && isset($j['run']) && is_callable($j['run']) && (int)($j['every'] ?? 0) >= 60);
    }

    /** Called at the end of a request: run due jobs unless a server cron is doing it. */
    public static function maybeRunAfterRequest(): void
    {
        if (PHP_SAPI === 'cli' || !DB::connected() || !Options::get('cron_visitor_trigger', true)) {
            return;
        }
        $next = (int)Options::get('cron_next', 0);
        if ($next > time()) {
            return;
        }
        // A server cron ran in the last 5 minutes: leave it to that.
        if ((int)Options::get('cron_server_seen', 0) > time() - 300) {
            return;
        }
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            ignore_user_abort(true);
            try {
                self::run(false, 'visit');
            } catch (\Throwable $e) {
                error_log('Slate cron: ' . $e->getMessage());
            }
        });
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
            Options::set('cron_next', $next === PHP_INT_MAX ? time() + 60 : max(time() + 60, $next));
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
