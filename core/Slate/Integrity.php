<?php
declare(strict_types=1);

namespace Slate;

/**
 * File integrity monitor. Detects tampering the way attackers usually leave traces on CMS sites:
 *
 *  1. Core files are compared with the official files on GitHub for the exact commit/release that is installed
 *     (git blob hashes, one API call), so legitimate deploys and updates never raise false alarms.
 *  2. Modules, custom themes and anything else that is not part of the release are compared with a snapshot you
 *     trusted (Settings → Security → Security check → "Trust current files"; installs/updates through Slate are
 *     trusted automatically).
 *  3. Executable files (.php, .phtml, .phar, .user.ini, scripts…) or new .htaccess files inside content/, uploads or
 *     other data folders are always reported — they never belong there.
 *
 * Runs daily (scheduled task) and on demand; administrators get an email when something new shows up.
 */
final class Integrity
{
    /** Folders whose files are code (hashed); everything else is data (only executables/.htaccess are watched). */
    private const CODE_DIRS = ['core', 'admin', 'bin', 'themes', 'modules', 'docs'];
    private const SKIP = ['.git', 'content/cache', 'content/backups', 'node_modules'];
    private const EXEC = '/\.(php\d?|phtml|phar|inc|cgi|pl|py|sh|asp|aspx|jsp)$|^(\.user\.ini|php\.ini)$/i';
    private const PRIVATE = ['config.php'];

    /** @return array{time:int, ok:bool, official:string|null, files:int, issues:array<int, array{level:string, kind:string, path:string, detail:string}>} */
    public static function check(bool $notify = true): array
    {
        @set_time_limit(300);
        $official = self::officialTree();
        Options::lazy('integrity_baseline');
        $baseline = (array)Options::get('integrity_baseline', []);
        $issues = [];
        $files = 0;
        foreach (self::walk() as $rel => $full) {
            $files++;
            $base = basename($rel);
            $code = self::isCode($rel);
            if (in_array($rel, self::PRIVATE, true)) {
                continue;
            }
            if (!$code) {
                // Data folders: only executables and access rules matter.
                if (preg_match(self::EXEC, $base)) {
                    $known = isset($baseline[$rel]) && $baseline[$rel] === hash_file('sha256', $full);
                    if (!$known) {
                        $issues[] = ['level' => 'danger', 'kind' => 'executable', 'path' => $rel,
                            'detail' => 'Executable file in a data folder — this is how most hacked sites are taken over. Inspect and delete it unless you put it there.'];
                    }
                } elseif ($base === '.htaccess') {
                    $matchesOfficial = isset($official['tree'][$rel]) && self::blobSha($full) === $official['tree'][$rel];
                    if (!$matchesOfficial && ($baseline[$rel] ?? null) !== hash_file('sha256', $full)) {
                        $issues[] = ['level' => 'danger', 'kind' => 'htaccess', 'path' => $rel,
                            'detail' => 'New or changed .htaccess in a data folder (could re-enable script execution or add redirects).'];
                    }
                }
                continue;
            }
            if ($official && isset($official['tree'][$rel])) {
                if (self::blobSha($full) !== $official['tree'][$rel]) {
                    $trusted = ($baseline[$rel] ?? null) === hash_file('sha256', $full);
                    if (!$trusted) {
                        $issues[] = ['level' => 'danger', 'kind' => 'modified', 'path' => $rel,
                            'detail' => 'Differs from the official file for ' . $official['ref'] . '.'];
                    }
                }
                continue;
            }
            $hash = hash_file('sha256', $full);
            if (!isset($baseline[$rel])) {
                $isExec = (bool)preg_match(self::EXEC, $base);
                $issues[] = ['level' => $isExec ? 'danger' : 'warn', 'kind' => 'new', 'path' => $rel,
                    'detail' => $official && !str_starts_with($rel, 'modules/') && !str_starts_with($rel, 'themes/')
                        ? 'Not part of ' . $official['ref'] . ' and not trusted. If it is left over from an older version, delete it.'
                        : 'New file since the last trusted snapshot.'];
            } elseif ($baseline[$rel] !== $hash) {
                $issues[] = ['level' => 'danger', 'kind' => 'modified', 'path' => $rel, 'detail' => 'Changed since the last trusted snapshot.'];
            }
        }
        if ($official) {
            foreach ($official['tree'] as $rel => $sha) {
                if (self::isCode($rel) && !is_file(SLATE_ROOT . '/' . $rel) && !str_starts_with($rel, 'modules/')) {
                    $issues[] = ['level' => 'warn', 'kind' => 'missing', 'path' => $rel, 'detail' => 'Missing compared with ' . $official['ref'] . '.'];
                }
            }
        }
        foreach ($baseline as $rel => $hash) {
            if (!$official || !isset($official['tree'][$rel])) {
                if (self::isCode($rel) && !is_file(SLATE_ROOT . '/' . $rel)) {
                    $issues[] = ['level' => 'info', 'kind' => 'removed', 'path' => $rel, 'detail' => 'Removed since the last trusted snapshot.'];
                }
            }
        }
        usort($issues, static fn($a, $b) => ['danger' => 0, 'warn' => 1, 'info' => 2][$a['level']] <=> ['danger' => 0, 'warn' => 1, 'info' => 2][$b['level']] ?: strcmp($a['path'], $b['path']));
        $report = ['time' => time(), 'ok' => !array_filter($issues, static fn($i) => $i['level'] !== 'info'), 'official' => $official['ref'] ?? null,
            'files' => $files, 'baseline' => count($baseline), 'issues' => array_slice($issues, 0, 500)];
        Options::set('integrity_report', $report, false);
        if ($notify) {
            self::notify($report);
        }
        return $report;
    }

