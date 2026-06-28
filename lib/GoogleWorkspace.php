<?php
/**
 * lib/GoogleWorkspace.php — REAL Google Workspace reads (not just deep links).
 *
 * Dependency-free service-account client (same RS256-JWT → access-token pattern
 * as lib/Drive.php) that actually calls the Google REST APIs and returns live
 * data: upcoming Calendar events, files in a shared Drive folder, and — with
 * domain-wide delegation — the Workspace user directory.
 *
 * Configure (config.php or env):
 *   AV_GDRIVE_SERVICE_ACCOUNT  service-account JSON (raw) or a path to it
 *   AV_WS_SUBJECT              admin email to impersonate for the Directory API
 *                             (domain-wide delegation; falls back to ADMIN_EMAIL)
 *   AV_WS_CALENDAR_ID          calendar to read (falls back to the subject / domain)
 *   AV_WS_DRIVE_FOLDER_ID      Drive folder to list (or AV_GDRIVE_FOLDER_ID)
 *
 * Every read is best-effort: any auth/transport/permission error is logged and
 * returns [] so the member portal cleanly falls back to the launchpad + embeds.
 * Unconfigured ⇒ configured() is false and nothing is attempted.
 */
declare(strict_types=1);

final class GoogleWorkspace
{
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE_CALENDAR  = 'https://www.googleapis.com/auth/calendar.readonly';
    const SCOPE_DRIVE     = 'https://www.googleapis.com/auth/drive.metadata.readonly';
    const SCOPE_DIRECTORY = 'https://www.googleapis.com/auth/admin.directory.user.readonly';

    /** @var array<string,array{v:string,exp:int}> per-request token cache, keyed by scope+subject */
    private static array $tokens = [];

    public static function configured(): bool { return self::credentials() !== null; }

    private static function credentials(): ?array
    {
        $v = (string) Config::get('AV_GDRIVE_SERVICE_ACCOUNT', '');
        if ($v === '') return null;
        $json = str_starts_with(ltrim($v), '{') ? $v : (is_file($v) ? (string) @file_get_contents($v) : '');
        $c = json_decode($json, true);
        return (is_array($c) && !empty($c['client_email']) && !empty($c['private_key'])) ? $c : null;
    }

    /** Admin to impersonate for delegated APIs (Directory). */
    private static function subject(): string
    {
        return (string) (Config::get('AV_WS_SUBJECT', '') ?: Config::get('ADMIN_EMAIL', ''));
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /**
     * Mint (per-request cache) an access token for one scope. $impersonate adds
     * the `sub` claim (required for Directory; harmless for Calendar/Drive on
     * resources the impersonated user can see). Returns null on any failure.
     */
    private static function accessToken(string $scope, bool $impersonate = false): ?string
    {
        $c = self::credentials();
        if (!$c) return null;
        $sub = $impersonate ? self::subject() : '';
        $key = $scope . '|' . $sub;
        if (isset(self::$tokens[$key]) && self::$tokens[$key]['exp'] > time() + 30) return self::$tokens[$key]['v'];

        $now = time();
        $claim = ['iss' => $c['client_email'], 'scope' => $scope, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600];
        if ($sub !== '') $claim['sub'] = $sub;
        $header = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::b64url(json_encode($claim));
        $sig = '';
        if (!openssl_sign($header . '.' . $payload, $sig, $c['private_key'], 'sha256WithRSAEncryption')) return null;
        $assertion = $header . '.' . $payload . '.' . self::b64url($sig);

        $res = self::http('POST', self::TOKEN_URL, null, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
        ]));
        if (!$res || empty($res['json']['access_token'])) {
            if ($res) error_log('[workspace] token error ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            return null;
        }
        self::$tokens[$key] = ['v' => (string) $res['json']['access_token'], 'exp' => $now + (int) ($res['json']['expires_in'] ?? 3600)];
        return self::$tokens[$key]['v'];
    }

