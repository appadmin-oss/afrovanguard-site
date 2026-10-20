<?php
/**
 * tests/taskledger.test.php — work goes to who will do it (lib/TaskLedger.php,
 * lib/TaskMatch.php).
 *
 *   • What happened to work is kept: given, handed back (and why), done.
 *   • Who finished work like it ranks above who is only labelled for it.
 *   • Handed back as "not my kind of work": the like of it is kept from them;
 *     "no time": only for a while.
 *   • Load is the size of what they hold, and what is overdue — heavier when
 *     the task is due soon. Somebody long absent ranks low, but still fits.
 *   • Away until a date: nothing is put their way.
 *   • The model is shown the same record, gives a confidence, and is not
 *     acted on below it; when it fails, the labels decide.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$lp = Database::pdo();
TaskLedger::ensure();
TaskMatch::ensure();
Prefs::ensure();
$lp->exec('DELETE FROM lms_users WHERE id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9940 AND 9949 OR assignee_id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM task_assign_log WHERE user_id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM user_prefs WHERE user_id BETWEEN 9940 AND 9949');
$recent = gmdate('Y-m-d H:i:s', time() - 86400);
foreach ([9941 => ['Bisi Boss', 'coordinator'], 9942 => ['Ade Shooter', 'member'], 9943 => ['Tola Snapper', 'member'], 9944 => ['Nneka Away', 'member'], 9945 => ['Femi Gone', 'member']] as $id => [$n, $role]) {
    $lp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status, last_login) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, $n, 'tl' . $id . '@afrovanguard.org.ng', 'x', $role, 'active', $id === 9945 ? gmdate('Y-m-d H:i:s', time() - 90 * 86400) : $recent]);
}
/* Both photographers by label; only one has done the work. */
foreach ([9942, 9943, 9944, 9945] as $id) Prefs::set($id, 'skills', 'Photography, Media');
$now = time();
$P = static function (int $id) { TaskMatch::forget(); return TaskMatch::profiles()[$id] ?? null; };

/* ── The ledger ──────────────────────────────────────────────────────── */
$t1 = Collab::addTask(9941, 'Photograph the robotics showcase', 9942, gmdate('Y-m-d', $now + 86400));
ck('ledger: a task given to somebody is kept as given, by a person', (bool) $lp->query("SELECT 1 FROM task_assign_log WHERE task_id = $t1 AND user_id = 9942 AND event = 'assigned' AND via = 'person'")->fetchColumn());
Collab::toggleTask(9942, $t1);
ck('ledger: and as done when they tick it', (bool) $lp->query("SELECT 1 FROM task_assign_log WHERE task_id = $t1 AND user_id = 9942 AND event = 'done'")->fetchColumn());
$t2 = Collab::addTask(9941, 'Film the graduation video', 9943);
ck('ledger: handed back with a reason', Collab::releaseTask(9943, $t2, 'not_mine')
   && (string) $lp->query("SELECT reason FROM task_assign_log WHERE task_id = $t2 AND event = 'handed_back'")->fetchColumn() === 'not_mine');
$t3 = Collab::addTask(9941, 'Sort the hall chairs', 9943);
Collab::releaseTask(9941, $t3);
ck('ledger: taken back by whoever gave it is not the holder handing it back', !$lp->query("SELECT 1 FROM task_assign_log WHERE task_id = $t3 AND event = 'handed_back'")->fetchColumn());
$h = TaskLedger::history([9942, 9943]);
ck('history: what they finished, newest first', ($h[9942]['done'][0] ?? '') === 'Photograph the robotics showcase');
ck('history: on time is counted against the due date', $h[9942]['on_time'] === 1 && $h[9942]['late'] === 0);
ck('history: what they handed back, and why', ($h[9943]['handed_back'][0]['reason'] ?? '') === 'not_mine');
ck('history: what they were given this week', $h[9942]['given_week'] === 1 && $h[9943]['given_week'] === 2);

