<?php
/**
 * tests/tasktz.test.php — a task's day is Lagos's day, everywhere.
 *
 * TaskReport and the reminders count days in Africa/Lagos; three readers
 * used UTC: a task's "overdue" flag (Collab::shapeTask), a person's overdue
 * load in the matcher (TaskMatch::profiles) and whether finished work was on
 * time (TaskLedger::history). Lagos is an hour ahead, so between 23:00 and
 * midnight UTC — the first hour of a Lagos day — a task due yesterday was not
 * yet overdue on the portal while the daily report already called it so, and
 * work finished just after midnight in Lagos on the day after it was due
 * counted as on time. Pinned to that hour, through TaskReport's clock.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$tz = Database::pdo();
Collab::myTasks(0, 1); TaskMatch::ensure(); TaskLedger::ensure();
$tz->exec('DELETE FROM lms_users WHERE id IN (9961, 9962)');
$tz->prepare("INSERT INTO lms_users (id, name, email, password_hash, role, status, last_login) VALUES
    (9961, 'Tobi Tz', 'tobi.tz@afrovanguard.org.ng', 'x', 'coordinator', 'active', ''),
    (9962, 'Uche Tz', 'uche.tz@mail.test', 'x', 'member', 'active', '')")->execute();

/* 23:30 UTC today is 00:30 tomorrow in Lagos. Today, not a fixed date: a
   reader still on the system clock in UTC must see the same day as the pin. */
$tzDay = gmdate('Y-m-d');
$tzNow = (int) strtotime($tzDay . ' 23:30:00 UTC');
TaskReport::$nowForTests = $tzNow;
ck('tz: the pinned hour is tomorrow in Lagos and today in UTC', TaskReport::today() === gmdate('Y-m-d', $tzNow + 86400) && gmdate('Y-m-d', $tzNow) === $tzDay);

$tzLate = Collab::addTask(9961, 'Collect the forms', 9962, $tzDay, 'normal', 0, '', 'person', true);
ck('tz: a task due today (UTC) is overdue in the first hour of tomorrow in Lagos', (Collab::oneTask(9962, $tzLate)['overdue'] ?? false) === true);
TaskMatch::forget();
ck('tz: and the matcher counts it against them as overdue', (int) (TaskMatch::profiles(true)[9962]['overdue'] ?? -1) === 1);

/* Finished at 23:30 UTC on its due day = 00:30 the next day in Lagos: late. */
$tz->prepare("UPDATE collab_tasks SET done = 1, state = 'done', done_at = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', $tzNow), $tzLate]);
$tzH = TaskLedger::history([9962], $tzNow)[9962];
ck('tz: finished after midnight in Lagos on the day after it was due is late, not on time', $tzH['late'] === 1 && $tzH['on_time'] === 0);

TaskReport::$nowForTests = null;
TaskMatch::forget();
