<?php
/**
 * tests/academynudges.test.php — the Academy in the portal's bell and to-do
 * list (lib/AcademyNudges.php).
 *
 * What it holds:
 *   • a submission rings the course's instructor, not the learner;
 *   • a pass, a fail and a return each ring the learner with the right words,
 *     and only the two that need action also mail;
 *   • a reply rings everyone in the thread except the replier, once a day;
 *   • a finished course rings once, however often completion is re-checked;
 *   • the daily pace sweep rings a learner who is behind, once a week, and
 *     runs once a day however often the cron ticks;
 *   • the to-do list carries a Learning section: redo first, then the next item.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
$db = Database::pdo();
LmsDrip::ensure();
Notifications::ensure();
foreach (['lms_drip_sent', 'course_enrolment', 'lesson_progress', 'assignment_submissions', 'forum_posts', 'forum_threads', 'certificates', 'user_notifications'] as $t) {
    try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
}
$sent = [];
$cap  = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
$mailWas = getenv('AV_MAIL_DISABLED');
putenv('AV_MAIL_DISABLED=0'); unset($_ENV['AV_MAIL_DISABLED'], $_SERVER['AV_MAIL_DISABLED']);

$bells = static function (int $uid) use ($db): array {
    $s = $db->prepare("SELECT title, body, url, dedupe_key FROM user_notifications WHERE user_id = ? AND kind = 'academy' ORDER BY id");
    $s->execute([$uid]);
    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$db->exec("INSERT INTO lms_users (id,name,email,password_hash,status,role) VALUES
    (811,'Ada Obi','ada811@x.co','x','active','learner'), (812,'Chidi Eze','chidi812@x.co','x','active','learner'),
    (813,'Ngozi Teach','ngozi813@x.co','x','active','instructor')");
$db->exec("INSERT INTO courses (id,slug,title,access_type,status,instructor_id) VALUES (821,'leading-well','Leading Well','open','published',813)");
$db->exec("INSERT INTO modules (id,course_id,title,position) VALUES (831,821,'Module one',1),(832,821,'Module two',2)");
$db->exec("INSERT INTO lessons (id,module_id,course_id,slug,title,position,kind,assignment_json) VALUES
    (841,831,821,'reflect','Write your reflection',1,'assignment','{\"pass\":70}'),
    (842,832,821,'plan','Your 90-day plan',2,'','')");
/* Enrolled sixty days ago on a two-module course: far past the suggested dates. */
$long = gmdate('Y-m-d H:i:s', time() - 60 * 86400);
$db->prepare('INSERT INTO course_enrolment (user_id,course_id,created_at) VALUES (811,821,?),(812,821,?)')->execute([$long, $long]);

$cw = new LmsCourseware();
$lesson = $db->query('SELECT * FROM lessons WHERE id = 841')->fetch(PDO::FETCH_ASSOC);

/* ══ A SUBMISSION ══ */
$r = $cw->submit(811, $lesson, 'My reflection on leading well.', '');
$tb = $bells(813);
ck('nudge: a submission rings the instructor', $r['ok'] && count($tb) === 1 && str_starts_with($tb[0]['title'], 'To mark: Write your reflection') && str_contains($tb[0]['body'], 'Ada Obi') && $tb[0]['url'] === '/academy/teach/grading');
ck('nudge: …and not the learner who handed it in', $bells(811) === []);

/* ══ GRADED: fail, return, pass ══ */
$sub = $cw->submission(811, 841);
$sent = [];
$cw->grade((int) $sub['id'], 40, 'Say what you would do differently.', 813);
$b = $bells(811);
ck('nudge: a fail rings "Try again" with the score and the feedback', count($b) === 1 && $b[0]['title'] === 'Try again: Write your reflection' && str_contains($b[0]['body'], '40/100') && str_contains($b[0]['body'], 'differently'));
ck('nudge: …and links to the item itself', $b[0]['url'] === '/academy/leading-well/learn/reflect');
ck('nudge: …and mails, because it needs action', count($sent) === 1);

$cw->submit(811, $lesson, 'Second go.', '');
$sub = $cw->submission(811, 841);
$sent = [];
$cw->grade((int) $sub['id'], null, 'Add one concrete example.', 813, true);
$b = $bells(811);
ck('nudge: a return rings "Changes asked" with what to change', count($b) === 2 && $b[1]['title'] === 'Changes asked: Write your reflection' && str_contains($b[1]['body'], 'concrete example') && count($sent) === 1);

$todo = AcademyNudges::todo(811);
ck('to-do: work handed back comes first, as a redo', ($todo[0]['id'] ?? '') === 'acredo-' . (int) $sub['id'] && $todo[0]['urgent'] === true && $todo[0]['href'] === '/academy/leading-well/learn/reflect');
ck('to-do: the item being redone is not listed a second time as "next"', !in_array('aclearn-821', array_column($todo, 'id'), true));
$it = AcademyNudges::todo(813);
ck('to-do: an instructor with nothing waiting has no "to mark" line', !in_array('acmark', array_column($it, 'id'), true));

