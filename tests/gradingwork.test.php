<?php
/**
 * tests/gradingwork.test.php — submitting, marking with several instructors,
 * moderation, release, late penalties and the latest-attempt rule
 * (lib/GradingWork.php), plus the notices and the daily digest.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';

$db = Database::pdo();
Grading::reset(); Grading::ensure();
$T0 = strtotime('2026-11-10 09:00:00 UTC');
Grading::setClock($T0);
$local = static fn(int $ts): string => Grading::toLocal(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d\TH:i');

$sent = [];
$cap = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
putenv('AV_MAIL_DISABLED=0');
$to = static fn(array $m): string => strtolower((string) ($m['to'] ?? ''));
/* Release mails only (a passing release also issues the certificate, which has its own email). */
$gradeMails = static function () use (&$sent): array {
    return array_values(array_filter($sent, static fn($m) => str_contains((string) $m['subject'], 'grade')));
};

$course = gt_course('Marking Course ' . getmypid());
$lead = gt_user('Lami Lead'); $ins = gt_user('Ifeoma Instructor'); $asst = gt_user('Ayo Assistant'); $out = gt_user('Oke Outsider');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $asst, 'assistant', $lead);

$essay = (int) Grading::saveItem($course, ['kind' => 'assignment', 'title' => 'Essay', 'weight' => 100, 'pass_score' => 50,
    'rubric' => gt_rubric(), 'due_at' => $local($T0 + 7 * 86400), 'late_hours' => 48, 'late_penalty' => 10,
    'max_attempts' => 2, 'moderation_pct' => 15], $lead)['id'];

$ada = gt_user('Ada Learner'); gt_enrol($ada, $course);
$ben = gt_user('Ben Learner'); gt_enrol($ben, $course);
$stranger = gt_user('Sam NotEnrolled');

/* ══ Submitting ═════════════════════════════════════════════════════════ */

ck('work: a draft item takes no submissions', !GradingWork::submit($essay, $ada, ['text' => 'mine'])['ok']);
Grading::publish($course, $lead);
ck('work: somebody not on the course cannot submit', !GradingWork::submit($essay, $stranger, ['text' => 'x'])['ok']);
ck('work: the team cannot submit to its own course', !GradingWork::submit($essay, $asst, ['text' => 'x'])['ok']);
ck('work: an empty submission is refused', !GradingWork::submit($essay, $ada, ['text' => '  '])['ok']);
ck('work: a link must be a web address', !GradingWork::submit($essay, $ada, ['link' => 'javascript:alert(1)'])['ok']);
$s1 = GradingWork::submit($essay, $ada, ['text' => 'My first essay', 'link' => 'https://example.org/essay']);
ck('work: a learner submits text and a link', $s1['ok'] && $s1['attempt'] === 1 && !$s1['late']);
$sub1 = (int) $s1['id'];

/* Files: allowlisted by what their bytes ARE, size-capped, kept privately. */
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$bad = GradingWork::checkFile('essay.pdf', "<?php echo 'not a pdf';");
ck('work: a file whose bytes are not what its name says is refused', !$bad['ok']);
ck('work: an executable type is refused outright', !GradingWork::checkFile('run.php', $pdf)['ok']);
ck('work: an oversized file is refused', !GradingWork::checkFile('big.pdf', str_repeat('A', GradingWork::MAX_FILE_BYTES + 1))['ok']);
$sb = GradingWork::submit($essay, $ben, ['text' => 'Ben essay'], ['name' => 'ben essay.pdf', 'bytes' => $pdf]);
ck('work: a PDF is accepted', $sb['ok']);
$subB = GradingWork::submission((int) $sb['id']);
$path = GradingWork::filePath($subB);
ck('work: the file is stored outside the web root', $path !== null && !str_starts_with($path, AV_ROOT . '/academy') && !str_starts_with($path, AV_ROOT . '/assets') && !str_starts_with($path, AV_ROOT . '/uploads'));
ck('work: the stored name is not the learner\'s file name', !str_contains((string) $path, 'ben essay'));
ck('work: the learner may read their own file', GradingWork::canSee($subB, $ben));
ck('work: an assistant on the team may read it', GradingWork::canSee($subB, $asst));
ck('work: another learner may not', !GradingWork::canSee($subB, $ada));
ck('work: an outsider may not', !GradingWork::canSee($subB, $out));

/* ══ Marking: one mark stands; nobody outside the team marks ════════════ */

ck('work: an outsider cannot mark', !GradingWork::mark($sub1, $out, [5, 5], 'x')['ok']);
ck('work: a mark must score every criterion', !GradingWork::mark($sub1, $asst, [5], 'x')['ok']);
$m = GradingWork::mark($sub1, $asst, [8, 8], 'Clear argument, good sources.');
ck('work: an assistant can mark', $m['ok']);
ck('work: a single mark stands as the final (16/20 = 80%)', abs((float) GradingWork::submission($sub1)['final_pct'] - 80.0) < 0.01 && !$m['flagged']);
ck('work: marking again edits your own mark — one mark per marker',
   GradingWork::mark($sub1, $asst, [9, 8], 'Clear argument, good sources.')['ok']
   && (int) $db->query('SELECT COUNT(*) FROM grade_marks WHERE submission_id = ' . $sub1)->fetchColumn() === 1);