/* ── Who ranks first ─────────────────────────────────────────────────── */
$task = 'Take photographs at the open day';
[$sAde, $wAde] = TaskMatch::score($P(9942), $task);
[$sTola, $wTola, $fTola] = TaskMatch::score($P(9943), $task);
ck('record: who finished work like it ranks above who is only labelled for it', $sAde > $sTola);
ck('record: and it says so', (bool) array_filter($wAde, fn($w) => str_starts_with($w, 'Finished 1 like it')));
ck('record: "photographs" meets "Photograph" by its root', TaskMatch::alike('Take photographs at the open day', 'Photograph the robotics showcase') !== '');
[, $wFilm] = TaskMatch::score($P(9943), 'Film the prize-giving video');
ck('handed back as not theirs: the like of it counts against them, and says so', (bool) array_filter($wFilm, fn($w) => str_contains($w, 'not their kind of work')));
$lp->prepare("UPDATE task_assign_log SET reason = 'busy', at = ? WHERE task_id = ?")->execute([gmdate('Y-m-d H:i:s', $now - 20 * 86400), $t2]);
[, $wOld] = TaskMatch::score($P(9943), 'Film the prize-giving video');
ck('handed back for no time, three weeks ago: forgotten', !array_filter($wOld, fn($w) => str_contains($w, 'Handed')));
$lp->prepare("UPDATE task_assign_log SET at = ? WHERE task_id = ?")->execute([gmdate('Y-m-d H:i:s', $now - 2 * 86400), $t2]);
[, $wNew] = TaskMatch::score($P(9943), 'Film the prize-giving video');
ck('handed back for no time, two days ago: said, and weighed a little', (bool) array_filter($wNew, fn($w) => str_contains($w, 'no time')));

/* ── Whether they can ────────────────────────────────────────────────── */
$base = TaskMatch::score($P(9943), $task)[0];
foreach (['L', 'L'] as $sz) Collab::addTask(9941, 'Big job ' . $sz . mt_rand(), 9943, '', 'normal', 0, $sz);
$loaded = TaskMatch::score($P(9943), $task)[0];
ck('load: two large tasks (8 points) weigh more than their count', $loaded < $base && (bool) array_filter(TaskMatch::score($P(9943), $task)[1], fn($w) => str_contains($w, '8 points')));
$soon = TaskMatch::score($P(9943), $task, '', ['due' => gmdate('Y-m-d', $now + 86400)])[0];
ck('load: and weigh double when the task is due within three days', $soon < $loaded);
Collab::addTask(9941, 'Overdue thing', 9943, gmdate('Y-m-d', $now - 3 * 86400));
ck('load: overdue work counts against them, and says so', (bool) array_filter(TaskMatch::score($P(9943), $task)[1], fn($w) => str_contains($w, '1 overdue')));
[$sGone, $wGone, $fGone] = TaskMatch::score($P(9945), $task);
[$sHere, , $fHere] = TaskMatch::score($P(9944), $task);
ck('absent: somebody not seen for 90 days ranks below the same labels seen yesterday', $sGone < $sHere && (bool) array_filter($wGone, fn($w) => str_contains($w, 'Not seen for 90 days')));
ck('absent: but fits as well — absence is not a lack of fit', $fGone === $fHere && $fGone >= 1);

/* ── Away ────────────────────────────────────────────────────────────── */
ck('away: a date in the past is refused', !TaskLedger::setAway(9944, gmdate('Y-m-d', $now - 86400))['ok']);
ck('away: set until a date', TaskLedger::setAway(9944, gmdate('Y-m-d', $now + 7 * 86400))['ok'] && TaskLedger::awayUntil(9944) !== '');
TaskMatch::forget();
ck('away: left off every shortlist until then', !in_array(9944, array_column(TaskMatch::shortlist($task, 50), 'id'), true));
TaskLedger::setAway(9944, '');
TaskMatch::forget();
ck('away: back, and on the shortlist again', in_array(9944, array_column(TaskMatch::shortlist($task, 50), 'id'), true));

