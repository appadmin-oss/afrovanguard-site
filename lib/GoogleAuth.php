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
 * The whole feature is gated by AV_GOOGLE_CLIENT_ID / AV_GOOGLE_CLIENT_SECRET:
 * with neither set, configured() is false and the UI keeps the button disabled.
 *
 * Security notes:
 *  - `state` is HMAC-signed (av_secret()) + 10-min TTL and carries the post-login
 *    `next` path, so it doubles as CSRF protection for the callback. We also pin
 *    it to a short cookie so a leaked state can't be replayed from another agent.
 *  - The id_token is read WITHOUT a JWKS signature check because it is received
 *    directly from Google's token endpoint over an authenticated TLS channel
 *    (OIDC §3.1.3.7 allows this for the code flow). We still verify `aud`.
 */
declare(strict_types=1);

final class GoogleAuth
{
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const STATE_COOKIE = 'av_oauth';
    const STATE_TTL = 600; // 10 minutes

    public static function configured(): bool
    {
        return defined('AV_GOOGLE_CLIENT_ID') && (string) AV_GOOGLE_CLIENT_ID !== ''
            && defined('AV_GOOGLE_CLIENT_SECRET') && (string) AV_GOOGLE_CLIENT_SECRET !== '';
    }

    public static function redirectUri(): string
    {
        return rtrim(SITE_URL, '/') . '/auth/google/callback';
    }

    /** Sanitise an intended post-login destination to a same-origin path. */
    public static function safeNext(string $next): string
    {
        return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, "\n")) ? $next : '/academy/';
    }

    /* ── signed state (also the CSRF token for the callback) ── */
    public static function makeState(string $next): string
    {
        $exp = time() + self::STATE_TTL;
        $nonce = bin2hex(random_bytes(8));
        $payload = $exp . '.' . $nonce . '.' . rtrim(strtr(base64_encode(self::safeNext($next)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, av_secret());
    }

    /** Verify a state string; returns the carried `next` path or null if invalid. */
    public static function readState(string $state): ?string
    {
        $p = explode('.', $state);
        if (count($p) !== 4) return null;
        [$exp, $nonce, $b64, $sig] = $p;
        if (!ctype_digit($exp) || (int) $exp < time()) return null;
        if (av_secret() === '') return null;
        if (!hash_equals(hash_hmac('sha256', "$exp.$nonce.$b64", av_secret()), $sig)) return null;
        $next = base64_decode(strtr($b64, '-_', '+/'), true);
        return $next === false ? '/academy/' : self::safeNext($next);
    }

    public static function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => AV_GOOGLE_CLIENT_ID,
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Exchange an authorization code for tokens and return the verified profile:
     * ['email','name','verified','sub'] — or null on any failure.
     */
    public static function exchange(string $code): ?array
    {
        if (!self::configured() || $code === '') return null;
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $code,
                'client_id'     => AV_GOOGLE_CLIENT_ID,
                'client_secret' => AV_GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => self::redirectUri(),
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200 || !is_string($res)) return null;
        $tok = json_decode($res, true);
        if (!is_array($tok) || empty($tok['id_token'])) return null;
        return self::readIdToken((string) $tok['id_token']);
    }

    /** Decode + minimally validate the id_token from the token endpoint. */
    private static function readIdToken(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return null;
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims) || empty($claims['email'])) return null;
        if (($claims['aud'] ?? '') !== AV_GOOGLE_CLIENT_ID) return null;       // audience binding
        if (!in_array(($claims['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
        return [
            'email'    => (string) $claims['email'],
            'name'     => (string) ($claims['name'] ?? ''),
            'verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'sub'      => (string) ($claims['sub'] ?? ''),
        ];
    }
}
