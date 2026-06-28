<?php
/**
 * lib/security.php — shared hardening for all PHP-rendered pages + APIs.
 *
 *  - Security response headers (CSP, frame, referrer, permissions, HSTS)
 *  - Stateless signed admin session cookie (httpOnly) + Bearer break-glass
 *  - HMAC CSRF tokens for state-changing requests
 *  - Lightweight file-based rate limiting
 *  - Production error hiding
 */
declare(strict_types=1);

/** Server secret for signing (admin token, else APP_KEY). */
function av_secret(): string {
    if (defined('ADMIN_TOKEN') && ADMIN_TOKEN) return (string) ADMIN_TOKEN;
    if (defined('APP_KEY') && APP_KEY) return (string) APP_KEY;
    $env = getenv('APP_KEY'); return $env ?: '';
}

function av_is_prod(): bool {
    $e = getenv('APP_ENV');
    if ($e) return strtolower($e) !== 'dev' && strtolower($e) !== 'local';
    // Heuristic: localhost ⇒ dev
    $h = $_SERVER['HTTP_HOST'] ?? '';
    return !preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/', $h);
}

function av_harden_errors(): void {
    if (av_is_prod()) { ini_set('display_errors', '0'); }
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

/** Domains allowed to be embedded as iframes (also used by the sanitizer). */
function av_embed_hosts(): array {
    return [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com',
        'open.spotify.com', 'w.soundcloud.com', 'player.soundcloud.com',
        'platform.twitter.com', 'www.instagram.com', 'instagram.com',
        'www.tiktok.com', 'codepen.io', 'www.google.com', 'maps.google.com',
        'docs.google.com', 'drive.google.com', 'calendar.google.com', 'flo.uri.sh', 'public.flourish.studio',
        'www.facebook.com', 'web.facebook.com', 'anchor.fm', 'embed.music.apple.com',
        'www.canva.com', 'datawrapper.dwcdn.net',
    ];
}

/** Send security headers. $page: 'public' | 'admin' (admin allows the editor CDN). */
function send_security_headers(string $page = 'public'): void {
    if (headers_sent()) return;
    $frame = implode(' ', array_map(fn($h) => 'https://' . $h, av_embed_hosts()));
    $script = "'self' 'unsafe-inline' https://cdn.jsdelivr.net https://ajax.googleapis.com https://platform.twitter.com https://www.instagram.com https://www.tiktok.com https://platform.instagram.com";
    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'self'",
        "form-action 'self' https://cacentre.afrovanguard.org.ng",
        "img-src 'self' data: blob: https:",
        "media-src 'self' https: blob:",
        "font-src 'self' https://fonts.gstatic.com data:",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
        "script-src $script",
        "connect-src 'self' https://api.cloudinary.com",
        "frame-src 'self' $frame",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self "https://cacentre.afrovanguard.org.ng")');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (av_is_prod()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/**
 * Fire a domain event from anywhere — including the standalone donation/contact
 * handlers that only load this file (not the full bootstrap). Lazy-loads JUST the
 * event/webhook/DB classes (no global functions ⇒ no redeclare risk on the money
 * path) and is fully guarded, so emitting can never break the caller.
 */
function av_emit_event(string $event, array $payload = []): void
{
    try {
        if (!defined('AV_ROOT'))    define('AV_ROOT', dirname(__DIR__));
        if (!defined('AV_DB_PATH')) define('AV_DB_PATH', AV_ROOT . '/db/diary.sqlite');
        if (!class_exists('Database')) require_once AV_ROOT . '/lib/Database.php';
        if (!class_exists('Events'))   require_once AV_ROOT . '/lib/Events.php';
        if (!class_exists('Webhooks')) require_once AV_ROOT . '/lib/Webhooks.php';
        Events::emit($event, $payload);
    } catch (Throwable $e) {
        error_log('[events] emit ' . $event . ' failed: ' . $e->getMessage());
    }
}

/* ── HMAC CSRF tokens (stateless) ─────────────────────────────── */
function av_csrf_token(int $ttl = 7200): string {
    $secret = av_secret(); if ($secret === '') return '';
    $exp = time() + $ttl; $nonce = bin2hex(random_bytes(8));
    $payload = $exp . '.' . $nonce;
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}
function av_csrf_valid(?string $t): bool {
    $secret = av_secret(); if ($secret === '' || !$t) return false;
    $p = explode('.', $t); if (count($p) !== 3) return false;
    [$exp, $nonce, $sig] = $p;
    if (!ctype_digit($exp) || (int) $exp < time()) return false;
    return hash_equals(hash_hmac('sha256', $exp . '.' . $nonce, $secret), $sig);
}
function av_csrf_require(): void {
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!av_csrf_valid($t)) json_out(['ok' => false, 'error' => 'Session expired — please reload and sign in again.'], 403);
}

