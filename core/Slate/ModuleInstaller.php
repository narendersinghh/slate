<?php
declare(strict_types=1);

namespace Slate;

/**
 * Installs, updates and removes modules, from an uploaded zip, any zip URL, a GitHub repository (or a folder inside one,
 * e.g. https://github.com/me/slate-modules/tree/main/shop) or a module registry.
 *
 * Registries are plain JSON files anyone can publish (Settings › Modules › Registries):
 *   {"name": "My registry", "modules": [{"slug": "shop", "name": "Shop", "description": "…", "version": "1.0.0",
 *     "requires": "0.6.0", "author": "…", "homepage": "…", "download": "https://github.com/me/repo/tree/main/shop",
 *     "sha256": "optional checksum of the zip", "tags": ["ecommerce"]}]}
 *
 * Every install is syntax-checked before it lands, replaced modules are backed up to content/backups/modules/
 * and restored automatically when anything goes wrong.
 */
final class ModuleInstaller
{
    public const DEFAULT_REGISTRY = 'https://raw.githubusercontent.com/narendersinghh/slate-modules/main/registry.json';
    private const MAX_ZIP = 60 * 1024 * 1024;
    private const MAX_UNPACKED = 200 * 1024 * 1024;
    private const MAX_ENTRIES = 10000;
    private const REGISTRY_TTL = 6 * 3600;
    private const BACKUPS = SLATE_CONTENT . '/backups/modules';

    // ------------------------------------------------------------------ policy

