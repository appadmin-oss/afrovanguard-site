<?php
/**
 * tests/taskassign.test.php — creating and assigning tasks (owner, 2026-11-13:
 * "fix the issues with creating and assigning tasks").
 *
 * Each check is a failure found by using the Tasks page as a member:
 *  - the Assign list only ever offered "Me": loading the page threw on a panel
 *    the redesign removed, and everything after it never ran;
 *  - "Open to anyone (pool)" shared an id with the pool card, so it never worked;
 *  - a refused or failed "Add task" cleared what you typed and said nothing;
 *  - a task could be made for an account that was gone, or someone away.
 * Who may give stays as designed (tests/taskmatch.test.php): a creator gives
 * their own task to anyone; a pool task needs whoever hands out work.
 */
declare(strict_types=1);
$r = AV_ROOT;
$pdo = Database::pdo();
$mk = static function (string $name, string $role = 'learner') use ($pdo): int {
    $pdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?,?,?,?,'active')")
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@ta.test', 'x', $role]);
    return (int) $pdo->lastInsertId();
};
$boss = $mk('Tola Lead', 'member'); $ada = $mk('Ada Doer'); $gone = $mk('Gone Person');
$pdo->prepare("UPDATE lms_users SET status = 'suspended' WHERE id = ?")->execute([$gone]);

/* The rule, in one place. */
ck('tasks: giving a task to yourself is always allowed', Collab::canGiveTo($ada, $ada, false) === '' && Collab::canGiveTo($ada, 0, false) === '');
ck('tasks: a creator may give their own task to an active person (as designed)', Collab::canGiveTo($ada, $boss, false) === '' && Collab::canGiveTo($boss, $ada, true) === '');
ck('tasks: not to an account that is not active', str_contains(Collab::canGiveTo($boss, $gone, true), 'not an active member'));
ck('tasks: not to an account that does not exist', str_contains(Collab::canGiveTo($boss, 99999999, true), 'not an active member'));
TaskLedger::setAway($ada, gmdate('Y-m-d', time() + 4 * 86400));
ck('tasks: not to someone who is away, and it says until when', (bool) preg_match('/^Ada Doer is away until \d{1,2} \w{3}/', Collab::canGiveTo($boss, $ada, true)));
TaskLedger::setAway($ada, '');

/* Made for someone: the same rule as giving one later. */
ck('tasks: a task cannot be MADE for an account that is gone', Collab::addTask($ada, 'Sweep the hall', $gone, '', 'normal', 0, '', 'person', false) === 0 && str_contains(Collab::$lastError, 'not an active member'));
ck('tasks: …not even by the planner', Collab::addTask($boss, 'Planner step', $gone) === 0);
$id = Collab::addTask($boss, 'Call the caterer', $ada, gmdate('Y-m-d', time() + 86400), 'high', 0, '', 'person', true);
$t = Collab::oneTask($boss, $id);
ck('tasks: a member makes one for someone else', $id > 0 && $t['assignee_id'] === $ada && $t['assigned_out'] === true);
ck('tasks: …and it records who gave it and when, as a later assignment does', $t['assigned_by'] === $boss && $t['assigned_at'] !== '');
ck('tasks: the person given it sees it as theirs', (Collab::oneTask($ada, $id)['mine'] ?? false) === true);
ck('tasks: a task for yourself has no "given by"', ($own = Collab::addTask($ada, 'Read chapter two', 0, '', 'normal', 0, '', 'person', false)) > 0 && Collab::oneTask($ada, $own)['assigned_out'] === false);
ck('tasks: an empty title is refused, saying so', Collab::addTask($boss, '   ', 0, '', 'normal', 0, '', 'person', true) === 0 && Collab::$lastError === 'Enter a task.');
ck('tasks: callers with their own rules (the planner) still give work to active people', Collab::addTask($ada, 'Planner step', $boss) > 0);

/* Given later: a reason when refused. */
$mine = Collab::addTask($ada, 'Fold the chairs', 0, '', 'normal', 0, '', 'person', false);
ck('tasks: a creator gives their own task later too (as designed)', (Collab::assignTask($ada, $mine, $boss, false)['assignee_id'] ?? 0) === $boss);
$pool2 = Collab::addPoolTask($boss, 'Mop the stage');
ck('tasks: a pool task needs whoever hands out work, and says so', Collab::assignTask($ada, $pool2, $ada, false) === null && Collab::$lastError !== '');
$pool = Collab::addPoolTask($boss, 'Stack the books');
ck('tasks: whoever hands out work gives a pool task', (Collab::assignTask($boss, $pool, $ada, true)['assignee_id'] ?? 0) === $ada);
ck('tasks: a finished or missing task says so', Collab::assignTask($boss, 99999999, $ada, true) === null && str_contains(Collab::$lastError, 'finished or no longer exists'));

/* The routes pass the rule and the reason through. */
$api = (string) file_get_contents("$r/portal/collab.php");
ck('tasks route: task_add runs the checks and returns their reason', str_contains($api, "'person', \$allocates);") && str_contains($api, "Collab::\$lastError ?: 'Enter a task.'"));
ck('tasks route: task_add answers with the task itself, not a search of my first 50', str_contains($api, "'task' => Collab::oneTask(\$uid, \$id) ?? ['id' => \$id]"));
ck('tasks route: the page is told whether this person may post to the pool', str_contains($api, "'org'      => \$isOrg,"));
ck('chat route: a task made from a message runs the same checks', str_contains((string) file_get_contents("$r/portal/chat.php"), "'person', \$allocates);"));

/* The page. */
$view = (string) file_get_contents("$r/portal/views/tasks.php"); $js = (string) file_get_contents("$r/portal/tasks.js");
ck('tasks page: the pool checkbox has its own id (it shared the pool card\'s)', substr_count($view, 'id="taskPool"') === 1 && str_contains($view, 'id="taskToPool"') && str_contains($js, "poolChk=document.getElementById('taskToPool')"));
ck('tasks page: one failing part no longer stops the rest loading', str_contains($js, ".forEach(function(step){ try{ step(); }catch(e)") && str_contains($js, 'function renderActivity(items){ if(!actEl) return;'));
ck('tasks page: a failed load says so, with Try again', str_contains($js, "Your tasks could not be loaded.") && str_contains($js, 'id="taskRetry"'));
ck('tasks page: what you typed stays when adding fails, and the reason is shown', str_contains($js, "if(!ok){ addErr((d&&d.error)||'The task was not added.") && str_contains($view, 'id="taskAddErr" role="alert"'));
ck('tasks page: the pool option is offered only to those who may post to it', str_contains($view, 'id="taskToPoolWrap" hidden') && str_contains($js, 'pw.hidden=!d.org'));
ck('tasks page: a person and the pool exclude each other', str_contains($js, "if(+aselEl.value) poolChk.checked=false;"));

$pdo->prepare('DELETE FROM collab_tasks WHERE creator_id IN (?,?,?) OR assignee_id IN (?,?,?)')->execute([$boss, $ada, $gone, $boss, $ada, $gone]);
