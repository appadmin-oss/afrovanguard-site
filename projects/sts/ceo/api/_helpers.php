<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Shared helpers
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

function sts_cors_and_json() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

function sts_ok($data = null) {
    echo json_encode(['ok' => true] + ($data === null ? [] : ['data' => $data]));
    exit;
}
function sts_fail($error, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

function sts_cidr_match($ip, $cidr) {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$subnet, $bits] = explode('/', $cidr, 2); $bits = (int)$bits;
    $ipB = @inet_pton($ip); $snB = @inet_pton($subnet);
    if ($ipB === false || $snB === false || strlen($ipB) !== strlen($snB)) return false;
    $bytes = intdiv($bits, 8); $rem = $bits % 8;
    if ($bytes > 0 && strncmp($ipB, $snB, $bytes) !== 0) return false;
    if ($rem === 0) return true;
    $mask = chr(0xFF << (8 - $rem) & 0xFF);
    return (($ipB[$bytes] ^ $snB[$bytes]) & $mask) === "\0";
}

/**
 * Real client IP. Forwarded headers are trusted ONLY from a Cloudflare edge
 * (or a STS_TRUSTED_PROXIES CIDR); otherwise they are attacker-controlled and
 * ignored — without this the per-IP rate limits reset on a spoofed header.
 */
function sts_client_ip() {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === '') return 'unknown';
    static $cf = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];
    $trusted = $cf;
    foreach (explode(',', (string)getenv('STS_TRUSTED_PROXIES')) as $c) { $c = trim($c); if ($c !== '') $trusted[] = $c; }
    $peerTrusted = false;
    foreach ($trusted as $cidr) { if (sts_cidr_match($remote, $cidr)) { $peerTrusted = true; break; } }
    if ($peerTrusted) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return $remote;
}

/**
 * File-based rate limiter (per IP per hour).
 * Returns true if under the limit; false otherwise.
 */
function sts_rate_limit($bucket, $limit) {
    $ip = sts_client_ip();
    $hour = date('YmdH');
    $file = DATA_DIR . '/.rate_' . preg_replace('/[^a-z_]/', '', $bucket) . '.json';

    $rates = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    foreach ($rates as $k => $_) { if (strpos($k, $hour) !== 0) unset($rates[$k]); }

    $key = $hour . ':' . $ip;
    $rates[$key] = ($rates[$key] ?? 0) + 1;
    @file_put_contents($file, json_encode($rates), LOCK_EX);

    return $rates[$key] <= $limit;
}

/**
 * Bridge to Google Apps Script web app.
 * Returns ['ok' => bool, 'data' => mixed, 'error' => ?string]
 */
function sts_appscript_post($action, $data = []) {
    if (!defined('APPS_SCRIPT_URL') || strpos(APPS_SCRIPT_URL, 'http') !== 0) {
        return ['ok' => false, 'error' => 'APPS_SCRIPT_URL not configured'];
    }
    $payload = json_encode([
        'action' => $action,
        'data'   => $data,
        'key'    => APPS_SCRIPT_KEY,
    ]);
    $ch = curl_init(APPS_SCRIPT_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/plain;charset=utf-8'],
        CURLOPT_FOLLOWLOCATION => true,  // Apps Script web apps redirect
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code >= 400) {
        return ['ok' => false, 'error' => 'Apps Script HTTP ' . $code . ' ' . $err];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Invalid Apps Script response'];
    return $json;
}

function sts_appscript_get($action, $params = []) {
    if (!defined('APPS_SCRIPT_URL') || strpos(APPS_SCRIPT_URL, 'http') !== 0) {
        return ['ok' => false, 'error' => 'APPS_SCRIPT_URL not configured'];
    }
    $q = http_build_query(array_merge(['action' => $action, 'key' => APPS_SCRIPT_KEY], $params));
    $url = APPS_SCRIPT_URL . (strpos(APPS_SCRIPT_URL, '?') !== false ? '&' : '?') . $q;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) return ['ok' => false, 'error' => 'HTTP ' . $code];
    $json = json_decode($raw, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Invalid response'];
    return $json;
}

function sts_sanitize($s, $max = 500) {
    if ($s === null) return '';
    $s = strip_tags((string)$s);
    return mb_substr(trim($s), 0, $max);
}

function sts_read_json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
