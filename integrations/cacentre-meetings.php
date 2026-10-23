<?php
/**
 * integrations/cacentre-meetings.php — CACENTRE's staff portal and the meetings.
 *
 *   GET  ?action=list            every meeting
 *   GET  ?action=get&id=N        one, with its minutes unless kept private
 *   POST ?action=schedule        {title, when, duration, frequency, agenda, attendees, minutes_private}
 *   POST ?action=cancel          {id}                    the organiser only
 *   POST ?action=privacy         {id, minutes_private}   the organiser only
 *
 * Signed by CACENTRE: header X-Cac-Token, verified by lib/CacMeetings.php.
 * Any failure to verify is the same short refusal. Server to server: no CORS.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/CacSso.php';
require_once AV_ROOT . '/lib/CacMeetings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = static function (array $b, int $code = 200): void {
    http_response_code($code);
    echo json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) $out(['ok' => false, 'error' => 'GET or POST only.'], 405);
$action = (string) ($_GET['action'] ?? 'list');
$body = $method === 'POST' ? (string) file_get_contents('php://input') : '';
/* A GET signs its query, so a token for one meeting cannot read another. */
$signed = $method === 'POST' ? $body : 'GET ' . $action . ' ' . (string) ($_GET['id'] ?? '');
$staff = CacMeetings::verify((string) ($_SERVER['HTTP_X_CAC_TOKEN'] ?? ''), $signed);
if (!is_array($staff)) $out(['ok' => false, 'error' => $staff === 'not-configured' ? 'not-configured' : 'refused'], $staff === 'not-configured' ? 503 : 403);

if ($method === 'GET' && !in_array($action, ['list', 'get'], true)) $out(['ok' => false, 'error' => 'POST required.'], 405);
if ($method === 'POST' && !in_array($action, ['schedule', 'cancel', 'privacy'], true)) $out(['ok' => false, 'error' => 'Unknown action.'], 400);
$in = $method === 'POST' ? (json_decode($body, true) ?: []) : ['id' => (int) ($_GET['id'] ?? 0)];
try {
    $r = CacMeetings::handle($staff, $action, is_array($in) ? $in : []);
} catch (Throwable $e) {
    error_log('[cacentre-meetings] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'The meeting could not be saved. Try again.'], 500);
}
$out($r, !empty($r['ok']) ? 200 : 422);
