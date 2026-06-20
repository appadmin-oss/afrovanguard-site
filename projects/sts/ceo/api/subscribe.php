<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Newsletter Subscription
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';
sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('subscribe', SUBSCRIBE_RATE_LIMIT)) sts_fail('Too many attempts. Please try again later.', 429);

$body = sts_read_json_body();
$email = strtolower(trim($body['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sts_fail('Invalid email address.');

// ── Local file (dedup) ──────────────────────────────────────────────
$file = DATA_DIR . '/subscribers.json';
$subs = file_exists($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];

foreach ($subs as $s) {
    if (strtolower($s['email'] ?? '') === $email) {
        sts_ok(['duplicate' => true]);
    }
}

$subs[] = [
    'email'     => $email,
    'timestamp' => date('c'),
    'ip'        => sts_client_ip(),
    'source'    => sts_sanitize($body['source'] ?? 'footer', 30),
];
@file_put_contents($file, json_encode($subs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// ── Apps Script (best-effort) ───────────────────────────────────────
sts_appscript_post('subscribe', ['email' => $email, 'source' => 'footer']);

sts_ok();
