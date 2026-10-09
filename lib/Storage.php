<?php
/**
 * lib/Storage.php — one entry point for file storage, routed by type.
 *
 *   images    → Cloudinary  (lib/Cloudinary.php)   — falls back to local /uploads
 *   documents → Google Drive (lib/Drive.php, service account) — falls back to local
 *
 * Centralising storage here keeps the LMS portable: a cloud deploy just sets
 * the Cloudinary + Drive env vars and no file ever needs the local disk.
 */
declare(strict_types=1);

final class Storage
{
    /** Detect the MIME type of a temp file. */
    public static function mime(string $tmp): string
    {
        if (!is_file($tmp)) return 'application/octet-stream';
        // Shared hosts can switch the fileinfo extension off; an image is still an image.
        if (class_exists('finfo')) {
            $m = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (is_string($m) && $m !== '') return $m;
        }
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($tmp);
            if (is_string($m) && $m !== '') return $m;
        }
        $i = @getimagesize($tmp);
        return is_array($i) && !empty($i['mime']) ? (string) $i['mime'] : 'application/octet-stream';
    }

    /** 'image' | 'document', from MIME (preferred) then extension. */
    public static function kindFor(string $mime, string $name = ''): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if ($mime === 'application/octet-stream' && preg_match('/\.(png|jpe?g|webp|gif|avif|svg)$/i', $name)) return 'image';
        return 'document';
    }

    /**
     * Store a local temp file. $kind: 'auto' (detect) | 'image' | 'document'.
     * Returns ['url'=>..., 'provider'=>..., 'kind'=>...] (+ 'id' for Drive).
     */
    public static function put(string $tmp, string $originalName, string $kind = 'auto', string $folder = 'diary'): array
    {
        if (!is_file($tmp)) throw new RuntimeException('No file to store.');
        $mime = self::mime($tmp);
        if ($kind === 'auto') $kind = self::kindFor($mime, $originalName);

        if ($kind === 'image') {
            return Cloudinary::upload($tmp, $originalName, $folder) + ['kind' => 'image']; // Cloudinary or local
        }

        // documents → Drive, with a local fallback so the app never hard-fails
        if (Drive::configured()) {
            try { return Drive::upload($tmp, $originalName, $mime) + ['kind' => 'document']; }
            catch (Throwable $e) { error_log('[storage] Drive upload failed, using local: ' . $e->getMessage()); }
        }
        return self::local($tmp, $originalName, 'documents') + ['kind' => 'document'];
    }

    /** Where each kind currently lands (for the admin UI / diagnostics). */
    public static function imagesProvider(): string { return Cloudinary::configured() ? 'cloudinary' : 'local'; }
    public static function documentsProvider(): string { return Drive::configured() ? 'drive' : 'local'; }

    /**
     * The extension a file is stored under on the local disk.
     *
     * Chosen from what the bytes ARE (their MIME type), never from the name the
     * browser sent. /uploads/ is under the web root, so a JPEG that also
     * carries PHP in its metadata, uploaded as "x.php", passed every type check
     * and was then saved — and served, and run — as x.php. An unknown type keeps
     * its own extension only when that extension is on a short list of inert
     * ones; anything else is stored as .bin.
     */
    public static function safeExt(string $mime, string $originalName): string
    {
        static $byMime = [
            'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/avif' => 'avif', 'image/heic' => 'heic', 'image/heif' => 'heif',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt', 'text/csv' => 'csv',
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
        ];
        static $inert = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'pdf', 'doc', 'docx', 'xls', 'xlsx',
                         'ppt', 'pptx', 'txt', 'csv', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'wav', 'webm', 'mp4'];
        $mime = strtolower(trim($mime));
        if (isset($byMime[$mime])) return $byMime[$mime];
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        return in_array($ext, $inert, true) ? $ext : 'bin';
    }

    /**
     * Make sure /uploads/ can never run what is stored in it, whatever name a
     * file arrived with — written here as well as shipped, because the folder
     * is created at runtime on a host where nothing else put the rules there.
     */
    public static function guardUploads(): void
    {
        $root = AV_ROOT . '/uploads';
        if (!is_dir($root)) @mkdir($root, 0775, true);
        $ht = $root . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, self::UPLOADS_HTACCESS);
    }

    /** The rules /uploads/.htaccess carries (kept in step with the shipped file). */
    public const UPLOADS_HTACCESS = "# Uploaded files are data, never code.\n"
        . "Options -Indexes\n"
        . "<FilesMatch \"(?i)\\.(php[0-9]?|pht|phtml|phar|phps|cgi|pl|py|sh|shtml|asp|aspx|jsp|htaccess|html?|svgz?|xht|xhtml)$\">\n"
        . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n"
        . "<IfModule mod_mime.c>\n  RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .pht .phar\n  RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .pht .phar\n</IfModule>\n"
        . "<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options \"nosniff\"\n  Header always set Content-Security-Policy \"default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox\"\n</IfModule>\n";

    private static function local(string $tmp, string $originalName, string $folder): array
    {
        self::guardUploads();
        $dir = AV_ROOT . '/uploads/' . preg_replace('/[^a-z0-9_-]/', '', $folder);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext  = self::safeExt(self::mime($tmp), $originalName);
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!@copy($tmp, $dest) && !@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        return ['url' => '/uploads/' . basename($dir) . '/' . $name, 'provider' => 'local'];
    }
}
