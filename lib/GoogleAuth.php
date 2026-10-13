<?php
/**
 * lib/GoogleAuth.php — minimal, dependency-free Google OAuth 2.0 / OIDC client.
 *
 * Server-side authorization-code flow:
 *   /auth/google/start    → redirect to Google with a signed, TTL'd state
 *   /auth/google/callback → verify state, exchange the code for tokens, read
 *                           the id_token, hand the verified profile to
 *                           LmsAuth::oauthSignIn().
 *
 * The whole feature is gated by AV_GOOGLE_CLIENT_ID / AV_GOOGLE_CLIENT_SECRET,
 * read through Config::get(): the Studio's Setup screen first (AvSettings, so an
 * operator with no shell can set them), then config.php, then the environment.
 * With neither set, configured() is false and the UI keeps the button disabled.
 *
 * Security notes:
 *  - `state` is HMAC-signed (av_mac 'google-state') + 10-min TTL and carries the
 *    post-login `next` path, so it doubles as CSRF protection for the callback.
 *    We also pin it to a short cookie so a leaked state can't be replayed from
 *    another agent. Its nonce is sent as the OIDC `nonce` and must come back in
 *    the id_token.
 *  - The id_token is read WITHOUT a JWKS signature check because it is received
 *    directly from Google's token endpoint over an authenticated TLS channel
 *    (OIDC §3.1.3.7 allows this for the code flow). We still verify `aud`,
 *    `iss`, `exp` and `nonce`.
 *  - A Google account whose address Google has not verified signs nobody in:
 *    sign-in finds the account by email, so an unverified address would be a
 *    way into whoever owns that address here.
 *  - The organisation's member role needs the id_token's `hd` (hosted domain)
 *    to be the org domain — Google sets it only for accounts the Workspace
 *    tenant manages. The `hd` request parameter is a chooser hint and proves
 *    nothing on its own.
 */
declare(strict_types=1);

final class GoogleAuth
{
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const STATE_COOKIE = 'av_oauth';
    const STATE_TTL = 600; // 10 minutes

    /** Why the last exchange failed, for the Setup screen ('' = no failure yet). */
    private static string $lastError = '';
    public static function lastError(): string { return self::$lastError; }