/* ── The model ───────────────────────────────────────────────────────── */
$seen = '';
TaskMatch::$aiForTests = static function ($sys, $prompt) use (&$seen) {
    $seen = $sys . "\n" . $prompt;
    return ['ok' => true, 'text' => '[{"id":9942,"confidence":0.9,"why":"Shot the showcase"},{"id":9943,"confidence":0.2,"why":"Labelled only"}]'];
};
TaskMatch::forget();
$sg = TaskMatch::suggest($task, 3, '', ['due' => gmdate('Y-m-d', $now + 86400), 'priority' => 'high']);
ck('AI: is shown each candidate’s record, load and when they were last here', str_contains($seen, 'RECORD: recently finished: "Photograph the robotics showcase"')
   && str_contains($seen, 'points') && str_contains($seen, 'last seen'));
ck('AI: is told the task’s date and priority, and to weigh record first', str_contains($seen, 'due ' . gmdate('Y-m-d', $now + 86400)) && str_contains($seen, 'high priority') && str_contains($seen, 'RECORD'));
ck('AI: its pick is used, with its confidence', ($sg['via'] ?? '') === 'ai' && ($sg['suggestions'][0]['id'] ?? 0) === 9942 && ($sg['suggestions'][0]['confidence'] ?? 0) === 0.9);
ck('AI: somebody it is not sure of (0.2) is not suggested', !in_array(9943, array_column($sg['suggestions'], 'id'), true));
TaskMatch::$aiForTests = static fn() => ['ok' => true, 'text' => '[{"step":1,"id":9942,"confidence":0.1,"why":"meh"}]'];
TaskMatch::forget();
$many = TaskMatch::assignMany([['title' => 'Photograph the volunteers day', 'kind' => 'media']]);
ck('AI: a goal step it is not sure of waits in the pool', array_key_exists(0, $many) && $many[0] === null);
/* The sweep: the model failing is not the model being unsure. */
$hrs = AvRules::int('tasks.auto_assign_hours');
AvRules::save(['tasks.auto_assign_hours' => '24'], 'test');
$stale = gmdate('Y-m-d H:i:s', $now - 30 * 3600);
$pa = Collab::addPoolTask(9941, 'Photograph the alumni dinner'); $lp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([$stale, $pa]);
TaskMatch::$aiForTests = static fn() => ['ok' => false, 'text' => ''];
TaskMatch::forget();
TaskMatch::autoAssign();
ck('sweep: the model down, the labels still give it out', (int) $lp->query("SELECT assignee_id FROM collab_tasks WHERE id = $pa")->fetchColumn() > 0);
ck('sweep: and it is kept as given by the sweep', (bool) $lp->query("SELECT 1 FROM task_assign_log WHERE task_id = $pa AND via = 'auto'")->fetchColumn());
$pb = Collab::addPoolTask(9941, 'Photograph the board meeting'); $lp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([$stale, $pb]);
TaskMatch::$aiForTests = static fn() => ['ok' => true, 'text' => '[{"id":9942,"confidence":0.3,"why":"maybe"}]'];
TaskMatch::forget();
TaskMatch::autoAssign();
ck('sweep: the model answering that it is not sure (0.3) leaves it in the pool', (int) $lp->query("SELECT assignee_id FROM collab_tasks WHERE id = $pb")->fetchColumn() === 0);
AvRules::save(['tasks.auto_assign_hours' => (string) $hrs], 'test');
TaskMatch::$aiForTests = null;

/* ── Wiring ──────────────────────────────────────────────────────────── */
$api = (string) file_get_contents(AV_ROOT . '/portal/collab.php');
ck('API: handing back takes a reason, and away can be set', str_contains($api, "Collab::releaseTask(\$uid, (int) (\$body['id'] ?? 0), (string) (\$body['reason'] ?? ''))") && str_contains($api, "case 'task_away':"));
$js = (string) file_get_contents(AV_ROOT . '/portal/tasks.js');
ck('Tasks: handing back asks why in one tap, and there is an away control', str_contains($js, 'Back to the pool — why?') && str_contains($js, "post('task_away'"));
ck('planner: a goal’s steps reach the matcher with their date, size and goal', str_contains((string) file_get_contents(AV_ROOT . '/lib/Collab.php'), "'goal' => \$goal['title']"));

$lp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9940 AND 9949 OR assignee_id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM task_assign_log WHERE user_id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM user_prefs WHERE user_id BETWEEN 9940 AND 9949');
$lp->exec('DELETE FROM lms_users WHERE id BETWEEN 9940 AND 9949');
TaskMatch::forget();
