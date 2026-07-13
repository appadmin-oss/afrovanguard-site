<?php
/**
 * lib/GoogleWorkspaceUser.php — per-user Google Workspace OAuth (act AS the member).
 *
 * The org service-account client (lib/GoogleWorkspace.php) acts as ONE shared
 * identity (a subject it impersonates). This client is the enterprise
 * counterpart: each member connects THEIR OWN Google account with offline
 * access, and the site then reads/acts on their real Gmail, Calendar and Drive.
 *
 * Flow (incremental authorization — it does NOT touch normal sign-in):
 *   /auth/google/connect     → consent screen requesting Workspace scopes offline
 *   /auth/google/callback    → (shared) detects a connect state, stores tokens
 *   /auth/google/disconnect  → revokes + forgets the tokens
 *
 * Tokens live in `google_connections`, one row per user. Refresh tokens are
 * encrypted at rest (libsodium secretbox, keyed off av_secret()); access tokens
 * are cached with their expiry and refreshed on demand. Nothing here runs unless
 * GoogleAuth is configured, so the site is unaffected until it's turned on.
 */
declare(strict_types=1);

final class GoogleWorkspaceUser
{
    const STATE_COOKIE = 'av_gws';
    const STATE_TTL    = 600; // 10 min
    const SKEW         = 60;  // refresh access tokens this many seconds early

    /** Default per-user scopes (read Gmail, full Calendar, read Drive). Override
     *  with AV_GWS_USER_SCOPES (space-separated). openid/email/profile keep the
     *  connect flow returning an id_token we can pin to the account. */
    const DEFAULT_SCOPES = 'openid email profile '
        . 'https://www.googleapis.com/auth/gmail.readonly '
        . 'https://www.googleapis.com/auth/calendar '
        . 'https://www.googleapis.com/auth/drive.readonly';

    public static function configured(): bool { return GoogleAuth::configured(); }

    public static function scopes(): string
    {
        $s = trim((string) getenv('AV_GWS_USER_SCOPES'));
        return $s !== '' ? $s : self::DEFAULT_SCOPES;
    }

    /* ── endpoints (overridable for tests, shared with GoogleWorkspace) ── */
    private static function tokenUrl(): string
    {
        $o = trim((string) getenv('AV_WS_TOKEN_URL'));
        return $o !== '' ? $o : GoogleAuth::TOKEN_URL;
    }
    private static function apiBase(): string
    {
        $o = trim((string) getenv('AV_WS_BASE_URL'));
        return $o !== '' ? rtrim($o, '/') : 'https://www.googleapis.com';
    }
    private static function revokeUrl(): string
    {
        $o = trim((string) getenv('AV_WS_REVOKE_URL'));
        return $o !== '' ? $o : 'https://oauth2.googleapis.com/revoke';
    }

