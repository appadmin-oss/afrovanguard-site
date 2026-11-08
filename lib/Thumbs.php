<?php
/**
 * lib/Thumbs.php — an image at the size it is shown, not the size it was
 * uploaded.
 *
 * Covers are uploaded straight from phones: 3–5 MB, 4000 px wide. The admin
 * console's Academy list drew every one of them as a 64-pixel thumbnail —
 * 52 MB for 46 courses on the test catalogue, every time the page opened —
 * and the public catalogue loaded a full photo per card.
 *
 *   Thumbs::url($src, 320)  → the URL to put in the page.
 *     · A Cloudinary image   → Cloudinary resizes it (w_, c_limit, f_auto, q_auto).
 *     · One of our uploads   → /thumb.php, which resizes once with GD, keeps the
 *                              result in /uploads/thumbs/ and serves it with a
 *                              year-long cache.
 *     · Anything else        → unchanged (a site image, another host).
 *
 * Widths are a fixed ladder, so a page cannot fill the disk with one
 * thumbnail per pixel.
 */
declare(strict_types=1);

final class Thumbs
{
    public const WIDTHS = [160, 320, 640, 960, 1280];

    public static function width(int $w): int
    {
        foreach (self::WIDTHS as $x) if ($x >= $w) return $x;
        return self::WIDTHS[count(self::WIDTHS) - 1];
    }

    public static function url(?string $src, int $w): string
    {
        $src = trim((string) $src);
        if ($src === '') return '';
        $w = self::width($w);
        if (preg_match('~^(https://res\.cloudinary\.com/[^/]+/image/upload/)(.+)$~', $src, $m)) {
            // Already transformed? Leave the author's transformation alone.
            if (preg_match('~^[a-z]_[^/]*(,[a-z]_[^/]*)*/~', $m[2])) return $src;
            return $m[1] . 'w_' . $w . ',c_limit,f_auto,q_auto/' . $m[2];
        }
        $path = (string) parse_url($src, PHP_URL_PATH);
        if (self::localImage($path) !== null) return '/thumb.php?w=' . $w . '&src=' . rawurlencode($path);
        return $src;
    }

    /** "srcset" for an <img>: the given widths, from the same source. */
    public static function srcset(?string $src, array $widths): string
    {
        $parts = [];
        foreach ($widths as $w) { $u = self::url($src, (int) $w); if ($u === '' || $u === $src) return ''; $parts[] = $u . ' ' . self::width((int) $w) . 'w'; }
        return implode(', ', array_unique($parts));
    }

    /** The file behind an /uploads/ image path, or null when it is not one. */
    public static function localImage(string $path): ?string
    {
        if (!preg_match('~^/uploads/[a-z0-9_-]+/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)$~i', $path)) return null;
        $root = realpath(AV_ROOT . '/uploads');
        $file = realpath(AV_ROOT . $path);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        if (str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR)) return null;
        return $file;
    }

    /**
     * The thumbnail file for a source + width, made on first request.
     * Returns [path, mime] or null (not an image we can read; no GD).
     */
    public static function make(string $path, int $w): ?array
    {
        $file = self::localImage($path);
        if ($file === null) return null;
        $w = self::width($w);
        $dir = AV_ROOT . '/uploads/thumbs/' . $w;
        $key = substr(hash('sha256', $path . '|' . filemtime($file) . '|' . filesize($file)), 0, 24);
        $webp = function_exists('imagewebp');
        $out = $dir . '/' . $key . ($webp ? '.webp' : '.jpg');
        $mime = $webp ? 'image/webp' : 'image/jpeg';
        if (is_file($out)) return [$out, $mime];
        if (!extension_loaded('gd')) return [$file, (string) (getimagesize($file)['mime'] ?? 'image/jpeg')];
        $info = @getimagesize($file);
        if (!$info || $info[0] < 1 || $info[1] < 1) return null;
        [$sw, $sh] = $info;
        /* Never upscale, and refuse a decompression bomb: GD holds the whole
           bitmap in memory, four bytes a pixel. */
        if ($sw * $sh > 40_000_000) return null;
        if ($sw <= $w) return [$file, (string) $info['mime']];
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($file),
            default => false,
        };
        if (!$src) return null;
        $h = max(1, (int) round($sh * $w / $sw));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        if (class_exists('Storage')) Storage::guardUploads();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = $webp ? @imagewebp($dst, $tmp, 80) : @imagejpeg($dst, $tmp, 82);
        imagedestroy($src); imagedestroy($dst);
        if (!$ok || !@rename($tmp, $out)) { @unlink($tmp); return null; }
        return [$out, $mime];
    }
}
