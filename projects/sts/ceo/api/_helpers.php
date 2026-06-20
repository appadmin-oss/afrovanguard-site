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

function sts_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
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
