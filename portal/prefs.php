<?php
/**
 * portal/prefs.php — JSON API for per-user preferences (currently timezone).
 *
 *   GET  ?action=get              → { ok, tz }
 *   POST ?action=set_tz {tz}      → { ok, tz }
 *
 * Any signed-in user; writes are same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'get');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

try {
    switch ($action) {
        case 'get':
            json_out(['ok' => true, 'tz' => av_user_tz($uid)]);

        case 'set_tz':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'prefs', 30);
            if (!Prefs::setTimezone($uid, (string) ($body['tz'] ?? ''))) json_out(['ok' => false, 'error' => 'Unknown timezone.'], 422);
            json_out(['ok' => true, 'tz' => av_user_tz($uid)]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[prefs] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
