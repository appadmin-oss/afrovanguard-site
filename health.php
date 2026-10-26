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
    /* Which kind of failure, by its code only — never a message, host, user or
       password. "Every PHP page is a 500" has four usual causes on shared
       hosting and each has a different fix; this tells them apart without a
       look at the server's error log. */
    $out['reason'] = av_health_reason($e);
    error_log('[health] ' . $e->getMessage());
}

/** A short, secret-free name for what went wrong. */
function av_health_reason(Throwable $e): string
{
    for ($x = $e; $x; $x = $x->getPrevious()) {
        if ($x instanceof PDOException) {
            $code = (int) ($x->errorInfo[1] ?? 0);
            if ($code === 0 && preg_match('/\[(\d{4})\]/', $x->getMessage(), $m)) $code = (int) $m[1];
            $map = [1045 => 'db_access_denied (check AV_DB_USER / AV_DB_PASS)', 1044 => 'db_no_privilege (the user has no rights on the database)',
                    1049 => 'db_missing (AV_DB_NAME does not exist)', 1040 => 'db_too_many_connections', 1203 => 'db_user_connection_limit (max_user_connections)',
                    2002 => 'db_server_down_or_wrong_host', 2003 => 'db_server_down_or_wrong_host', 2005 => 'db_unknown_host', 2006 => 'db_gone_away',
                    1226 => 'db_user_resource_limit'];
            return $map[$code] ?? ('db_error_' . ($code ?: 'unknown'));
        }
    }
    $f = basename($e->getFile()) . ':' . $e->getLine();
    return str_contains($e->getMessage(), 'AV_DB_STRICT') ? 'db_unreachable_strict' : 'boot_failed (' . get_class($e) . ' at ' . $f . ')';
}
echo json_encode($out);
