<?php
declare(strict_types=1);

namespace Slate;

/** Media library: uploads, optimization, metadata, URLs. */
final class Media
{
    private static array $cache = [];

    /** Extensions that are never accepted, regardless of settings. */
    private const BLOCKED = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'htaccess', 'htpasswd', 'ini', 'asp', 'aspx', 'jsp'];

    public static function find(int $id): ?array
    {
        if (!$id) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::$cache[$id] = DB::row('SELECT * FROM {{media}} WHERE id = :id', ['id' => $id]);
        }
        return self::$cache[$id];
    }

    public static function prime(array $ids): void
    {
        $ids = array_diff(array_unique(array_filter(array_map('intval', $ids))), array_keys(self::$cache));
        if (!$ids) {
            return;
        }
        [$in, $params] = DB::in($ids);
        foreach (DB::all("SELECT * FROM {{media}} WHERE id IN ($in)", $params) as $row) {
            self::$cache[(int)$row['id']] = $row;
        }
    }

    public static function url(array|int|null $media, string $size = 'full'): string
    {
        if (is_int($media)) {
            $media = self::find($media);
        }
        if (!$media) {
            return '';
        }
        $path = $size === 'thumb' && $media['thumb_path'] ? $media['thumb_path'] : $media['path'];
        return url('content/uploads/' . $path);
    }

    public static function isImage(array $media): bool
    {
        return str_starts_with((string)$media['mime'], 'image/');
    }

    /**
     * Handle one entry of $_FILES.
     * @return array{ok:bool, media?:array, error?:string}
     */
    public static function upload(array $file, bool $trustedLocal = false): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadError((int)($file['error'] ?? 4))];
        }
        $tmp = (string)$file['tmp_name'];
        if (!$trustedLocal && !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }
        $maxBytes = (int)Options::get('media_max_upload_mb', 20) * 1024 * 1024;
        $size = (int)filesize($tmp);
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'File is larger than the ' . Options::get('media_max_upload_mb', 20) . ' MB limit.'];
        }
        $original = (string)$file['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string)Options::get('media_allowed')))));
        if (!$ext || in_array($ext, self::BLOCKED, true) || !in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => "Files of type .$ext are not allowed."];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        if (self::mimeMismatch($ext, $mime)) {
            return ['ok' => false, 'error' => 'The file contents do not match its extension.'];
        }

        $sub = gmdate('Y/m');
        $dir = SLATE_UPLOADS . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create the upload folder. Check permissions of content/uploads.'];
        }
        $baseName = Options::get('media_rename_seo', true)
            ? slugify(pathinfo($original, PATHINFO_FILENAME))
            : preg_replace('/[^A-Za-z0-9._-]/', '-', pathinfo($original, PATHINFO_FILENAME));
        $baseName = self::uniqueBase($dir, substr((string)$baseName, 0, 80) ?: 'file');

        $width = $height = null;
        $finalPath = null;
        $finalMime = $mime;

        if (Options::get('media_optimize', true) && in_array($mime, ImageOptimizer::RASTER, true)) {
            $result = ImageOptimizer::process($tmp, $dir . '/' . $baseName, $mime);
            if ($result) {
                $finalPath = $result['path'];
                $finalMime = $result['mime'];
                $width = $result['width'];
                $height = $result['height'];
            }
        }
        if (!$finalPath) {
            $finalPath = $dir . '/' . $baseName . '.' . $ext;
            $moved = $trustedLocal ? copy($tmp, $finalPath) : move_uploaded_file($tmp, $finalPath);
            if (!$moved) {
                return ['ok' => false, 'error' => 'Could not save the file.'];
            }
            if (str_starts_with($mime, 'image/') && ($info = @getimagesize($finalPath))) {
                [$width, $height] = $info;
            }
        } elseif (!$trustedLocal) {
            @unlink($tmp);
        }
        @chmod($finalPath, 0644);

        $thumb = null;
        if (str_starts_with($finalMime, 'image/') && $width && max($width, $height) > (int)Options::get('media_thumb_size', 400)) {
            $thumbFile = $dir . '/' . $baseName . '-thumb.' . (function_exists('imagewebp') ? 'webp' : 'jpg');
            if (ImageOptimizer::thumbnail($finalPath, $thumbFile, (int)Options::get('media_thumb_size', 400))) {
                $thumb = $sub . '/' . basename($thumbFile);
            }
        }

        $title = ucfirst(trim(str_replace(['-', '_'], ' ', pathinfo($original, PATHINFO_FILENAME))));
        $id = DB::insert('media', [
            'path' => $sub . '/' . basename($finalPath),
            'thumb_path' => $thumb,
            'original_name' => mb_substr($original, 0, 255),
            'mime' => $finalMime,
            'size' => (int)filesize($finalPath),
            'original_size' => $size,
            'width' => $width,
            'height' => $height,
            'alt' => '',
            'title' => mb_substr($title, 0, 255),
            'caption' => '',
            'uploaded_by' => Auth::id() ?: null,
            'created_at' => now(),
        ]);
        Hooks::action('media_uploaded', $id);
        return ['ok' => true, 'media' => self::find($id)];
    }

    private static function mimeMismatch(string $ext, string $mime): bool
    {
        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        if (in_array($ext, $imageExt, true)) {
            return !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml';
        }
        // Never accept anything the server could interpret as code.
        return in_array($mime, ['text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/html', 'image/svg+xml', 'application/javascript', 'text/javascript'], true);
    }

    private static function uniqueBase(string $dir, string $base): string
    {
        $candidate = $base;
        $n = 2;
        while (glob($dir . '/' . $candidate . '.*')) {
            $candidate = $base . '-' . $n++;
        }
        return $candidate;
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed (code ' . $code . ').',
        };
    }

    /** Update metadata. Supplying a new "filename" renames the file on disk (SEO-friendly image URLs). */
    public static function update(int $id, array $data): ?string
    {
        $m = self::find($id);
        if (!$m) {
            return 'Media not found.';
        }
        $row = [
            'alt' => mb_substr(trim(strip_tags((string)($data['alt'] ?? $m['alt']))), 0, 255),
            'title' => mb_substr(trim(strip_tags((string)($data['title'] ?? $m['title']))), 0, 255),
            'caption' => trim(strip_tags((string)($data['caption'] ?? $m['caption']))),
        ];
        $newName = isset($data['filename']) ? slugify((string)$data['filename']) : '';
        $currentName = pathinfo($m['path'], PATHINFO_FILENAME);
        if ($newName !== '' && $newName !== $currentName) {
            $dir = dirname(SLATE_UPLOADS . '/' . $m['path']);
            $ext = pathinfo($m['path'], PATHINFO_EXTENSION);
            $newName = self::uniqueBase($dir, $newName);
            $sub = dirname($m['path']);
            if (!@rename(SLATE_UPLOADS . '/' . $m['path'], $dir . '/' . $newName . '.' . $ext)) {
                return 'Could not rename the file.';
            }
            $oldUrl = url('content/uploads/' . $m['path']);
            $row['path'] = $sub . '/' . $newName . '.' . $ext;
            if ($m['thumb_path']) {
                $text = pathinfo($m['thumb_path'], PATHINFO_EXTENSION);
                if (@rename(SLATE_UPLOADS . '/' . $m['thumb_path'], $dir . '/' . $newName . '-thumb.' . $text)) {
                    $row['thumb_path'] = $sub . '/' . $newName . '-thumb.' . $text;
                }
            }
            // Update references inside content so nothing breaks.
            DB::query('UPDATE {{posts}} SET content = REPLACE(content, :old, :new) WHERE content LIKE :like', [
                'old' => $oldUrl, 'new' => url('content/uploads/' . $row['path']), 'like' => '%' . $oldUrl . '%',
            ]);
        }
        DB::update('media', $row, ['id' => $id]);
        unset(self::$cache[$id]);
        PageCache::clear();
        return null;
    }

    public static function delete(int $id): void
    {
        $m = self::find($id);
        if (!$m) {
            return;
        }
        foreach ([$m['path'], $m['thumb_path']] as $p) {
            if ($p) {
                $full = realpath(SLATE_UPLOADS . '/' . $p);
                if ($full && str_starts_with($full, realpath(SLATE_UPLOADS))) {
                    @unlink($full);
                }
            }
        }
        DB::delete('media', ['id' => $id]);
        DB::query('UPDATE {{posts}} SET featured_media_id = NULL WHERE featured_media_id = :id', ['id' => $id]);
        unset(self::$cache[$id]);
        PageCache::clear();
    }

    public static function list(int $page = 1, string $search = '', string $kind = '', int $perPage = 40): array
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = '(title LIKE :q OR original_name LIKE :q2 OR alt LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if ($kind === 'image') {
            $where[] = "mime LIKE 'image/%'";
        } elseif ($kind === 'video') {
            $where[] = "mime LIKE 'video/%'";
        } elseif ($kind === 'document') {
            $where[] = "mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%'";
        } elseif ($kind === 'noalt') {
            $where[] = "mime LIKE 'image/%' AND alt = ''";
        }
        $w = implode(' AND ', $where);
        $total = (int)DB::val("SELECT COUNT(*) FROM {{media}} WHERE $w", $params);
        $offset = (max(1, $page) - 1) * $perPage;
        $items = DB::all("SELECT * FROM {{media}} WHERE $w ORDER BY id DESC LIMIT $perPage OFFSET $offset", $params);
        return ['items' => array_map([self::class, 'toJson'], $items), 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
    }

    public static function toJson(array $m): array
    {
        return [
            'id' => (int)$m['id'],
            'url' => self::url($m),
            'absolute_url' => site_url('content/uploads/' . $m['path']),
            'thumb' => self::url($m, 'thumb'),
            'filename' => pathinfo($m['path'], PATHINFO_FILENAME),
            'ext' => pathinfo($m['path'], PATHINFO_EXTENSION),
            'original_name' => $m['original_name'],
            'mime' => $m['mime'],
            'is_image' => str_starts_with($m['mime'], 'image/'),
            'size' => (int)$m['size'],
            'size_h' => format_bytes((int)$m['size']),
            'original_size_h' => format_bytes((int)$m['original_size']),
            'saved' => $m['original_size'] > 0 ? max(0, (int)round(100 - $m['size'] / $m['original_size'] * 100)) : 0,
            'width' => $m['width'] ? (int)$m['width'] : null,
            'height' => $m['height'] ? (int)$m['height'] : null,
            'alt' => $m['alt'],
            'title' => $m['title'],
            'caption' => $m['caption'],
            'date' => local_date($m['created_at']),
        ];
    }
}
