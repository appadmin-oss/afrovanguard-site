<?php
/**
 * portal/goals.php — JSON API for team goals / OKRs (org members only).
 *
 *   GET  ?action=list                       → { ok, goals:[…] }  each as GoalTrack::shape
 *   GET  ?action=one&id=                    → { ok, goal, checkins:[…] }
 *   POST ?action=create   {title, target, purpose, unit, direction, baseline,
 *                          target_value, start_on, due_on}       → { ok, id }
 *   POST ?action=edit     {id, title, …the same}                 → { ok }
 *   POST ?action=checkin  {id, value, confidence, note, blocker} → { ok, goal }
 *   POST ?action=close    {id, status: achieved|abandoned, note} → { ok }
 *   POST ?action=delete   {id}              → { ok }
 *
 * Progress is not typed: a goal with a number is checked in, one without
 * follows its steps (lib/GoalTrack.php).
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Goals.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Goals are for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'goals', 60); };

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'goals' => Goals::listGoals($uid, 40)]);

        case 'one':
            $g = GoalTrack::row((int) ($_GET['id'] ?? 0));
            if (!$g) json_out(['ok' => false, 'error' => 'No such goal.'], 404);
            json_out(['ok' => true, 'goal' => GoalTrack::shape($g, $uid), 'checkins' => GoalTrack::checkins((int) $g['id'], 12)]);

        case 'create':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $more = array_intersect_key($body, array_flip(Goals::fields()));
            $m = Goals::measure($more);
            if (isset($m['error'])) json_out(['ok' => false, 'error' => $m['error']], 422);
            $id = Goals::create($uid, (string) ($body['title'] ?? ''), (string) ($body['target'] ?? ''), $more);
            if (!$id) json_out(['ok' => false, 'error' => 'Add an objective.'], 422);
            /* Tasks come from goals: its first steps are drawn now and given to
               whoever fits them (lib/GoalPlanner.php). Failing that, the cron does it. */
            $plan = ['created' => 0];
            if (class_exists('GoalPlanner') && GoalPlanner::enabled()) {
                try { $plan = GoalPlanner::plan($id); } catch (Throwable $e) { error_log('[goals] plan: ' . $e->getMessage()); }
            }
            json_out(['ok' => true, 'id' => $id, 'steps' => (int) ($plan['created'] ?? 0), 'via' => (string) ($plan['via'] ?? '')]);

        case 'edit':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $r = Goals::edit($uid, (int) ($body['id'] ?? 0), (string) ($body['title'] ?? ''), (string) ($body['target'] ?? ''),
                array_intersect_key($body, array_flip(Goals::fields())));
            json_out(is_string($r) ? ['ok' => false, 'error' => $r] : ['ok' => $r], is_string($r) ? 422 : 200);

        case 'checkin':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $r = GoalTrack::checkin($uid, (int) ($body['id'] ?? 0), $body);
            json_out($r, $r['ok'] ? 200 : 422);

        case 'close':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::close($uid, (int) ($body['id'] ?? 0), (string) ($body['status'] ?? 'achieved'), (string) ($body['note'] ?? ''))]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Goals::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[goals] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
