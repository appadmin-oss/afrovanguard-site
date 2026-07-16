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

header('Content-Type: application/json; charset=utf-8');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$uid    = (int) $u['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'bootstrap');
$body   = json_decode((string) file_get_contents('php://input'), true) ?: [];

/** Guard state-changing calls. */
$writeGuard = function () use ($method) {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    require_same_origin();
    av_csrf_require();
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
                'roster'   => Collab::roster(),
            ]);

        case 'roster':
            json_out(['ok' => true, 'roster' => Collab::roster()]);

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
