<?php
/**
 * lib/Cloudinary.php — image uploads.
 *
 * Uses Cloudinary (signed server-side upload) when configured via
 * CLOUDINARY_CLOUD_NAME / CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET.
 * Otherwise falls back to storing the file under /uploads so the editor
 * still works in development or before keys are set.
 */
declare(strict_types=1);

final class Cloudinary
{
    public static function configured(): bool
    {
        return defined('CLOUDINARY_CLOUD_NAME') && defined('CLOUDINARY_API_KEY') && defined('CLOUDINARY_API_SECRET')
            && CLOUDINARY_CLOUD_NAME && CLOUDINARY_API_KEY && CLOUDINARY_API_SECRET;
    }

    /**
     * Upload a local temp file. Returns ['url'=>..., 'provider'=>...].
     * Throws RuntimeException on failure.
     */
    public static function upload(string $tmpPath, string $originalName, string $folder = 'diary'): array
    {
        if (!is_file($tmpPath)) throw new RuntimeException('No file to upload.');

        if (self::configured()) {
            return self::uploadToCloudinary($tmpPath, $folder);
        }
        return self::uploadLocal($tmpPath, $originalName, $folder);
    }

    private static function uploadToCloudinary(string $tmpPath, string $folder): array
    {
        $ts = time();
        // Signature: all params (except file/api_key/resource_type) sorted, joined, + secret, sha1.
        $params = ['folder' => $folder, 'timestamp' => $ts];
        ksort($params);
        $toSign = urldecode(http_build_query($params));
        $signature = sha1($toSign . CLOUDINARY_API_SECRET);

        $endpoint = 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/image/upload';
        $post = [
            'file'      => new CURLFile($tmpPath),
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $ts,
            'folder'    => $folder,
            'signature' => $signature,
        ];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new RuntimeException('Cloudinary upload failed (' . $code . ') ' . $err);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || empty($data['secure_url'])) {
            throw new RuntimeException('Unexpected Cloudinary response.');
        }
        return ['url' => $data['secure_url'], 'provider' => 'cloudinary', 'public_id' => $data['public_id'] ?? null];
    }

    private static function uploadLocal(string $tmpPath, string $originalName, string $folder): array
    {
        $dir = AV_ROOT . '/uploads/' . preg_replace('/[^a-z0-9_-]/', '', $folder);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'jpg';
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!@copy($tmpPath, $dest) && !@move_uploaded_file($tmpPath, $dest)) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
        return ['url' => '/uploads/' . basename($dir) . '/' . $name, 'provider' => 'local'];
    }
}