    public static function report(): ?array
    {
        Options::lazy('integrity_report');
        $r = Options::get('integrity_report');
        return is_array($r) ? $r : null;
    }

    /** Trust everything that is on disk now (or only below $prefix, e.g. "modules/shop"). */
    public static function trust(string $prefix = ''): int
    {
        Options::lazy('integrity_baseline');
        $baseline = $prefix === '' ? [] : (array)Options::get('integrity_baseline', []);
        if ($prefix !== '') {
            foreach (array_keys($baseline) as $rel) {
                if ($rel === $prefix || str_starts_with($rel, rtrim($prefix, '/') . '/')) {
                    unset($baseline[$rel]);
                }
            }
        }
        $n = 0;
        foreach (self::walk($prefix) as $rel => $full) {
            if (in_array($rel, self::PRIVATE, true)) {
                continue;
            }
            $base = basename($rel);
            if (self::isCode($rel) || preg_match(self::EXEC, $base) || $base === '.htaccess') {
                $baseline[$rel] = hash_file('sha256', $full);
                $n++;
            }
        }
        Options::set('integrity_baseline', $baseline, false);
        if ($prefix === '') {
            log_activity('integrity_trusted', "$n files");
            $r = self::report();
            if ($r) {
                Options::set('integrity_report', ['time' => time(), 'ok' => true, 'issues' => []] + $r, false);
            }
        }
        return $n;
    }

    /** Daily scheduled task. */
    public static function scheduled(): void
    {
        Options::lazy('integrity_baseline');
        if (!Options::get('integrity_baseline')) {
            self::trust(); // first run: snapshot what is there
        }
        self::check(true);
    }

    // ------------------------------------------------------------------ helpers

