<?php
declare(strict_types=1);

namespace Slate;

/**
 * Automatic, safe module updates.
 *
 * Modules installed from a registry or a source URL ("source" in module.json) are checked twice a day (every 12 hours,
 * scheduled task "module_updates") and updated one at a time, at most MAX_PER_RUN per run. Local modules (no "source",
 * or a git checkout) are never touched. Every update, automatic or manual ("Try again", CLI `module:update`), goes
 * through the same pipeline, recorded step by step in an update log (content/backups/modules/logs/):
 *
 *   1. Preflight  download, sha256 (when published), unpack to a staging folder, manifest, requirements (Slate, PHP,
 *                 dependencies, modules that depend on it), syntax check (token parser and `php -l` when a PHP command
 *                 line exists), code scan. New high-risk capabilities stop here and ask for approval.
 *   2. Backup     zip of the module folder, plus a snapshot of the module's data: the tables and options declared in
 *                 module.json ("data": {"tables": [...], "options": ["prefix_*"]}), else a full database dump.
 *   3. Swap       atomic folder rename, then the module's migrations and "module_updated_<slug>" hook run in a separate
 *                 PHP process (PHP command line, or a request to the site itself) with a time limit.
 *   4. Verify     the module loads, the migration succeeded, Modules::errors() has no new entry, the error log has no new
 *                 fatal error, and the homepage, the dashboard, the module's admin pages and its "health" paths answer
 *                 200 (compared with the same checks before the update, so a page that was already unreachable from the
 *                 server itself does not block it).
 *   5. On failure the files, the data snapshot and the module bookkeeping are restored and the error report is kept for
 *      the Modules screen and the dashboard ("Try again", "Skip this version"). An update interrupted for longer than
 *      STUCK_AFTER seconds (killed process, hang) is rolled back by the next request.
 *   6. On success the new files are trusted by the integrity check and the page cache is cleared.
 */
final class ModuleUpdater
{
    private const DIR = SLATE_CONTENT . '/backups/modules';
    private const LOCK = self::DIR . '/.update-lock';
    private const LOGS = self::DIR . '/logs';
    private const DATA = self::DIR . '/data';
    public const STUCK_AFTER = 600;
    private const MAX_PER_RUN = 3;
    private const KEEP_LOGS = 60;
    private const MARK = '@@SLATE-MODULE-TASK@@';
    private const HEADER = 'X-Slate-Update-Check';

    /** Update log being written (also mirrored to the lock file). */
    private array $log = [];
    private array $lock = [];
    private string $slug;
    private bool $auto;
    private static bool $emitted = false;

    // ------------------------------------------------------------------ settings & state

    /** "Update modules automatically" (Modules › Sources), on by default. */
    public static function enabled(): bool
    {
        return (bool)Options::get('module_autoupdate', true);
    }

    public static function setEnabled(bool $on): void
    {
        Options::set('module_autoupdate', $on, false);
    }

    /** Modules switched out of automatic updates: slug => true. */
    public static function optedOut(): array
    {
        return array_filter((array)Options::get('module_autoupdate_off', []));
    }

    public static function setAuto(string $slug, bool $on): void
    {
        $off = self::optedOut();
        if ($on) {
            unset($off[$slug]);
        } else {
            $off[$slug] = true;
        }
        Options::set('module_autoupdate_off', $off, false);
    }

    /** Installed from a registry or a URL (so it has somewhere to update from) and not a git checkout. */
    public static function managed(string $slug): bool
    {
        $m = Modules::get($slug);
        return $m && $m['source'] !== '' && !is_dir(SLATE_MODULES . "/$slug/.git");
    }

    /** Will this module be updated automatically? */
    public static function autoFor(string $slug): bool
    {
        return self::enabled() && self::managed($slug) && empty(self::optedOut()[$slug]);
    }

    /** Per-module update state: last (successful update), failed (report), approval (needed), skip (version). */
    public static function status(?string $slug = null): array
    {
        $all = (array)Options::get('module_update_status', []);
        return $slug === null ? $all : (array)($all[$slug] ?? []);
    }

    private static function setStatus(string $slug, string $key, mixed $value): void
    {
        $all = (array)self::fresh('module_update_status', []);
        $all[$slug] = (array)($all[$slug] ?? []);
        if ($value === null) {
            unset($all[$slug][$key]);
        } else {
            $all[$slug][$key] = $value;
        }
        if (!$all[$slug]) {
            unset($all[$slug]);
        }
        Options::set('module_update_status', $all, false);
    }

    /** Drop a deleted module's update state and opt-out. */
    public static function forget(string $slug): void
    {
        $all = (array)self::fresh('module_update_status', []);
        if (isset($all[$slug])) {
            unset($all[$slug]);
            Options::set('module_update_status', $all, false);
        }
        $off = self::optedOut();
        if (isset($off[$slug])) {
            unset($off[$slug]);
            Options::set('module_autoupdate_off', $off, false);
        }
    }

    /** Do not install $version automatically (newer versions still are). */
    public static function skip(string $slug, string $version): void
    {
        self::setStatus($slug, 'skip', $version);
        $st = self::status($slug);
        if (($st['failed']['version'] ?? null) === $version) {
            self::setStatus($slug, 'failed', null);
        }
        if (($st['approval']['version'] ?? null) === $version) {
            self::setStatus($slug, 'approval', null);
        }
        log_activity('module_update_skipped', "$slug $version");
    }

    /**
     * Failed updates and approvals still relevant (the version is still newer than the installed one):
     * slug => [kind => failed|approval, name, version, ...].
     */
    public static function notices(): array
    {
        $out = [];
        foreach (self::status() as $slug => $st) {
            $m = Modules::get((string)$slug);
            if (!$m) {
                continue;
            }
            foreach (['failed', 'approval'] as $kind) {
                $n = $st[$kind] ?? null;
                if (is_array($n) && version_compare((string)($n['version'] ?? '0'), $m['version'], '>') && ($st['skip'] ?? null) !== $n['version']) {
                    $out[$slug] = $n + ['kind' => $kind, 'name' => $m['name'], 'slug' => $slug];
                    break;
                }
            }
        }
        return $out;
    }

    /** Slug of the module being updated right now, if any. */
    public static function busy(): ?string
    {
        $l = self::readLock();
        return $l['slug'] ?? null;
    }

    // ------------------------------------------------------------------ scheduled task

    /** Twice-daily task: refresh the registries, then update what may be updated automatically. */
    public static function cron(): void
    {
        $updates = ModuleInstaller::updates(true);
        if (!self::enabled() || !ModuleInstaller::allowed() || Modules::safeMode()) {
            return;
        }
        $done = 0;
        $start = time();
        foreach ($updates as $slug => $u) {
            if ($done >= self::MAX_PER_RUN || time() - $start > 300) {
                break;
            }
            if (!self::wantsAuto((string)$slug, $u)) {
                continue;
            }
            self::run((string)$slug, true);
            $done++;
        }
    }

