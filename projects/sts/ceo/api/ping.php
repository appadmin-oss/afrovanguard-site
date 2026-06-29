<?php
// ─────────────────────────────────────────────────────────────────────
// STS · API health check
// React pings this on startup to verify API_BASE was resolved correctly.
// If this 200s, every other endpoint at api/*.php is reachable too.
// ─────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
echo json_encode([
    'ok'        => true,
    'service'   => 'sts-2026',
    'php'       => PHP_VERSION,
    'time'      => date('c'),
    'curl'      => function_exists('curl_init'),
    'mbstring'  => function_exists('mb_substr'),
]);
