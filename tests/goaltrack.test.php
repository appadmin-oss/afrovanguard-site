<?php
/**
 * tests/goaltrack.test.php — how a goal is tracked, and how its tasks move it
 * (lib/GoalTrack.php, the task states in lib/Collab.php).
 *
 *   • A measured goal's progress is its number, from its baseline, in its
 *     direction; an unmeasured one follows its steps, weighted by size.
 *   • Health is progress against the share of time gone, as CACENTRE judges it.
 *   • A forecast from steps finished, and from the number's own trend.
 *   • Steps done but the number not moving: said, and passed to the planner.
 *   • Check-ins: the owner's, with a confidence; due weekly; not for mirrors.
 *   • Tasks: to do, doing, blocked (with what), done (with when); stuck work flagged.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tp = Database::pdo();
GoalTrack::ensure();
Collab::myTasks(0, 1);
$tp->exec('DELETE FROM lms_users WHERE id BETWEEN 9930 AND 9939');
$tp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9930 AND 9939');
$tp->exec('DELETE FROM team_goals WHERE author_id BETWEEN 9930 AND 9939');
foreach ([9931 => 'Ola Owner', 9932 => 'Tayo Helper'] as $id => $n) {
    $tp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, 'gt' . $id . '@afrovanguard.org.ng', 'x', 'member', 'active']);
}
$now = strtotime('2026-10-19 12:00:00 UTC');
$day = static fn(int $d) => gmdate('Y-m-d', $now + $d * 86400);
$at = static fn(int $d) => gmdate('Y-m-d H:i:s', $now + $d * 86400);
$shape = static fn(int $id) => GoalTrack::shape(GoalTrack::row($id), 9931, $now);

/* ── A measured goal ─────────────────────────────────────────────────── */
$m = Goals::create(9931, 'Grow paying members', '', ['unit' => 'members', 'baseline' => '200', 'target_value' => '500', 'direction' => 'increase',
    'start_on' => $day(-30), 'due_on' => $day(30), 'purpose' => 'Dues fund the centres.']);
ck('measure: a goal says what it measures, from where, to where, by when', $m > 0 && $shape($m)['measured'] && $shape($m)['target_value'] === 500.0 && $shape($m)['due_on'] === $day(30));
ck('measure: a target that is not a number is refused', Goals::create(9931, 'Bad', '', ['target_value' => 'lots']) === 0);
ck('measure: and a goal due before it starts', isset(Goals::measure(['start_on' => '2026-12-01', 'due_on' => '2026-11-01'])['error']));
ck('measure: before any reading it is at its baseline — 0%', $shape($m)['pct'] === 0);

ck('check-in: only the owner checks in', !GoalTrack::checkin(9932, $m, ['value' => 260, 'confidence' => 7], $now)['ok']);
ck('check-in: confidence is asked for, 1 to 10', !GoalTrack::checkin(9931, $m, ['value' => 260], $now)['ok']);
$r = GoalTrack::checkin(9931, $m, ['value' => '260', 'confidence' => 6, 'note' => 'Two schools signed up', 'blocker' => 'Paystack link broken'], $now);
ck('check-in: the number moves progress, from the baseline — 260 of 200→500 is 20%', $r['ok'] && $r['goal']['pct'] === 20);
ck('check-in: confidence and the blocker are kept', $r['goal']['confidence'] === 6 && $r['goal']['last_checkin']['blocker'] === 'Paystack link broken');
ck('check-in: the cached progress follows', (int) GoalTrack::row($m)['progress'] === 20);
$h = $shape($m)['health'];
ck('health: half its time gone and 20% done is behind (0.4 of pace)', $h['state'] === 'behind' && abs($h['ratio'] - 0.4) < 0.01);

$d = Goals::create(9931, 'Cut dropouts', '', ['unit' => '%', 'baseline' => 20, 'target_value' => 10, 'direction' => 'decrease', 'start_on' => $day(-10), 'due_on' => $day(10)]);
GoalTrack::checkin(9931, $d, ['value' => 15, 'confidence' => 8], $now);
ck('direction: a number that must go down — 20% to 15%, toward 10%, is half way', $shape($d)['pct'] === 50);
ck('health: half way at half time is on track', $shape($d)['health']['state'] === 'on_track');
$k = Goals::create(9931, 'Keep the waiting list short', '', ['unit' => 'children', 'target_value' => 40, 'direction' => 'maintain', 'due_on' => $day(60)]);
GoalTrack::checkin(9931, $k, ['value' => 44, 'confidence' => 7], $now);
ck('direction: holding a number — 44 against 40 is 90% held', $shape($k)['pct'] === 90);

