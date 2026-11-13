<?php
/**
 * tests/goalspace.test.php — CACENTRE's Goalspace, worked inline on the
 * Tasks page (owner, 2026-11-13: "connect and work inline with the
 * Goalspace" … "make it smarter").
 *
 * Verified end to end in a browser against a real CACENTRE on MariaDB: the
 * ranked "Needs you now" list, a one-tap report filed as the member, a link
 * task finished, a lead giving a task with "who fits", axe clean.
 * CACENTRE's own side: cac/tests/integration/avgoalspace.php (21 checks).
 */
declare(strict_types=1);
$r = AV_ROOT;
$t = static fn(array $o) => $o + ['id' => 1, 'title' => 'T', 'status' => 'open', 'due_on' => '', 'proof_type' => 'link', 'owner_name' => 'Kemi', 'is_request' => false, 'accepted' => true, 'last_report' => null];
$w = static fn(array $o) => $o + ['id' => 1, 'title' => 'Goal', 'role' => 'member', 'progress' => 0.5, 'elapsed' => 0.5, 'behind_pct' => 0, 'my_tasks' => [], 'tasks' => [], 'members' => []];
$today = '2026-11-13';

/* What counts as behind: a real gap, after the start. */
ck('behind: an hour into a new goal is "early days", not behind', CacGoalspace::behind($w(['progress' => 0, 'elapsed' => 0.02, 'behind_pct' => 100])) === false);
ck('behind: two-thirds of the time gone and nothing done is behind', CacGoalspace::behind($w(['progress' => 0, 'elapsed' => 0.67])) === true);
ck('behind: a small gap is not', CacGoalspace::behind($w(['progress' => 0.6, 'elapsed' => 0.65])) === false);

/* Needs you now: ranked, each with its reason in words. */
$tri = CacGoalspace::triage([$w(['my_tasks' => [
    $t(['id' => 1, 'title' => 'Fine', 'due_on' => '2026-11-30']),
    $t(['id' => 2, 'title' => 'Late', 'due_on' => '2026-11-10']),
    $t(['id' => 3, 'title' => 'Soon', 'due_on' => '2026-11-14']),
    $t(['id' => 4, 'title' => 'Stuck', 'due_on' => '2026-11-30', 'last_report' => ['status' => 'blocked', 'at' => '2026-11-04 09:00:00']]),
    $t(['id' => 5, 'title' => 'Asked', 'is_request' => true, 'accepted' => false]),
]])], $today);
$order = array_map(static fn($x) => $x['task']['title'], $tri['mine']);
ck('needs you: overdue, then asked of you, then blocked, then due soon; a task that is fine is not listed', $order === ['Late', 'Asked', 'Stuck', 'Soon']);
ck('needs you: the reasons say why, in words', $tri['mine'][0]['why'] === '3 days overdue.' && $tri['mine'][1]['why'] === 'Asked of you: accept it, or say you can’t.'
   && $tri['mine'][2]['why'] === 'You said it was blocked on 4 Nov. Still?' && $tri['mine'][3]['why'] === 'Due tomorrow.');
$quiet = CacGoalspace::triage([$w(['progress' => 0, 'elapsed' => 0.67, 'my_tasks' => [$t(['due_on' => '2026-11-30'])]])], $today);
ck('needs you: a behind goal with nothing said asks, with where it should be', ($quiet['mine'][0]['why'] ?? '') === 'The goal should be about 67% done by now and is at 0%. You haven’t said how this is going yet.');
$said = CacGoalspace::triage([$w(['progress' => 0, 'elapsed' => 0.67, 'my_tasks' => [$t(['due_on' => '2026-11-30', 'last_report' => ['status' => 'on_track', 'at' => '2026-11-11 10:00:00']])]])], $today);
ck('needs you: …but not if they said how it is going this week', $said['mine'] === []);
$early = CacGoalspace::triage([$w(['progress' => 0, 'elapsed' => 0.02, 'behind_pct' => 100, 'my_tasks' => [$t(['due_on' => '2026-12-30'])]])], $today);
ck('needs you: nothing on day one of a goal', $early['mine'] === []);

/* A lead: the team's tasks that need their help. */
$lead = CacGoalspace::triage([$w(['role' => 'lead', 'tasks' => [
    $t(['id' => 7, 'title' => 'Poster', 'owner_name' => 'Kemi', 'last_report' => ['status' => 'blocked', 'at' => '2026-11-12 08:00:00']]),
    $t(['id' => 8, 'title' => 'Proof in', 'owner_name' => 'Bode', 'status' => 'awaiting_review']),
    $t(['id' => 9, 'title' => 'Fine', 'owner_name' => 'Ada', 'due_on' => '2026-11-30']),
]])], $today);
ck('needs your help: proof to check, then someone blocked; fine work is not listed', array_map(static fn($x) => $x['why'], $lead['team']) === ['Bode is waiting for you to check the proof.', 'Kemi said it was blocked on 12 Nov.']);

/* Only the door's own requests leave this site. */
$src = (string) file_get_contents("$r/lib/CacGoalspace.php");
ck('door: only reports, a lead\'s task, and accept/complete/decline are sent', str_contains($src, "private const ROUTES = ['~^workspaces/\\d+/(reports|tasks)$~', '~^tasks/\\d+/(accept|complete|decline)$~'];"));
ck('door: anything else is refused here, before it is sent', str_contains(json_encode(CacGoalspace::act(['id' => 1, 'email' => 'x@y.z'], 'workspaces/1/close', [])), 'Goalspace itself') || !CacGoalspace::ready());
ck('door: signed for the goalspace door only', str_contains($src, "'goalspace');"));
ck('who fits: only for a lead of that goal, checked with CACENTRE, among its members', str_contains($src, "if (\$x['id'] === \$wsId && \$x['role'] === 'lead')") && str_contains($src, 'array_diff(array_map(static fn($p) => (int) $p[\'id\'], TaskMatch::profiles()), array_keys($actorOf))'));

/* The page. */
$js = (string) file_get_contents("$r/portal/goalspace.js"); $view = (string) file_get_contents("$r/portal/views/tasks.php");
ck('page: loading, empty, linked-not and an honest error with Try again', str_contains($js, 'Loading your goals') || str_contains($view, 'Loading your goals') ? str_contains($js, 'id="gspRetry"') && str_contains($js, 'You are not in any goal at CACENTRE yet') : false);
ck('page: a file task or sign-off points to Goalspace, not a fake "done"', str_contains($js, 'Finish it in Goalspace'));
ck('page: a bad link is refused on the page, in words', str_contains($js, 'novalidate') && str_contains($js, 'Paste the full link, starting with https://'));
ck('page: shown only where the link to CACENTRE is set up', str_contains($view, "CacGoalspace::ready()"));
ck('page: at most three as Now, the rest folded as Next', str_contains($js, 'nowL = need.slice(0, 3), nextL = need.slice(3)') && str_contains($js, '<summary>Next: '));
