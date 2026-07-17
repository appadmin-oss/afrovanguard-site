<?php
/**
 * portal/calendar.php — JSON API for the integrated team calendar.
 *
 *   GET  ?action=feed&from=YYYY-MM-DD&to=YYYY-MM-DD  → { ok, items:[…], is_org }
 *   POST ?action=create {title,date,start,end,location,note} → { ok, id }   (org)
 *   POST ?action=delete {id}                          → { ok }               (org)
 *
 * Reads are open to any signed-in user (their own sessions/reminders + AFG
 * events show; team events/tasks only for org members). Writes are org-only
 * and same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/TeamCalendar.php';
require_once AV_ROOT . '/lib/Reminders.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid   = (int) $u['id'];
$isOrg = LmsAuth::isOrgMember($u);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'feed');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid, $isOrg) {
    if (!$isOrg) json_out(['ok' => false, 'error' => 'Adding events is for Afrovanguard members.'], 403);
    av_require_write($uid, 'calendar', 60);
};

try {
    switch ($action) {
        case 'feed':
            $from = (string) ($_GET['from'] ?? gmdate('Y-m-01'));
            $to   = (string) ($_GET['to'] ?? gmdate('Y-m-t'));
            json_out(['ok' => true, 'is_org' => $isOrg, 'items' => TeamCalendar::feed($uid, $from, $to, $isOrg)]);

        case 'create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = TeamCalendar::create(
                $uid, (string) ($body['title'] ?? ''), (string) ($body['date'] ?? ''),
                (string) ($body['start'] ?? ''), (string) ($body['end'] ?? ''),
                (string) ($body['location'] ?? ''), (string) ($body['note'] ?? '')
            );
            if (!$id) json_out(['ok' => false, 'error' => 'Add a title and a valid date.'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => TeamCalendar::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[calendar] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
