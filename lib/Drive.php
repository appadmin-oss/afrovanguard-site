<?php
/**
 * lib/Drive.php — Google Drive uploads via a service account (documents).
 *
 * Dependency-free: builds an RS256 JWT with openssl, exchanges it for an
 * access token, and uploads to a shared Drive folder the org owns. No
 * per-user OAuth — works headless on a cloud host.
 *
 * Configure (config.php or env):
 *   AV_GDRIVE_SERVICE_ACCOUNT  → the service-account JSON (raw) or a path to it
 *   AV_GDRIVE_FOLDER_ID        → the shared Drive folder id documents land in
 * Unset ⇒ configured() is false and Storage falls back to local /uploads.
 */
declare(strict_types=1);

final class Drive
{
    const SCOPE     = 'https://www.googleapis.com/auth/drive.file';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const UPLOAD    = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink';

    private static ?array $token = null; // simple per-request cache

    public static function configured(): bool
    {
        return self::credentials() !== null
            && defined('AV_GDRIVE_FOLDER_ID') && (string) AV_GDRIVE_FOLDER_ID !== '';
    }

    /** Parse the service-account JSON (raw or a file path). Returns null if absent/invalid. */
    private static function credentials(): ?array
    {
        if (!defined('AV_GDRIVE_SERVICE_ACCOUNT') || (string) AV_GDRIVE_SERVICE_ACCOUNT === '') return null;
        $v = (string) AV_GDRIVE_SERVICE_ACCOUNT;
        $json = (str_starts_with(ltrim($v), '{')) ? $v : (is_file($v) ? (string) @file_get_contents($v) : '');
        $c = json_decode($json, true);
        return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key'])) ? $c : null;
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /** Mint (and cache) an access token via the JWT-bearer grant. */
    private static function accessToken(): ?string
    {
        if (self::$token && self::$token['exp'] > time() + 30) return self::$token['v'];
        $c = self::credentials();
        if (!$c) return null;
        $now = time();
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = self::b64url(json_encode([
            'iss' => $c['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL,
            'iat' => $now, 'exp' => $now + 3600,
        ]));
        $sig = '';
        if (!openssl_sign($header . '.' . $claim, $sig, $c['private_key'], 'sha256WithRSAEncryption')) return null;
        $assertion = $header . '.' . $claim . '.' . self::b64url($sig);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 12,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]),
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200 || !is_string($res)) return null;
        $d = json_decode($res, true);
        if (empty($d['access_token'])) return null;
        self::$token = ['v' => (string) $d['access_token'], 'exp' => $now + (int) ($d['expires_in'] ?? 3600)];
        return self::$token['v'];
    }

    /**
     * Upload a document to the shared folder. Returns ['url','provider','id'].
     * Throws RuntimeException on failure (caller may fall back to local).
     */
    public static function upload(string $tmpPath, string $originalName, string $mime = 'application/octet-stream'): array
    {
        if (!self::configured()) throw new RuntimeException('Drive is not configured.');
        $tok = self::accessToken();
        if (!$tok) throw new RuntimeException('Drive auth failed.');

        $meta = json_encode(['name' => $originalName !== '' ? $originalName : ('document-' . date('Ymd-His')), 'parents' => [AV_GDRIVE_FOLDER_ID]]);
        $boundary = 'av' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
              . "--$boundary\r\nContent-Type: $mime\r\n\r\n" . (string) file_get_contents($tmpPath) . "\r\n--$boundary--";

        $ch = curl_init(self::UPLOAD);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: multipart/related; boundary=' . $boundary],
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code >= 400 || !is_string($res)) throw new RuntimeException('Drive upload failed (' . $code . ').');
        $d = json_decode($res, true);
        if (empty($d['id'])) throw new RuntimeException('Unexpected Drive response.');
        return ['url' => $d['webViewLink'] ?? ('https://drive.google.com/file/d/' . $d['id'] . '/view'), 'provider' => 'drive', 'id' => $d['id']];
    }
}
