<?php
/**
 * portal/collab.php — the collaboration API behind the portal workspace.
 *
 *   GET  ?action=bootstrap  → { online:[…], count, activity:[…], tasks:[…] }
 *   POST ?action=heartbeat  → record presence (body: {status})
 *   POST ?action=task_add   → { task }           (body: {title, due})
 *   POST ?action=task_toggle→ { id, done }        (body: {id})
 *   POST ?action=task_delete→ { id }              (body: {id})
 *
 * Signed-in members only. Writes require same-origin + a valid CSRF token
 * (X-CSRF-Token header), matching the rest of the portal's endpoints.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Goals.php';   // for AI goal → tasks

header('Content-Type: application/json; charset=utf-8');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$uid    = (int) $u['id'];
$isOrg  = LmsAuth::isOrgMember($u);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'bootstrap');
$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];

/** Guard state-changing calls. */
$writeGuard = function () use ($method) {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    require_same_origin();
    av_csrf_require();
};

/** The task pool + goal AI are org-member features. */
$orgGuard = function () use ($isOrg) {
    if (!$isOrg) json_out(['ok' => false, 'error' => 'The task pool is for Afrovanguard members.'], 403);
};

try {
    switch ($action) {
        case 'bootstrap':
            // A heartbeat on every load keeps presence fresh without a write call.
            Collab::heartbeat($uid);
            json_out([
                'ok'       => true,
                'online'   => Collab::onlineUsers(40),
                'count'    => Collab::onlineCount(),
                'activity' => Collab::recentActivity(18),
                'tasks'    => Collab::myTasks($uid),
                'pool'     => $isOrg ? Collab::poolTasks(60) : [],
                'roster'   => Collab::roster(),
                'ai'       => $isOrg && Collab::aiAvailable(),
                'goals'    => $isOrg ? array_values(array_filter(Goals::listGoals($uid, 40), fn($g) => empty($g['closed']))) : [],
            ]);

        case 'roster':
            json_out(['ok' => true, 'roster' => Collab::roster()]);

        case 'pool':
            $orgGuard();
            json_out(['ok' => true, 'pool' => Collab::poolTasks(60)]);

        case 'goals':
            $orgGuard();
            json_out(['ok' => true, 'goals' => array_values(array_filter(Goals::listGoals($uid, 40), fn($g) => empty($g['closed']))), 'ai' => Collab::aiAvailable()]);

        case 'heartbeat':
            $writeGuard();
            if (!av_rate_ok('collab_hb_' . $uid, 60, 300)) json_out(['ok' => true]); // silently ignore floods
            Collab::heartbeat($uid, (string) ($body['status'] ?? 'online'));
            json_out(['ok' => true, 'count' => Collab::onlineCount()]);

        case 'task_add':
            $writeGuard();
            if (!av_rate_ok('collab_task_' . $uid, 40, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $id = Collab::addTask($uid, (string) ($body['title'] ?? ''), (int) ($body['assignee'] ?? 0), (string) ($body['due'] ?? ''), (string) ($body['priority'] ?? 'normal'));
            if (!$id) json_out(['ok' => false, 'error' => 'Enter a task.'], 400);
            $mine = array_values(array_filter(Collab::myTasks($uid), fn($t) => $t['id'] === $id));
            json_out(['ok' => true, 'task' => $mine[0] ?? ['id' => $id]]);

        case 'task_add_pool':
            $orgGuard();
            $writeGuard();
            if (!av_rate_ok('collab_task_' . $uid, 40, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $id = Collab::addPoolTask($uid, (string) ($body['title'] ?? ''), (string) ($body['due'] ?? ''), (string) ($body['priority'] ?? 'normal'), (int) ($body['goal_id'] ?? 0));
            if (!$id) json_out(['ok' => false, 'error' => 'Enter a task.'], 400);
            json_out(['ok' => true, 'task' => Collab::oneTask($uid, $id)]);

        case 'task_claim':
            $orgGuard();
            $writeGuard();
            $task = Collab::claimTask($uid, (int) ($body['id'] ?? 0));
            if ($task === null) json_out(['ok' => false, 'error' => 'Too late — someone already took this one.'], 409);
            json_out(['ok' => true, 'task' => $task]);

        case 'task_release':
            $orgGuard();
            $writeGuard();
            if (!Collab::releaseTask($uid, (int) ($body['id'] ?? 0))) json_out(['ok' => false, 'error' => 'Could not return this task to the pool.'], 400);
            json_out(['ok' => true, 'id' => (int) $body['id']]);

        case 'ai_from_goal':
            $orgGuard();
            $writeGuard();
            if (!av_rate_ok('collab_ai_' . $uid, 8, 600)) json_out(['ok' => false, 'error' => 'You’ve generated a lot just now — give it a minute.'], 429);
            // AI failures are soft — returned as ok:false with a message the UI
            // shows inline, not an HTTP error (nothing is broken server-side).
            json_out(Collab::aiTasksFromGoal($uid, (int) ($body['goal_id'] ?? 0), (int) ($body['max'] ?? 6)));

        case 'task_toggle':
            $writeGuard();
            $done = Collab::toggleTask($uid, (int) ($body['id'] ?? 0));
            if ($done === null) json_out(['ok' => false, 'error' => 'Task not found.'], 404);
            json_out(['ok' => true, 'id' => (int) $body['id'], 'done' => $done]);

        case 'task_delete':
            $writeGuard();
            if (!Collab::deleteTask($uid, (int) ($body['id'] ?? 0))) json_out(['ok' => false, 'error' => 'Task not found.'], 404);
            json_out(['ok' => true, 'id' => (int) $body['id']]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal collab] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