    /** Should the scheduled task install $u for $slug? */
    public static function wantsAuto(string $slug, array $u): bool
    {
        if (!self::autoFor($slug)) {
            return false;
        }
        $st = self::status($slug);
        $v = (string)$u['version'];
        if (($st['skip'] ?? null) === $v || ($st['failed']['version'] ?? null) === $v || ($st['approval']['version'] ?? null) === $v) {
            return false; // failed and approval-needed versions wait for a person ("Try again" / "Review and update")
        }
        if (($u['requires'] ?? '') !== '' && version_compare(SLATE_VERSION, (string)$u['requires'], '<')) {
            return false;
        }
        if (($u['requires_php'] ?? '') !== '' && version_compare(PHP_VERSION, (string)$u['requires_php'], '<')) {
            return false;
        }
        return true;
    }

    /** Why an available update will not be installed automatically (for the Modules screen), or null. */
    public static function blockedReason(string $slug, array $u): ?string
    {
        if (($u['requires'] ?? '') !== '' && version_compare(SLATE_VERSION, (string)$u['requires'], '<')) {
            return "Needs Slate {$u['requires']} or newer.";
        }
        if (($u['requires_php'] ?? '') !== '' && version_compare(PHP_VERSION, (string)$u['requires_php'], '<')) {
            return "Needs PHP {$u['requires_php']} or newer.";
        }
        return null;
    }

    // ------------------------------------------------------------------ the pipeline

    /**
     * Update $slug to the newest version its registry/source offers, safely.
     * @return array{ok:bool, error?:string, slug:string, version?:string, updated?:bool, log?:string, approval?:bool}
     */
    public static function run(string $slug, bool $auto = false, bool $approved = false): array
    {
        if (!ModuleInstaller::allowed()) {
            return ['ok' => false, 'slug' => $slug, 'error' => ModuleInstaller::lockReason()];
        }
        if (!Modules::get($slug)) {
            return ['ok' => false, 'slug' => $slug, 'error' => 'Module not found.'];
        }
        if (!self::managed($slug) && $auto) {
            return ['ok' => false, 'slug' => $slug, 'error' => 'Local modules are never updated automatically.'];
        }
        self::recoverIfStuck();
        if (!is_dir(self::LOGS) && !@mkdir(self::LOGS, 0755, true)) {
            return ['ok' => false, 'slug' => $slug, 'error' => 'Cannot write to content/backups/modules/.'];
        }
        $fp = @fopen(self::LOCK, 'x');
        if (!$fp) {
            $busy = self::busy();
            return ['ok' => false, 'slug' => $slug, 'error' => 'Another module update is running' . ($busy ? " ($busy)" : '') . '. Try again in a few minutes.'];
        }
        fclose($fp);
        $u = new self();
        $u->slug = $slug;
        $u->auto = $auto;
        try {
            return $u->pipeline($approved);
        } catch (\Throwable $e) {
            // Something unexpected in the pipeline itself: undo whatever was changed.
            $u->fail('pipeline', $e->getMessage(), $e->getFile(), $e->getLine());
            return ['ok' => false, 'slug' => $slug, 'error' => $e->getMessage(), 'log' => $u->log['id'] ?? ''];
        } finally {
            if (is_file(self::LOCK) && (self::readLock()['nonce'] ?? null) === ($u->lock['nonce'] ?? '')) {
                @unlink(self::LOCK);
            }
        }
    }

