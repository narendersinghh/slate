<?php
declare(strict_types=1);

namespace Slate;

/**
 * Resizes, re-encodes and compresses images with GD.
 * Defaults (Settings → Media): WebP, 87% quality, longest edge 1600px, transparency preserved.
 */
final class ImageOptimizer
{
    public const RASTER = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public static function supported(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public static function isAnimatedGif(string $file): bool
    {
        $data = (string)file_get_contents($file, false, null, 0, 1024 * 1024);
        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data) > 1;
    }

    /**
     * @return array{path:string, width:int, height:int, mime:string}|null  null = leave original untouched
     */
    public static function process(string $src, string $destNoExt, string $mime, array $opts = []): ?array
    {
        if (!self::supported() || !in_array($mime, self::RASTER, true)) {
            return null;
        }
        if ($mime === 'image/gif' && self::isAnimatedGif($src)) {
            return null; // GD would drop the animation.
        }
        $opts += [
            'webp' => (bool)Options::get('media_webp', true),
            'quality' => (int)Options::get('media_quality', 87),
            'max_edge' => (int)Options::get('media_max_edge', 1600),
            'transparency' => (bool)Options::get('media_keep_transparency', true),
        ];
        $img = @imagecreatefromstring((string)file_get_contents($src));
        if (!$img) {
            return null;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $img = self::fixOrientation($img, $src);
        }
        $out = self::resize($img, $opts['max_edge'], $opts['transparency'] && $mime !== 'image/jpeg');
        // (freed automatically since PHP 8)

        $targetMime = $opts['webp'] && function_exists('imagewebp') ? 'image/webp' : ($mime === 'image/avif' ? 'image/webp' : $mime);
        $path = $destNoExt . '.' . self::ext($targetMime);
        $ok = self::write($out, $path, $targetMime, $opts['quality']);
        $w = imagesx($out);
        $h = imagesy($out);
        // (freed automatically since PHP 8)
        return $ok ? ['path' => $path, 'width' => $w, 'height' => $h, 'mime' => $targetMime] : null;
    }

    /** Create a thumbnail (longest edge = $size). */
    public static function thumbnail(string $src, string $dest, int $size, int $quality = 80): bool
    {
        if (!self::supported()) {
            return false;
        }
        $img = @imagecreatefromstring((string)file_get_contents($src));
        if (!$img) {
            return false;
        }
        $out = self::resize($img, $size, true);
        // (freed automatically since PHP 8)
        $ok = self::write($out, $dest, function_exists('imagewebp') ? 'image/webp' : 'image/jpeg', $quality);
        // (freed automatically since PHP 8)
        return $ok;
    }

    private static function resize(\GdImage $img, int $maxEdge, bool $alpha): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxEdge / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefilledrectangle($out, 0, 0, $nw, $nh, imagecolorallocatealpha($out, 0, 0, 0, 127));
        } else {
            imagefilledrectangle($out, 0, 0, $nw, $nh, imagecolorallocate($out, 255, 255, 255));
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    private static function write(\GdImage $img, string $path, string $mime, int $quality): bool
    {
        return match ($mime) {
            'image/webp' => imagewebp($img, $path, $quality),
            'image/png' => imagepng($img, $path, 6),
            'image/gif' => imagegif($img, $path),
            'image/avif' => function_exists('imageavif') && imageavif($img, $path, $quality),
            default => imagejpeg($img, $path, $quality),
        };
    }

    private static function fixOrientation(\GdImage $img, string $src): \GdImage
    {
        $exif = @exif_read_data($src);
        $o = (int)($exif['Orientation'] ?? 1);
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated) {
            // (freed automatically since PHP 8)
            return $rotated;
        }
        return $img;
    }

    public static function ext(string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'webp', 'image/png' => 'png', 'image/gif' => 'gif', 'image/avif' => 'avif', default => 'jpg',
        };
    }
}