    /** @return \Generator<string, string> relative path => absolute path */
    private static function walk(string $prefix = ''): \Generator
    {
        $root = rtrim(str_replace('\\', '/', SLATE_ROOT), '/');
        $start = $prefix === '' ? $root : $root . '/' . trim($prefix, '/');
        if (is_file($start)) {
            yield trim($prefix, '/') => $start;
            return;
        }
        if (!is_dir($start)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $f) use ($root): bool {
                $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($root)), '/');
                foreach (self::SKIP as $s) {
                    if ($rel === $s || str_starts_with($rel, $s . '/')) {
                        return false;
                    }
                }
                return !$f->isLink();
            }
        ));
        foreach ($it as $f) {
            if ($f->isFile()) {
                yield ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($root)), '/') => $f->getPathname();
            }
        }
    }

    private static function isCode(string $rel): bool
    {
        if (!str_contains($rel, '/')) {
            return true; // files in the site root
        }
        return in_array(explode('/', $rel)[0], self::CODE_DIRS, true);
    }

    private static function blobSha(string $file): string
    {
        $data = (string)file_get_contents($file);
        return sha1('blob ' . strlen($data) . "\0" . $data);
    }

    /**
     * Official file list for the installed code: the deployed git commit, or the release tag v<version>.
     * @return array{ref:string, tree:array<string,string>}|null
     */
    public static function officialTree(): ?array
    {
        $ref = self::installedRef();
        $label = preg_match('/^[0-9a-f]{40}$/', $ref) ? 'commit ' . substr($ref, 0, 7) : $ref;
        Options::lazy('integrity_tree');
        $cache = Options::get('integrity_tree');
        if (is_array($cache) && ($cache['ref'] ?? '') === $ref && !empty($cache['tree'])) {
            return ['ref' => $label, 'tree' => (array)$cache['tree']];
        }
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = Crypto::decrypt((string)Options::get('update_token'));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $r = Http::json('GET', 'https://api.github.com/repos/' . Updater::repo() . '/git/trees/' . rawurlencode($ref) . '?recursive=1', $headers, null, 20);
        if ($r['status'] !== 200 || empty($r['json']['tree']) || !empty($r['json']['truncated'])) {
            return null;
        }
        $tree = [];
        foreach ($r['json']['tree'] as $e) {
            if (($e['type'] ?? '') === 'blob') {
                $tree[(string)$e['path']] = (string)$e['sha'];
            }
        }
        Options::set('integrity_tree', ['ref' => $ref, 'tree' => $tree], false);
        return ['ref' => $label, 'tree' => $tree];
    }

    /** Commit SHA of a git deploy, else the release tag of this version. */
    public static function installedRef(): string
    {
        $head = @file_get_contents(SLATE_ROOT . '/.git/HEAD');
        if (is_string($head)) {
            $head = trim($head);
            if (preg_match('/^[0-9a-f]{40}$/', $head)) {
                return $head;
            }
            if (preg_match('#^ref: (refs/heads/[\w./-]+)$#', $head, $m)) {
                $sha = @file_get_contents(SLATE_ROOT . '/.git/' . $m[1]);
                if (is_string($sha) && preg_match('/^[0-9a-f]{40}/', trim($sha))) {
                    return substr(trim($sha), 0, 40);
                }
                $packed = (string)@file_get_contents(SLATE_ROOT . '/.git/packed-refs');
                if (preg_match('/^([0-9a-f]{40}) ' . preg_quote($m[1], '/') . '$/m', $packed, $p)) {
                    return $p[1];
                }
            }
        }
        return 'v' . SLATE_VERSION;
    }

    /** Email administrators when the set of problems changed since the last alert. */
    private static function notify(array $report): void
    {
        $serious = array_values(array_filter($report['issues'], static fn($i) => $i['level'] !== 'info'));
        $sig = $serious ? md5(json_encode(array_map(static fn($i) => $i['kind'] . $i['path'], $serious))) : '';
        Options::lazy('integrity_notified');
        if ($sig === '' || $sig === Options::get('integrity_notified')) {
            if ($sig === '') {
                Options::set('integrity_notified', '', false);
            }
            return;
        }
        $to = DB::col("SELECT email FROM {{users}} WHERE role = 'administrator' AND status = 'active'");
        if ($to) {
            $site = (string)Options::get('site_title', 'your site');
            $rows = '';
            foreach (array_slice($serious, 0, 25) as $i) {
                $rows .= '<li><code>' . e($i['path']) . '</code> — ' . e($i['detail']) . '</li>';
            }
            $more = count($serious) > 25 ? '<p>…and ' . (count($serious) - 25) . ' more.</p>' : '';
            $link = AdminAccess::baseUrl() . '/' . admin_slug() . '/settings/security/check';
            Mailer::send($to, "Security alert: files changed on $site",
                '<p>The daily file check on <strong>' . e($site) . '</strong> found ' . count($serious) . ' file(s) that need a look:</p><ul>' . $rows . '</ul>' . $more
                . '<p><a href="' . e($link) . '">Open the security check</a>. If you made these changes yourself, choose “Trust current files”.</p>');
        }
        Options::set('integrity_notified', $sig, false);
        log_activity('integrity_alert', count($serious) . ' files');
    }
}
