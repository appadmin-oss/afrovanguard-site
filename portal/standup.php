<?php
/**
 * portal/standup.php — JSON API for async daily standups (org members only).
 *
 *   GET  ?action=board                        → { ok, board:[…], mine:{…}|null }
 *   POST ?action=post {done, next, blockers}  → { ok }
 *   POST ?action=delete                       → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Standup.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Standups are for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'board');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'standup', 40); };

try {
    switch ($action) {
        case 'board':
            json_out(['ok' => true, 'board' => Standup::board($uid, 60), 'mine' => Standup::mine($uid)]);

        case 'post':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $ok = Standup::submit($uid, (string) ($body['done'] ?? ''), (string) ($body['next'] ?? ''), (string) ($body['blockers'] ?? ''));
            if (!$ok) json_out(['ok' => false, 'error' => 'Add at least one field.'], 422);
            json_out(['ok' => true]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Standup::remove($uid)]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[standup] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