    /** Site owners can switch installing off in config.php: 'modules_locked' => true. */
    public static function allowed(): bool
    {
        return !(defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['modules_locked'])) && is_writable(SLATE_MODULES);
    }

    public static function lockReason(): string
    {
        if (defined('SLATE_CONFIG') && !empty(SLATE_CONFIG['modules_locked'])) {
            return 'Installing, updating and deleting modules is switched off in config.php (modules_locked).';
        }
        return is_writable(SLATE_MODULES) ? '' : 'The modules folder is not writable by PHP.';
    }

    // ------------------------------------------------------------------ install

    /**
     * Install from a URL: a .zip, a GitHub repository, or a folder in a GitHub repository.
     * @return array{ok:bool, error?:string, slug?:string, updated?:bool, scan?:array}
     */
    public static function fromUrl(string $url, ?string $sha256 = null, bool $replace = false, ?string $expect = null): array
    {
        if (!self::allowed()) {
            return ['ok' => false, 'error' => self::lockReason()];
        }
        $src = self::resolveUrl($url);
        if (isset($src['error'])) {
            return ['ok' => false, 'error' => $src['error']];
        }
        $work = self::workDir();
        $zipFile = "$work/module.zip";
        @set_time_limit(300);
        $r = Http::request('GET', $src['zip'], self::authHeaders($src['zip']), null, 180, $zipFile);
        if ($r['error'] || $r['status'] !== 200) {
            Updater::rrmdir($work);
            $hint = in_array($r['status'], [401, 403, 404], true) && str_contains($src['zip'], 'github')
                ? ' If the repository is private, add a GitHub token under Modules › Sources.' : '';
            return ['ok' => false, 'error' => 'Download failed: ' . ($r['error'] ?: 'HTTP ' . $r['status']) . '.' . $hint];
        }
        if ($sha256 && !hash_equals(strtolower(trim($sha256)), hash_file('sha256', $zipFile))) {
            Updater::rrmdir($work);
            return ['ok' => false, 'error' => 'The download does not match its published checksum (sha256), so it was not installed.'];
        }
        $res = self::fromZip($zipFile, $src['subdir'], $replace, $expect, $url, $work);
        return $res;
    }

    /**
     * Install a module zip (uploaded file or download).
     * @param string|null $subdir module folder inside the archive (for repositories holding several modules)
     */
    public static function fromZip(string $zipFile, ?string $subdir = null, bool $replace = false, ?string $expect = null,
        string $source = '', ?string $work = null, ?string $nameHint = null): array
    {
        if (!self::allowed()) {
            return ['ok' => false, 'error' => self::lockReason()];
        }
        $work ??= self::workDir();
        try {
            if (!class_exists('ZipArchive')) {
                return ['ok' => false, 'error' => 'The PHP zip extension is required to install modules.'];
            }
            if (!is_file($zipFile) || filesize($zipFile) > self::MAX_ZIP) {
                return ['ok' => false, 'error' => 'The file is missing or larger than ' . format_bytes(self::MAX_ZIP) . '.'];
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                return ['ok' => false, 'error' => 'That is not a valid zip file.'];
            }
            if ($zip->numFiles > self::MAX_ENTRIES) {
                $zip->close();
                return ['ok' => false, 'error' => 'The archive has too many files.'];
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string)($st['name'] ?? ''));
                $total += (int)($st['size'] ?? 0);
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':')) {
                    $zip->close();
                    return ['ok' => false, 'error' => 'The archive contains an unsafe path (' . $name . ').'];
                }
            }
            if ($total > self::MAX_UNPACKED) {
                $zip->close();
                return ['ok' => false, 'error' => 'The archive unpacks to more than ' . format_bytes(self::MAX_UNPACKED) . '.'];
            }
            $zip->extractTo("$work/src");
            $zip->close();

            $root = self::findRoot("$work/src", $subdir);
            if (isset($root['error'])) {
                return ['ok' => false, 'error' => $root['error']];
            }
            $dir = $root['dir'];
            $meta = json_decode((string)file_get_contents("$dir/module.json"), true);
            if (!is_array($meta)) {
                return ['ok' => false, 'error' => 'module.json is not valid JSON.'];
            }
            if (!is_file("$dir/module.php")) {
                return ['ok' => false, 'error' => 'The module has no module.php file.'];
            }
            $slug = self::slugFor($meta, $dir, $work, $nameHint ?? ($source !== '' ? basename(parse_url($source, PHP_URL_PATH) ?: '') : null));
            if (!Modules::validSlug($slug)) {
                return ['ok' => false, 'error' => 'Could not work out a valid module name; add "slug" to module.json (lowercase letters, numbers, dashes).'];
            }
            if ($expect !== null && $slug !== $expect) {
                return ['ok' => false, 'error' => "The download contains module “{$slug}”, not “{$expect}”."];
            }
            if ($errors = ModuleScanner::lint($dir)) {
                return ['ok' => false, 'error' => 'The module has syntax errors and was not installed: ' . implode('; ', array_slice($errors, 0, 3))];
            }
            $m = Modules::manifest($dir);
            if (version_compare(SLATE_VERSION, $m['requires'], '<')) {
                return ['ok' => false, 'error' => "{$m['name']} needs Slate {$m['requires']} or newer (this site runs " . SLATE_VERSION . ').'];
            }
            $target = SLATE_MODULES . '/' . $slug;
            $exists = is_dir($target);
            if ($exists && !$replace) {
                return ['ok' => false, 'error' => "A module called “{$slug}” is already installed. Use Update, or delete it first."];
            }
            $scan = ModuleScanner::scan($dir);
            if ($source !== '' && !$m['source']) {
                // Remember where it came from so updates can find it.
                $meta['source'] = $source;
                @file_put_contents("$dir/module.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
            $backup = $exists ? self::backup($slug) : null;
            if ($exists && !$backup) {
                return ['ok' => false, 'error' => 'Could not back up the installed version, so nothing was changed.'];
            }
            if (!self::place($dir, $target)) {
                if ($backup) {
                    self::restore($slug, $backup);
                }
                return ['ok' => false, 'error' => 'Could not write to the modules folder' . ($backup ? '; the previous version was restored.' : '.')];
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            log_activity($exists ? 'module_updated' : 'module_installed', "$slug {$m['version']}");
            Integrity::trust("modules/$slug");
            PageCache::clear();
            return ['ok' => true, 'slug' => $slug, 'updated' => $exists, 'version' => $m['version'], 'scan' => $scan, 'backup' => $backup];
        } finally {
            Updater::rrmdir($work);
        }
    }

    /** Write a module from generated files (AI or blank scaffold). @param array<string,string> $files */
    public static function fromFiles(string $slug, array $files): array
    {
        if (!self::allowed()) {
            return ['ok' => false, 'error' => self::lockReason()];
        }
        if (!Modules::validSlug($slug)) {
            return ['ok' => false, 'error' => 'Invalid module name.'];
        }
        if (is_dir(SLATE_MODULES . "/$slug")) {
            return ['ok' => false, 'error' => "A module called “{$slug}” already exists."];
        }
        $work = self::workDir();
        try {
            foreach ($files as $path => $content) {
                $path = str_replace('\\', '/', (string)$path);
                if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) || !preg_match('#^[\w./-]+$#', $path)) {
                    return ['ok' => false, 'error' => "Unsafe file name: $path"];
                }
                @mkdir(dirname("$work/src/$path"), 0755, true);
                file_put_contents("$work/src/$path", (string)$content);
            }
            if (!is_file("$work/src/module.json") || !is_file("$work/src/module.php")) {
                return ['ok' => false, 'error' => 'A module needs module.json and module.php.'];
            }
            if ($errors = ModuleScanner::lint("$work/src")) {
                return ['ok' => false, 'error' => 'Syntax errors: ' . implode('; ', array_slice($errors, 0, 3))];
            }
            $scan = ModuleScanner::scan("$work/src");
            if (!self::place("$work/src", SLATE_MODULES . "/$slug")) {
                return ['ok' => false, 'error' => 'Could not write to the modules folder.'];
            }
            log_activity('module_created', $slug);
            Integrity::trust("modules/$slug");
            return ['ok' => true, 'slug' => $slug, 'scan' => $scan];
        } finally {
            Updater::rrmdir($work);
        }
    }

    // ------------------------------------------------------------------ update & delete

    /** Where an installed module's newer version can be downloaded from, if anywhere. */
    public static function updateSource(string $slug): ?array
    {
        $m = Modules::get($slug);
        if (!$m) {
            return null;
        }
        foreach (self::available() as $entry) {
            if ($entry['slug'] === $slug && !empty($entry['download'])) {
                return ['version' => $entry['version'], 'url' => $entry['download'], 'sha256' => $entry['sha256'] ?? null, 'from' => $entry['registry']];
            }
        }
        if ($m['source'] !== '' && ($v = self::remoteVersion($m['source']))) {
            return ['version' => $v, 'url' => $m['source'], 'sha256' => null, 'from' => $m['source']];
        }
        return null;
    }

    /** Installed modules with a newer version available: slug => info. */
    public static function updates(bool $force = false): array
    {
        $cached = Options::get('module_updates');
        if (!$force && is_array($cached) && ($cached['time'] ?? 0) > time() - self::REGISTRY_TTL) {
            $out = (array)$cached['items'];
        } else {
            $out = [];
            foreach (Modules::all() as $slug => $m) {
                $src = self::updateSource($slug);
                if ($src && version_compare($src['version'], $m['version'], '>')) {
                    $out[$slug] = $src;
                }
            }
            Options::set('module_updates', ['time' => time(), 'items' => $out], false);
        }
        // Drop entries that were installed in the meantime.
        return array_filter($out, static fn($u, $s) => ($m = Modules::get($s)) && version_compare($u['version'], $m['version'], '>'), ARRAY_FILTER_USE_BOTH);
    }

    public static function update(string $slug): array
    {
        $src = self::updateSource($slug);
        if (!$src) {
            return ['ok' => false, 'error' => 'No update source is known for this module.'];
        }
        $res = self::fromUrl($src['url'], $src['sha256'], true, $slug);
        Options::delete('module_updates');
        return $res;
    }

    /** Delete an inactive module (backed up first). With $purge its uninstall.php runs to remove its data. */
    public static function delete(string $slug, bool $purge = false): array
    {
        if (!self::allowed()) {
            return ['ok' => false, 'error' => self::lockReason()];
        }
        $m = Modules::get($slug);
        if (!$m) {
            return ['ok' => false, 'error' => 'Module not found.'];
        }
        if ($m['active']) {
            return ['ok' => false, 'error' => 'Deactivate the module before deleting it.'];
        }
        $backup = self::backup($slug);
        if (!$backup) {
            return ['ok' => false, 'error' => 'Could not back up the module, so it was not deleted.'];
        }
        $dir = SLATE_MODULES . "/$slug";
        if ($purge && is_file("$dir/uninstall.php")) {
            try {
                (static fn(string $__file) => require $__file)("$dir/uninstall.php");
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'uninstall.php failed: ' . $e->getMessage()];
            }
        }
        Updater::rrmdir($dir);
        if (is_dir($dir)) {
            return ['ok' => false, 'error' => 'Some files could not be removed (permissions).'];
        }
        foreach (['module_versions', 'module_errors'] as $opt) {
            $v = (array)Options::get($opt, []);
            unset($v[$slug]);
            Options::set($opt, $v, false);
        }
        if ($purge) {
            $v = (array)Options::get('module_migrations', []);
            unset($v[$slug]);
            Options::set('module_migrations', $v, false);
        }
        log_activity('module_deleted', $slug);
        Integrity::trust("modules/$slug");
        return ['ok' => true, 'backup' => basename($backup)];
    }

    // ------------------------------------------------------------------ registries

    public static function registries(): array
    {
        $raw = (string)Options::get('module_registries', self::DEFAULT_REGISTRY);
        $urls = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $u) {
            if (preg_match('#^https?://#i', $u)) {
                $urls[] = $u;
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Every module listed by the configured registries (first registry wins on duplicate slugs).
     * @return array<int, array{slug:string, name:string, description:string, version:string, requires:string, author:string,
     *   homepage:string, download:string, sha256:?string, tags:array, registry:string, installed:?string}>
     */
    public static function available(bool $force = false): array
    {
        $out = [];
        foreach (self::registries() as $url) {
            $data = self::fetchRegistry($url, $force);
            foreach ((array)($data['modules'] ?? []) as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $slug = (string)($e['slug'] ?? '');
                if (!Modules::validSlug($slug) || isset($out[$slug])) {
                    continue;
                }
                $out[$slug] = [
                    'slug' => $slug,
                    'name' => mb_substr((string)($e['name'] ?? $slug), 0, 80),
                    'description' => mb_substr((string)($e['description'] ?? ''), 0, 400),
                    'version' => (string)($e['version'] ?? '1.0.0'),
                    'requires' => (string)($e['requires'] ?? '0.1.0'),
                    'author' => mb_substr((string)($e['author'] ?? ''), 0, 80),
                    'homepage' => preg_match('#^https?://#', (string)($e['homepage'] ?? '')) ? (string)$e['homepage'] : '',
                    'download' => preg_match('#^https?://#', (string)($e['download'] ?? '')) ? (string)$e['download'] : '',
                    'sha256' => isset($e['sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string)$e['sha256']) ? (string)$e['sha256'] : null,
                    'tags' => array_slice(array_values(array_filter((array)($e['tags'] ?? []), 'is_string')), 0, 6),
                    'icon' => preg_replace('/[^a-z0-9-]/', '', strtolower((string)($e['icon'] ?? ''))),
                    'registry' => (string)($data['name'] ?? parse_url($url, PHP_URL_HOST)),
                    'installed' => Modules::get($slug)['version'] ?? null,
                ];
            }
        }
        return array_values($out);
    }

    /** Problems reaching registries during the last fetch: url => message. */
    public static function registryErrors(): array
    {
        $errors = [];
        foreach (self::registries() as $url) {
            Options::lazy('module_registry_' . md5($url));
            $c = Options::get('module_registry_' . md5($url));
            if (is_array($c) && !empty($c['error'])) {
                $errors[$url] = $c['error'];
            }
        }
        return $errors;
    }

    private static function fetchRegistry(string $url, bool $force): array
    {
        $key = 'module_registry_' . md5($url);
        Options::lazy($key);
        $c = Options::get($key);
        if (!$force && is_array($c) && ($c['time'] ?? 0) > time() - self::REGISTRY_TTL) {
            return (array)($c['data'] ?? []);
        }
        $r = Http::request('GET', $url, array_merge(['Accept: application/json'], self::authHeaders($url)), null, 20);
        $data = json_decode($r['body'], true);
        $error = null;
        if ($r['error'] || $r['status'] !== 200) {
            $error = $r['error'] ?: 'HTTP ' . $r['status'];
        } elseif (!is_array($data) || !isset($data['modules'])) {
            $error = 'Not a module registry (expected {"modules": [...]})';
        }
        if ($error) {
            // Keep serving the last good copy, retry in 30 minutes.
            $data = is_array($c) ? (array)($c['data'] ?? []) : [];
            Options::set($key, ['time' => time() - self::REGISTRY_TTL + 1800, 'data' => $data, 'error' => $error], false);
            return $data;
        }
        Options::set($key, ['time' => time(), 'data' => $data], false);
        return $data;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Turn a user-supplied address into a zip download.
     * @return array{zip?:string, subdir?:?string, error?:string}
     */
    public static function resolveUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://[^\s]+$#i', $url)) {
            return ['error' => 'Enter a full https:// address.'];
        }
        // https://github.com/owner/repo[/tree/<ref>/<path>]  (also .git suffix)
        if (preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?(?:/tree/([^/]+)(?:/(.+?))?)?/?$#', $url, $m)) {
            $ref = $m[3] ?? '';
            $zip = "https://api.github.com/repos/{$m[1]}/{$m[2]}/zipball" . ($ref !== '' ? '/' . rawurlencode($ref) : '');
            return ['zip' => $zip, 'subdir' => isset($m[4]) && $m[4] !== '' ? trim($m[4], '/') : null];
        }
        return ['zip' => $url, 'subdir' => null];
    }

    /** Latest version of a module published at a GitHub repository/folder, read from its module.json. */
    private static function remoteVersion(string $source): ?string
    {
        if (!preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?(?:/tree/([^/]+)(?:/(.+?))?)?/?$#', $source, $m)) {
            return null;
        }
        $path = isset($m[4]) && $m[4] !== '' ? trim($m[4], '/') . '/module.json' : 'module.json';
        $api = "https://api.github.com/repos/{$m[1]}/{$m[2]}/contents/" . str_replace('%2F', '/', rawurlencode($path))
            . (isset($m[3]) && $m[3] !== '' ? '?ref=' . rawurlencode($m[3]) : '');
        $r = Http::request('GET', $api, array_merge(['Accept: application/vnd.github.raw+json'], self::authHeaders($api)), null, 15);
        $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
        return is_array($j) && isset($j['version']) ? (string)$j['version'] : null;
    }

    /** GitHub token (Modules › Sources, falling back to the core updater's), only ever sent to GitHub. */
    private static function authHeaders(string $url): array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['github.com', 'api.github.com', 'raw.githubusercontent.com', 'codeload.github.com'], true)) {
            return [];
        }
        $token = Crypto::decrypt((string)Options::get('modules_github_token')) ?: Crypto::decrypt((string)Options::get('update_token'));
        $h = $host === 'api.github.com' ? ['X-GitHub-Api-Version: 2022-11-28'] : [];
        if ($token !== '') {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        return $h;
    }

    /** Locate the folder holding module.json inside an unpacked archive. */
    private static function findRoot(string $base, ?string $subdir): array
    {
        $candidates = [$base];
        $top = array_values(array_filter(glob("$base/*", GLOB_ONLYDIR) ?: [], static fn($d) => !str_starts_with(basename($d), '__MACOSX')));
        if (count($top) === 1) {
            $candidates[] = $top[0]; // GitHub zipballs and most zips wrap everything in one folder
        }
        if ($subdir !== null) {
            foreach ($candidates as $c) {
                if (is_file("$c/$subdir/module.json")) {
                    return ['dir' => "$c/$subdir"];
                }
            }
            return ['error' => "No module.json found in “{$subdir}”."];
        }
        foreach ($candidates as $c) {
            if (is_file("$c/module.json")) {
                return ['dir' => $c];
            }
        }
        // A repository holding several modules: list them so the person can pick one.
        $found = [];
        foreach ($candidates as $c) {
            foreach (glob("$c/*/module.json") ?: [] as $mj) {
                $found[] = basename(dirname($mj));
            }
            foreach (glob("$c/modules/*/module.json") ?: [] as $mj) {
                $found[] = 'modules/' . basename(dirname($mj));
            }
        }
        if ($found) {
            return ['error' => 'This archive holds several modules (' . implode(', ', array_slice(array_unique($found), 0, 12))
                . '). Install one by its folder address, e.g. https://github.com/owner/repo/tree/main/' . $found[0]];
        }
        return ['error' => 'No module.json found in the archive.'];
    }

    private static function slugFor(array $meta, string $dir, string $work, ?string $hint): string
    {
        if (!empty($meta['slug']) && is_string($meta['slug'])) {
            return strtolower($meta['slug']);
        }
        $name = basename($dir);
        if (dirname($dir) === "$work/src") {
            // The archive's wrapper folder: "my-module-main", "my-module-1.2.0" (GitHub downloads and release zips).
            $name = preg_replace('/-(?:main|master|develop|trunk|v?\d+(?:\.\d+)*)$/i', '', $name);
        }
        if ($dir === "$work/src" || preg_match('/^[\w.-]+-[0-9a-f]{7,40}$/', $name)) {
            // Archive root or a GitHub "owner-repo-sha" folder: use the address or the module name instead.
            $name = $hint ? preg_replace('/\.zip$/i', '', $hint) : (string)($meta['name'] ?? '');
            $name = preg_replace('/^slate-module-/', '', (string)$name);
        }
        return slugify((string)$name);
    }

    private static function workDir(): string
    {
        $dir = SLATE_CACHE . '/module-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0755, true);
        return $dir;
    }

    /** Move a prepared folder into modules/<slug>, replacing what is there. */
    private static function place(string $src, string $target): bool
    {
        $old = null;
        if (is_dir($target)) {
            $old = $target . '.old-' . bin2hex(random_bytes(3));
            if (!@rename($target, $old)) {
                return false;
            }
        }
        $ok = @rename($src, $target);
        if (!$ok) {
            try {
                self::copyDir($src, $target);
                $ok = true;
            } catch (\Throwable) {
                Updater::rrmdir($target);
                $ok = false;
            }
        }
        if ($old) {
            if ($ok) {
                Updater::rrmdir($old);
            } else {
                @rename($old, $target);
            }
        }
        return $ok;
    }

    private static function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
            throw new \RuntimeException("Cannot create $dest");
        }
        foreach (new \FilesystemIterator($src) as $f) {
            $t = $dest . '/' . $f->getFilename();
            if ($f->isDir()) {
                self::copyDir($f->getPathname(), $t);
            } elseif (!copy($f->getPathname(), $t)) {
                throw new \RuntimeException("Cannot write $t");
            }
        }
    }

    /** Zip an installed module to content/backups/modules/. Keeps the newest 5 per module. */
    public static function backup(string $slug): ?string
    {
        if (!is_dir(self::BACKUPS) && !@mkdir(self::BACKUPS, 0755, true)) {
            return null;
        }
        $v = preg_replace('/[^\w.-]/', '', Modules::manifest($slug)['version']);
        $file = self::BACKUPS . "/$slug-$v-" . gmdate('Ymd-His') . '.zip';
        if (!self::zipModule($slug, $file)) {
            return null;
        }
        $all = glob(self::BACKUPS . "/$slug-*.zip") ?: [];
        usort($all, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($all, 5) as $old) {
            @unlink($old);
        }
        return $file;
    }

    /**
     * Package an installed module as an installable zip (<slug>/module.json …) for reuse on another site.
     * Returns the temporary file path; the caller deletes it after sending.
     */
    public static function export(string $slug): ?string
    {
        if (!Modules::get($slug)) {
            return null;
        }
        $v = preg_replace('/[^\w.-]/', '', Modules::manifest($slug)['version']);
        $dir = self::workDir();
        $file = "$dir/$slug-$v.zip";
        return self::zipModule($slug, $file) ? $file : null;
    }

    private static function zipModule(string $slug, string $file): bool
    {
        $dir = SLATE_MODULES . "/$slug";
        if (!Modules::validSlug($slug) || !is_dir($dir) || !class_exists('ZipArchive')) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !in_array($f->getFilename(), ['.DS_Store', 'Thumbs.db'], true)) {
                $zip->addFile($f->getPathname(), $slug . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1)));
            }
        }
        return $zip->close();
    }

    /** Put a backup zip back in place of the module folder. */
    public static function restore(string $slug, string $backup): bool
    {
        $work = self::workDir();
        try {
            $zip = new \ZipArchive();
            if ($zip->open($backup) !== true) {
                return false;
            }
            $zip->extractTo($work);
            $zip->close();
            $ok = is_dir("$work/$slug") && self::place("$work/$slug", SLATE_MODULES . "/$slug");
            if ($ok) {
                Integrity::trust("modules/$slug");
            }
            return $ok;
        } finally {
            Updater::rrmdir($work);
        }
    }

    /** Backups of a module, newest first: [file, version, time]. */
    public static function backups(string $slug): array
    {
        $out = [];
        foreach (glob(self::BACKUPS . "/$slug-*.zip") ?: [] as $f) {
            if (preg_match('/^' . preg_quote($slug, '/') . '-(.+)-(\d{8}-\d{6})\.zip$/', basename($f), $m)) {
                $out[] = ['file' => basename($f), 'version' => $m[1], 'time' => filemtime($f)];
            }
        }
        usort($out, static fn($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }
}