$cw->submit(811, $lesson, 'Third, with an example.', '');
$it = AcademyNudges::todo(813);
ck('to-do: …and gets one when a submission is waiting', in_array('acmark', array_column($it, 'id'), true));
$sub = $cw->submission(811, 841);
$sent = [];
$cw->grade((int) $sub['id'], 85, 'Strong.', 813);
$b = $bells(811);
ck('nudge: a pass rings "Passed" with the score', end($b)['title'] === 'Passed: Write your reflection' && str_contains(end($b)['body'], '85/100'));
ck('nudge: …and does not mail (good news is the bell; the certificate has its own mail)', $sent === []);
ck('to-do: a passed piece of work is no longer a redo', !array_filter(AcademyNudges::todo(811), static fn($x) => str_starts_with($x['id'], 'acredo')));

/* ══ REPLIES ══ */
$db->exec("INSERT INTO forum_threads (id,course_id,user_id,title,body) VALUES (851,821,811,'How do you plan a week?','')");
$cw->reply(812, 851, 'I block mornings.');
ck('nudge: a reply rings the thread starter', count(array_filter($bells(811), static fn($x) => str_starts_with($x['title'], 'New reply'))) === 1);
ck('nudge: …not the person replying', !array_filter($bells(812), static fn($x) => str_starts_with($x['title'], 'New reply')));
$cw->reply(813, 851, 'Good question.');
$cw->reply(812, 851, 'Also evenings.');
$ada = array_filter($bells(811), static fn($x) => str_starts_with($x['title'], 'New reply'));
$chidi = array_filter($bells(812), static fn($x) => str_starts_with($x['title'], 'New reply'));
ck('nudge: a busy thread is one bell per person per day', count($ada) === 1 && count($chidi) === 1);
ck('nudge: …and links to the thread', array_values($ada)[0]['url'] === '/academy/leading-well/discussions/851');

/* ══ PACE ══ */
$db->exec("DELETE FROM user_notifications WHERE dedupe_key LIKE 'acbehind:%'");
$sent = [];
$m = AcademyNudges::sweepPace(true);
$be = array_values(array_filter($bells(812), static fn($x) => str_starts_with($x['title'], 'Behind on')));
ck('pace: a learner past the suggested date is told, with the next item and a link', $m['behind'] >= 1 && count($be) === 1 && str_contains($be[0]['body'], 'Write your reflection') && $be[0]['url'] === '/academy/leading-well/learn/reflect');
ck('pace: …and mailed', count(array_filter($sent, static fn($x) => str_contains(json_encode($x), 'Behind on'))) >= 1);
$again = AcademyNudges::sweepPace(true);
ck('pace: a second sweep the same week rings nobody again', $again['behind'] === 0);
ck('pace: the unforced sweep runs once a day however often the cron ticks', !empty(AcademyNudges::sweepPace()['skipped']));
$t = AcademyNudges::todo(812);
ck('to-do: the next item, marked behind', ($t[0]['id'] ?? '') === 'aclearn-821' && $t[0]['urgent'] === true && str_contains($t[0]['meta'], 'behind'));

/* ══ CERTIFICATE ══ */
$repo = new LmsRepository();
$repo->markComplete(811, $db->query('SELECT * FROM lessons WHERE id = 842')->fetch(PDO::FETCH_ASSOC));
$d1 = $repo->completeIfDone(811, 821);
$repo->completeIfDone(811, 821);
$c = array_filter($bells(811), static fn($x) => str_starts_with($x['title'], 'Certificate earned'));
ck('cert: finishing the course rings once, with the certificate link', $d1['complete'] && count($c) === 1 && array_values($c)[0]['url'] === '/academy/leading-well/certificate');

/* ══ THE TO-DO POP-UP ══ */
$sec = Todo::sections(['learning' => AcademyNudges::todo(812), 'assigned' => [], 'due' => [], 'open' => [], 'cac' => [], 'week' => [], 'suggested' => [],
    'member' => [], 'volunteer' => [], 'goals' => [], 'checkins' => [], 'today' => gmdate('Y-m-d'), 'role' => []]);
$ls = array_values(array_filter($sec, static fn($s) => $s['key'] === 'learning'))[0] ?? null;
ck('pop-up: a "Learning" section, with the Academy icon and a Behind badge', $ls && $ls['title'] === 'Learning' && $ls['items'][0]['kind'] === 'learn' && ($ls['items'][0]['badge']['text'] ?? '') === 'Behind');

Mailer::fakeRoads(null);
/* Put outbound mail back as the runner had it: later suites rely on it being off. */
if ($mailWas === false) putenv('AV_MAIL_DISABLED'); else { putenv('AV_MAIL_DISABLED=' . $mailWas); $_ENV['AV_MAIL_DISABLED'] = $mailWas; }