    /** Minimal curl wrapper → ['code'=>int,'body'=>string,'json'=>?array] or null on transport error. */
    private static function http(string $method, string $url, ?string $bearer, ?string $postBody = null): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers];
        if ($method === 'POST') { $opt[CURLOPT_POST] = true; if ($postBody !== null) $opt[CURLOPT_POSTFIELDS] = $postBody; }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) { error_log('[workspace] transport: ' . $err); return null; }
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    }

    private static function apiGet(string $url, string $scope, bool $impersonate): ?array
    {
        $tok = self::accessToken($scope, $impersonate);
        if (!$tok) return null;
        $res = self::http('GET', $url, $tok);
        if (!$res || $res['code'] >= 400) {
            if ($res) error_log('[workspace] GET ' . $url . ' → ' . $res['code'] . ': ' . substr((string) $res['body'], 0, 200));
            return null;
        }
        return is_array($res['json']) ? $res['json'] : null;
    }

    /* ── live reads (normalised; [] on any problem) ─────────────── */

    /** Upcoming events from a calendar, soonest first. */
    public static function calendarEvents(?string $calendarId = null, int $max = 8): array
    {
        if (!self::configured()) return [];
        $cal = $calendarId ?: (string) (Config::get('AV_WS_CALENDAR_ID', '') ?: self::subject());
        if ($cal === '') return [];
        $q = http_build_query([
            'timeMin' => gmdate('Y-m-d\TH:i:s\Z'), 'singleEvents' => 'true', 'orderBy' => 'startTime',
            'maxResults' => max(1, min(50, $max)),
        ]);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal) . '/events?' . $q;
        $d = self::apiGet($url, self::SCOPE_CALENDAR, self::subject() !== '');
        if (!$d || empty($d['items'])) return [];
        $out = [];
        foreach ($d['items'] as $e) {
            $start = $e['start']['dateTime'] ?? ($e['start']['date'] ?? '');
            $out[] = [
                'title'    => (string) ($e['summary'] ?? '(busy)'),
                'start'    => $start,
                'all_day'  => !isset($e['start']['dateTime']),
                'location' => (string) ($e['location'] ?? ''),
                'url'      => (string) ($e['htmlLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** Files in a shared Drive folder, most-recently-modified first. */
    public static function driveFiles(?string $folderId = null, int $max = 12): array
    {
        if (!self::configured()) return [];
        $folder = $folderId ?: (string) (Config::get('AV_WS_DRIVE_FOLDER_ID', '') ?: Config::get('AV_GDRIVE_FOLDER_ID', ''));
        if ($folder === '') return [];
        $q = http_build_query([
            'q' => "'" . str_replace("'", '', $folder) . "' in parents and trashed = false",
            'fields' => 'files(id,name,mimeType,modifiedTime,webViewLink,iconLink)',
            'orderBy' => 'modifiedTime desc', 'pageSize' => max(1, min(100, $max)),
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]);
        $d = self::apiGet('https://www.googleapis.com/drive/v3/files?' . $q, self::SCOPE_DRIVE, self::subject() !== '');
        if (!$d || empty($d['files'])) return [];
        $out = [];
        foreach ($d['files'] as $f) {
            $out[] = [
                'name'     => (string) ($f['name'] ?? 'Untitled'),
                'mime'     => (string) ($f['mimeType'] ?? ''),
                'modified' => (string) ($f['modifiedTime'] ?? ''),
                'url'      => (string) ($f['webViewLink'] ?? ''),
                'icon'     => (string) ($f['iconLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** Org users from the Directory API (needs domain-wide delegation + AV_WS_SUBJECT). */
    public static function directoryUsers(int $max = 200): array
    {
        if (!self::configured() || self::subject() === '') return [];
        $domain = (string) Config::get('AV_ORG_DOMAIN', 'afrovanguard.org.ng');
        $q = http_build_query(['domain' => $domain, 'maxResults' => max(1, min(500, $max)), 'orderBy' => 'email', 'viewType' => 'domain_public']);
        $d = self::apiGet('https://admin.googleapis.com/admin/directory/v1/users?' . $q, self::SCOPE_DIRECTORY, true);
        if (!$d || empty($d['users'])) return [];
        $out = [];
        foreach ($d['users'] as $u) {
            $out[] = [
                'name'    => (string) ($u['name']['fullName'] ?? ''),
                'email'   => (string) ($u['primaryEmail'] ?? ''),
                'photo'   => (string) ($u['thumbnailPhotoUrl'] ?? ''),
                'is_admin'=> !empty($u['isAdmin']),
                'suspended'=> !empty($u['suspended']),
            ];
        }
        return $out;
    }

    /**
     * Connectivity probe for the Studio → System page. Reports what actually
     * works against live Google, without leaking tokens.
     */
    public static function probe(): array
    {
        if (!self::configured()) return ['configured' => false];
        $tok = self::accessToken(self::SCOPE_CALENDAR, self::subject() !== '');
        return [
            'configured' => true,
            'subject'    => self::subject(),
            'token_ok'   => $tok !== null,
            'calendar'   => count(self::calendarEvents(null, 1)),
            'drive'      => count(self::driveFiles(null, 1)),
            'directory'  => self::subject() !== '' ? count(self::directoryUsers(1)) : null,
        ];
    }
}