    private function pipeline(bool $approved): array
    {
        $slug = $this->slug;
        $m = Modules::get($slug);
        @set_time_limit(900);
        @ignore_user_abort(true);
        $this->log = ['id' => gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '-' . $slug, 'slug' => $slug, 'name' => $m['name'], 'from' => $m['version'], 'to' => null,
            'trigger' => $this->auto ? 'automatic' : 'manual', 'user' => Auth::user()['display_name'] ?? null,
            'started' => time(), 'finished' => null, 'result' => 'running', 'steps' => [], 'error' => null, 'checks' => []];
        $this->lock = ['slug' => $slug, 'nonce' => bin2hex(random_bytes(16)), 'started' => time(), 'started_at' => now(), 'log' => $this->log['id'],
            'from' => $m['version'], 'to' => null, 'active' => $m['active'], 'step' => 'preflight', 'swapped' => false, 'migrating' => false,
            'files_backup' => null, 'data' => null, 'book' => null, 'error_log_size' => (int)@filesize(SLATE_CONTENT . '/slate-error.log')];
        $this->save();

        // ---------------------------------------------------------- 1. preflight
        $src = ModuleInstaller::updateSource($slug);
        if (!$src) {
            return $this->stop('preflight', 'No update source is known for this module (its registry or source URL lists no download).');
        }
        if (!version_compare((string)$src['version'], $m['version'], '>')) {
            return $this->stop('preflight', "Version {$m['version']} is the newest available.", true);
        }
        $this->log['to'] = $this->lock['to'] = (string)$src['version'];
        $this->step('check', true, "Version {$src['version']} offered by " . $src['from'] . '.');
        $work = ModuleInstaller::workDir();
        try {
            $t = microtime(true);
            $staged = ModuleInstaller::stage((string)$src['url'], $src['sha256'] ?? null, $slug, $work, $m['source']);
            if (isset($staged['error'])) {
                $step = str_contains($staged['error'], 'checksum') ? 'checksum' : 'download';
                return $this->failed($step, $staged['error']);
            }
            $this->step('download', true, 'Downloaded and unpacked' . (!empty($src['sha256']) ? ', sha256 verified' : ' (the registry publishes no sha256)') . '.', $t);
            $new = $staged['manifest'];
            $this->log['to'] = $this->lock['to'] = $new['version'];
            if (!version_compare($new['version'], $m['version'], '>')) {
                return $this->failed('manifest', "The download contains version {$new['version']}, which is not newer than the installed {$m['version']}.");
            }
            if ($err = $this->requirements($new)) {
                return $this->failed('requirements', $err);
            }
            $this->step('requirements', true, 'Slate ' . SLATE_VERSION . ', PHP ' . PHP_VERSION . ' and dependencies are compatible.');

            $t = microtime(true);
            $lint = ModuleScanner::lint($staged['dir']);
            $php = self::phpCli();
            if (!$lint && $php) {
                $lint = self::phpLint($php, $staged['dir']);
            }
            if ($lint) {
                return $this->failed('syntax', 'Syntax errors: ' . implode('; ', array_slice($lint, 0, 5)));
            }
            $this->step('syntax', true, count(ModuleScanner::phpFiles($staged['dir'])) . ' PHP files parsed' . ($php ? ' and checked with php -l.' : ' (no PHP command line, token check only).'), $t);

            $newScan = ModuleScanner::scan($staged['dir']);
            $oldScan = ModuleScanner::scan(SLATE_MODULES . "/$slug");
            $added = array_diff_key(array_filter($newScan['findings'], static fn($f) => $f['risk'] === 'high'), $oldScan['findings']);
            if ($added && !$approved) {
                $labels = array_values(array_map(static fn($f) => $f['label'] . ' (' . implode(', ', array_slice($f['where'], 0, 3)) . ')', $added));
                $this->step('code scan', false, 'New high-risk capabilities: ' . implode('; ', $labels) . '. Waiting for approval.');
                self::setStatus($slug, 'failed', null);
                self::setStatus($slug, 'approval', ['version' => $new['version'], 'from' => $m['version'], 'reasons' => $labels, 'time' => time(), 'log' => $this->log['id']]);
                $this->finish('needs_approval');
                log_activity('module_update_held', "$slug {$new['version']} needs approval");
                return ['ok' => false, 'slug' => $slug, 'approval' => true, 'log' => $this->log['id'],
                    'error' => "{$m['name']} {$new['version']} can do new things (" . implode('; ', array_map(static fn($f) => $f['label'], $added)) . '). Review and approve the update.'];
            }
            $this->step('code scan', true, $added ? 'New high-risk capabilities approved by ' . ($this->log['user'] ?? 'an administrator') . '.' : 'No new high-risk capabilities.');

            $runner = null;
            if ($m['active']) {
                $runner = $php ? 'cli' : ($this->ping() ? 'http' : null);
                if (!$runner) {
                    return $this->failed('preflight', 'This server cannot test the new version in a separate process (no PHP command line, and the site cannot reach itself over HTTP), so the update was not started.');
                }
            }

            // ------------------------------------------------------ baseline (old version)
            $targets = $this->targets($slug, []);
            $baseline = $this->probe($targets);
            $this->log['checks']['before'] = $baseline;
            $reachable = ($baseline['Homepage']['status'] ?? 0) === 200;
            $this->step('baseline', true, $reachable ? count($baseline) . ' pages checked on the current version.'
                : 'The site could not reach itself (' . ($baseline['Homepage']['error'] ?: 'HTTP ' . ($baseline['Homepage']['status'] ?? 0)) . '); page checks are skipped.');

            // ---------------------------------------------------------- 2. backup
            $t = microtime(true);
            $this->lock['step'] = 'backup';
            $zip = ModuleInstaller::backup($slug);
            if (!$zip) {
                return $this->failed('backup', 'Could not back up the installed version (content/backups/modules/ not writable?). Nothing was changed.');
            }
            $this->lock['files_backup'] = $zip;
            $this->lock['book'] = $this->bookkeeping($slug, $m['active']);
            $this->save();
            $this->step('backup files', true, 'modules/' . $slug . '/ saved as ' . basename($zip) . '.', $t);
            if ($m['active']) {
                $t = microtime(true);
                $this->lock['data'] = $this->snapshot($slug, $m, $new);
                $this->save();
                $d = $this->lock['data'];
                $this->step('backup data', true, $d['type'] === 'scoped'
                    ? 'Snapshot of ' . ($d['tables'] ? 'tables ' . implode(', ', $d['tables']) : 'no existing tables') . ' and ' . count($d['options']) . ' options (' . format_bytes((int)@filesize($d['file'])) . ').'
                    : 'The module declares no data in module.json, so the whole database was backed up (' . format_bytes((int)@filesize($d['file'])) . ').', $t);
            } else {
                $this->step('backup data', null, 'Not needed: the module is not active, so no migration runs now.');
            }

            // ---------------------------------------------------------- 3. swap + migrate
            $this->lock['step'] = 'swap';
            $this->save();
            if (!ModuleInstaller::place($staged['dir'], SLATE_MODULES . "/$slug")) {
                return $this->failed('swap', 'Could not replace the module folder (permissions).');
            }
            $this->lock['swapped'] = true;
            $this->save();
            self::afterFilesChanged($slug);
            $this->step('swap', true, 'New files in place.');

            if (!$m['active']) {
                return $this->succeed($new, []);
            }
            $this->lock['step'] = 'migrate';
            $this->lock['migrating'] = true;
            $this->save();
            $t = microtime(true);
            $res = $runner === 'cli' ? $this->runCli($php) : $this->runHttp();
            $this->lock['step'] = 'verify';
            $this->save();
            if (empty($res['ok'])) {
                return $this->failed((string)($res['step'] ?? 'migrate'), (string)($res['message'] ?? 'The update task failed.'),
                    (string)($res['file'] ?? ''), (int)($res['line'] ?? 0), (string)($res['output'] ?? ''));
            }
            $this->step('load', true, "Module loaded in a separate PHP process ($runner).");
            $this->step('migrate', true, ($res['migrations'] ?? []) ? 'Ran ' . implode(', ', $res['migrations']) . ' and module_updated_' . $slug . '.'
                : 'module_updated_' . $slug . ' ran without errors.', $t);

            // ---------------------------------------------------------- 4. verify
            $errs = self::newErrors((string)$this->lock['started_at']);
            if ($errs) {
                $first = reset($errs);
                return $this->failed('verify', 'Module ' . key($errs) . ' was switched off: ' . $first['message'], (string)($first['file'] ?? ''), (int)($first['line'] ?? 0));
            }
            if ($reachable) {
                $after = $this->probe($this->targets($slug, (array)($res['pages'] ?? [])));
                $this->log['checks']['after'] = $after;
                foreach ($after as $label => $c) {
                    $was = $baseline[$label]['status'] ?? null;
                    if (!$c['ok'] && ($was === 200 || $was === null)) {
                        return $this->failed('verify', "$label ({$c['path']}) answered " . ($c['error'] ?: 'HTTP ' . $c['status']) . ' after the update' . ($was === 200 ? ' (200 before).' : '.'), '', 0, (string)$c['excerpt']);
                    }
                }
                $this->step('pages', true, implode(', ', array_map(static fn($l, $c) => "$l {$c['status']}", array_keys($after), $after)) . '.');
            } else {
                $this->step('pages', null, 'Skipped (the site cannot reach itself).');
            }
            if ($fatal = $this->newFatals()) {
                return $this->failed('verify', 'New fatal error in the PHP error log.', '', 0, $fatal);
            }
            $this->step('errors', true, 'No module was switched off and no new fatal error was logged.');
            return $this->succeed($new, (array)($res['pages'] ?? []));
        } finally {
            Updater::rrmdir($work);
        }
    }

