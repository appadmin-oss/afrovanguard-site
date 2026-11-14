<?php
/**
 * tests/taskadd.test.php — creating a task FOR somebody is handing out work.
 *
 * `task_add` (portal/collab.php) and chat's `assign` took any assignee id from
 * any signed-in learner, and Collab::addTask() never asked whether that id was
 * a person at all: every call put a notification and an email with the
 * caller's own text in front of whoever they named. Giving an existing task
 * away (assignTask) already required the right to hand out work and an active
 * assignee; creating one for somebody now requires the same.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tap = Database::pdo();
Collab::myTasks(0, 1); Notifications::ensure();
$tap->exec('DELETE FROM lms_users WHERE id BETWEEN 9981 AND 9984');
$tap->prepare("INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES
    (9981, 'Lee Learner', 'lee.ta@mail.test', 'x', 'learner', 'active'),
    (9982, 'Mo Target', 'mo.ta@mail.test', 'x', 'learner', 'active'),
    (9983, 'Gone Person', 'gone.ta@mail.test', 'x', 'learner', 'suspended')")->execute();
$taNotes = static fn(int $uid): int => (int) $tap->query('SELECT COUNT(*) FROM user_notifications WHERE user_id = ' . $uid)->fetchColumn();

$n0 = $taNotes(9982);
ck('add: somebody who may not hand out work cannot create a task for someone else', Collab::addTask(9981, 'Send me your bank details', 9982) === 0);
ck('add: …and the person named hears nothing', $taNotes(9982) === $n0);
ck('add: their own task is still theirs to create', Collab::addTask(9981, 'Read chapter two') > 0);
ck('add: naming themselves is the same as naming nobody', Collab::addTask(9981, 'Read chapter three', 9981) > 0);
ck('add: whoever hands out work still cannot give one to a suspended account', Collab::addTask(9981, 'Ring the venue', 9983, '', 'normal', 0, '', 'person', true) === 0);
ck('add: …nor to an id that is nobody', Collab::addTask(9981, 'Ring the venue', 9984, '', 'normal', 0, '', 'person', true) === 0);
$given = Collab::addTask(9981, 'Ring the venue', 9982, '', 'normal', 0, '', 'person', true);
ck('add: and does give one to an active member, who is told', $given > 0 && $taNotes(9982) === $n0 + 1);

/* Both doors pass the caller's right to hand out work, the one assignTask takes. */
$taCollab = (string) file_get_contents(AV_ROOT . '/portal/collab.php');
$taChat   = (string) file_get_contents(AV_ROOT . '/portal/chat.php');
ck('door: task_add passes $allocates to addTask', (bool) preg_match('/case \'task_add\':.*?Collab::addTask\([^;]*\$allocates\)/s', $taCollab));
ck('door: chat\'s assign passes it too, from the same rule', (bool) preg_match('/case \'assign\':.*?Collab::addTask\([^;]*\$allocates\)/s', $taChat)
   && str_contains($taChat, 'Collab::mayAllocate($u)') && str_contains($taCollab, 'Collab::mayAllocate($u)'));