/* The number's own trend: a forecast */
$tp->prepare('UPDATE goal_checkins SET at = ? WHERE goal_id = ?')->execute([$at(-14), $m]);
GoalTrack::checkin(9931, $m, ['value' => 290, 'confidence' => 6], $now);
$f = $shape($m)['forecast'];
ck('forecast: from the readings — 30 members in two weeks leaves 210 for ~14 weeks, well past the due date', $f && $f['by'] === 'number' && $f['late_days'] > 30);
ck('forecast: and a goal finishing late says so', in_array('late', array_column($shape($m)['warnings'], 'kind'), true));

/* ── Steps: weighted by size, and the leading signal ─────────────────── */
$u = Goals::create(9931, 'Open the Ikotun library', '', ['start_on' => $day(-20), 'due_on' => $day(20)]);
$s1 = Collab::addTask(9931, 'Shelve the donated books', 9932, '', 'normal', $u, 'L');
$s2 = Collab::addTask(9931, 'Print the borrowing cards', 9931, '', 'normal', $u, 'S');
$s3 = Collab::addTask(9931, 'Agree opening hours', 9931, '', 'normal', $u, '');
ck('steps: an unmeasured goal follows its steps, and says it is unmeasured', !$shape($u)['measured'] && in_array('unmeasured', array_column($shape($u)['warnings'], 'kind'), true));
Collab::toggleTask(9932, $s1);
ck('steps: weighted by size — the large step (4 of 7 points) is 57%, not one in three', $shape($u)['pct'] === 57);
ck('steps: a finished step has its time — the forecast is drawn from it', (string) $tp->query('SELECT done_at FROM collab_tasks WHERE id = ' . $s1)->fetchColumn() !== '');
$f = $shape($u)['forecast'];
ck('forecast: from steps finished in four weeks', $f && $f['by'] === 'steps' && $f['date'] !== null);

/* Steps done, the number not moving */
$v = Goals::create(9931, 'Raise ₦2m', '', ['unit' => '₦', 'baseline' => 0, 'target_value' => 2000000, 'start_on' => $day(-10), 'due_on' => $day(50)]);
foreach (['Draft the appeal', 'List 40 donors', 'Design the flyer'] as $t) Collab::toggleTask(9931, Collab::addTask(9931, $t, 9931, '', 'normal', $v, 'M'));
Collab::addTask(9931, 'Book the launch', 9931, '', 'normal', $v, 'S');
GoalTrack::checkin(9931, $v, ['value' => 50000, 'confidence' => 4], $now);
$w = array_column($shape($v)['warnings'], 'kind');
ck('diverging: most steps done but the number barely moved — said', in_array('diverging', $w, true));
ck('confidence: a low one is raised as a question', in_array('confidence', $w, true));
ck('planning: the next round is told the steps did not move the number', str_contains(GoalTrack::planningContext($v), 'have not moved the number'));
GoalTrack::checkin(9931, $d, ['value' => 15, 'confidence' => 7, 'blocker' => 'Paystack link broken'], $now);
ck('planning: and what is in the way, from the last check-in', str_contains(GoalTrack::planningContext($d), 'Paystack link broken'));
ck('planning: and the number, against its target', str_contains(GoalTrack::planningContext($m), '290 members now, from 200, target 500'));

/* ── Task states ────────────────────────────────────────────────────── */
$r = Collab::setState(9931, $s2, 'blocked');
ck('state: blocked needs what it is blocked on', isset($r['error']));
$r = Collab::setState(9931, $s2, 'doing');
ck('state: doing, and when it started', ($r['state'] ?? '') === 'doing' && ($r['started_at'] ?? '') !== '');
$r = Collab::setState(9931, $s2, 'blocked', 'Printer out of toner');
ck('state: blocked, with the reason kept', ($r['state'] ?? '') === 'blocked' && $r['blocked_reason'] === 'Printer out of toner');
ck('state: a blocked step shows on its goal', in_array('blocked', array_column($shape($u)['warnings'], 'kind'), true) && $shape($u)['steps']['blocked'] === 1);
$tp->prepare('UPDATE collab_tasks SET state_at = ? WHERE id = ?')->execute([$at(-3), $s2]);
ck('stuck: blocked over two days is flagged', Collab::flagOf($tp->query('SELECT * FROM collab_tasks WHERE id = ' . $s2)->fetch(PDO::FETCH_ASSOC), $now)['flag'] === 'blocked');
$r = Collab::setState(9931, $s2, 'done');
ck('state: done through setState ticks it — the goal moves', ($r['done'] ?? false) === true && $shape($u)['pct'] === 71);
$r = Collab::toggleTask(9931, $s2);
ck('state: put back, a started step is doing again, its finish time cleared', (string) $tp->query('SELECT state FROM collab_tasks WHERE id = ' . $s2)->fetchColumn() === 'doing'
   && (string) $tp->query('SELECT done_at FROM collab_tasks WHERE id = ' . $s2)->fetchColumn() === '');
