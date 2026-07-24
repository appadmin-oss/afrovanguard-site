<?php
/**
 * health.php — a lightweight liveness/readiness probe for load balancers, Cloud
 * Run, App Runner, ELB target groups, Kubernetes, and uptime monitors.
 *
 *   GET /health.php        → 200 {ok:true, db:"sqlite|mysql|pgsql", ...}
 *   GET /health.php?ping=1 → 200 {ok:true} without touching the DB (fast liveness)
 *
 * Returns 503 when the primary database is unreachable. Exposes no secrets and
 * is excluded from indexing. Safe to hit frequently.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$out = ['ok' => true, 'time' => gmdate('c')];

// Fast liveness: is the PHP process serving? (no DB, no bootstrap side effects)
if (isset($_GET['ping'])) { echo json_encode($out); exit; }

try {
    require_once __DIR__ . '/lib/bootstrap.php';
    $pdo = Database::pdo();
    $pdo->query('SELECT 1')->fetchColumn();
    $out['db'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $out['php'] = PHP_VERSION;
} catch (Throwable $e) {
    http_response_code(503);
    $out['ok'] = false;
    $out['error'] = 'db_unreachable';   // detail is logged server-side, never returned
    error_log('[health] ' . $e->getMessage());
}
echo json_encode($out);
