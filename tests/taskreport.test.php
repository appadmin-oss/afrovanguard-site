<?php
/**
 * tests/taskreport.test.php — the day's tasks: done, undone, unclaimed
 * (lib/TaskReport.php), on the Tasks page and sent each evening.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$xp = Database::pdo();
Collab::myTasks(0, 1);
Notifications::ensure();
$xp->exec('DELETE FROM collab_tasks');
$xp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9850 AND 9859');
$xp->exec('DELETE FROM lms_users WHERE id BETWEEN 9850 AND 9859');
foreach ([9851 => ['Kunle Coord', 'coordinator'], 9852 => ['Amaka Doer', 'member'], 9853 => ['Tunde Late', 'member']] as $id => [$n, $role]) {
    $xp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, 'tr' . $id . '@afrovanguard.org.ng', 'x', $role, 'active']);
}
$now = time();
$today = TaskReport::today($now);
$ago = static fn(int $d) => gmdate('Y-m-d', strtotime($today . ' 12:00 UTC') - $d * 86400);

$d1 = Collab::addTask(9851, 'Print the flyers', 9852, $today); Collab::toggleTask(9852, $d1);
$d2 = Collab::addTask(9851, 'Book the hall', 9852, $ago(2)); Collab::toggleTask(9852, $d2);
$yd = Collab::addTask(9851, 'Done yesterday', 9852); Collab::toggleTask(9852, $yd);
$xp->prepare('UPDATE collab_tasks SET done_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now - 2 * 86400), $yd]);
$o1 = Collab::addTask(9851, 'Chase the sponsor', 9853, $ago(3));
$o2 = Collab::addTask(9851, 'Send the minutes', 9853, $today);
$o3 = Collab::addTask(9851, 'Fix the projector', 9852, '');
Collab::setState(9852, $o3, 'blocked', 'Waiting on the cable');
$o4 = Collab::addTask(9851, 'Plan next term', 9852, $ago(-10));
$p1 = Collab::addPoolTask(9851, 'Count the chairs');
$xp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', $now - 4 * 86400), $p1]);
$p2 = Collab::addPoolTask(9851, 'Sweep the stage', '', 'high');

$r = TaskReport::build(null, $now);
ck('done: finished today, not yesterday', array_column($r['done'], 'id') !== [] && !array_diff([$d1, $d2], array_column($r['done'], 'id')) && !in_array($yd, array_column($r['done'], 'id'), true));
ck('done: by whom, and whether late', ($x = array_values(array_filter($r['done'], fn($t) => $t['id'] === $d2))[0]) && $x['by'] === 'Amaka Doer' && $x['late'] === true);
ck('undone: overdue first, then due today, then blocked, then the rest', array_column($r['undone'], 'id') === [$o1, $o2, $o3, $o4]);
ck('undone: how many days overdue, and on what it is blocked', $r['undone'][0]['late_days'] === 3 && $r['undone'][2]['blocked'] === 'Waiting on the cable');
ck('unclaimed: the pool, oldest first, with its age and priority', array_column($r['unclaimed'], 'id') === [$p1, $p2] && $r['unclaimed'][0]['age_days'] === 4 && $r['unclaimed'][1]['priority'] === 'high');
ck('counts: done, undone, overdue, blocked, unclaimed', $r['counts'] === ['done' => 2, 'undone' => 4, 'overdue' => 1, 'blocked' => 1, 'unclaimed' => 2]);
$people = array_column($r['people'], null, 'name');
ck('by person: what each finished, holds and has overdue', ($people['Amaka Doer']['done'] ?? 0) === 2 && ($people['Tunde Late']['open'] ?? 0) === 2 && ($people['Tunde Late']['overdue'] ?? 0) === 1);
ck('headline', TaskReport::headline($r) === 'Done 2 · Undone 4 (1 overdue) · Unclaimed 2');
$html = implode("\n", TaskReport::htmlLines($r));
ck('email: the three lists, with names and lateness', str_contains($html, 'Done today (2)') && str_contains($html, 'Chase the sponsor') && str_contains($html, '3 days overdue') && str_contains($html, 'Unclaimed (2)'));

/* ── Sent each evening ───────────────────────────────────────────────── */
$was = [AvRules::bool('tasks.daily_report'), AvRules::int('tasks.daily_report_hour')];
AvRules::save(['tasks.daily_report' => '1', 'tasks.daily_report_hour' => '18'], 'test');
Database::metaSet('task_report_day', '');
$lagos = static fn(string $hm) => (new DateTimeImmutable($today . ' ' . $hm, new DateTimeZone('Africa/Lagos')))->getTimestamp();
ck('send: not before the hour', TaskReport::sendIfDue($lagos('17:30')) === null);
$s = TaskReport::sendIfDue($lagos('18:05'));
ck('send: after it, to coordinators and admins', is_array($s) && $s['sent'] >= 1 && (bool) $xp->query("SELECT 1 FROM user_notifications WHERE user_id = 9851 AND title LIKE 'Tasks today:%'")->fetchColumn());
ck('send: not to a member who does not hand out work', !$xp->query("SELECT 1 FROM user_notifications WHERE user_id = 9852 AND title LIKE 'Tasks today:%'")->fetchColumn());
ck('send: once a day', TaskReport::sendIfDue($lagos('20:00')) === null);
AvRules::save(['tasks.daily_report' => '0'], 'test');
Database::metaSet('task_report_day', '');
ck('send: off, nothing', TaskReport::sendIfDue($lagos('19:00')) === null);
AvRules::save(['tasks.daily_report' => $was[0] ? '1' : '0', 'tasks.daily_report_hour' => (string) $was[1]], 'test');

/* ── Wiring ──────────────────────────────────────────────────────────── */
ck('cron sends it', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'TaskReport::sendIfDue()'));
$api = (string) file_get_contents(AV_ROOT . '/portal/collab.php');
ck('Tasks page: for whoever hands out work only', str_contains($api, "case 'task_report':") && str_contains($api, "if (!\$allocates) json_out(['ok' => false, 'error' => 'The daily report is for whoever hands out work.'], 403);"));
ck('Tasks page: the card with its three lists', str_contains((string) file_get_contents(AV_ROOT . '/portal/views/tasks.php'), 'id="taskReport"'));

$xp->exec('DELETE FROM collab_tasks');
$xp->exec('DELETE FROM user_notifications WHERE user_id BETWEEN 9850 AND 9859');
$xp->exec('DELETE FROM lms_users WHERE id BETWEEN 9850 AND 9859');
Database::metaSet('task_report_day', '');