    /** Slate, PHP, dependency and dependent constraints of the new version. */
    private function requirements(array $new): ?string
    {
        if (version_compare(SLATE_VERSION, $new['requires'], '<')) {
            return "Version {$new['version']} needs Slate {$new['requires']} or newer (this site runs " . SLATE_VERSION . ').';
        }
        if ($new['requires_php'] !== '' && version_compare(PHP_VERSION, $new['requires_php'], '<')) {
            return "Version {$new['version']} needs PHP {$new['requires_php']} or newer (this server runs " . PHP_VERSION . ').';
        }
        $active = Modules::isActive($this->slug);
        foreach ($new['depends'] as $dep => $constraint) {
            $dm = Modules::get($dep);
            if (!$dm) {
                return "Version {$new['version']} needs module “{$dep}”, which is not installed.";
            }
            if (!Modules::satisfies($dm['version'], $constraint)) {
                return "Version {$new['version']} needs {$dm['name']} $constraint (installed: {$dm['version']}).";
            }
            if ($active && !$dm['active']) {
                return "Version {$new['version']} needs {$dm['name']} to be active.";
            }
        }
        foreach (Modules::all() as $s => $other) {
            $c = $other['depends'][$this->slug] ?? null;
            if ($other['active'] && $c !== null && !Modules::satisfies($new['version'], $c)) {
                return "{$other['name']} needs {$this->slug} $c, version {$new['version']} would break it.";
            }
        }
        return null;
    }

    private function succeed(array $new, array $pages): array
    {
        $slug = $this->slug;
        Integrity::trust("modules/$slug");
        PageCache::clear();
        Options::delete('module_updates');
        self::setStatus($slug, 'last', ['from' => $this->log['from'], 'to' => $new['version'], 'time' => time(), 'auto' => $this->auto, 'log' => $this->log['id']]);
        self::setStatus($slug, 'failed', null);
        self::setStatus($slug, 'approval', null);
        $this->lock['step'] = 'done';
        $this->save();
        $this->step('finish', true, "Updated to {$new['version']}. New files trusted by the security check, page cache cleared.");
        $this->finish('updated');
        log_activity($this->auto ? 'module_auto_updated' : 'module_updated', "$slug from {$this->log['from']} to {$new['version']}");
        $this->pruneData();
        return ['ok' => true, 'slug' => $slug, 'version' => $new['version'], 'updated' => true, 'log' => $this->log['id']];
    }

    /** Stop before anything changed (nothing to roll back). */
    private function stop(string $step, string $message, bool $info = false): array
    {
        $this->step($step, $info ? null : false, $message);
        $this->finish($info ? 'nothing_to_do' : 'failed');
        return ['ok' => $info, 'slug' => $this->slug, 'error' => $message, 'log' => $this->log['id'], 'updated' => false];
    }

    /** A step failed: undo, record the report, tell people. */
    private function failed(string $step, string $message, string $file = '', int $line = 0, string $excerpt = ''): array
    {
        $this->fail($step, $message, $file, $line, $excerpt);
        $to = (string)($this->log['to'] ?? '');
        return ['ok' => false, 'slug' => $this->slug, 'log' => $this->log['id'],
            'error' => 'Updating ' . $this->log['name'] . ($to !== '' ? " to $to" : '') . " failed at “{$step}”: $message"
                . ($this->lock['swapped'] ? ' It was undone, your site is unchanged.' : ' Nothing was changed.')];
    }

    private function fail(string $step, string $message, string $file = '', int $line = 0, string $excerpt = ''): void
    {
        $file = str_replace(SLATE_ROOT . '/', '', $file);
        $this->step($step, false, $message . ($file !== '' ? " ($file:$line)" : ''));
        $excerpt = trim($excerpt . "\n" . $this->logTail());
        $rolled = $this->rollback();
        $to = (string)($this->log['to'] ?? '');
        $this->log['error'] = ['step' => $step, 'message' => mb_substr($message, 0, 1000), 'file' => $file, 'line' => $line, 'excerpt' => mb_substr($excerpt, -4000)];
        $this->finish($rolled === false ? 'rollback_failed' : 'failed');
        // A download that failed on an automatic run is retried by the next run; everything else waits for a person.
        $transient = $this->auto && $step === 'download';
        if (!$transient && $to !== '' && version_compare($to, (string)$this->log['from'], '>')) {
            self::setStatus($this->slug, 'approval', null);
            self::setStatus($this->slug, 'failed', ['version' => $to, 'from' => $this->log['from'], 'time' => time(), 'auto' => $this->auto, 'log' => $this->log['id'],
                'undone' => $rolled !== false] + $this->log['error']);
        }
        log_activity('module_update_failed', "{$this->slug} $to at $step" . ($rolled === null ? '' : ($rolled ? ', rolled back' : ', ROLLBACK FAILED')));
        if ($this->auto && !$transient) {
            self::mail($this->log);
        }
    }