    /* ── schema ── */
    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS google_connections (
            user_id INTEGER PRIMARY KEY,
            google_sub VARCHAR(64) NOT NULL DEFAULT '',
            email VARCHAR(191) NOT NULL DEFAULT '',
            scopes VARCHAR(1000) NOT NULL DEFAULT '',
            refresh_token TEXT NOT NULL DEFAULT '',
            access_token TEXT NOT NULL DEFAULT '',
            access_expires INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /* ── signed state (carries uid + next; doubles as CSRF for the callback) ── */
    public static function makeState(int $uid, string $next): string
    {
        $exp = time() + self::STATE_TTL;
        $nonce = bin2hex(random_bytes(8));
        $b64 = rtrim(strtr(base64_encode(GoogleAuth::safeNext($next)), '+/', '-_'), '=');
        $payload = 'c.' . $exp . '.' . $nonce . '.' . $uid . '.' . $b64; // 'c' = connect marker
        return $payload . '.' . hash_hmac('sha256', $payload, av_secret());
    }

    /** Verify a connect-state; returns ['uid'=>int,'next'=>string] or null. */
    public static function readState(string $state): ?array
    {
        $p = explode('.', $state);
        if (count($p) !== 6 || $p[0] !== 'c') return null;
        [$mark, $exp, $nonce, $uid, $b64, $sig] = $p;
        if (!ctype_digit($exp) || (int) $exp < time()) return null;
        if (!ctype_digit($uid)) return null;
        if (av_secret() === '') return null;
        $payload = "$mark.$exp.$nonce.$uid.$b64";
        if (!hash_equals(hash_hmac('sha256', $payload, av_secret()), $sig)) return null;
        $next = base64_decode(strtr($b64, '-_', '+/'), true);
        return ['uid' => (int) $uid, 'next' => $next === false ? '/workspace' : GoogleAuth::safeNext($next)];
    }

    public static function connectUrl(string $state, string $loginHint = ''): string
    {
        $params = [
            'client_id'             => AV_GOOGLE_CLIENT_ID,
            'redirect_uri'          => GoogleAuth::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => self::scopes(),
            'state'                 => $state,
            'access_type'           => 'offline',      // ← ask for a refresh token
            'prompt'                => 'consent',       // ← force a refresh token even on re-consent
            'include_granted_scopes'=> 'true',
        ];
        if ($loginHint !== '' && filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
            $params['login_hint'] = $loginHint;
            $at = strrchr($loginHint, '@');
            if ($at !== false) $params['hd'] = substr($at, 1);
        }
        return GoogleAuth::AUTH_URL . '?' . http_build_query($params);
    }

    /* ── token exchange + storage ── */
    public static function exchangeAndStore(string $code, int $uid): bool
    {
        if (!self::configured() || $code === '' || $uid <= 0) return false;
        $tok = self::post(self::tokenUrl(), [
            'code'          => $code,
            'client_id'     => AV_GOOGLE_CLIENT_ID,
            'client_secret' => AV_GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GoogleAuth::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (!is_array($tok) || empty($tok['access_token'])) {
            error_log('[gws-user] code exchange failed for uid ' . $uid);
            return false;
        }
        $profile = !empty($tok['id_token']) ? self::readIdToken((string) $tok['id_token']) : null;
        self::store($uid, $tok, $profile);
        if (class_exists('Events')) { try { Events::emit('workspace.connected', ['user_id' => $uid]); } catch (Throwable $e) {} }
        return true;
    }

    private static function store(int $uid, array $tok, ?array $profile): void
    {
        self::ensure();
        $pdo = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');
        $expires = time() + max(0, (int) ($tok['expires_in'] ?? 3600)) - self::SKEW;
        $accessEnc = self::enc((string) $tok['access_token']);
        $scopes = (string) ($tok['scope'] ?? self::scopes());
        $email = (string) ($profile['email'] ?? '');
        $sub   = (string) ($profile['sub'] ?? '');

        $existing = self::rawRow($uid);
        // A refresh token only comes back on first consent; keep the stored one otherwise.
        $refreshEnc = !empty($tok['refresh_token'])
            ? self::enc((string) $tok['refresh_token'])
            : (string) ($existing['refresh_token'] ?? '');

        if ($existing) {
            $pdo->prepare('UPDATE google_connections SET google_sub=?, email=?, scopes=?, refresh_token=?, access_token=?, access_expires=?, updated_at=? WHERE user_id=?')
                ->execute([$sub ?: ($existing['google_sub'] ?? ''), $email ?: ($existing['email'] ?? ''), $scopes, $refreshEnc, $accessEnc, $expires, $now, $uid]);
        } else {
            $pdo->prepare('INSERT INTO google_connections (user_id, google_sub, email, scopes, refresh_token, access_token, access_expires, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$uid, $sub, $email, $scopes, $refreshEnc, $accessEnc, $expires, $now, $now]);
        }
    }

    private static function rawRow(int $uid): ?array
    {
        self::ensure();
        $s = Database::pdo()->prepare('SELECT * FROM google_connections WHERE user_id = ?');
        $s->execute([$uid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Public, safe connection summary (no secrets). */
    public static function connection(int $uid): ?array
    {
        $r = self::rawRow($uid);
        if (!$r) return null;
        return [
            'email'      => (string) $r['email'],
            'scopes'     => array_values(array_filter(explode(' ', (string) $r['scopes']))),
            'connected'  => ((string) $r['refresh_token']) !== '',
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    public static function connected(int $uid): bool
    {
        $r = self::rawRow($uid);
        return $r !== null && ((string) $r['refresh_token']) !== '';
    }

    public static function disconnect(int $uid): void
    {
        $r = self::rawRow($uid);
        if (!$r) {
            if (class_exists('Events')) { try { Events::emit('workspace.disconnected', ['user_id' => $uid]); } catch (Throwable $e) {} }
            return;
        }
        // Emit FIRST — while the token still works — so listeners can act on the
        // live account (e.g. AvAutomation stops the watch channels remotely).
        if (class_exists('Events')) { try { Events::emit('workspace.disconnected', ['user_id' => $uid]); } catch (Throwable $e) {} }
        // Best-effort remote revoke of the refresh token, then forget it.
        $rt = self::dec((string) $r['refresh_token']);
        if ($rt) { try { self::post(self::revokeUrl(), ['token' => $rt]); } catch (Throwable $e) {} }
        Database::pdo()->prepare('DELETE FROM google_connections WHERE user_id = ?')->execute([$uid]);
    }

    /** A valid access token for this user, refreshing via the refresh token if needed. */
    public static function accessTokenFor(int $uid): ?string
    {
        $r = self::rawRow($uid);
        if (!$r) return null;
        if ((int) $r['access_expires'] > time()) {
            $tok = self::dec((string) $r['access_token']);
            if ($tok) return $tok;
        }
        $rt = self::dec((string) $r['refresh_token']);
        if (!$rt) return null;
        return self::refresh($uid, $rt);
    }

    private static function refresh(int $uid, string $refreshToken): ?string
    {
        $tok = self::post(self::tokenUrl(), [
            'client_id'     => AV_GOOGLE_CLIENT_ID,
            'client_secret' => AV_GOOGLE_CLIENT_SECRET,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        if (!is_array($tok) || empty($tok['access_token'])) {
            error_log('[gws-user] refresh failed for uid ' . $uid);
            return null;
        }
        $expires = time() + max(0, (int) ($tok['expires_in'] ?? 3600)) - self::SKEW;
        Database::pdo()->prepare('UPDATE google_connections SET access_token=?, access_expires=?, updated_at=? WHERE user_id=?')
            ->execute([self::enc((string) $tok['access_token']), $expires, gmdate('Y-m-d H:i:s'), $uid]);
        return (string) $tok['access_token'];
    }

    /* ── authenticated API calls as the user ── */
    public static function apiGet(int $uid, string $path): ?array
    {
        $tok = self::accessTokenFor($uid);
        if (!$tok) return null;
        return self::http('GET', self::apiBase() . $path, $tok, null);
    }
    public static function apiPost(int $uid, string $path, array $body): ?array
    {
        $tok = self::accessTokenFor($uid);
        if (!$tok) return null;
        return self::http('POST', self::apiBase() . $path, $tok, $body);
    }

    /* ── convenience surfaces (each best-effort; [] / null on any failure) ── */

    /** Count of unread inbox messages (via the UNREAD system label). */
    public static function gmailUnread(int $uid): ?int
    {
        $r = self::apiGet($uid, '/gmail/v1/users/me/labels/UNREAD');
        return is_array($r) && isset($r['messagesTotal']) ? (int) $r['messagesTotal'] : null;
    }

    /** Recent inbox messages: [{id, from, subject, date, snippet}]. */
    public static function gmailRecent(int $uid, int $max = 6): array
    {
        $max = max(1, min(10, $max));
        $list = self::apiGet($uid, '/gmail/v1/users/me/messages?' . http_build_query(['maxResults' => $max, 'labelIds' => 'INBOX']));
        $ids = is_array($list) && !empty($list['messages']) ? $list['messages'] : [];
        $out = [];
        foreach ($ids as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') continue;
            $msg = self::apiGet($uid, '/gmail/v1/users/me/messages/' . rawurlencode($id) . '?' . http_build_query([
                'format' => 'metadata', 'metadataHeaders' => 'From',
            ]) . '&metadataHeaders=Subject&metadataHeaders=Date');
            if (!is_array($msg)) continue;
            $h = [];
            foreach (($msg['payload']['headers'] ?? []) as $hd) { $h[strtolower((string) ($hd['name'] ?? ''))] = (string) ($hd['value'] ?? ''); }
            $out[] = [
                'id'      => $id,
                'from'    => self::fromName($h['from'] ?? ''),
                'subject' => $h['subject'] ?? '(no subject)',
                'date'    => $h['date'] ?? '',
                'snippet' => (string) ($msg['snippet'] ?? ''),
                'unread'  => in_array('UNREAD', $msg['labelIds'] ?? [], true),
                'url'     => 'https://mail.google.com/mail/u/0/#inbox/' . rawurlencode($id),
            ];
        }
        return $out;
    }

    /** Upcoming events from the user's primary calendar. */
    public static function calendarUpcoming(int $uid, int $max = 8): array
    {
        $max = max(1, min(20, $max));
        $r = self::apiGet($uid, '/calendar/v3/calendars/primary/events?' . http_build_query([
            'timeMin'      => gmdate('c'),
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => $max,
        ]));
        $out = [];
        foreach ((is_array($r) ? ($r['items'] ?? []) : []) as $e) {
            $start = $e['start']['dateTime'] ?? ($e['start']['date'] ?? '');
            $out[] = [
                'id'       => (string) ($e['id'] ?? ''),
                'title'    => (string) ($e['summary'] ?? '(busy)'),
                'start'    => (string) $start,
                'all_day'  => !isset($e['start']['dateTime']),
                'location' => (string) ($e['location'] ?? ''),
                'meet_url' => (string) ($e['hangoutLink'] ?? ''),
                'url'      => (string) ($e['htmlLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** Create an event on the user's own calendar (optionally with a Meet link). */
    public static function createEvent(int $uid, string $title, string $startIso, int $durationMin = 60, string $desc = '', bool $withMeet = true): ?array
    {
        $start = strtotime($startIso) ?: time();
        $tz = getenv('AV_WS_TZ') ?: 'Africa/Lagos';
        $body = [
            'summary'     => mb_substr($title, 0, 300),
            'description' => mb_substr($desc, 0, 4000),
            'start'       => ['dateTime' => gmdate('c', $start), 'timeZone' => $tz],
            'end'         => ['dateTime' => gmdate('c', $start + max(5, $durationMin) * 60), 'timeZone' => $tz],
        ];
        $q = '';
        if ($withMeet) {
            $body['conferenceData'] = ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
            $q = '?conferenceDataVersion=1';
        }
        $r = self::apiPost($uid, '/calendar/v3/calendars/primary/events' . $q, $body);
        if (!is_array($r) || empty($r['id'])) return null;
        return ['id' => (string) $r['id'], 'url' => (string) ($r['htmlLink'] ?? ''), 'meet_url' => (string) ($r['hangoutLink'] ?? '')];
    }

    /** Recently modified Drive files. */
    public static function driveRecent(int $uid, int $max = 8): array
    {
        $max = max(1, min(25, $max));
        $r = self::apiGet($uid, '/drive/v3/files?' . http_build_query([
            'orderBy'  => 'modifiedTime desc',
            'pageSize' => $max,
            'fields'   => 'files(id,name,webViewLink,modifiedTime,mimeType,iconLink)',
            'q'        => 'trashed = false',
        ]));
        $out = [];
        foreach ((is_array($r) ? ($r['files'] ?? []) : []) as $f) {
            $out[] = [
                'id'       => (string) ($f['id'] ?? ''),
                'name'     => (string) ($f['name'] ?? ''),
                'modified' => (string) ($f['modifiedTime'] ?? ''),
                'mime'     => (string) ($f['mimeType'] ?? ''),
                'url'      => (string) ($f['webViewLink'] ?? ''),
            ];
        }
        return $out;
    }

    /** One-call snapshot for the hub (each piece independent + best-effort). */
    public static function snapshot(int $uid): array
    {
        return [
            'connected'      => self::connected($uid),
            'unread'         => self::gmailUnread($uid),
            'mail'           => self::gmailRecent($uid, 5),
            'events'         => self::calendarUpcoming($uid, 6),
            'files'          => self::driveRecent($uid, 6),
        ];
    }

    /* ── helpers ── */

    /** "Ada Obi <ada@x.com>" → "Ada Obi"; bare address → the address. */
    private static function fromName(string $from): string
    {
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>/', $from, $m)) return trim($m[1]);
        return trim($from) !== '' ? trim($from) : 'Unknown';
    }

    private static function readIdToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return null;
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims)) return null;
        return ['email' => (string) ($claims['email'] ?? ''), 'sub' => (string) ($claims['sub'] ?? '')];
    }

    /* ── crypto (libsodium secretbox; key derived from av_secret()) ── */
    private static function key(): string { return hash('sha256', 'gws|' . av_secret(), true); }

    private static function enc(string $plain): string
    {
        if ($plain === '') return '';
        if (function_exists('sodium_crypto_secretbox')) {
            $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $c = sodium_crypto_secretbox($plain, $n, self::key());
            return 's1:' . base64_encode($n . $c);
        }
        $n = random_bytes(12);
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $n, $tag);
        return 'o1:' . base64_encode($n . $tag . $c);
    }

    private static function dec(string $blob): ?string
    {
        if ($blob === '') return null;
        if (str_starts_with($blob, 's1:')) {
            $raw = base64_decode(substr($blob, 3), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
            $n = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $c = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $p = sodium_crypto_secretbox_open($c, $n, self::key());
            return $p === false ? null : $p;
        }
        if (str_starts_with($blob, 'o1:')) {
            $raw = base64_decode(substr($blob, 3), true);
            if ($raw === false || strlen($raw) <= 28) return null;
            $n = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $c = substr($raw, 28);
            $p = openssl_decrypt($c, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $n, $tag);
            return $p === false ? null : $p;
        }
        return null;
    }

    /* ── HTTP ── */
    private static function post(string $url, array $form): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($res) || $http < 200 || $http >= 300) {
            error_log('[gws-user] POST ' . $url . ' → HTTP ' . $http . ' ' . substr((string) $res, 0, 200));
            return null;
        }
        $j = json_decode($res, true);
        return is_array($j) ? $j : null;
    }

    private static function http(string $method, string $url, string $bearer, ?array $jsonBody): ?array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $bearer, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($res) || $http < 200 || $http >= 300) {
            error_log('[gws-user] ' . $method . ' ' . $url . ' → HTTP ' . $http . ' ' . substr((string) $res, 0, 200));
            return null;
        }
        $j = json_decode($res, true);
        return is_array($j) ? $j : ($res === '' ? [] : null);
    }
}