/* ── Admin session cookie (httpOnly, signed) ──────────────────── */
define('AV_ADMIN_COOKIE', 'av_admin');
function av_admin_cookie_issue(int $ttl = 43200): void {
    $secret = av_secret(); if ($secret === '') return;
    $exp = time() + $ttl; $nonce = bin2hex(random_bytes(10));
    $val = $exp . '.' . $nonce . '.' . hash_hmac('sha256', $exp . '.' . $nonce, $secret);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(AV_ADMIN_COOKIE, $val, [
        'expires' => $exp, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[AV_ADMIN_COOKIE] = $val;
}
function av_admin_cookie_valid(): bool {
    $secret = av_secret(); if ($secret === '') return false;
    $v = $_COOKIE[AV_ADMIN_COOKIE] ?? ''; if ($v === '') return false;
    $p = explode('.', $v); if (count($p) !== 3) return false;
    [$exp, $nonce, $sig] = $p;
    if (!ctype_digit($exp) || (int) $exp < time()) return false;
    return hash_equals(hash_hmac('sha256', $exp . '.' . $nonce, $secret), $sig);
}
function av_admin_cookie_clear(): void {
    setcookie(AV_ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[AV_ADMIN_COOKIE]);
}

/** True if the request carries a valid Bearer admin token. */
function av_admin_bearer_ok(): bool {
    if (!defined('ADMIN_TOKEN') || strlen((string) ADMIN_TOKEN) < 8) return false;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    return $token !== '' && hash_equals((string) ADMIN_TOKEN, $token);
}

/* ── File-based rate limiting ─────────────────────────────────── */
function av_cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2); $bits = (int) $bits;
    $ipB = @inet_pton($ip); $snB = @inet_pton($subnet);
    if ($ipB === false || $snB === false || strlen($ipB) !== strlen($snB)) return false;
    $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipB, $snB, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr(0xFF << (8 - $rem) & 0xFF);
    return (($ipB[$bytes] ^ $snB[$bytes]) & $mask) === "\0";
}
/**
 * Real client IP. CF-Connecting-IP / X-Forwarded-For are honoured ONLY when the
 * direct peer (REMOTE_ADDR) is a Cloudflare edge or an operator-listed proxy
 * (AV_TRUSTED_PROXIES, comma CIDRs). Otherwise those headers are attacker-
 * controlled — trusting them would let anyone reset every rate limit and forge
 * the IP bound to a session. Refresh CF ranges from cloudflare.com/ips.
 */
function av_client_ip(): string {
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') return 'cli';
    static $cf = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];
    $trusted = $cf;
    foreach (explode(',', (string) getenv('AV_TRUSTED_PROXIES')) as $c) { $c = trim($c); if ($c !== '') $trusted[] = $c; }
    $peerTrusted = false;
    foreach ($trusted as $cidr) { if (av_cidr_match($remote, $cidr)) { $peerTrusted = true; break; } }
    if ($peerTrusted) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return $remote;
}
function av_rate_ok(string $bucket, int $max, int $window): bool {
    $dir = AV_ROOT . '/db/cache/rl'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $key = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $bucket . '_' . av_client_ip()) . '.json';
    $now = time(); $hits = [];
    if (is_file($key)) { $hits = json_decode((string) @file_get_contents($key), true) ?: []; }
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    if (count($hits) >= $max) return false;
    $hits[] = $now; @file_put_contents($key, json_encode($hits), LOCK_EX);
    return true;
}