    /**
     * Put the previous version back: files, data snapshot, module bookkeeping. Returns null when nothing had changed,
     * true when restored, false when restoring failed (reported loudly).
     */
    private function rollback(): ?bool
    {
        $l = $this->lock;
        if (empty($l['swapped']) && empty($l['migrating'])) {
            return null;
        }
        $ok = true;
        $notes = [];
        if (!empty($l['swapped']) && $l['files_backup']) {
            if (ModuleInstaller::restore($this->slug, (string)$l['files_backup'])) {
                $notes[] = 'files restored from ' . basename((string)$l['files_backup']);
            } else {
                $ok = false;
                $notes[] = 'COULD NOT restore the files from ' . basename((string)$l['files_backup']);
            }
        }
        if (!empty($l['migrating']) && $l['data']) {
            try {
                self::restoreData((array)$l['data']);
                $notes[] = 'data restored (' . ($l['data']['type'] === 'scoped' ? 'module tables and options' : 'full database') . ')';
            } catch (\Throwable $e) {
                $ok = false;
                $notes[] = 'COULD NOT restore the data: ' . $e->getMessage() . ' (backup: ' . basename((string)$l['data']['file']) . ')';
            }
        }
        try {
            if ($l['book']) {
                self::restoreBookkeeping($this->slug, (array)$l['book'], (string)$l['started_at']);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $notes[] = 'could not restore the module settings: ' . $e->getMessage();
        }
        self::afterFilesChanged($this->slug);
        PageCache::clear();
        $this->step('rollback', $ok, ucfirst(implode('; ', $notes)) . '.');
        if ($ok) {
            $home = $this->probe(['Homepage' => ['url' => site_url('/') . '?slate_update_check=' . bin2hex(random_bytes(3)), 'path' => '/']]);
            $this->step('after rollback', null, 'Homepage answers ' . ($home['Homepage']['error'] ?: 'HTTP ' . $home['Homepage']['status']) . '.');
        }
        return $ok;
    }

    // ------------------------------------------------------------------ stuck updates

    /** Called early on every request (Modules::boot): roll back a stuck update; answer the pipeline's checks. */
    public static function onBoot(): void
    {
        if (!is_file(self::LOCK)) {
            return;
        }
        $check = (string)($_SERVER['HTTP_X_SLATE_UPDATE_CHECK'] ?? '');
        if ($check !== '' && PHP_SAPI !== 'cli') {
            $l = self::readLock();
            if ($l && hash_equals((string)$l['nonce'], $check)) {
                self::answerCheck($l);
                return;
            }
        }
        self::recoverIfStuck();
    }

    /** An update whose lock has not moved for STUCK_AFTER seconds died or hangs: undo it. */
    public static function recoverIfStuck(): void
    {
        clearstatcache(true, self::LOCK);
        $mtime = @filemtime(self::LOCK);
        if ($mtime === false || $mtime > time() - self::STUCK_AFTER || !DB::connected()) {
            return;
        }
        $claimed = self::LOCK . '.recovering-' . bin2hex(random_bytes(3));
        if (!@rename(self::LOCK, $claimed)) {
            return; // another request is on it
        }
        try {
            $l = json_decode((string)@file_get_contents($claimed), true);
            if (!is_array($l) || empty($l['slug'])) {
                return;
            }
            $u = new self();
            $u->slug = (string)$l['slug'];
            $u->lock = $l;
            $u->auto = ($l['trigger'] ?? '') === 'automatic';
            $u->log = self::log((string)($l['log'] ?? '')) ?? ['id' => (string)($l['log'] ?? gmdate('Ymd-His') . '-0000-' . $u->slug), 'slug' => $u->slug,
                'name' => $u->slug, 'from' => $l['from'] ?? '', 'to' => $l['to'] ?? '', 'trigger' => 'unknown', 'started' => $l['started'] ?? time(), 'steps' => []];
            $u->auto = ($u->log['trigger'] ?? '') === 'automatic';
            if (($l['step'] ?? '') === 'done') {
                $u->step('recover', null, 'The update had finished; only the lock was left behind.');
                $u->finish('updated');
                return;
            }
            @set_time_limit(600);
            $u->fail((string)($l['step'] ?? 'unknown'), 'The update stopped responding for more than ' . (self::STUCK_AFTER / 60)
                . ' minutes (killed or hanging) during “' . ($l['step'] ?? '?') . '” and was rolled back automatically.');
        } finally {
            @unlink($claimed);
        }
    }

    // ------------------------------------------------------------------ the separate process

    /**
     * Runs in a fresh PHP process (CLI `php bin/slate module:task <nonce>` or a request to the site itself) after the new
     * files are in place: every active module is loaded by the normal boot, then the module's migrations and update
     * hook run, then its health filter. Prints a JSON report after a marker; a fatal error prints one too.
     */
    public static function task(string $nonce): array
    {
        $l = self::readLock();
        if (!$l || !hash_equals((string)$l['nonce'], $nonce)) {
            return ['ok' => false, 'step' => 'task', 'message' => 'No matching update is running.'];
        }
        $slug = (string)$l['slug'];
        $timeout = self::timeout();
        @set_time_limit($timeout + 5);
        $state = ['ok' => false, 'step' => 'load', 'message' => 'The process ended while loading.'];
        register_shutdown_function(static function () use (&$state): void {
            if (self::$emitted) {
                return;
            }
            $err = error_get_last();
            if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
                $state = ['ok' => false, 'step' => $state['step'], 'message' => (string)$err['message'], 'file' => (string)$err['file'], 'line' => (int)$err['line']];
            } elseif (empty($state['done'])) {
                $state['message'] = 'The process exited during “' . $state['step'] . '” without finishing.';
            }
            echo "\n" . self::MARK . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        });
        if (PHP_SAPI === 'cli') {
            Hooks::action('init');
        }
        $errors = self::newErrors((string)$l['started_at']);
        if (isset($errors[$slug]) || !Modules::isActive($slug)) {
            $e = $errors[$slug] ?? ['message' => 'The module was switched off while loading.'];
            $state = ['ok' => false, 'step' => 'load', 'message' => (string)$e['message'], 'file' => (string)($e['file'] ?? ''), 'line' => (int)($e['line'] ?? 0), 'done' => true];
            return $state;
        }
        $state['step'] = 'migrate';
        $before = (array)(self::fresh('module_migrations', [])[$slug] ?? []);
        $err = Modules::upgrade($slug, (string)$l['from'], (string)$l['to']);
        if ((self::readLock()['nonce'] ?? null) !== $nonce) {
            // Finished after the pipeline gave up on it and rolled back (only possible with the HTTP runner, which cannot
            // kill a hanging request): do not leave this run's migration records on top of the restored ones.
            $mig = (array)self::fresh('module_migrations', []);
            $mig[$slug] = $before;
            Options::set('module_migrations', $mig, false);
            $state = ['ok' => false, 'step' => 'migrate', 'message' => 'Finished after the update was rolled back.', 'done' => true];
            return $state;
        }
        if ($err) {
            $state = ['ok' => false, 'step' => 'migrate', 'message' => $err, 'done' => true];
            return $state;
        }
        Modules::markVersion($slug);
        Modules::refreshAutoload();
        $ran = array_values(array_diff((array)(self::fresh('module_migrations', [])[$slug] ?? []), $before));
        $state['step'] = 'health';
        try {
            $h = Hooks::filter("module_health_$slug", null);
        } catch (\Throwable $e) {
            $h = $e->getMessage();
        }
        if (is_string($h) && $h !== '') {
            $state = ['ok' => false, 'step' => 'health', 'message' => "module_health_$slug: $h", 'done' => true];
            return $state;
        }
        $pages = [];
        foreach (Modules::adminPages() as $p) {
            if (($p['module'] ?? '') === $slug && $p['section'] !== 'hidden') {
                $pages[$p['label']] = 'm/' . $p['slug'];
            }
        }
        if (isset(Modules::settingsSections()[$slug])) {
            $pages['Settings'] = "m/$slug/settings";
        }
        $state = ['ok' => true, 'step' => 'done', 'migrations' => $ran, 'pages' => $pages, 'done' => true];
        return $state;
    }

    /** Print a task report for the parent (CLI or HTTP) and stop. */
    public static function emit(array $state): never
    {
        self::$emitted = true;
        echo "\n" . self::MARK . json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        exit(0);
    }

    /** A request from the pipeline itself (header with the lock's secret): act as an administrator, or run the task. */
    private static function answerCheck(array $lock): void
    {
        if (function_exists('opcache_invalidate') && Modules::validSlug((string)$lock['slug'])) {
            // This worker may hold the previous version in its opcache: compile the module's files afresh.
            foreach (ModuleScanner::phpFiles(SLATE_MODULES . '/' . $lock['slug']) as $rel) {
                @opcache_invalidate(SLATE_MODULES . '/' . $lock['slug'] . '/' . $rel, true);
            }
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST' && in_array($_POST['slate_task'] ?? '', ['run', 'ping'], true)) {
            $kind = (string)$_POST['slate_task'];
            Hooks::add('init', static function () use ($kind): void {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
                if ($kind === 'ping') {
                    self::emit(['ok' => true, 'php' => PHP_VERSION]);
                }
                $nonce = (string)($_SERVER['HTTP_X_SLATE_UPDATE_CHECK'] ?? '');
                self::emit(self::task($nonce));
            }, 100000);
            return;
        }
        if ($method === 'GET') {
            PageCache::skip();
            $admin = DB::row("SELECT * FROM {{users}} WHERE role = 'administrator' AND status = 'active' ORDER BY id LIMIT 1");
            if ($admin) {
                Auth::actAs($admin);
            }
        }
    }

