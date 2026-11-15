<?php
declare(strict_types=1);

namespace Slate;

/**
 * Site backups in content/backups/ (never reachable from the web):
 *  - database: site-YYYYmmdd-His-db.sql.gz — every Slate table (core + modules), plain SQL, pure PHP (no mysqldump needed)
 *  - files:    site-YYYYmmdd-His-files.zip — config.php, modules/, custom themes and content/ (uploads etc.)
 * Scheduled backups and retention: Settings → System → Backups. Restore: `php bin/slate backup:restore <file>`.
 */
final class Backup
{
    public const DIR = SLATE_CONTENT . '/backups';
    private const CHUNK = 500;

    /** @return array{ok:bool, files?:string[], error?:string} */
    public static function create(bool $withFiles = false): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create content/backups.'];
        }
        $stamp = gmdate('Ymd-His');
        $made = [];
        try {
            $made[] = self::database(self::DIR . "/site-$stamp-db.sql.gz");
            if ($withFiles) {
                $made[] = self::files(self::DIR . "/site-$stamp-files.zip");
            }
        } catch (\Throwable $e) {
            foreach ($made as $f) {
                @unlink($f);
            }
            @unlink(self::DIR . "/site-$stamp-db.sql.gz");
            @unlink(self::DIR . "/site-$stamp-files.zip");
            error_log('Slate backup failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        Options::set('backup_last', time(), false);
        log_activity('backup_created', implode(', ', array_map('basename', $made)));
        Hooks::action('backup_created', $made);
        return ['ok' => true, 'files' => $made];
    }

    /** Tables that belong to this site (prefix match). */
    public static function tables(): array
    {
        $prefix = DB::$prefix;
        if (DB::$driver === 'sqlite') {
            $all = DB::col("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        } else {
            $all = DB::col('SHOW TABLES');
        }
        return array_values(array_filter($all, static fn($t) => str_starts_with((string)$t, $prefix)));
    }

    private static function database(string $file): string
    {
        $gz = gzopen($file, 'wb6');
        if (!$gz) {
            throw new \RuntimeException('Cannot write ' . basename($file));
        }
        $pdo = DB::pdo();
        $sqlite = DB::$driver === 'sqlite';
        $w = static function (string $s) use ($gz): void {
            if (gzwrite($gz, $s) === false) {
                throw new \RuntimeException('Writing the backup failed (disk full?).');
            }
        };
        $w("-- Slate " . SLATE_VERSION . " database backup\n-- Site: " . site_url() . "\n-- Created: " . gmdate('c') . " UTC\n-- Driver: " . DB::$driver . "\n\n");
        if (!$sqlite) {
            $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        }
        foreach (self::tables() as $table) {
            $q = $sqlite ? '"' . $table . '"' : '`' . $table . '`';
            $create = $sqlite
                ? (string)DB::val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :n", ['n' => $table])
                : (string)(DB::row("SHOW CREATE TABLE $q")['Create Table'] ?? '');
            $w("DROP TABLE IF EXISTS $q;\n" . rtrim($create, ";\n") . ";\n");
            if ($sqlite) {
                foreach (DB::col("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = :n AND sql IS NOT NULL", ['n' => $table]) as $idx) {
                    $w(rtrim((string)$idx, ";\n") . ";\n");
                }
            }
            $cols = $sqlite ? array_column(DB::all("PRAGMA table_info($q)"), 'name') : array_column(DB::all("SHOW COLUMNS FROM $q"), 'Field');
            $keyset = in_array('id', $cols, true);
            $last = 0;
            $offset = 0;
            while (true) {
                $rows = $keyset
                    ? DB::all("SELECT * FROM $q WHERE id > :last ORDER BY id LIMIT " . self::CHUNK, ['last' => $last])
                    : DB::all("SELECT * FROM $q LIMIT " . self::CHUNK . " OFFSET $offset");
                if (!$rows) {
                    break;
                }
                $colList = implode(',', array_map(static fn($c) => $sqlite ? "\"$c\"" : "`$c`", array_keys($rows[0])));
                $values = [];
                $bytes = 0;
                foreach ($rows as $row) {
                    $v = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)), $row)) . ')';
                    // Keep each INSERT around 1 MB so it fits MySQL's max_allowed_packet on any host.
                    if ($values && $bytes + strlen($v) > 1_000_000) {
                        $w("INSERT INTO $q ($colList) VALUES\n" . implode(",\n", $values) . ";\n");
                        $values = [];
                        $bytes = 0;
                    }
                    $values[] = $v;
                    $bytes += strlen($v);
                    if ($keyset) {
                        $last = (int)$row['id'];
                    }
                }
                $w("INSERT INTO $q ($colList) VALUES\n" . implode(",\n", $values) . ";\n");
                $offset += count($rows);
                if (count($rows) < self::CHUNK) {
                    break;
                }
            }
            $w("\n");
        }
        if (!$sqlite) {
            $w("SET FOREIGN_KEY_CHECKS = 1;\n");
        }
        $w("-- end of backup\n");
        gzclose($gz);
        return $file;
    }

    private static function files(string $file): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is required for file backups.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot write ' . basename($file));
        }
        $add = static function (string $path) use ($zip): void {
            $full = SLATE_ROOT . '/' . $path;
            if (is_file($full)) {
                $zip->addFile($full, $path);
                return;
            }
            if (!is_dir($full)) {
                return;
            }
            $skip = ['content/cache', 'content/backups'];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(SLATE_ROOT) + 1));
                foreach ($skip as $s) {
                    if (str_starts_with($rel, $s . '/')) {
                        continue 2;
                    }
                }
                if ($f->isFile()) {
                    $zip->addFile($f->getPathname(), $rel);
                    $zip->setCompressionName($rel, preg_match('/\.(jpe?g|png|gif|webp|avif|mp4|webm|zip|gz|woff2?)$/i', $rel) ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE);
                }
            }
        };
        foreach (['config.php', '.htaccess', 'modules', 'content'] as $p) {
            $add($p);
        }
        foreach (glob(SLATE_THEMES . '/*', GLOB_ONLYDIR) ?: [] as $theme) {
            if (basename($theme) !== 'default') {
                $add('themes/' . basename($theme));
            }
        }
        foreach ((array)Hooks::filter('backup_paths', []) as $extra) {
            if (is_string($extra) && !str_contains($extra, '..')) {
                $add(ltrim($extra, '/'));
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Could not finish the files archive (disk full?).');
        }
        return $file;
    }

    /** @return array<int, array{name:string, kind:string, size:int, time:int}> newest first */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::DIR . '/site-*-{db.sql.gz,files.zip}', GLOB_BRACE) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'kind' => str_ends_with($f, '.zip') ? 'files' : 'database', 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
        }
        usort($out, static fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Absolute path of a backup by name, or null (blocks path tricks). */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^site-\d{8}-\d{6}-(db\.sql\.gz|files\.zip)$/', $name)) {
            return null;
        }
        $f = self::DIR . '/' . $name;
        return is_file($f) ? $f : null;
    }

    public static function delete(string $name): bool
    {
        $f = self::path($name);
        return $f !== null && @unlink($f);
    }

    /** Hourly cron job: make a backup when the schedule says one is due, then apply retention. */
    public static function scheduled(): void
    {
        $every = ['daily' => 86400, 'weekly' => 604800][(string)Options::get('backups_schedule', 'off')] ?? 0;
        if (!$every) {
            return;
        }
        Options::lazy('backup_last');
        if ((int)Options::get('backup_last', 0) > time() - $every + 600) {
            return;
        }
        $r = self::create((bool)Options::get('backups_include_files', false));
        if (!$r['ok']) {
            throw new \RuntimeException($r['error']);
        }
        self::prune();
    }

    /** Keep the newest N database and N files backups. */
    public static function prune(): void
    {
        $keep = max(1, (int)Options::get('backups_keep', 7));
        foreach (['database', 'files'] as $kind) {
            $list = array_values(array_filter(self::list(), static fn($b) => $b['kind'] === $kind));
            foreach (array_slice($list, $keep) as $old) {
                @unlink(self::DIR . '/' . $old['name']);
            }
        }
    }

    /**
     * Restore a database backup made by Slate. Takes a safety backup first. CLI only (long-running, all-or-nothing).
     * @return array{ok:bool, error?:string, statements?:int, safety?:string}
     */
    public static function restoreDatabase(string $name): array
    {
        $f = self::path($name);
        if (!$f || !str_ends_with($name, '-db.sql.gz')) {
            return ['ok' => false, 'error' => 'Backup not found.'];
        }
        $probe = gzopen($f, 'rb');
        $head = $probe ? (string)gzgets($probe, 200) : '';
        if ($probe) {
            gzclose($probe);
        }
        if (!str_starts_with($head, '-- Slate ')) {
            return ['ok' => false, 'error' => 'That file was not made by Slate.'];
        }
        $safety = self::create(false);
        if (!$safety['ok']) {
            return ['ok' => false, 'error' => 'Could not make a safety backup first: ' . $safety['error']];
        }
        @set_time_limit(0);
        $gz = gzopen($f, 'rb');
        $buf = '';
        $n = 0;
        while (!gzeof($gz)) {
            $line = (string)gzgets($gz, 1 << 20);
            if ($buf === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $buf .= $line;
            // Statements written by Slate end with ";\n" at the end of a line; string values never end a line that way
            // unless they literally contain ";\n" — so only cut when the accumulated statement parses as complete.
            if (str_ends_with(rtrim($buf, "\r\n"), ';') && self::complete($buf)) {
                DB::pdo()->exec($buf);
                $buf = '';
                $n++;
            }
        }
        gzclose($gz);
        if (trim($buf) !== '') {
            return ['ok' => false, 'error' => 'The backup ended in the middle of a statement; restored up to that point. Safety backup: ' . basename($safety['files'][0])];
        }
        PageCache::clear();
        log_activity('backup_restored', $name);
        return ['ok' => true, 'statements' => $n, 'safety' => basename($safety['files'][0])];
    }

    /** True when $sql has no open quote (single-quoted strings with backslash escapes, as PDO::quote writes them). */
    private static function complete(string $sql): bool
    {
        $in = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($in) {
                if ($c === '\\' && DB::$driver !== 'sqlite') {
                    $i++;
                } elseif ($c === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $i++;
                    } else {
                        $in = false;
                    }
                }
            } elseif ($c === "'") {
                $in = true;
            }
        }
        return !$in;
    }
}
