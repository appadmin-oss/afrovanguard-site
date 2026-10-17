<?php
/**
 * tests/todo.test.php — the to-do pop-up's list, "suggested for you", and the
 * sweep that gives an unclaimed pool task to whoever fits it.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tdp = Database::pdo();
NggVolunteers::ensure(); TaskMatch::ensure();
$tdp->exec('DELETE FROM collab_tasks');
$tdp->exec('DELETE FROM ngg_volunteers');
$tdp->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9969');
$mk = static function (int $id, string $n, string $e, string $role = 'learner') use ($tdp): array {
    $tdp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, 'x', $role, 'active']);
    return ['id' => $id, 'name' => $n, 'email' => $e, 'role' => $role];
};
$seedV = static function (string $vid, string $n, string $e, string $role, string $courses, string $centre) use ($tdp): void {
    $tdp->prepare("INSERT INTO ngg_volunteers (volunteer_id, name, email, role, role_label, centre, status, safeguarding_cleared, programme, faculty, courses, origin, seeded_at) VALUES (?,?,?,?,?,?,'active',1,'summer','',?,'seed','x')")
        ->execute([$vid, $n, $e, $role, VolunteerSeeds::roleLabel(['role' => $role, 'courses' => $courses]), $centre, $courses]);
};
$boss  = $mk(9951, 'Kemi Coord', 'kemi.td@afrovanguard.org.ng', 'coordinator');
$cook  = $mk(9952, 'Ade Cook', 'ade.td@gmail.test');   $seedV('TD-1', 'Ade Cook', 'ade.td@gmail.test', 'instructor', 'Catering', 'Egbeda');
$clerk = $mk(9953, 'Chi Clerk', 'chi.td@gmail.test');  $seedV('TD-2', 'Chi Clerk', 'chi.td@gmail.test', 'admin', '', 'Ikotun');
$learner = $mk(9954, 'Lola Learner', 'lola.td@gmail.test');
TaskMatch::forget();

$today = gmdate('Y-m-d'); $yday = gmdate('Y-m-d', time() - 86400); $next = gmdate('Y-m-d', time() + 5 * 86400);
$given = Collab::addTask(9951, 'Prepare the catering practical', 9952, $next);
$own   = Collab::addTask(9952, 'Buy flour', 9952, $yday);
$later = Collab::addTask(9952, 'Plan next month', 9952, $next);
$pool1 = Collab::addPoolTask(9951, 'Teach the catering class at Egbeda');
$pool2 = Collab::addPoolTask(9951, 'Collate the attendance register at Ikotun');

$d = Todo::for($cook);
ck('todo: a task somebody gave them is "just assigned", with who from', ($d['assigned'][0]['id'] ?? 0) === $given && $d['assigned'][0]['from'] === 'Kemi Coord');
ck('todo: their own overdue task is due', array_column($d['due'], 'id') === [$own]);
ck('todo: a task due later is not nagged about', !in_array($later, array_column($d['due'], 'id'), true));
ck('todo: the pool task that fits them is suggested, with why', ($d['suggested'][0]['id'] ?? 0) === $pool1 && str_contains($d['suggested'][0]['why'], 'Catering'));
ck('todo: the register is not suggested to the cook', !in_array($pool2, array_column($d['suggested'], 'id'), true));
$sec = Todo::sections($d);
$keys = array_column($sec, 'key');
ck('todo: sections in the order a day reads them', $keys === ['assigned', 'due', 'suggested']);
$it = $sec[2]['items'][0];
ck('todo: a suggestion carries its own action, so the pop-up knows nothing about the site', $it['take']['action'] === 'todo_take' && $it['take']['body'] === ['id' => $pool1]);
ck('todo: an overdue task is marked urgent', $sec[1]['items'][0]['urgent'] === true && str_starts_with($sec[1]['items'][0]['meta'], 'Overdue'));
ck('todo: a learner who is neither member nor volunteer is offered nothing to take', !Todo::mayTake($learner) && Todo::for($learner)['suggested'] === []);
ck('todo: a volunteer may take', Todo::mayTake($cook));
AvRules::save(['tasks.todo_popup' => '0'], 'test');
ck('todo: switched off in the Studio, the list is empty and says so', Todo::for($cook)['enabled'] === false && Todo::for($cook)['assigned'] === []);
AvRules::save(['tasks.todo_popup' => '1'], 'test');

/* The sweep */
AvRules::save(['tasks.auto_assign_hours' => '0'], 'test');
ck('sweep: 0 hours is off', TaskMatch::autoAssign()['off'] === true);
AvRules::save(['tasks.auto_assign_hours' => '24'], 'test');
ck('sweep: a task posted just now is left for people to take', TaskMatch::autoAssign()['assigned'] === 0);
$stale = gmdate('Y-m-d H:i:s', time() - 30 * 3600);
$tdp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id IN (?, ?)')->execute([$stale, $pool1, $pool2]);
$pool3 = Collab::addPoolTask(9951, 'Zzzz qqqq'); $tdp->prepare('UPDATE collab_tasks SET created_at = ? WHERE id = ?')->execute([$stale, $pool3]);
TaskMatch::forget();
$r = TaskMatch::autoAssign();
$who = static fn(int $id) => (int) $tdp->query('SELECT assignee_id FROM collab_tasks WHERE id = ' . $id)->fetchColumn();
ck('sweep: a day unclaimed, the catering class goes to the catering instructor', $who($pool1) === 9952);
ck('sweep: the register goes to the admin volunteer', $who($pool2) === 9953);
ck('sweep: a task nobody fits stays in the pool', $who($pool3) === 0 && $r['assigned'] === 2 && $r['left'] === 1);
$n = $tdp->query("SELECT body FROM user_notifications WHERE user_id = 9953 ORDER BY id DESC LIMIT 1")->fetchColumn();
ck('sweep: the person is told why it came to them, and that they can hand it back', is_string($n) && str_contains($n, 'fits you') && str_contains($n, 'Hand it back'));
Collab::releaseTask(9953, $pool2);
TaskMatch::forget();
TaskMatch::autoAssign();
ck('sweep: handed back, it is not pushed on anybody again', $who($pool2) === 0);
ck('sweep: and the one it was given to sees it as just assigned', in_array($pool1, array_column(Todo::for($cook)['assigned'], 'id'), true));

AvRules::save(['tasks.auto_assign_hours' => '24'], 'test');
$tdp->exec('DELETE FROM collab_tasks');
$tdp->exec('DELETE FROM ngg_volunteers');
$tdp->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9969');
TaskMatch::forget();