ck('work: who marked what and when is recorded', (int) $db->query('SELECT marker_id FROM grade_marks WHERE submission_id = ' . $sub1)->fetchColumn() === $asst
   && (int) $db->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'grading.mark' AND target LIKE '%submission#$sub1'")->fetchColumn() === 2);

/* ══ Release gating: nothing is seen until somebody releases ════════════ */

$item = Grading::item($essay);
$r = Grading::result($item, $ada);
ck('work: a marked but unreleased grade is invisible to the learner', $r['status'] === 'marked' && $r['score'] === null && $r['feedback'] === '');
ck('work: and does not count towards their course grade', Grading::standing($ada, $course)['grade'] === 0.0);
ck('work: the team sees it as pending in the gradebook', abs((float) Grading::result($item, $ada, true)['pending'] - 85.0) < 0.01);
$sent = [];
ck('work: an assistant cannot release grades', !GradingWork::release($essay, $asst)['ok'] && Grading::result($item, $ada)['score'] === null);
$rel = GradingWork::release($essay, $ins);
ck('work: an instructor releases the item', $rel['ok'] && $rel['released'] === 1 && $rel['waiting'] === 1);
$r = Grading::result($item, $ada);
ck('work: once released the learner sees the score and feedback', $r['status'] === 'released' && $r['score'] === 85.0 && str_contains($r['feedback'], 'Clear argument'));
ck('work: the learner is told their grade is out', count($gradeMails()) === 1 && str_contains($to($gradeMails()[0]), 'ada'));
ck('work: releasing again with nothing new tells nobody twice', GradingWork::release($essay, $ins)['released'] === 0 && count($gradeMails()) === 1);

/* A mark changed after release changes nothing the learner sees until the next release. */
GradingWork::mark($sub1, $asst, [6, 6], 'Revised after a second look.');
ck('work: a re-mark after release is not seen until released again', Grading::result($item, $ada)['score'] === 85.0);
GradingWork::release($essay, $lead);
ck('work: the next release shows the new score and feedback', Grading::result($item, $ada)['score'] === 60.0
   && str_contains(Grading::result($item, $ada)['feedback'], 'second look') && count($gradeMails()) === 2
   && str_starts_with((string) $gradeMails()[1]['subject'], 'Updated grade and feedback'));

/* ══ Moderation: two marks further apart than the threshold ═════════════ */

GradingWork::mark((int) $sb['id'], $asst, [10, 10], 'Excellent.');      // 100%
$m2 = GradingWork::mark((int) $sb['id'], $ins, [7, 9], 'Good.');          // 80% — 20 points apart > 15
ck('work: marks 20% apart are flagged for moderation', $m2['flagged'] && GradingWork::submission((int) $sb['id'])['final_pct'] === null);
ck('work: the moderation queue lists it', in_array((int) $sb['id'], array_map(static fn($q) => (int) $q['id'], GradingWork::queue($course, 'moderation')), true));
$rel = GradingWork::release($essay, $lead);
ck('work: a flagged submission is held back from release', $rel['held'] === 1 && Grading::result($item, $ben)['score'] === null);
ck('work: an assistant cannot agree the final', !GradingWork::agree((int) $sb['id'], $asst, 18, 'split the difference')['ok']);
ck('work: agreeing needs a reason', !GradingWork::agree((int) $sb['id'], $ins, 18, '  ')['ok']);
ck('work: an instructor agrees a final with a reason', GradingWork::agree((int) $sb['id'], $ins, 18, 'Second marker under-read the evidence')['ok']);
$sub = GradingWork::submission((int) $sb['id']);
ck('work: the agreed score stands and the flag clears', abs((float) $sub['final_pct'] - 90.0) < 0.01 && !(int) $sub['flagged'] && $sub['final_source'] === 'moderated');
ck('work: the reason is recorded', str_contains((string) $sub['final_reason'], 'under-read')
   && (bool) $db->query("SELECT 1 FROM lms_audit WHERE action = 'grading.agree' AND detail LIKE '%under-read%'")->fetchColumn());

/* Two marks within the threshold are averaged. */
$cal = gt_user('Cal Learner'); gt_enrol($cal, $course);
$sc = (int) GradingWork::submit($essay, $cal, ['text' => 'Cal essay'])['id'];
GradingWork::mark($sc, $asst, [8, 8], '');   // 80
$mm = GradingWork::mark($sc, $ins, [9, 8], '');  // 85 — 5 apart
ck('work: marks within the threshold are averaged, not flagged', !$mm['flagged'] && abs((float) GradingWork::submission($sc)['final_pct'] - 82.5) < 0.01);
/* Exactly at the threshold is not "more than" it. */
$dee = gt_user('Dee Learner'); gt_enrol($dee, $course);
$sd = (int) GradingWork::submit($essay, $dee, ['text' => 'Dee essay'])['id'];
GradingWork::mark($sd, $asst, [10, 7], '');   // 85
$md = GradingWork::mark($sd, $ins, [7, 7], ''); // 70 — exactly 15 apart
ck('work: marks exactly at the threshold are not flagged', !$md['flagged']);
/* The threshold is per item. */
Grading::saveItem($course, ['id' => $essay, 'title' => 'Essay', 'moderation_pct' => 10], $lead);
ck('work: a tighter threshold on the item flags the same pair', (bool) GradingWork::submission($sd)['flagged']);
Grading::saveItem($course, ['id' => $essay, 'title' => 'Essay', 'moderation_pct' => 15], $lead);

/* ══ Late submissions and the late penalty ══════════════════════════════ */

Grading::setClock($T0 + 8 * 86400);   // a day after the due date, inside the 48h late window
$eve = gt_user('Eve Learner'); gt_enrol($eve, $course);
$se = GradingWork::submit($essay, $eve, ['text' => 'Eve essay, late']);
ck('work: a submission after the due date is accepted and marked late', $se['ok'] && $se['late']);
GradingWork::mark((int) $se['id'], $ins, [9, 9], 'Strong.');    // 90
GradingWork::release($essay, $ins);
$re = Grading::result($item, $eve);
ck('work: the late penalty comes off the final (90 → 81)', $re['score'] === 81.0 && $re['raw'] === 90.0 && $re['penalty'] === 10 && $re['late']);
Grading::setClock($T0 + 10 * 86400);  // past due + 48h
$fay = gt_user('Fay Learner'); gt_enrol($fay, $course);
$sf = GradingWork::submit($essay, $fay, ['text' => 'too late']);
ck('work: after the late window, submissions are refused', !$sf['ok'] && ($sf['code'] ?? '') === 'closed');
ck('work: and an item never handed in is missed, scoring 0', Grading::result($item, $fay)['status'] === 'missed' && Grading::result($item, $fay)['score'] === 0.0);
Grading::setClock($T0 + 86400);

/* ══ Resubmission: the latest marked attempt counts ═════════════════════ */

$s2 = GradingWork::submit($essay, $ada, ['text' => 'My second essay']);
ck('work: a second attempt is allowed within max attempts', $s2['ok'] && $s2['attempt'] === 2);
ck('work: a third is refused', ($x = GradingWork::submit($essay, $ada, ['text' => 'third']))['ok'] === false && ($x['code'] ?? '') === 'attempts');
$r = Grading::result($item, $ada);
ck('work: until attempt 2 is released, attempt 1 still counts', $r['score'] === 60.0 && $r['counts_attempt'] === 1 && $r['latest_newer'] && $r['status'] === 'submitted');
GradingWork::mark((int) $s2['id'], $ins, [4, 4], 'Weaker than the first.');   // 40
GradingWork::release($essay, $ins);
$r = Grading::result($item, $ada);
ck('work: the latest released attempt counts, even when it scores lower than an earlier one', $r['score'] === 40.0 && $r['counts_attempt'] === 2);
ck('work: the queue lists only each learner\'s latest attempt',
   !in_array($sub1, array_map(static fn($q) => (int) $q['id'], GradingWork::queue($course, 'all')), true));

/* ══ The marking queue ══════════════════════════════════════════════════ */

$gus = gt_user('Gus Learner'); gt_enrol($gus, $course);
Grading::setClock($T0 + 2 * 86400);
$sg = (int) GradingWork::submit($essay, $gus, ['text' => 'Gus essay'])['id'];
$q = GradingWork::queue($course, 'unmarked');
ck('work: the unmarked queue holds work with no final score', in_array($sg, array_map(static fn($x) => (int) $x['id'], $q), true));
ck('work: and not work already marked', !in_array($sc, array_map(static fn($x) => (int) $x['id'], $q), true));
$all = GradingWork::queue($course, 'all');
$ts = array_map(static fn($x) => (string) $x['submitted_at'], $all);
$sorted = $ts; sort($sorted);
ck('work: the queue is oldest first', $ts === $sorted);
ck('work: the queue can be filtered by item', count(GradingWork::queue($course, 'all', 999999)) === 0 && count(GradingWork::queue($course, 'all', $essay)) === count($all));

/* ══ The daily digest: once per course per day ══════════════════════════ */

$sent = [];
Grading::setClock($T0 + 2 * 86400 + 3600);
ck('work: no digest for work younger than the threshold', GradingWork::digest() === 0 && $sent === []);
Grading::setClock($T0 + 6 * 86400);
$n = GradingWork::digest();
ck('work: the digest goes to every member of the team', $n === 1 && count($sent) === 3);
ck('work: running the cron again the same day sends nothing', GradingWork::cronTick()['digests'] === 0 && count($sent) === 3);
Grading::setClock($T0 + 7 * 86400);
ck('work: the next day it goes again while work still waits', GradingWork::digest() === 1 && count($sent) === 6);

Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED=1');
Grading::setClock(null);
if ($path) @unlink($path);   // the fixture's stored file, so the suite leaves nothing in the private store
