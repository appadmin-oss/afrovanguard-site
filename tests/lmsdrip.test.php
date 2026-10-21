<?php
/**
 * tests/lmsdrip.test.php — the Academy emails a clock sends.
 *
 * A sweep that re-reads the same rows every tick mails the same person every
 * tick, and the cron runs every few minutes. So the thing worth pinning is
 * not that these send — it is that they send ONCE:
 *
 *   • a lesson unlocking tells each enrolled learner exactly once;
 *   • a stalled course nudges weekly, not on every tick;
 *   • a live class reminds each booked person once, inside the 24-hour
 *     window and not before it;
 *   • a scheduled lesson is actually SHUT until its date, or available_at
 *     is a label on an unlocked door and the email arrives after the fact.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();
LmsDrip::ensure();
foreach (['lms_drip_sent', 'lms_live_classes', 'lms_live_bookings', 'course_enrolment', 'lesson_progress'] as $t) {
    try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
}

$sent = [];
$cap  = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
putenv('AV_MAIL_DISABLED=0'); unset($_ENV['AV_MAIL_DISABLED'], $_SERVER['AV_MAIL_DISABLED']);
$reset = static function () use (&$sent): void { $sent = []; };

$db->exec("INSERT INTO lms_users (id,name,email,password_hash,status) VALUES
    (501,'Ada Obi','ada501@x.co','x','active'), (502,'Chidi Eze','chidi502@x.co','x','active')");
$db->exec("INSERT INTO courses (id,slug,title,access_type,status) VALUES (601,'foundations','Foundations','open','published')");
$db->exec("INSERT INTO modules (id,course_id,title,position) VALUES (651,601,'Module one',1)");
$db->exec("INSERT INTO lessons (id,module_id,course_id,slug,title,position,duration_min,available_at) VALUES
    (701,651,601,'one','Character before competency',1,18,''),
    (702,651,601,'two','Owning the outcome',2,15,''),
    (703,651,601,'three','The long view',3,20,'')");
$db->exec("INSERT INTO course_enrolment (user_id,course_id,created_at) VALUES (501,601,'2026-01-01 00:00:00'),(502,601,'2026-01-01 00:00:00')");

/* ══ A LESSON UNLOCKING ═══════════════════════════════════════════════════ */

$shut = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
$db->prepare('UPDATE lessons SET available_at = ? WHERE id = 703')->execute([$shut]);
$repo = new LmsRepository();
$course = ['id' => 601, 'access_type' => 'open'];
$l703 = $db->query('SELECT * FROM lessons WHERE id = 703')->fetch(PDO::FETCH_ASSOC);
$l701 = $db->query('SELECT * FROM lessons WHERE id = 701')->fetch(PDO::FETCH_ASSOC);

ck('drip: a lesson with no release date is open, as every lesson was before '
 . 'this existed', LmsDrip::lessonOpen($l701) && $repo->canAccess(null, $course, $l701));
ck('drip: a lesson dated in the future is SHUT, not merely unannounced — '
 . 'otherwise available_at is a label on an unlocked door',
    !LmsDrip::lessonOpen($l703) && !$repo->canAccess(['id' => 501, 'role' => 'learner'], $course, $l703));

$reset();
ck('drip: and it mails nobody while it is still shut', LmsDrip::sweepLessons() === 0 && $sent === []);

/* Open it: just unlocked, within the catch-up window. */
$db->prepare('UPDATE lessons SET available_at = ? WHERE id = 703')
   ->execute([gmdate('Y-m-d H:i:s', time() - 300)]);
$l703 = $db->query('SELECT * FROM lessons WHERE id = 703')->fetch(PDO::FETCH_ASSOC);
ck('drip: once its moment passes it is open', $repo->canAccess(['id' => 501, 'role' => 'learner'], $course, $l703));

$reset();
$n = LmsDrip::sweepLessons();
ck('drip: the unlock tells both enrolled learners', $n === 2 && count($sent) === 2);
$reset();
ck('drip: and the next tick tells nobody again — the cron runs every few '
 . 'minutes, so this is the whole feature', LmsDrip::sweepLessons() === 0 && $sent === []);

/* ══ A COURSE LEFT SITTING ════════════════════════════════════════════════ */

$reset();
ck('drip: a learner who has not started is not nudged — there is nothing to '
 . 'continue', LmsDrip::sweepContinue() === 0);

$stale = gmdate('Y-m-d H:i:s', time() - 20 * 86400);
$db->prepare('INSERT INTO lesson_progress (user_id,lesson_id,course_id,completed_at) VALUES (?,?,?,?)')
   ->execute([501, 701, 601, $stale]);
$reset();
$n = LmsDrip::sweepContinue();
ck('drip: a course touched three weeks ago nudges once', $n === 1 && count($sent) === 1);
$reset();
ck('drip: …and not again on the next tick', LmsDrip::sweepContinue() === 0);

/* Finishing it stops the nudging. */
$db->exec('DELETE FROM lms_drip_sent');
$db->prepare('INSERT INTO lesson_progress (user_id,lesson_id,course_id,completed_at) VALUES (?,?,?,?),(?,?,?,?)')
   ->execute([501, 702, 601, $stale, 501, 703, 601, $stale]);
$reset();
ck('drip: a finished course is never nudged, however long ago it was finished',
    LmsDrip::sweepContinue() === 0);

/* ══ A LIVE CLASS TOMORROW ════════════════════════════════════════════════ */

$db->prepare('INSERT INTO lms_live_classes (id,course_id,title,host_name,starts_at,join_url,created_at) VALUES (?,?,?,?,?,?,?)')
   ->execute([801, 601, 'Live clinic: difficult conversations', 'Adaeze Okafor',
              gmdate('Y-m-d H:i:s', time() + 10 * 3600), 'https://meet.google.com/abc-defg-hij', gmdate('Y-m-d H:i:s')]);
$db->prepare('INSERT INTO lms_live_classes (id,course_id,title,host_name,starts_at,join_url,created_at) VALUES (?,?,?,?,?,?,?)')
   ->execute([802, 601, 'Next month', 'Adaeze Okafor',
              gmdate('Y-m-d H:i:s', time() + 30 * 86400), 'https://meet.google.com/zzz', gmdate('Y-m-d H:i:s')]);
$db->exec("INSERT INTO lms_live_bookings (class_id,user_id,created_at) VALUES (801,501,''),(802,501,'')");

$reset();
$n = LmsDrip::sweepLiveClasses();
ck('drip: the class ten hours away reminds its booked learner', $n === 1 && count($sent) === 1);
ck('drip: the one next month does not — a reminder a month early is not a '
 . 'reminder', count($sent) === 1);
$reset();
ck('drip: and the reminder does not repeat', LmsDrip::sweepLiveClasses() === 0);

/* ══ THE TICK ═════════════════════════════════════════════════════════════ */

$t = LmsDrip::cronTick();
ck('drip: one tick reports all three sweeps',
    array_keys($t) === ['lessons', 'continue', 'live_classes']);

Mailer::fakeRoads(null);
