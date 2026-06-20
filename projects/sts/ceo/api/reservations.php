<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Reservations Fetch
// Fetches reserved-date list from Google Sheets via Apps Script.
// File-cached for RESERVATIONS_CACHE_TTL seconds to avoid hitting limits.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';
sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') sts_fail('GET only', 405);

$cache_file = DATA_DIR . '/.reservations_cache.json';

// ── Serve from cache if fresh ───────────────────────────────────────
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < RESERVATIONS_CACHE_TTL) {
    $cached = json_decode(@file_get_contents($cache_file), true);
    if (is_array($cached)) {
        header('X-Cache: HIT');
        sts_ok($cached);
    }
}

// ── Fetch fresh from Apps Script ────────────────────────────────────
$resp = sts_appscript_get('fetch_reservations');

if (!empty($resp['ok']) && isset($resp['data']['reservations'])) {
    $data = ['reservations' => $resp['data']['reservations'], 'fetched_at' => date('c')];
    @file_put_contents($cache_file, json_encode($data), LOCK_EX);
    header('X-Cache: MISS');
    sts_ok($data);
}

// ── Apps Script unreachable → fall back to last good cache (stale OK) ──
if (file_exists($cache_file)) {
    $cached = json_decode(@file_get_contents($cache_file), true);
    if (is_array($cached)) {
        header('X-Cache: STALE');
        sts_ok($cached);
    }
}

// ── Last resort: empty list (form still works, just no reservations shown) ──
sts_ok(['reservations' => [], 'fetched_at' => date('c'), 'fallback' => true]);
