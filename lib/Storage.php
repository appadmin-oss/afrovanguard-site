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
        return (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
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

    private static function local(string $tmp, string $originalName, string $folder): array
    {
        $dir = AV_ROOT . '/uploads/' . preg_replace('/[^a-z0-9_-]/', '', $folder);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext  = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($originalName, PATHINFO_EXTENSION))) ?: 'bin';
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!@copy($tmp, $dest) && !@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        return ['url' => '/uploads/' . basename($dir) . '/' . $name, 'provider' => 'local'];
    }
}
