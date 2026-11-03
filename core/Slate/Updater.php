<?php
declare(strict_types=1);

namespace Slate;

/**
 * Core updates from GitHub Releases (Settings → System → Updates).
 * - Reads releases of the configured repo (default narendersinghh/slate)
 * - Downloads the release zip (asset "slate.zip" if attached, otherwise the source zipball)
 * - Backs up the current core to content/backups, then replaces core files
 * - Never touches config.php, content/ (uploads, cache, db), modules/ or custom themes
 */
final class Updater
{
    /** Paths replaced during an update. */
    public const CORE_PATHS = ['core', 'admin', 'themes/default', 'index.php', 'config.sample.php', 'README.md', 'CHANGELOG.md', 'nginx.conf.example'];

    private static function headers(): array
    {
        $h = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        $token = Crypto::decrypt((string)Options::get('update_token'));
        if ($token !== '') {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        return $h;
    }

    public static function repo(): string
    {
        $repo = (string)Options::get('update_repo', 'narendersinghh/slate');
        return preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? $repo : 'narendersinghh/slate';
    }

    /** @return array{ok:bool, error?:string, current:string, latest?:string, available?:bool, notes?:string, url?:string, published?:string, zip?:string} */
    public static function check(bool $force = false): array
    {
        $cached = Options::get('update_info');
        if (!$force && is_array($cached) && time() - (int)($cached['checked_at'] ?? 0) < 43200) {
            $cached['available'] = version_compare($cached['latest'] ?? '0', SLATE_VERSION, '>');
            return $cached + ['current' => SLATE_VERSION];
        }
        $beta = Options::get('update_channel') === 'beta';
        $r = Http::json('GET', 'https://api.github.com/repos/' . self::repo() . '/releases?per_page=10', self::headers(), null, 20);
        if ($r['error'] || $r['status'] !== 200 || !is_array($r['json'])) {
            $msg = $r['error'] ?: ($r['json']['message'] ?? 'HTTP ' . $r['status']);
            return ['ok' => false, 'error' => 'Could not reach GitHub: ' . $msg, 'current' => SLATE_VERSION];
        }
        $release = null;
        foreach ($r['json'] as $rel) {
            if (!empty($rel['draft']) || (!$beta && !empty($rel['prerelease']))) {
                continue;
            }
            $release = $rel;
            break;
        }
        if (!$release) {
            $info = ['ok' => true, 'checked_at' => time(), 'latest' => SLATE_VERSION, 'notes' => '', 'url' => '', 'zip' => ''];
            Options::set('update_info', $info, false);
            return $info + ['current' => SLATE_VERSION, 'available' => false];
        }
        $zip = $release['zipball_url'] ?? '';
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? '') === 'slate.zip') {
                $zip = $asset['url']; // API asset URL (works for private repos with the token)
            }
        }
        $info = [
            'ok' => true,
            'checked_at' => time(),
            'latest' => ltrim((string)$release['tag_name'], 'vV'),
            'name' => $release['name'] ?? $release['tag_name'],
            'notes' => (string)($release['body'] ?? ''),
            'url' => (string)($release['html_url'] ?? ''),
            'published' => (string)($release['published_at'] ?? ''),
            'zip' => $zip,
        ];
        Options::set('update_info', $info, false);
        return $info + ['current' => SLATE_VERSION, 'available' => version_compare($info['latest'], SLATE_VERSION, '>')];
    }

    /** Download and install the latest release. */
    public static function install(): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'The PHP zip extension is required for updates.'];
        }
        $info = self::check(true);
        if (!$info['ok']) {
            return $info;
        }
        if (empty($info['available'])) {
            return ['ok' => false, 'error' => 'You already run the latest version (' . SLATE_VERSION . ').'];
        }
        foreach (self::CORE_PATHS as $p) {
            $full = SLATE_ROOT . '/' . $p;
            if (file_exists($full) && !is_writable($full)) {
                return ['ok' => false, 'error' => "$p is not writable by PHP. Fix file permissions and retry."];
            }
        }
        @set_time_limit(300);
        $work = SLATE_CACHE . '/update-' . bin2hex(random_bytes(4));
        @mkdir($work, 0755, true);
        $zipFile = $work . '/release.zip';
        $headers = self::headers();
        if (str_contains($info['zip'], '/releases/assets/')) {
            $headers[0] = 'Accept: application/octet-stream';
        }
        $r = Http::request('GET', $info['zip'], $headers, null, 300, $zipFile);
        if ($r['error'] || $r['status'] !== 200 || filesize($zipFile) < 1000) {
            self::rrmdir($work);
            return ['ok' => false, 'error' => 'Download failed: ' . ($r['error'] ?: 'HTTP ' . $r['status'])];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            self::rrmdir($work);
            return ['ok' => false, 'error' => 'The downloaded file is not a valid zip.'];
        }
        // Reject archive entries that try to escape the extraction folder.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                self::rrmdir($work);
                return ['ok' => false, 'error' => 'Unsafe path in update archive.'];
            }
        }
        $zip->extractTo($work . '/src');
        $zip->close();

        $root = $work . '/src';
        if (!is_file($root . '/core/bootstrap.php')) {
            $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
            $root = count($dirs) === 1 ? $dirs[0] : $root;
        }
        if (!is_file($root . '/core/bootstrap.php')) {
            self::rrmdir($work);
            return ['ok' => false, 'error' => 'The release does not look like a Slate package.'];
        }

        $backup = self::backup();
        if (!$backup['ok']) {
            self::rrmdir($work);
            return $backup;
        }
        try {
            foreach (self::CORE_PATHS as $p) {
                $src = $root . '/' . $p;
                if (!file_exists($src)) {
                    continue;
                }
                $dest = SLATE_ROOT . '/' . $p;
                if (is_dir($src)) {
                    self::rrmdir($dest . '.old');
                    if (is_dir($dest)) {
                        rename($dest, $dest . '.old');
                    }
                    self::copyDir($src, $dest);
                    self::rrmdir($dest . '.old');
                } else {
                    copy($src, $dest);
                }
            }
        } catch (\Throwable $e) {
            self::restore($backup['file']);
            self::rrmdir($work);
            return ['ok' => false, 'error' => 'Update failed and was rolled back: ' . $e->getMessage()];
        }
        self::rrmdir($work);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        Options::delete('update_info');
        PageCache::clear();
        log_activity('core_update', SLATE_VERSION . ' → ' . $info['latest']);
        return ['ok' => true, 'version' => $info['latest'], 'backup' => basename($backup['file'])];
    }

    public static function backup(): array
    {
        $dir = SLATE_CONTENT . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create content/backups.'];
        }
        $file = $dir . '/slate-' . SLATE_VERSION . '-' . gmdate('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) {
            return ['ok' => false, 'error' => 'Cannot write backup zip.'];
        }
        foreach (self::CORE_PATHS as $p) {
            $full = SLATE_ROOT . '/' . $p;
            if (is_file($full)) {
                $zip->addFile($full, $p);
            } elseif (is_dir($full)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    $zip->addFile($f->getPathname(), $p . '/' . substr($f->getPathname(), strlen($full) + 1));
                }
            }
        }
        $zip->close();
        // Keep the 5 newest backups.
        $all = glob($dir . '/slate-*.zip') ?: [];
        usort($all, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($all, 5) as $old) {
            @unlink($old);
        }
        return ['ok' => true, 'file' => $file];
    }

    public static function restore(string $file): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            return false;
        }
        $ok = $zip->extractTo(SLATE_ROOT);
        $zip->close();
        return $ok;
    }

    private static function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest) && !mkdir($dest, 0755, true) && !is_dir($dest)) {
            throw new \RuntimeException("Cannot create $dest");
        }
        foreach (new \FilesystemIterator($src) as $f) {
            $target = $dest . '/' . $f->getFilename();
            if ($f->isDir()) {
                self::copyDir($f->getPathname(), $target);
            } elseif (!copy($f->getPathname(), $target)) {
                throw new \RuntimeException("Cannot write $target");
            }
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
