<?php
/**
 * portal/boards.php — JSON API for the shared team Kanban board (org members only).
 *
 *   GET  ?action=board                     → { ok, cols:[…] }
 *   POST ?action=add    {title, col}       → { ok, id }
 *   POST ?action=move   {id, col}          → { ok }
 *   POST ?action=rename {id, title}        → { ok }
 *   POST ?action=delete {id}               → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Boards.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'The board is for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'board');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) {
    require_same_origin();
    if (!av_csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) json_out(['ok' => false, 'error' => 'Bad token.'], 403);
    if (!av_rate_ok('boards_' . $uid, 80, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
};

try {
    switch ($action) {
        case 'board':
            json_out(['ok' => true, 'cols' => Boards::board($uid)]);

        case 'add':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = Boards::add($uid, (string) ($body['title'] ?? ''), (string) ($body['col'] ?? 'todo'));
            if (!$id) json_out(['ok' => false, 'error' => 'Add a card title.'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'move':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Boards::move((int) ($body['id'] ?? 0), (string) ($body['col'] ?? ''))]);

        case 'rename':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Boards::rename($uid, (int) ($body['id'] ?? 0), (string) ($body['title'] ?? ''))]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Boards::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[boards] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
