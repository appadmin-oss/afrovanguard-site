<?php
/**
 * portal/collab.php — the collaboration API behind the portal workspace.
 *
 *   GET  ?action=bootstrap  → { online:[…], count, activity:[…], tasks:[…] }
 *   POST ?action=heartbeat  → record presence (body: {status})
 *   POST ?action=task_add   → { task }           (body: {title, due})
 *   POST ?action=task_toggle→ { id, done }        (body: {id})
 *   POST ?action=task_delete→ { id }              (body: {id})
 *   POST ?action=task_suggest → { suggestions }  (body: {title, kind?}) — who it fits
 *   POST ?action=task_assign  → { task }         (body: {id, assignee})
 *   GET  ?action=todo         → the to-do pop-up's list (lib/Todo.php)
 *   POST ?action=todo_take    → { task }         (body: {id}) — take a suggested pool task
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
/* Who hands out work: organisation members and volunteer coordinators. */
$allocates = $isOrg || (class_exists('Volunteers') && Volunteers::canRunTeams($u));

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
                'roster'   => Collab::roster(200, $allocates),
                'allocates'=> $allocates,
                /* Not taking new tasks until this date (TaskLedger), and the reasons a task can be handed back with. */
                'away_until' => class_exists('TaskLedger') ? TaskLedger::awayUntil($uid) : '',
                'handback_reasons' => class_exists('TaskLedger') ? TaskLedger::REASONS : [],
                'ai'       => $isOrg && Collab::aiAvailable(),
                'goals'    => $isOrg ? array_values(array_filter(Goals::listGoals($uid, 40), fn($g) => empty($g['closed']))) : [],
                /* Where CACENTRE's goals stand, so an empty list says why; and an
                   administrator may read them now (cac_goals_sync). */
                'cac_goals'=> $isOrg && class_exists('CacGoals') ? CacGoals::status() + ['can_sync' => LmsAuth::atLeast($u, 'admin')] : null,
            ]);

        case 'roster':
            json_out(['ok' => true, 'roster' => Collab::roster(200, $allocates)]);

        /* An administrator reads CACENTRE's goals now, rather than waiting for the hour. */
        case 'cac_goals_sync':
            $writeGuard();
            if (!LmsAuth::atLeast($u, 'admin')) json_out(['ok' => false, 'error' => 'Administrators only.'], 403);
            if (!class_exists('CacGoals') || !CacGoals::ready()) json_out(['ok' => false, 'error' => 'The CACENTRE link is not set up here.'], 503);
            $r = CacGoals::sync();
            json_out(['ok' => true, 'result' => $r, 'cac_goals' => CacGoals::status() + ['can_sync' => true],
                      'goals' => array_values(array_filter(Goals::listGoals($uid, 40), fn($g) => empty($g['closed'])))]);

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
            if (!Collab::releaseTask($uid, (int) ($body['id'] ?? 0), (string) ($body['reason'] ?? ''))) json_out(['ok' => false, 'error' => 'Could not return this task to the pool.'], 400);
            json_out(['ok' => true, 'id' => (int) $body['id']]);

        /* The day's tasks: done, undone, unclaimed — for whoever hands out work. */
        case 'task_report':
            if (!$allocates) json_out(['ok' => false, 'error' => 'The daily report is for whoever hands out work.'], 403);
            $d = (string) ($_GET['day'] ?? '');
            json_out(['ok' => true, 'report' => TaskReport::build(preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null)]);

        /* Away until a date: no task is given or suggested to them until then. */
        case 'task_away':
            $writeGuard();
            json_out(TaskLedger::setAway($uid, (string) ($body['until'] ?? '')));

        case 'ai_from_goal':
            $orgGuard();
            $writeGuard();
            if (!av_rate_ok('collab_ai_' . $uid, 8, 600)) json_out(['ok' => false, 'error' => 'You’ve generated a lot just now — give it a minute.'], 429);
            // AI failures are soft — returned as ok:false with a message the UI
            // shows inline, not an HTTP error (nothing is broken server-side).
            json_out(Collab::aiTasksFromGoal($uid, (int) ($body['goal_id'] ?? 0), (int) ($body['max'] ?? 6), !empty($body['auto_assign']) && $allocates));

        /* ── The to-do pop-up (lib/Todo.php, portal/todo.js) ─────────── */
        case 'todo':
            $td = Todo::for($u);
            json_out(['ok' => true, 'enabled' => $td['enabled'], 'role' => $td['role'], 'sections' => Todo::sections($td), 'all' => $td['role']['member'] || $td['role']['volunteer'] ? '/portal/#tasks' : '/portal/']);

        case 'todo_take':
            $writeGuard();
            if (!Todo::mayTake($u)) json_out(['ok' => false, 'error' => 'Taking pool tasks is for members and volunteers.'], 403);
            $task = Collab::claimTask($uid, (int) ($body['id'] ?? 0));
            if ($task === null) json_out(['ok' => false, 'error' => 'Somebody took that one a moment ago.'], 409);
            json_out(['ok' => true, 'task' => $task]);

        /* Who a task fits, from people's labels; AI picks from that shortlist when it is configured. */
        case 'task_suggest':
            if (!$allocates) json_out(['ok' => false, 'error' => 'Only people who hand out work see suggestions.'], 403);
            if (!av_rate_ok('collab_suggest_' . $uid, 30, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            json_out(TaskMatch::suggest((string) ($body['title'] ?? $_GET['title'] ?? ''), 3, (string) ($body['kind'] ?? ''),
                ['due' => (string) ($body['due'] ?? ''), 'priority' => (string) ($body['priority'] ?? ''), 'size' => (string) ($body['size'] ?? '')]));

        case 'task_assign':
            $writeGuard();
            $task = Collab::assignTask($uid, (int) ($body['id'] ?? 0), (int) ($body['assignee'] ?? 0), $allocates);
            if ($task === null) json_out(['ok' => false, 'error' => 'You cannot give this task to them.'], 403);
            json_out(['ok' => true, 'task' => $task]);

        case 'task_toggle':
            $writeGuard();
            $done = Collab::toggleTask($uid, (int) ($body['id'] ?? 0));
            if ($done === null) json_out(['ok' => false, 'error' => 'Task not found.'], 404);
            json_out(['ok' => true, 'id' => (int) $body['id'], 'done' => $done]);

        /* Where a task is: to do, doing, blocked (on what), done — and how big. */
        case 'task_state':
            $writeGuard();
            $r = Collab::setState($uid, (int) ($body['id'] ?? 0), (string) ($body['state'] ?? ''), (string) ($body['reason'] ?? ''));
            if (isset($r['error'])) json_out(['ok' => false, 'error' => $r['error']], 422);
            json_out(['ok' => true, 'task' => $r]);

        case 'task_size':
            $writeGuard();
            $t = Collab::setSize($uid, (int) ($body['id'] ?? 0), (string) ($body['size'] ?? ''));
            if ($t === null) json_out(['ok' => false, 'error' => 'Task not found.'], 404);
            json_out(['ok' => true, 'task' => $t]);

        case 'task_delete':
            $writeGuard();
            if (!Collab::deleteTask($uid, (int) ($body['id'] ?? 0))) json_out(['ok' => false, 'error' => 'Task not found.'], 404);
            json_out(['ok' => true, 'id' => (int) $body['id']]);

        /* ── This week at CACENTRE (lib/CacWeek.php) ─────────────────────
           The member's commitments for the week and the goals they feed; a
           task here can count toward one of them. */
        case 'cac_week':
            /* CACENTRE's goals arrive from the cron (CacGoals::syncIfDue, hourly at
               most). Where the host's cron is not running they never would; so the
               Tasks page also asks — after its answer has gone out, so nobody waits. */
            if (class_exists('CacGoals')) register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
                try { CacGoals::syncIfDue(); } catch (Throwable $e) { error_log('[cac-goals] from tasks: ' . $e->getMessage()); }
            });
            $w = CacWeek::forMember($u, isset($_GET['on']) ? (string) $_GET['on'] : null);
            json_out($w + ['attached' => (object) CacWeek::attachedFor($uid)]);

        case 'cac_count':
            $writeGuard();
            if (!av_rate_ok('collab_cac_' . $uid, 30, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $r = CacWeek::attach($u, (int) ($body['id'] ?? 0), max(0, (int) ($body['commitment_id'] ?? 0)));
            if ($r['ok']) json_out(['ok' => true, 'week' => $r['week'], 'attached' => (object) CacWeek::attachedFor($uid)]);
            json_out(['ok' => false, 'error' => match ($r['error']) {
                'not-yours'      => 'That commitment is not one of yours on CACENTRE.',
                'unreachable'    => 'CACENTRE is not available right now. Nothing was changed, and the administrators have been told.',
                'not-configured' => 'The link to CACENTRE is not set up here.',
                default          => $r['error'] !== '' ? $r['error'] : 'CACENTRE refused that.',
            }], 503);

        /* ── A CACENTRE task, changed over there ───────────────────────
           Not stored here. The change is sent to CACENTRE, which holds the
           row and checks the task's owner against it before touching it. One
           task, edited from two places, rather than two that will disagree. */
        case 'cac_toggle':
        case 'cac_update':
            $writeGuard();
            if (!class_exists('CacTasks')) json_out(['ok' => false, 'error' => 'The console is not linked.'], 503);

            $fields = [];
            if (array_key_exists('due', $body))      $fields['due'] = (string) $body['due'];
            if (array_key_exists('priority', $body)) $fields['priority'] = (string) $body['priority'];

            $r = CacTasks::write($uid, $action === 'cac_toggle' ? 'toggle' : 'update',
                                 (int) ($body['id'] ?? 0), $fields);
            if ($r['ok']) json_out(['ok' => true] + $r['data']);
            json_out(['ok' => false, 'error' => match ($r['error']) {
                'not-yours'      => 'That task is not yours.',
                'bad-due'        => 'The console will not take that date.',
                'unreachable'    => 'CACENTRE is not available right now. Nothing was changed, and the administrators have been told.',
                'not-configured' => 'The link to the console is not set up here.',
                default          => 'The console refused that change.',
            }], 503);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[portal collab] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
