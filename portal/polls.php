<?php
/**
 * portal/polls.php — JSON API for Team Polls (org members only).
 *
 *   GET  ?action=list                          → { ok, polls:[…] }
 *   POST ?action=create {question, options[]}  → { ok, id }
 *   POST ?action=vote   {id, idx}              → { ok }
 *   POST ?action=close  {id}                   → { ok }
 *   POST ?action=delete {id}                   → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Polls.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Team polls are for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) {
    require_same_origin();
    if (!av_csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) json_out(['ok' => false, 'error' => 'Bad token.'], 403);
    if (!av_rate_ok('polls_' . $uid, 40, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
};

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'polls' => Polls::listPolls($uid, 20)]);

        case 'create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $opts = is_array($body['options'] ?? null) ? $body['options'] : [];
            $id = Polls::create($uid, (string) ($body['question'] ?? ''), $opts);
            if (!$id) json_out(['ok' => false, 'error' => 'Add a question and at least two options.'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'vote':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            if (!Polls::vote($uid, (int) ($body['id'] ?? 0), (int) ($body['idx'] ?? -1))) json_out(['ok' => false, 'error' => 'Could not record your vote.'], 422);
            json_out(['ok' => true]);

        case 'close':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Polls::close($uid, (int) ($body['id'] ?? 0))]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Polls::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[polls] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