    private static function timeout(): int
    {
        $t = (int)(defined('SLATE_CONFIG') ? (SLATE_CONFIG['module_update_timeout'] ?? 0) : 0);
        if (PHP_SAPI === 'cli' && (int)getenv('SLATE_MODULE_UPDATE_TIMEOUT') > 0) {
            $t = (int)getenv('SLATE_MODULE_UPDATE_TIMEOUT'); // tests
        }
        return $t > 0 ? min($t, 300) : 120;
    }

    private function runCli(string $php): array
    {
        $r = self::proc([$php, SLATE_ROOT . '/bin/slate', 'module:task', (string)$this->lock['nonce']], self::timeout());
        if ($r['timeout']) {
            return ['ok' => false, 'step' => 'migrate', 'message' => 'The update task did not finish within ' . self::timeout() . ' seconds and was stopped.', 'output' => self::tail($r['out'] . $r['err'])];
        }
        return self::parse($r['out'] . "\n" . $r['err'], 'exit code ' . $r['code']);
    }

    private function runHttp(): array
    {
        $r = Http::request('POST', site_url('/'), [self::HEADER . ': ' . $this->lock['nonce'], 'Content-Type: application/x-www-form-urlencoded'],
            'slate_task=run', self::timeout());
        if ($r['error'] && str_contains(strtolower((string)$r['error']), 'timed out')) {
            return ['ok' => false, 'step' => 'migrate', 'message' => 'The update task did not finish within ' . self::timeout() . ' seconds.'];
        }
        return self::parse((string)$r['body'], $r['error'] ?: 'HTTP ' . $r['status']);
    }

    private function ping(): bool
    {
        $r = Http::request('POST', site_url('/'), [self::HEADER . ': ' . $this->lock['nonce'], 'Content-Type: application/x-www-form-urlencoded'], 'slate_task=ping', 20);
        return !empty(self::parse((string)$r['body'], '')['ok']);
    }

    private static function parse(string $out, string $fallback): array
    {
        $pos = strrpos($out, self::MARK);
        if ($pos === false) {
            return ['ok' => false, 'step' => 'load', 'message' => 'The update task crashed (' . $fallback . ') without a report.', 'output' => self::tail(strip_tags($out))];
        }
        $json = json_decode(trim(strtok(substr($out, $pos + strlen(self::MARK)), "\n") ?: ''), true);
        if (!is_array($json)) {
            return ['ok' => false, 'step' => 'load', 'message' => 'Unreadable report from the update task.', 'output' => self::tail($out)];
        }
        $json['output'] = self::tail(strip_tags(substr($out, 0, $pos)));
        return $json;
    }

    // ------------------------------------------------------------------ checks over HTTP

    /** Pages to check: label => [url, path]. */
    private function targets(string $slug, array $extraPages): array
    {
        $adminBase = AdminAccess::baseUrl() . '/' . admin_slug() . '/';
        $t = ['Homepage' => ['url' => site_url('/') . '?slate_update_check=' . bin2hex(random_bytes(3)), 'path' => '/'],
            'Dashboard' => ['url' => $adminBase, 'path' => '/' . admin_slug() . '/']];
        $pages = [];
        foreach (Modules::adminPages() as $p) {
            if (($p['module'] ?? '') === $slug && $p['section'] !== 'hidden') {
                $pages[$p['label']] = 'm/' . $p['slug'];
            }
        }
        if (isset(Modules::settingsSections()[$slug])) {
            $pages['Settings'] = "m/$slug/settings";
        }
        foreach ($pages + $extraPages as $label => $rel) {
            $rel = ltrim((string)$rel, '/');
            if (preg_match('#^m/[\w/-]+$#', $rel)) {
                $t["Module page “{$label}”"] = ['url' => $adminBase . $rel, 'path' => '/' . admin_slug() . '/' . $rel];
            }
        }
        foreach (Modules::manifest($slug)['health'] as $path) {
            $t["Health path $path"] = ['url' => rtrim(site_url(), '/') . $path . (str_contains($path, '?') ? '&' : '?') . 'slate_update_check=1', 'path' => $path];
        }
        return array_slice($t, 0, 15, true);
    }