    /**
     * Keep the latest failed Google round-trip where the System page can show
     * it. A member is only ever told "we couldn't complete Google sign-in";
     * the reason (redirect_uri_mismatch, invalid_client, an expired grant) was
     * written to an error log nobody on a shared host can open.
     */
    public static function recordFailure(string $where, string $why): void
    {
        if (!class_exists('Database')) return;
        try {
            Database::metaSet('google_last_failure', (string) json_encode(
                ['at' => gmdate('c'), 'where' => mb_substr($where, 0, 40), 'why' => mb_substr($why !== '' ? $why : 'no reason given', 0, 300)],
                JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) { /* never break a redirect over a diagnostic */ }
    }

    /** @return array{at:string,where:string,why:string}|null */
    public static function lastFailure(): ?array
    {
        if (!class_exists('Database')) return null;
        try { $j = json_decode((string) (Database::metaGet('google_last_failure') ?? ''), true); }
        catch (Throwable $e) { return null; }
        return is_array($j) ? $j : null;
    }

    public static function clientId(): string
    {
        return trim((string) (class_exists('Config') ? Config::get('AV_GOOGLE_CLIENT_ID', '')
            : (defined('AV_GOOGLE_CLIENT_ID') ? AV_GOOGLE_CLIENT_ID : getenv('AV_GOOGLE_CLIENT_ID'))));
    }

    public static function clientSecret(): string
    {
        return trim((string) (class_exists('Config') ? Config::get('AV_GOOGLE_CLIENT_SECRET', '')
            : (defined('AV_GOOGLE_CLIENT_SECRET') ? AV_GOOGLE_CLIENT_SECRET : getenv('AV_GOOGLE_CLIENT_SECRET'))));
    }

    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** The organisation's Workspace domain (AV_ORG_DOMAIN). */
    public static function orgDomain(): string
    {
        return strtolower(trim((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng')));
    }

    /** The token endpoint (AV_WS_TOKEN_URL overrides it for tests only). */
    private static function tokenUrl(): string
    {
        $o = trim((string) getenv('AV_WS_TOKEN_URL'));
        return $o !== '' ? $o : self::TOKEN_URL;
    }

    public static function redirectUri(): string
    {
        return rtrim(SITE_URL, '/') . '/auth/google/callback';
    }

    /** Sanitise an intended post-login destination to a same-origin path. */
    public static function safeNext(string $next): string
    {
        /* A same-site PATH only. Browsers read "/\evil.example" as
           "//evil.example", and drop tabs and newlines from a URL, so
           "/\t/evil.example" is one too: a backslash or any control character
           anywhere sends the visitor home instead of off-site. */
        return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//')
                && !str_contains($next, '\\') && !preg_match('/[\x00-\x1f\x7f]/', $next)) ? $next : '/portal/';
    }

    /* ── signed state (also the CSRF token for the callback) ── */
    public static function makeState(string $next): string
    {
        $exp = time() + self::STATE_TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = $exp . '.' . $nonce . '.' . rtrim(strtr(base64_encode(self::safeNext($next)), '+/', '-_'), '=');
        return $payload . '.' . av_mac('google-state', $payload);
    }

    /** The nonce inside a state value: sent to Google as the OIDC nonce. */
    public static function stateNonce(string $state): string
    {
        $p = explode('.', $state);
        return count($p) === 4 && ctype_xdigit($p[1]) ? $p[1] : '';
    }

    /** Verify a state string; returns the carried `next` path or null if invalid. */
    public static function readState(string $state): ?string
    {
        $p = explode('.', $state);
        if (count($p) !== 4) return null;
        [$exp, $nonce, $b64, $sig] = $p;
        if (!ctype_digit($exp) || (int) $exp < time()) return null;
        if (av_secret() === '') return null;
        if (!hash_equals(av_mac('google-state', "$exp.$nonce.$b64"), $sig)) return null;
        $next = base64_decode(strtr($b64, '-_', '+/'), true);
        return $next === false ? '/portal/' : self::safeNext($next);
    }

    /**
     * Build the consent URL. With $extraScopes (space-separated) the flow also
     * asks for OFFLINE access so a refresh token comes back — used to connect the
     * member's Workspace during ordinary sign-in (no separate step). openid/email/
     * profile are always included and de-duplicated against $extraScopes.
     */
    public static function authUrl(string $state, string $loginHint = '', string $extraScopes = ''): string
    {
        $scopes = ['openid', 'email', 'profile'];
        if (trim($extraScopes) !== '') {
            foreach (preg_split('/\s+/', trim($extraScopes)) as $s) { if ($s !== '' && !in_array($s, $scopes, true)) $scopes[] = $s; }
        }
        $params = [
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
            'access_type'   => trim($extraScopes) !== '' ? 'offline' : 'online',
            'prompt'        => 'select_account',
        ];
        // Bound into the id_token, so a token minted for another flow is refused.
        $nonce = self::stateNonce($state);
        if ($nonce !== '') $params['nonce'] = $nonce;
        // Merge with any access the user already granted, so re-consent stays minimal.
        if (trim($extraScopes) !== '') $params['include_granted_scopes'] = 'true';
        // Pre-fill the account chooser with the address the member typed.
        if ($loginHint !== '' && filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
            $params['login_hint'] = $loginHint;
            /* Scope the chooser to the Workspace domain — for the ORG's domain
               only. It used to be set from whatever address was typed, so a
               gmail.com hint sent hd=gmail.com. It is a hint either way; what
               counts is the hd claim in the id_token (orgVerified()). */
            if (self::isOrgAddress($loginHint)) $params['hd'] = self::orgDomain();
        }
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens and return the verified profile:
     * ['email','name','verified','sub'] — or null on any failure.
     */
    public static function exchange(string $code): ?array
    {
        $tok = self::exchangeTokens($code);
        return $tok === null ? null : self::profileFromTokens($tok);
    }

    /**
     * The verified profile from an already-fetched token bundle (id_token).
     * $nonce, when given, must match the id_token's nonce claim.
     */
    public static function profileFromTokens(array $tok, ?string $nonce = null): ?array
    {
        return empty($tok['id_token']) ? null : self::readIdToken((string) $tok['id_token'], $nonce);
    }

    private static function isOrgAddress(string $email): bool
    {
        $at = strrchr(strtolower(trim($email)), '@');
        return $at !== false && substr($at, 1) === self::orgDomain();
    }

    /**
     * Does this profile prove a member of the organisation's Workspace? A
     * verified address at the org domain AND Google's own hd claim naming that
     * domain. The address alone is not enough: an "unmanaged" Google account
     * can be registered on an org address outside the tenant's control.
     */
    public static function orgVerified(array $profile): bool
    {
        return !empty($profile['verified'])
            && self::isOrgAddress((string) ($profile['email'] ?? ''))
            && strtolower((string) ($profile['hd'] ?? '')) === self::orgDomain();
    }

    /**
     * Exchange an authorization code and return the RAW token bundle
     * (access_token, refresh_token, id_token, scope, expires_in) — or null.
     * Callers that only need the profile use exchange(); the sign-in flow uses
     * this to also capture a Workspace connection (refresh token).
     */
    public static function exchangeTokens(string $code): ?array
    {
        self::$lastError = '';
        if (!self::configured() || $code === '') { self::$lastError = 'not configured or no code'; return null; }
        $ch = curl_init(self::tokenUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => self::clientId(),
                'client_secret' => self::clientSecret(),
                'redirect_uri'  => self::redirectUri(),
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($http !== 200 || !is_string($res)) {
            // Surface the real reason (redirect_uri mismatch, invalid_client, etc.)
            // to the error log so production failures are diagnosable.
            error_log('[google] token exchange failed: HTTP ' . $http . ($cerr ? ' curl=' . $cerr : '') . ' body=' . substr((string) $res, 0, 300));
            $j = is_string($res) ? json_decode($res, true) : null;
            self::$lastError = is_array($j) && !empty($j['error'])
                ? (string) $j['error'] . (!empty($j['error_description']) ? ': ' . (string) $j['error_description'] : '')
                : ('HTTP ' . $http . ($cerr ? ' ' . $cerr : ''));
            return null;
        }
        $tok = json_decode($res, true);
        if (!is_array($tok) || empty($tok['id_token'])) {
            error_log('[google] token response missing id_token: ' . substr($res, 0, 300));
            return null;
        }
        return $tok;
    }

    /**
     * Ask Google whether the client ID and secret are real, without a user.
     *
     * Exchanging a code we made up can only fail — HOW it fails is the answer:
     * invalid_grant means Google accepted the client and only refused the code;
     * invalid_client / unauthorized_client mean the ID or secret is wrong. The
     * redirect URI cannot be checked this way (Google compares it against the
     * one used to obtain a code), so it is printed for the operator to register.
     *
     * @return array{ok:bool, detail:string, redirect_uri:string}
     */
    public static function probeClient(): array
    {
        $uri = self::redirectUri();
        if (!self::configured()) return ['ok' => false, 'detail' => 'The OAuth client ID and secret are not set.', 'redirect_uri' => $uri];
        $ch = curl_init(self::tokenUrl());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => 'av-setup-probe', 'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
                'redirect_uri' => $uri, 'grant_type' => 'authorization_code',
            ]),
        ]);
        $res = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        $j = is_string($res) ? json_decode($res, true) : null;
        $err = is_array($j) ? (string) ($j['error'] ?? '') : '';
        $reg = ' Authorised redirect URI to register on this client: ' . $uri;
        if (!is_string($res) || $http === 0) return ['ok' => false, 'detail' => 'Could not reach Google' . ($cerr ? ' (' . $cerr . ')' : '') . '.', 'redirect_uri' => $uri];
        if ($err === 'invalid_grant') return ['ok' => true, 'detail' => 'Google accepted the client ID and secret.' . $reg, 'redirect_uri' => $uri];
        if ($err === 'invalid_client' || $err === 'unauthorized_client') {
            return ['ok' => false, 'detail' => 'Google rejected the client ID or secret (' . $err . '). Copy both again from the same OAuth client.', 'redirect_uri' => $uri];
        }
        if ($err === 'redirect_uri_mismatch') return ['ok' => false, 'detail' => 'The redirect URI is not registered on this client.' . $reg, 'redirect_uri' => $uri];
        return ['ok' => false, 'detail' => 'Unexpected answer from Google: ' . ($err !== '' ? $err : 'HTTP ' . $http) . '.' . $reg, 'redirect_uri' => $uri];
    }

    /** Decode + validate the id_token from the token endpoint. */
    private static function readIdToken(string $jwt, ?string $nonce = null): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return null;
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims) || empty($claims['email'])) return null;
        $aud = $claims['aud'] ?? '';
        if (is_array($aud) ? !in_array(self::clientId(), $aud, true) : $aud !== self::clientId()) return null;   // audience binding
        if (self::clientId() === '') return null;
        if (!in_array(($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
        if (!isset($claims['exp']) || (int) $claims['exp'] < time() - 300) return null;                       // expired (5 min skew)
        if ($nonce !== null && $nonce !== '' && !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) return null;
        return [
            'email'    => (string) $claims['email'],
            'name'     => (string) ($claims['name'] ?? ''),
            'verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sub'      => (string) ($claims['sub'] ?? ''),
            /* The Workspace tenant that manages this account; absent for a
               consumer account. orgVerified() reads it. */
            'hd'       => strtolower((string) ($claims['hd'] ?? '')),
            /* The profile photo (the `profile` scope carries it): the member's
               card photo when they have none yet (CardPhoto::fromGoogle). */
            'picture'  => (string) ($claims['picture'] ?? ''),
        ];
    }
}
