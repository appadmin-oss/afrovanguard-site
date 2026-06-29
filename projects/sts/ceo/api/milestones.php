<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Milestones Fetch
// Reads the editable Milestones tab from Google Sheets via Apps Script.
// File-cached for RESERVATIONS_CACHE_TTL seconds — milestones change
// very rarely, so caching cuts most Apps Script calls.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            // Fail-safe: empty list keeps the hero countdown rendering its fallback.
            echo json_encode(['ok' => true, 'data' => ['milestones' => []]]);
        }
        error_log('[STS milestones FATAL] ' . $e['message']);
    }
});

sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') sts_fail('GET only', 405);

$cache_file = DATA_DIR . '/.milestones_cache.json';

// ── Serve from cache if fresh ───────────────────────────────────────
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < RESERVATIONS_CACHE_TTL) {
    $cached = json_decode(@file_get_contents($cache_file), true);
    if (is_array($cached)) {
        header('X-Cache: HIT');
        sts_ok($cached);
    }
}

// ── Fetch fresh from Apps Script ────────────────────────────────────
$resp = sts_appscript_get('fetch_milestones');

if (!empty($resp['ok']) && isset($resp['data']['milestones'])) {
    $data = ['milestones' => $resp['data']['milestones'], 'fetched_at' => date('c')];
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

// ── Last resort: empty list (hero falls back to the bundled defaults) ──
sts_ok(['milestones' => [], 'fetched_at' => date('c'), 'fallback' => true]);
