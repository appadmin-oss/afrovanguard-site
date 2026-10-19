<?php
/**
 * tests/goalplanner.test.php — tasks come from goals.
 *
 *   • An open goal with nothing open under it is planned; one with steps in
 *     hand is not; a closed goal never is.
 *   • With no AI, the goal's author is given one step: agree the next step.
 *   • Its progress follows its steps as they are ticked.
 *   • Every step done: planned again — but not twice in a day.
 *   • The to-do pop-up files each goal, with its steps and progress, under Goals.
 *
 * Run via tests/run.php (provides ck()). AI is not configured in the suite.
 */
declare(strict_types=1);

$gp = Database::pdo();
GoalPlanner::ensure();
$gp->exec('DELETE FROM collab_tasks');
$gp->exec('DELETE FROM team_goals');
$gp->exec('DELETE FROM lms_users WHERE id BETWEEN 9970 AND 9979');
$gp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([9971, 'Gina Goals', 'gina.gp@afrovanguard.org.ng', 'x', 'coordinator', 'active']);
$gina = ['id' => 9971, 'name' => 'Gina Goals', 'email' => 'gina.gp@afrovanguard.org.ng', 'role' => 'coordinator'];
$now = time();

$g1 = Goals::create(9971, 'Grow membership to 500', '500 paying members');
$g2 = Goals::create(9971, 'Run the summer school');
$g3 = Goals::create(9971, 'Old campaign'); Goals::close(9971, $g3);
Collab::addTask(9971, 'Book the venue', 9971, '', 'normal', $g2);

$due = array_map(fn($g) => (int) $g['id'], GoalPlanner::due($now));
ck('goals: an open goal with nothing open under it needs steps', in_array($g1, $due, true));
ck('goals: one with a step in hand does not', !in_array($g2, $due, true));
ck('goals: a closed goal never does', !in_array($g3, $due, true));

$r = GoalPlanner::plan($g1, $now);
$steps = $gp->query("SELECT * FROM collab_tasks WHERE goal_id = $g1")->fetchAll(PDO::FETCH_ASSOC);
ck('goals: with no AI, the author is given one step — agree the next step', $r['ok'] && $r['via'] === 'author' && count($steps) === 1
   && str_contains($steps[0]['title'], 'next step') && (int) $steps[0]['assignee_id'] === 9971);
ck('goals: and it is no longer due', !in_array($g1, array_map(fn($g) => (int) $g['id'], GoalPlanner::due($now)), true));

/* progress follows the steps */
$s2 = Collab::addTask(9971, 'Draft the membership drive', 9971, '', 'normal', $g1);
Collab::toggleTask(9971, (int) $steps[0]['id']);
ck('goals: one of two steps done is 50%', Goals::get($g1)['progress'] === 50);
Collab::toggleTask(9971, $s2);
ck('goals: both done is 100%', Goals::get($g1)['progress'] === 100);
ck('goals: and the goal stays open — it may need more', Goals::get($g1)['closed'] === false);
ck('goals: every step done, it is not planned again the same day', !in_array($g1, array_map(fn($g) => (int) $g['id'], GoalPlanner::due($now)), true));
ck('goals: …but is the next day', in_array($g1, array_map(fn($g) => (int) $g['id'], GoalPlanner::due($now + 86400 + 60)), true));

/* the sweep */
AvRules::save(['tasks.from_goals' => '0'], 'test');
ck('goals: switched off, the sweep plans nothing', GoalPlanner::sweep($now + 2 * 86400)['off'] === true);
AvRules::save(['tasks.from_goals' => '1'], 'test');
$sw = GoalPlanner::sweep($now + 2 * 86400);
ck('goals: the sweep plans what is due', $sw['planned'] >= 1 && $sw['tasks'] >= 1);

/* the pop-up */
$fu = GoalPlanner::forUser(9971);
$mine = array_values(array_filter($fu, fn($g) => $g['id'] === $g2))[0] ?? null;
ck('goals: what they work towards, with their open steps', $mine && $mine['tasks'][0]['title'] === 'Book the venue');
$sec = Todo::sections(Todo::for($gina));
$goalSecs = array_values(array_filter($sec, fn($s) => ($s['group'] ?? '') === 'goals' && $s['key'] === 'goal'));
ck('goals: the pop-up files each goal under Goals, with progress and its steps', count($goalSecs) >= 2 && isset($goalSecs[0]['progress'], $goalSecs[0]['sub']) && !empty($goalSecs[0]['items'][0]['tick']));
ck('goals: each goal section has its own id', count(array_unique(array_column($goalSecs, 'id'))) === count($goalSecs));
$js = (string) file_get_contents(AV_ROOT . '/portal/todo-pop.js');
ck('goals: the pop-up has a Goals tab, and names a task\'s goal elsewhere', str_contains($js, "['goals', 'Goals'") && str_contains($js, 'it.goal ?'));
ck('goals: a new goal is planned as it is made', str_contains((string) file_get_contents(AV_ROOT . '/portal/goals.php'), 'GoalPlanner::plan($id)'));

$gp->exec('DELETE FROM collab_tasks');
$gp->exec('DELETE FROM team_goals');
$gp->exec('DELETE FROM lms_users WHERE id BETWEEN 9970 AND 9979');