    private function probe(array $targets): array
    {
        $out = [];
        foreach ($targets as $label => $tg) {
            $r = Http::request('GET', $tg['url'], [self::HEADER . ': ' . $this->lock['nonce'], 'Accept: text/html'], null, 30);
            $body = (string)$r['body'];
            $crash = str_contains($body, 'A module stopped working') || (str_contains($body, '<h1 style="font-size:22px">Something went wrong</h1>'));
            $out[$label] = ['path' => $tg['path'], 'status' => (int)$r['status'], 'error' => $r['error'] ?: ($crash ? 'a module crash page' : ''),
                'ok' => !$r['error'] && (int)$r['status'] === 200 && !$crash,
                'excerpt' => (int)$r['status'] === 200 && !$crash ? '' : self::tail(strip_tags($body), 600)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ data snapshots

    /** Back up what the module owns: declared tables + options, else the whole database. */
    private function snapshot(string $slug, array $old, array $new): array
    {
        @mkdir(self::DATA, 0755, true);
        $stamp = gmdate('Ymd-His');
        $spec = null;
        foreach ([$old['data'] ?? null, $new['data'] ?? null] as $d) {
            if (is_array($d)) {
                $spec ??= ['tables' => [], 'options' => []];
                $spec['tables'] = array_values(array_unique([...$spec['tables'], ...$d['tables']]));
                $spec['options'] = array_values(array_unique([...$spec['options'], ...$d['options']]));
            }
        }
        $existing = Backup::tables();
        if ($spec === null) {
            $file = self::DATA . "/$slug-$stamp-full.sql.gz";
            Backup::dumpTables($file, $existing);
            return ['type' => 'full', 'file' => $file, 'tables' => $existing];
        }
        $declared = array_map(static fn($t) => DB::$prefix . $t, $spec['tables']);
        $have = array_values(array_intersect($declared, $existing));
        $file = self::DATA . "/$slug-$stamp-data.sql.gz";
        Backup::dumpTables($file, $have);
        $rows = [];
        foreach ($spec['options'] as $pattern) {
            foreach (self::optionRows($pattern) as $row) {
                $rows[$row['name']] = $row;
            }
        }
        $optFile = self::DATA . "/$slug-$stamp-options.json";
        file_put_contents($optFile, json_encode(array_values($rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return ['type' => 'scoped', 'file' => $file, 'options_file' => $optFile, 'declared' => $declared, 'tables' => $have,
            'patterns' => $spec['options'], 'options' => array_keys($rows)];
    }

    private static function optionRows(string $pattern): array
    {
        if (str_ends_with($pattern, '*')) {
            $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], substr($pattern, 0, -1)) . '%';
            return DB::all("SELECT name, value, autoload FROM {{options}} WHERE name LIKE :p ESCAPE '!'", ['p' => $like]);
        }
        return DB::all('SELECT name, value, autoload FROM {{options}} WHERE name = :n', ['n' => $pattern]);
    }

    private static function restoreData(array $d): void
    {
        $quote = static fn(string $t) => DB::$driver === 'sqlite' ? '"' . $t . '"' : '`' . $t . '`';
        if ($d['type'] === 'full') {
            // Tables created by the migration are not in the dump: drop them, then load the dump.
            foreach (array_diff(Backup::tables(), (array)$d['tables']) as $t) {
                DB::exec('DROP TABLE IF EXISTS ' . $quote($t));
            }
            if (Backup::importSql((string)$d['file']) < 0) {
                throw new \RuntimeException('the database backup is incomplete');
            }
            return;
        }
        foreach (array_diff((array)$d['declared'], (array)$d['tables']) as $t) {
            DB::exec('DROP TABLE IF EXISTS ' . $quote($t));
        }
        if ($d['tables'] && Backup::importSql((string)$d['file']) < 0) {
            throw new \RuntimeException('the table snapshot is incomplete');
        }
        $rows = json_decode((string)@file_get_contents((string)$d['options_file']), true);
        if (!is_array($rows)) {
            throw new \RuntimeException('the options snapshot is unreadable');
        }
        foreach ((array)$d['patterns'] as $pattern) {
            foreach (self::optionRows($pattern) as $row) {
                DB::delete('options', ['name' => $row['name']]);
            }
        }
        foreach ($rows as $row) {
            DB::upsert('options', ['name' => $row['name'], 'value' => $row['value'], 'autoload' => (int)$row['autoload']], 'name');
        }
    }

    /** Core records about the module that an update can change. */
    private function bookkeeping(string $slug, bool $active): array
    {
        $baseline = (array)self::fresh('integrity_baseline', []);
        $mine = array_filter($baseline, static fn($k) => str_starts_with((string)$k, "modules/$slug/"), ARRAY_FILTER_USE_KEY);
        return ['active' => $active, 'version' => self::fresh('module_versions', [])[$slug] ?? null,
            'migrations' => self::fresh('module_migrations', [])[$slug] ?? null, 'integrity' => $mine];
    }

    private static function restoreBookkeeping(string $slug, array $b, string $since): void
    {
        $active = array_values(array_filter((array)self::fresh('active_modules', []), 'is_string'));
        if (!empty($b['active']) && !in_array($slug, $active, true)) {
            $active[] = $slug;
        } elseif (empty($b['active'])) {
            $active = array_values(array_diff($active, [$slug]));
        }
        Options::set('active_modules', $active);
        foreach (['module_versions' => 'version', 'module_migrations' => 'migrations'] as $opt => $key) {
            $v = (array)self::fresh($opt, []);
            if ($b[$key] === null) {
                unset($v[$slug]);
            } else {
                $v[$slug] = $b[$key];
            }
            Options::set($opt, $v, false);
        }
        // Crash records written during the update belong to the version that is gone again.
        $errors = (array)self::fresh('module_errors', []);
        $kept = array_filter($errors, static fn($e) => (string)($e['time'] ?? '') < $since);
        if ($kept !== $errors) {
            Options::set('module_errors', $kept, false);
        }
        // The restored files are the ones trusted before.
        $baseline = (array)self::fresh('integrity_baseline', []);
        foreach (array_keys($baseline) as $k) {
            if (str_starts_with((string)$k, "modules/$slug/")) {
                unset($baseline[$k]);
            }
        }
        Options::set('integrity_baseline', $baseline + (array)$b['integrity'], false);
    }

    /** Module crash records written since $since: slug => record. */
    private static function newErrors(string $since): array
    {
        return array_filter((array)self::fresh('module_errors', []), static fn($e) => is_array($e) && (string)($e['time'] ?? '') >= $since);
    }

    /** Read an option from the database, bypassing this request's cache (another process may have changed it). */
    private static function fresh(string $name, mixed $default): mixed
    {
        $v = DB::val('SELECT value FROM {{options}} WHERE name = :n', ['n' => $name]);
        return $v === null ? $default : json_decode((string)$v, true);
    }

    private static function afterFilesChanged(?string $slug = null): void
    {
        if ($slug !== null) {
            // Fresh modification times, so every PHP worker's opcache notices the change (zip entries keep old times,
            // and two versions can carry identical ones).
            $now = time();
            foreach (ModuleScanner::phpFiles(SLATE_MODULES . "/$slug") as $rel) {
                @touch(SLATE_MODULES . "/$slug/$rel", $now);
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache();
        Modules::refreshAutoload();
        Modules::all(true);
    }

    /** Keep the two newest data snapshots per module. */
    private function pruneData(): void
    {
        $all = glob(self::DATA . "/{$this->slug}-*") ?: [];
        $stamps = array_unique(array_map(static fn($f) => preg_replace('/^.*-(\d{8}-\d{6})-.*$/', '$1', basename($f)), $all));
        rsort($stamps);
        foreach (array_slice($stamps, 2) as $old) {
            foreach (glob(self::DATA . "/{$this->slug}-$old-*") ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------ error log

    private function newFatals(): string
    {
        $chunk = $this->newLogText();
        $lines = array_filter(explode("\n", $chunk), static fn($l) => preg_match('/PHP (Fatal|Parse) error|Uncaught /', $l));
        return $lines ? implode("\n", array_slice($lines, -5)) : '';
    }

    private function logTail(): string
    {
        $t = trim($this->newLogText());
        return $t === '' ? '' : "Error log:\n" . self::tail($t, 2000);
    }

    private function newLogText(): string
    {
        $f = SLATE_CONTENT . '/slate-error.log';
        clearstatcache(true, $f);
        $from = (int)($this->lock['error_log_size'] ?? 0);
        $size = (int)@filesize($f);
        if ($size <= $from) {
            return '';
        }
        $fp = @fopen($f, 'rb');
        if (!$fp) {
            return '';
        }
        fseek($fp, max($from, $size - 20000));
        $s = (string)stream_get_contents($fp);
        fclose($fp);
        return $s;
    }

    private static function tail(string $s, int $max = 1500): string
    {
        $s = trim(preg_replace("/\n{3,}/", "\n\n", $s) ?? '');
        return strlen($s) > $max ? '…' . substr($s, -$max) : $s;
    }

    // ------------------------------------------------------------------ PHP command line

    /** A PHP command-line binary of the same version as the site, or null. Cached for a day. */
    public static function phpCli(): ?string
    {
        if (defined('SLATE_CONFIG') && (SLATE_CONFIG['module_update_runner'] ?? '') === 'http') {
            return null; // config.php can force the HTTP runner (hosts where the PHP command line misbehaves; tests)
        }
        if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return null;
        }
        Options::lazy('module_php_cli');
        $c = Options::get('module_php_cli');
        if (is_array($c) && ($c['time'] ?? 0) > time() - 86400 && ($c['for'] ?? '') === PHP_VERSION) {
            return $c['bin'] ?: null;
        }
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $driver = DB::$driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        $candidates = [];
        if (PHP_SAPI === 'cli') {
            $candidates[] = PHP_BINARY;
        }
        $candidates[] = PHP_BINDIR . '/php';
        $nodot = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        array_push($candidates, "/opt/alt/php$nodot/usr/bin/php", "/usr/local/bin/php$want", "/usr/bin/php$want", "/usr/local/bin/php", "/usr/bin/php");
        $found = '';
        foreach (array_unique($candidates) as $bin) {
            if (!@is_file($bin) || !@is_executable($bin)) {
                continue;
            }
            $r = self::proc([$bin, '-r', 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "|" . PHP_SAPI . "|" . (extension_loaded("' . $driver . '") ? 1 : 0);'], 10);
            if (trim($r['out']) === "$want|cli|1") {
                $found = $bin;
                break;
            }
        }
        Options::set('module_php_cli', ['bin' => $found, 'time' => time(), 'for' => PHP_VERSION], false);
        return $found ?: null;
    }

    /** `php -l` on every PHP file of a folder. @return string[] errors */
    private static function phpLint(string $php, string $dir): array
    {
        $errors = [];
        foreach (array_slice(ModuleScanner::phpFiles($dir), 0, 400) as $rel) {
            $r = self::proc([$php, '-l', $dir . '/' . $rel], 20);
            if ($r['code'] !== 0) {
                $msg = trim(preg_replace('/^(PHP )?(Parse|Fatal) error:\s*|Errors parsing.*$/m', '', $r['out'] . ' ' . $r['err']) ?? '');
                $errors[] = $rel . ': ' . preg_replace('/\s+in \S+ on line (\d+)/', ' (line $1)', str_replace($dir . '/', '', $msg));
            }
        }
        return $errors;
    }

    /** Run a command with a wall-clock limit. @return array{code:int, out:string, err:string, timeout:bool} */
    private static function proc(array $cmd, int $timeout): array
    {
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, SLATE_ROOT);
        if (!is_resource($p)) {
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed', 'timeout' => false];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($read, $w, $e, 0, 200000) > 0) {
                foreach ($read as $r) {
                    $chunk = (string)fread($r, 65536);
                    if ($r === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err .= (string)stream_get_contents($pipes[2]);
                $code = (int)$st['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($p, 9);
                $code = -1;
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        return ['code' => $code, 'out' => strlen($out) > 200000 ? substr($out, -200000) : $out, 'err' => substr($err, -20000), 'timeout' => $timedOut];
    }

    // ------------------------------------------------------------------ lock & log files

    private static function readLock(): ?array
    {
        $j = @file_get_contents(self::LOCK);
        $l = $j ? json_decode($j, true) : null;
        return is_array($l) ? $l : null;
    }

    /** Write the lock (heartbeat) and the log. */
    private function save(): void
    {
        $this->lock['trigger'] = $this->log['trigger'] ?? '';
        $tmp = self::LOCK . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, json_encode($this->lock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($tmp, self::LOCK);
        }
        $this->writeLog();
    }

    private function step(string $name, ?bool $ok, string $message, ?float $since = null): void
    {
        $this->log['steps'][] = ['step' => $name, 'ok' => $ok, 'message' => mb_substr($message, 0, 2000), 'at' => time(),
            'ms' => $since ? (int)((microtime(true) - $since) * 1000) : null];
        if (isset($this->lock['nonce']) && (self::readLock()['nonce'] ?? null) === $this->lock['nonce']) {
            $this->save();
        } else {
            $this->writeLog();
        }
    }

    private function finish(string $result): void
    {
        $this->log['result'] = $result;
        $this->log['finished'] = time();
        $this->writeLog();
        $logs = glob(self::LOGS . '/*.json') ?: [];
        if (count($logs) > self::KEEP_LOGS) {
            sort($logs);
            foreach (array_slice($logs, 0, count($logs) - self::KEEP_LOGS) as $old) {
                @unlink($old);
            }
        }
    }

    private function writeLog(): void
    {
        if (empty($this->log['id'])) {
            return;
        }
        @mkdir(self::LOGS, 0755, true);
        @file_put_contents(self::LOGS . '/' . $this->log['id'] . '.json', json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Recent update logs, newest first (optionally for one module). */
    public static function logs(?string $slug = null, int $limit = 30): array
    {
        $files = glob(self::LOGS . '/*.json') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $f) {
            if ($slug !== null && !str_ends_with(basename($f, '.json'), "-$slug")) {
                continue;
            }
            $l = json_decode((string)file_get_contents($f), true);
            if (is_array($l)) {
                $out[] = $l;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public static function log(string $id): ?array
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{4}-[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            return null;
        }
        $l = json_decode((string)@file_get_contents(self::LOGS . "/$id.json"), true);
        return is_array($l) ? $l : null;
    }

    // ------------------------------------------------------------------ email

    private static function mail(array $log): void
    {
        try {
            $to = DB::col("SELECT email FROM {{users}} WHERE role = 'administrator' AND status = 'active'");
            if (!$to) {
                return;
            }
            $site = (string)Options::get('site_title', 'your site');
            $err = (array)($log['error'] ?? []);
            $link = AdminAccess::baseUrl() . '/' . admin_slug() . '/modules/log/' . $log['id'];
            Mailer::send($to, "Module update failed on $site: {$log['name']} {$log['to']}",
                '<p>Slate tried to update <strong>' . e((string)$log['name']) . '</strong> from ' . e((string)$log['from']) . ' to ' . e((string)$log['to'])
                . ' on <strong>' . e($site) . '</strong>. It failed at “' . e((string)($err['step'] ?? '')) . '” and was undone; your site is unchanged.</p>'
                . '<p><code>' . e((string)($err['message'] ?? '')) . '</code></p>'
                . '<p><a href="' . e($link) . '">Open the update report</a>. From the Modules screen you can try again or skip this version.</p>');
        } catch (\Throwable $e) {
            error_log('Slate module update mail: ' . $e->getMessage());
        }
    }
}
