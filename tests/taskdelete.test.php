<?php
/**
 * tests/taskdelete.test.php — deleting a task keeps the goal honest and the
 * record of finished work intact.
 *
 * Collab::deleteTask() was a bare DELETE. Two things followed from it. A goal
 * kept the progress it had with the step in it — every other change to a step
 * (tick, size) resyncs the goal, deleting did not, so the bar lied until the
 * next tick somewhere else. And the creator could delete a task its assignee
 * had already finished; what somebody finished is read from collab_tasks
 * (TaskLedger::history, the daily report), so their work vanished from their
 * record and from what the matcher weighs next time.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tdl = Database::pdo();
GoalPlanner::ensure();
$tdl->exec('DELETE FROM lms_users WHERE id IN (9991, 9992)');
$tdl->prepare("INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES
    (9991, 'Gail Giver', 'gail.td@afrovanguard.org.ng', 'x', 'coordinator', 'active'),
    (9992, 'Hal Doer', 'hal.td@mail.test', 'x', 'member', 'active')")->execute();

/* The goal follows. */
$tdG = Goals::create(9991, 'Paint the library');
$tdA = Collab::addTask(9991, 'Buy the paint', 9991, '', 'normal', $tdG, 'S');
$tdB = Collab::addTask(9991, 'Paint the north wall', 9991, '', 'normal', $tdG, 'S');
Collab::toggleTask(9991, $tdA);
ck('delete: the fixture goal is half done', Goals::get($tdG)['progress'] === 50);
ck('delete: an open step can be deleted', Collab::deleteTask(9991, $tdB));
ck('delete: and the goal\'s progress follows at once — its only remaining step is done', Goals::get($tdG)['progress'] === 100);

/* Somebody else's finished work stays. */
$tdGiven = Collab::addTask(9991, 'Sand the shelves', 9992, '', 'normal', 0, '', 'person', true);
Collab::toggleTask(9992, $tdGiven);
ck('delete: the creator cannot delete a task its assignee has finished', !Collab::deleteTask(9991, $tdGiven));
ck('delete: …so it is still there', (int) $tdl->query('SELECT COUNT(*) FROM collab_tasks WHERE id = ' . $tdGiven)->fetchColumn() === 1);
ck('delete: …and still in the record of what they finished', in_array('Sand the shelves', TaskLedger::history([9992])[9992]['done'], true));
ck('delete: the refusal says why, so the door does not answer "not found"', Collab::deleteRefused(9991, $tdGiven) !== '');
$tdOpen = Collab::addTask(9991, 'Varnish the shelves', 9992, '', 'normal', 0, '', 'person', true);
ck('delete: a task given out and not yet done can still be deleted by its creator', Collab::deleteTask(9991, $tdOpen));
$tdMine = Collab::addTask(9992, 'Tidy my desk'); Collab::toggleTask(9992, $tdMine);
ck('delete: and somebody may still delete their own finished task', Collab::deleteTask(9992, $tdMine));
ck('door: task_delete gives the refusal rather than "Task not found."', str_contains((string) file_get_contents(AV_ROOT . '/portal/collab.php'), 'Collab::deleteRefused('));