$tp->prepare('UPDATE collab_tasks SET state_at = ? WHERE id = ?')->execute([$at(-9), $s2]);
ck('stuck: doing for over a week is stalled', Collab::flagOf($tp->query('SELECT * FROM collab_tasks WHERE id = ' . $s2)->fetch(PDO::FETCH_ASSOC), $now)['flag'] === 'stalled');
ck('state: nobody else moves your task', isset(Collab::setState(9932, $s3, 'doing')['error']));
ck('size: resizing moves the goal — the S step made L', Collab::setSize(9931, $s2, 'L') !== null && $shape($u)['steps']['points'] === 10);

/* ── Check-ins due; mirrors; closing ────────────────────────────────── */
ck('due: a goal checked in today is not owed one', !GoalTrack::checkinDue(GoalTrack::row($m), $now));
ck('due: a week later it is', GoalTrack::checkinDue(GoalTrack::row($m), $now + 8 * 86400));
ck('due: the owner’s list holds the goals owed one', in_array($u, array_map(fn($g) => (int) $g['id'], GoalTrack::dueFor(9931, $now + 8 * 86400)), true));
$tp->prepare('UPDATE team_goals SET cac_goal_id = 77 WHERE id = ?')->execute([$k]);
ck('mirror: a CACENTRE goal is checked in there, not here', !GoalTrack::checkin(9931, $k, ['value' => 40, 'confidence' => 9], $now)['ok'] && !GoalTrack::checkinDue(GoalTrack::row($k), $now + 30 * 86400));
$pool = Collab::addPoolTask(9931, 'Paint the library sign', '', 'normal', $u);
ck('close: achieved, with how it went', Goals::close(9931, $u, 'achieved', 'Opened on the 12th with 400 books') && GoalTrack::row($u)['status'] === 'achieved' && GoalTrack::row($u)['closed_note'] !== '');
ck('close: its unclaimed steps go with it; work people hold stays theirs', !$tp->query('SELECT 1 FROM collab_tasks WHERE id = ' . $pool)->fetchColumn() && $tp->query('SELECT 1 FROM collab_tasks WHERE id = ' . $s3)->fetchColumn());
ck('close: a closed goal is not owed a check-in, nor warned about', !GoalTrack::checkinDue(GoalTrack::row($u), $now + 30 * 86400) && $shape($u)['warnings'] === []);

/* ── The to-do pop-up asks for the check-in, and shows a step's state ─── */
$tp->prepare("UPDATE team_goals SET checked_in_at = ?, created_at = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - 9 * 86400), gmdate('Y-m-d H:i:s', time() - 30 * 86400), $m]);
$b = Collab::addTask(9931, 'Fix the Paystack link', 9931, '', 'high', $m, 'S');
Collab::setState(9931, $b, 'blocked', 'Waiting on the bank');
$secs = Todo::sections(Todo::for(['id' => 9931, 'name' => 'Ola Owner', 'email' => 'gt9931@afrovanguard.org.ng', 'role' => 'member']));
$ci = array_values(array_filter($secs, fn($x) => $x['key'] === 'checkin'))[0] ?? null;
ck('pop-up: a goal owed a check-in is asked for, with its health, linking to the goal', $ci && in_array('ci' . $m, array_column($ci['items'], 'id'), true)
   && str_contains(json_encode($ci), 'app=goals') && ($ci['items'][0]['badge']['text'] ?? '') === 'Check in');
$gs = array_values(array_filter($secs, fn($x) => ($x['id'] ?? '') === 'goal' . $m))[0] ?? null;
ck('pop-up: a goal reads its number and pace, not just steps', $gs && str_contains($gs['sub'], '290 of 500 members') && preg_match('/^(Behind|At risk) · /u', $gs['sub']) === 1);
ck('pop-up: a blocked step says on what', $gs && str_contains(json_encode($gs['items']), 'Blocked: Waiting on the bank'));

$tp->exec('DELETE FROM goal_checkins WHERE goal_id IN (SELECT id FROM team_goals WHERE author_id BETWEEN 9930 AND 9939)');
$tp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9930 AND 9939');
$tp->exec('DELETE FROM team_goals WHERE author_id BETWEEN 9930 AND 9939');
$tp->exec('DELETE FROM lms_users WHERE id BETWEEN 9930 AND 9939');
