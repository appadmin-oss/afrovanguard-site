<?php
// ─────────────────────────────────────────────────────────────────────
// STS · Client error log
// Receives uncaught React errors from the ErrorBoundary and appends
// them to data/client-errors.log for debugging.
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_helpers.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

sts_cors_and_json();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sts_fail('POST only', 405);
if (!sts_rate_limit('errlog', 30)) sts_fail('Too many error reports.', 429);

$body = sts_read_json_body();
if (!$body) sts_fail('No payload.');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
$entry = [
    'ts'      => date('c'),
    'ip'      => sts_client_ip(),
    'message' => sts_sanitize($body['message'] ?? '', 500),
    'stack'   => sts_sanitize($body['stack'] ?? '', 2000),
    'info'    => sts_sanitize($body['info'] ?? '', 2000),
    'url'     => sts_sanitize($body['url'] ?? '', 500),
    'ua'      => sts_sanitize($body['ua'] ?? '', 500),
];
@file_put_contents(
    DATA_DIR . '/client-errors.log',
    json_encode($entry) . "\n",
    FILE_APPEND | LOCK_EX
);
error_log('[STS Client Error] ' . $entry['message'] . ' · ' . $entry['url']);

sts_ok(['logged' => true]);
