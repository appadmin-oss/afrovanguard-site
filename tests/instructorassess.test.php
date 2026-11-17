<?php
/**
 * tests/instructorassess.test.php — instructors being assessed: learners'
 * written feedback on a course (lib/LearnerFeedback.php), teaching
 * observations (lib/Observations.php) and the scorecard (lib/Scorecard.php).
 *
 * Every rule is exercised by running the code: lessons are completed through
 * LmsRepository, work is submitted, marked, moderated and released through
 * GradingWork with the clock pinned, feedback is saved through save(), and
 * the database's own UNIQUE key is tried directly.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';

$db = Database::pdo();
Grading::reset(); Grading::ensure();
LearnerFeedback::reset(); LearnerFeedback::ensure();
Observations::reset(); Observations::ensure();
$T0 = strtotime('2026-11-10 09:00:00 UTC');
$D = 86400;
Grading::setClock($T0);
$lms = new LmsRepository();

$sent = [];
$cap = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
putenv('AV_MAIL_DISABLED=0');

/* ── A course with four lessons, a team, and a second course ── */
$course = gt_course('Assessed Course ' . getmypid());
$other = gt_course('Other Course ' . getmypid());
$lead = gt_user('Lola Lead'); $ins = gt_user('Ike Instructor'); $asst = gt_user('Ada Assistant'); $quiet = gt_user('Quinn Quiet');
$coord = gt_user('Cora Coordinator', 'coordinator'); $admin = gt_user('Ade Admin', 'admin');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $asst, 'assistant', $lead);
CourseTeam::add($course, $quiet, 'instructor', $lead);
// On the other course, Ike is a plain instructor and Lola is NOT the lead.
$otherLead = gt_user('Obi OtherLead');
CourseTeam::add($other, $otherLead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($other, $ins, 'instructor', $otherLead);
CourseTeam::add($other, $lead, 'assistant', $otherLead);

$mid = $lms->addModule($course, 'Module');
$lessons = [];
for ($i = 1; $i <= 4; $i++) {
    $lid = $lms->saveLesson(['module_id' => $mid, 'slug' => 'ia-l' . $i . '-' . getmypid(), 'title' => 'Lesson ' . $i, 'body_html' => '<p>x</p>', 'video_url' => '']);
    $lessons[] = $lms->lessonById($lid);
}
$doLessons = static function (int $uid, int $n) use ($lms, $lessons): void { for ($i = 0; $i < $n; $i++) $lms->markComplete($uid, $lessons[$i]); };

/* ══ Rights live in the one table ════════════════════════════════════════ */

foreach (['view_team_scorecard', 'view_all_scorecards', 'observe', 'moderate_feedback'] as $r) {
    ck("assess: right '$r' is in CourseTeam::RIGHTS", isset(CourseTeam::RIGHTS[$r]));
}
ck('assess: only a lead holds view_team_scorecard', CourseTeam::RIGHTS['view_team_scorecard'] === ['lead']);
ck('assess: observing and moderating are staff-only (no team role named)', CourseTeam::RIGHTS['observe'] === [] && CourseTeam::RIGHTS['moderate_feedback'] === []);
ck('assess: a coordinator may observe anywhere', CourseTeam::canAnywhere($coord, 'observe'));
ck('assess: a lead may not observe', !CourseTeam::canAnywhere($lead, 'observe') && !CourseTeam::can($course, $lead, 'observe'));
ck('assess: a lead may see team scorecards somewhere; an instructor nowhere',
   CourseTeam::canAnywhere($lead, 'view_team_scorecard') && !CourseTeam::canAnywhere($ins, 'view_team_scorecard'));

/* ══ Eligibility ═════════════════════════════════════════════════════════ */

$L = [];
foreach (['Amaka Obi', 'Bisi Bello', 'Chuka Eze', 'Dayo Ade', 'Efe Uche', 'Femi Ojo'] as $n) { $u = gt_user($n); gt_enrol($u, $course); $L[] = $u; }
$stranger = gt_user('Sam Stranger');

ck('assess: a learner with no lessons and no released grade may not give feedback', !LearnerFeedback::eligibility($course, $L[0])['ok']);
$doLessons($L[0], 1);
ck('assess: a quarter of the lessons is not enough', LearnerFeedback::eligibility($course, $L[0])['code'] === 'progress');
$doLessons($L[0], 2);
ck('assess: exactly half the lessons qualifies', LearnerFeedback::eligibility($course, $L[0])['ok']);
ck('assess: someone not enrolled may not give feedback', LearnerFeedback::eligibility($course, $stranger)['code'] === 'enrol');
ck('assess: a team member may not give learner feedback on their own course', LearnerFeedback::eligibility($course, $ins)['code'] === 'team');
ck('assess: feedback while ineligible is refused by save()', !LearnerFeedback::save($course, $L[1], ['helped' => 'x'])['ok']
   && LearnerFeedback::mine($course, $L[1]) === null);

/* A released grade qualifies without any lessons. */
$it = Grading::saveItem($course, ['kind' => 'assignment', 'title' => 'Essay', 'weight' => 100, 'pass_score' => 50, 'rubric' => gt_rubric(), 'max_attempts' => 1, 'moderation_pct' => 15], $lead);
$item = (int) $it['id'];
Grading::publish($course, $lead);

/* ── Submissions and marks on a clock, for the turnaround arithmetic ──
   Six learners hand in at T0. Ike marks them 0.5, 1, 2, 3, 4 and 10 days
   later. Ada (assistant) second-marks the first two far apart, flagging both;
   Ike agrees the first at +12d; the second stays flagged. Ike releases at +14d. */
$subs = [];
foreach ($L as $u) { $r = GradingWork::submit($item, $u, ['text' => 'My answer', 'link' => '']); $subs[] = (int) ($r['id'] ?? 0); }
ck('assess: six submissions made', count(array_filter($subs)) === 6);
foreach ([0.5, 1, 2, 3, 4, 10] as $k => $d) {
    Grading::setClock((int) ($T0 + $d * $D));
    GradingWork::mark($subs[$k], $ins, [8, 8], 'Solid work');
}
Grading::setClock($T0 + 11 * $D);
GradingWork::mark($subs[0], $asst, [2, 2], 'Thin');
GradingWork::mark($subs[1], $asst, [2, 2], 'Thin');
ck('assess: the disagreeing marks flag both submissions', (int) GradingWork::submission($subs[0])['flagged'] === 1 && (int) GradingWork::submission($subs[1])['flagged'] === 1);
Grading::setClock($T0 + 12 * $D);
ck('assess: Ike agrees the first', GradingWork::agree($subs[0], $ins, 14, 'Second marker misread the brief')['ok']);
Grading::setClock($T0 + 14 * $D);
$rel = GradingWork::release($item, $ins);
ck('assess: five released, one held for moderation', $rel['ok'] && $rel['released'] === 5 && $rel['held'] === 1);

ck('assess: a released grade qualifies without lessons; held work does not', LearnerFeedback::eligibility($course, $L[1])['ok'] === false
   && LearnerFeedback::eligibility($course, $L[2])['ok'] && LearnerFeedback::eligibility($course, $L[2])['released']);

/* ══ Feedback is written, on the course — no scores, no instructor named ══ */

ck('assess: the prompts are about the course', array_keys(LearnerFeedback::PROMPTS) === ['helped', 'clearer', 'anything_else'] && str_contains(LearnerFeedback::PROMPTS['helped'], 'this course'));
$cols = array_column($db->query('PRAGMA table_info(course_feedback)')->fetchAll(PDO::FETCH_ASSOC), 'name');
ck('assess: no feedback column names, targets or scores an instructor', !array_intersect($cols, ['instructor_id', 'stars', 'overall', 'clarity', 'score'])
   && !(bool) $db->query("SELECT 1 FROM sqlite_master WHERE name IN ('instructor_feedback','instructor_ratings','course_ratings')")->fetchColumn());

/* ══ Saving: one per learner per course, 30-day window ═══════════════════ */

Grading::setClock($T0 + 15 * $D);
ck('assess: an empty feedback is refused', !LearnerFeedback::save($course, $L[2], ['helped' => '  ', 'clearer' => ''])['ok']);
$r = LearnerFeedback::save($course, $L[2], ['helped' => 'The worked examples.', 'clearer' => 'Week 2 went fast.', 'share' => 1]);
ck('assess: an eligible learner gives feedback', $r['ok'] && !$r['edited']);
ck('assess: it waits for approval', LearnerFeedback::mine($course, $L[2])['state'] === 'pending');
$r2 = LearnerFeedback::save($course, $L[2], ['helped' => 'The worked examples, and the forum.', 'clearer' => 'Week 2 went fast.', 'share' => 1]);
ck('assess: giving it again edits the same feedback', $r2['ok'] && $r2['edited'] && $r2['id'] === $r['id']);
$cnt = static fn(string $t, string $w) => (int) $db->query("SELECT COUNT(*) FROM $t WHERE $w")->fetchColumn();
ck('assess: still one feedback for this learner on this course', $cnt('course_feedback', "course_id = $course AND user_id = {$L[2]}") === 1);
$dupRefused = false;
try { $db->prepare('INSERT INTO course_feedback (course_id, user_id, helped) VALUES (?,?,?)')->execute([$course, $L[2], 'again']); } catch (Throwable $e) { $dupRefused = true; }
ck('assess: the database itself refuses a second feedback for the same learner and course', $dupRefused);
Grading::setClock($T0 + 15 * $D + 29 * $D);
ck('assess: editing on day 29 is allowed', LearnerFeedback::save($course, $L[2], ['helped' => 'The worked examples, and the forum.', 'clearer' => 'Week 2 went fast.', 'share' => 1])['ok']);
Grading::setClock($T0 + 15 * $D + 31 * $D);
$late = LearnerFeedback::save($course, $L[2], ['helped' => 'Changed my mind']);
ck('assess: after 30 days the feedback is fixed', !$late['ok'] && $late['code'] === 'locked' && LearnerFeedback::mine($course, $L[2])['helped'] === 'The worked examples, and the forum.');
ck('assess: and mine() says it is no longer editable', LearnerFeedback::mine($course, $L[2])['editable'] === false);

/* ══ Approval, the threshold of three, anonymity ════════════════════════ */

Grading::setClock($T0 + 16 * $D);
$fid = static fn(int $learner): int => (int) $db->query("SELECT id FROM course_feedback WHERE user_id = $learner AND course_id = $course")->fetchColumn();
ck('assess: an instructor cannot approve feedback', !LearnerFeedback::moderate($fid($L[2]), $ins, 'approve')['ok']);
ck('assess: a lead cannot either', !LearnerFeedback::moderate($fid($L[2]), $lead, 'approve')['ok']);
ck('assess: the coordinator approves it', LearnerFeedback::moderate($fid($L[2]), $coord, 'approve')['ok']);
$t = LearnerFeedback::forTeam($course, $ins);
ck('assess: one learner has given feedback — the team sees none of it, even approved', $t['ok'] && !$t['shown'] && $t['givers'] === 1 && $t['items'] === []);
LearnerFeedback::save($course, $L[3], ['clearer' => 'Too fast for me — Dayo from the Tuesday group']);
$t = LearnerFeedback::forTeam($course, $ins);
ck('assess: two learners — still nothing', !$t['shown'] && $t['givers'] === 2);
ck('assess: hiding needs a reason', !LearnerFeedback::moderate($fid($L[3]), $coord, 'hide')['ok']);
ck('assess: hide the identifying one', LearnerFeedback::moderate($fid($L[3]), $admin, 'hide', 'identifying')['ok']);
LearnerFeedback::save($course, $L[4], ['anything_else' => 'More live sessions please']);
$t = LearnerFeedback::forTeam($course, $ins);
ck('assess: three learners have given feedback — the approved piece now reaches the team', $t['shown'] && $t['givers'] === 3 && count($t['items']) === 1
   && $t['items'][0]['helped'] === 'The worked examples, and the forum.');
ck('assess: pending and hidden feedback never reach the team', !str_contains(json_encode($t), 'Too fast') && !str_contains(json_encode($t), 'More live'));
ck('assess: every member of the team reads it — the assistant too', LearnerFeedback::forTeam($course, $asst)['shown'] && count(LearnerFeedback::forTeam($course, $quiet)['items']) === 1);
ck('assess: someone not on the team reads nothing', !LearnerFeedback::forTeam($course, $otherLead)['ok'] && LearnerFeedback::forTeam($course, $otherLead)['items'] === []);
ck('assess: an item carries the words only — no person, no date, no id', array_keys($t['items'][0]) === ['helped', 'clearer', 'anything_else']);
$blob = json_encode($t) . json_encode(LearnerFeedback::counts([$course]));
$leak = false;
foreach ([$L[2], $L[3], $L[4]] as $u) {
    $row = $db->query("SELECT name, email FROM lms_users WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    if (str_contains($blob, $row['name']) || str_contains($blob, $row['email']) || str_contains($blob, 'user_id')) $leak = true;
}
ck('assess: nothing the team reads names a learner or carries their id', !$leak);
$c = LearnerFeedback::counts([$course])[$course];
ck('assess: counts: received 3, approved 1, shared 1', $c['received'] === 3 && $c['approved'] === 1 && $c['shared'] === 1 && $c['threshold_met']);
$queue = LearnerFeedback::pending($coord);
ck('assess: the coordinator\'s queue holds the pending feedback, without the learner', count(array_filter($queue, static fn($q) => str_contains(json_encode($q), 'More live'))) === 1
   && !str_contains(json_encode($queue), 'Efe Uche') && !str_contains(json_encode($queue), 'user_id'));
ck('assess: an instructor has no moderation queue', LearnerFeedback::pending($ins) === [] && LearnerFeedback::pending($lead) === []);
CourseTeam::add($course, $coord, 'assistant', $lead);
$ctitle = (string) $db->query("SELECT title FROM courses WHERE id = $course")->fetchColumn();
ck('assess: a coordinator on the team cannot moderate the course\'s feedback, nor sees it queued', !LearnerFeedback::moderate($fid($L[4]), $coord, 'approve')['ok']
   && !in_array($ctitle, array_column(LearnerFeedback::pending($coord), 'course_title'), true));
CourseTeam::remove($course, $coord, $lead);
Grading::setClock($T0 + 20 * $D);
ck('assess: edit within the window', LearnerFeedback::save($course, $L[4], ['anything_else' => 'More live sessions please!'])['ok']);

/* ══ Testimonials: only with the learner's permission ═══════════════════ */

ck('assess: L[2] allowed quoting and is approved — one testimonial, first name and last initial', ($tm = LearnerFeedback::testimonials($course)) && count($tm) === 1
   && $tm[0]['who'] === 'Chuka E.' && $tm[0]['text'] === 'The worked examples, and the forum.');
ck('assess: publicName handles one name', LearnerFeedback::publicName('Madonna') === 'Madonna' && LearnerFeedback::publicName('  ada   lovelace ') === 'ada L.');
LearnerFeedback::moderate($fid($L[4]), $coord, 'approve');
ck('assess: approved feedback without permission is never public', count(LearnerFeedback::testimonials($course)) === 1);
$page = static fn(): string => render_page(AV_ROOT . '/academy/course.php', ['slug' => (string) $db->query("SELECT slug FROM courses WHERE id = $course")->fetchColumn()]);
$cp = $page();
ck('assess: the course page quotes it as shared with permission', str_contains($cp, 'The worked examples, and the forum.') && str_contains($cp, 'Chuka E.') && str_contains($cp, 'Shared with permission'));
ck('assess: the page shows no stars and nothing else learners wrote', !str_contains($cp, '★') && !str_contains($cp, 'Week 2 went fast') && !str_contains($cp, 'More live') && !str_contains($cp, 'Chuka Eze'));
$db->exec("UPDATE course_feedback SET created_at = '" . Grading::stamp() . "' WHERE user_id = {$L[2]} AND course_id = $course");
ck('assess: unticking permission takes it down at once', LearnerFeedback::save($course, $L[2], ['helped' => 'The worked examples, and the forum.', 'clearer' => 'Week 2 went fast.', 'share' => 0])['ok']
   && LearnerFeedback::testimonials($course) === [] && LearnerFeedback::mine($course, $L[2])['state'] === 'approved');
ck('assess: changed words go back to the queue and off the team\'s page', LearnerFeedback::save($course, $L[2], ['helped' => 'Edited words', 'share' => 1])['ok']
   && LearnerFeedback::mine($course, $L[2])['state'] === 'pending' && LearnerFeedback::testimonials($course) === []
   && !str_contains(json_encode(LearnerFeedback::forTeam($course, $ins)), 'Edited words'));

/* ══ Observations: visibility before final ══════════════════════════════ */

$obsBase = ['course_id' => $course, 'instructor_id' => $ins, 'observed_on' => '2026-11-25', 'target_kind' => 'lesson', 'target_id' => (int) $lessons[0]['id'],
            'scores' => [3, 4, 3, 2, 3], 'strengths' => 'Warm, well paced', 'develop' => 'Check understanding more often', 'action' => 'Use exit tickets', 'follow_up_on' => '2026-12-09'];
ck('assess: a lead cannot record an observation', !Observations::save($obsBase, $lead)['ok']);
ck('assess: nobody observes themselves', !Observations::save(['instructor_id' => $coord] + $obsBase, $coord)['ok']);
ck('assess: the observed person must be on the team', !Observations::save(['instructor_id' => $stranger] + $obsBase, $coord)['ok']);
ck('assess: a lesson from another course is refused', !Observations::save(['course_id' => $other, 'instructor_id' => $ins] + $obsBase, $coord)['ok']);
ck('assess: "other" needs a description', !Observations::save(['target_kind' => 'other', 'target_note' => ''] + $obsBase, $coord)['ok']);
ck('assess: scores are 1 to 4', !Observations::save(['scores' => [5, 4, 3, 2, 3]] + $obsBase, $coord)['ok']);
ck('assess: a follow-up cannot come before the observation', !Observations::save(['follow_up_on' => '2026-11-01'] + $obsBase, $coord)['ok']);
$o = Observations::save($obsBase, $coord);
ck('assess: the coordinator records a draft', $o['ok'] && !$o['final']);
$oid = (int) $o['id'];
ck('assess: the instructor cannot see a draft', !Observations::canSee(Observations::get($oid), $ins) && Observations::listFor($ins) === []);
ck('assess: a draft is not on the scorecard', Observations::finals($ins, [$course]) === []);
ck('assess: the instructor cannot reflect on a draft', !Observations::reflect($oid, $ins, 'Thoughts')['ok']);
ck('assess: finalising needs every criterion scored', !Observations::save(['id' => $oid, 'scores' => [3, 4], 'final' => 1] + $obsBase, $coord)['ok']);
ck('assess: an agreed action needs a follow-up date to be final', !Observations::save(['id' => $oid, 'follow_up_on' => '', 'final' => 1] + $obsBase, $coord)['ok']);
$sentBefore = count($sent);
ck('assess: the admin marks it final', Observations::save(['id' => $oid, 'final' => 1] + $obsBase, $admin)['ok']);
ck('assess: the instructor is told it is shared', count($sent) === $sentBefore + 1);
ck('assess: now the instructor sees it', Observations::canSee(Observations::get($oid), $ins) && count(Observations::listFor($ins)) === 1);
ck('assess: a colleague does not', !Observations::canSee(Observations::get($oid), $quiet) && !Observations::canSee(Observations::get($oid), $lead));
ck('assess: a final observation cannot be edited', !Observations::save(['id' => $oid, 'strengths' => 'changed'] + $obsBase, $coord)['ok']
   && Observations::get($oid)['strengths'] === 'Warm, well paced');
ck('assess: a final observation cannot be deleted', !Observations::deleteDraft($oid, $coord)['ok']);
ck('assess: only the instructor reflects', !Observations::reflect($oid, $coord, 'x')['ok'] && Observations::reflect($oid, $ins, 'I will try exit tickets.')['ok']);
ck('assess: the scores keep their criterion names', array_column(Observations::get($oid)['scores'], 'name') === Observations::DEFAULT_CRITERIA);
// Criteria are one editable list; an old final observation keeps its names.
ck('assess: an instructor cannot edit the criteria', !Observations::setCriteria("A\nB", $ins)['ok']);
ck('assess: staff edit the criteria list', Observations::setCriteria("Preparation\nClarity\nPacing", $coord)['ok'] && Observations::criteria() === ['Preparation', 'Clarity', 'Pacing']);
ck('assess: the final observation still has its own five', count(Observations::get($oid)['scores']) === 5);
$o2 = Observations::save(['observed_on' => '2026-11-27', 'scores' => [2, 2, 4], 'final' => 1, 'action' => '', 'follow_up_on' => ''] + $obsBase, $coord);
ck('assess: a second observation is scored against the new list', $o2['ok'] && array_column(Observations::get((int) $o2['id'])['scores'], 'name') === ['Preparation', 'Clarity', 'Pacing']);
$draft = Observations::save(['observed_on' => '2026-11-28'] + $obsBase, $coord);
ck('assess: a draft may be deleted', Observations::deleteDraft((int) $draft['id'], $coord)['ok'] && Observations::get((int) $draft['id']) === null);
Database::metaSet(Observations::META_KEY, json_encode(Observations::DEFAULT_CRITERIA));

/* ══ Follow-up reminder: the day before, once ═══════════════════════════ */

Grading::setClock(strtotime('2026-12-07 12:00:00 UTC'));
ck('assess: two days before, nothing is sent', Observations::remindFollowUps() === 0);
Grading::setClock(strtotime('2026-12-08 12:00:00 UTC'));
$before = count($sent);
ck('assess: the day before, one observation is reminded', Observations::remindFollowUps() === 1);
ck('assess: and both the observer and the instructor are emailed', count($sent) === $before + 2);
ck('assess: the next tick sends nothing', Observations::remindFollowUps() === 0 && count($sent) === $before + 2);

/* ══ Feedback reminder: once per learner per course ═════════════════════ */

Grading::setClock($T0 + 21 * $D);
$doLessons($L[5], 4);   // Femi: all lessons, has given no feedback
$first = LearnerFeedback::remind();
$claimed = static fn(int $u): int => (int) $db->query("SELECT COUNT(*) FROM feedback_prompts WHERE course_id = $course AND user_id = $u")->fetchColumn();
ck('assess: a qualifying learner with no feedback is reminded', $first >= 1 && $claimed($L[5]) === 1);
ck('assess: one who has given feedback is not', $claimed($L[2]) === 0);
ck('assess: one who does not qualify is not', $claimed($L[1]) === 0);
ck('assess: the next tick reminds nobody again', LearnerFeedback::remind() === 0);
ck('assess: the invitation still shows on the page until they give feedback', LearnerFeedback::shouldInvite($course, $L[5]) && !LearnerFeedback::shouldInvite($course, $L[2]));

/* ══ Scorecard permissions ══════════════════════════════════════════════ */

ck('assess: an instructor sees their own scorecard', Scorecard::canSee($ins, $ins) && Scorecard::build($ins, $ins)['ok']);
ck('assess: an instructor cannot see a colleague\'s', !Scorecard::canSee($ins, $asst) && !Scorecard::build($asst, $ins)['ok']);
ck('assess: an assistant cannot see the lead\'s', !Scorecard::canSee($asst, $lead));
ck('assess: a lead sees a team member on the course they lead', Scorecard::canSee($lead, $ins, $course) && Scorecard::build($ins, $lead, $course)['ok']);
ck('assess: but not on a course they do not lead', !Scorecard::canSee($lead, $ins, $other) && !Scorecard::build($ins, $lead, $other)['ok']);
$lv = Scorecard::build($ins, $lead);
ck('assess: a lead\'s overall view is limited to the courses they lead', $lv['ok'] && array_keys($lv['courses']) === [$course] && $lv['limited']);
ck('assess: the lead of the other course sees the other course only', array_keys(Scorecard::visibleCourses($otherLead, $ins)) === [$other]);
ck('assess: a coordinator sees every course of anyone', count(Scorecard::visibleCourses($coord, $ins)) === 2 && count(Scorecard::visibleCourses($admin, $ins)) === 2);
ck('assess: an outsider sees nothing', Scorecard::visibleCourses($stranger, $ins) === [] && !Scorecard::directory($ins)['ok']);

/* ══ Turnaround arithmetic and the other figures ═════════════════════════ */

Grading::setClock($T0 + 21 * $D);
ck('assess: median of an even set', Scorecard::median([0.5, 1, 2, 3, 4, 10]) === 2.5);
ck('assess: p90 by nearest rank', Scorecard::percentile([0.5, 1, 2, 3, 4, 10], 90) === 10.0 && Scorecard::percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 90) === 9.0);
ck('assess: p50 of an odd set is its middle', Scorecard::percentile([5, 1, 3], 50) === 3.0);
$sc = Scorecard::build($ins, $coord, $course, '12m');
$t = $sc['turnaround'];
ck('assess: six marks counted for turnaround', $t['median']['n'] === 6);
ck('assess: median days to mark is 2.5', abs($t['median']['value'] - 2.5) < 0.001);
ck('assess: the 90th percentile is 10 days', abs($t['p90']['value'] - 10.0) < 0.001);
ck('assess: five of six within 7 days', abs($t['within']['value'] - 83.33) < 0.01);
ck('assess: moderation rate is 2 of 6 marks', abs($sc['moderation']['value'] - 33.33) < 0.01 && $sc['moderation']['n'] === 6);
ck('assess: release lag is the median of 2, 12, 11, 10 and 4 days', abs($sc['release_lag']['value'] - 10.0) < 0.001 && $sc['release_lag']['n'] === 5);
ck('assess: a figure from five or more is not flagged small', !$t['median']['small']);
$sa = Scorecard::build($asst, $coord, $course, '12m');
ck('assess: the assistant\'s two marks are flagged as a small count, not hidden', $sa['turnaround']['median']['n'] === 2 && $sa['turnaround']['median']['small'] && abs($sa['turnaround']['median']['value'] - 11.0) < 0.001);
ck('assess: the scorecard carries the course\'s feedback counts, per course, not the instructor\'s', !isset($sc['ratings']) && $sc['feedback']['courses'][$course]['received'] === 3
   && $sc['feedback']['courses'][$course]['approved'] === 1 && $sc['feedback']['total']['shared'] === 1);
ck('assess: and the approved feedback itself, once past the threshold', $sc['feedback_items'][$course]['shown'] && count($sc['feedback_items'][$course]['items']) === 1);
$si = Scorecard::build($ins, $ins, 0, '12m');
ck('assess: the other course (no feedback) reads as waiting, with zero counts', $si['feedback']['courses'][$other]['received'] === 0 && !$si['feedback']['courses'][$other]['threshold_met']);
ck('assess: observations: two finals, latest is the second', $sc['observations']['n'] === 2 && $sc['observations']['latest']['observed_on'] === '2026-11-27');
ck('assess: per-criterion average keeps each name\'s own count', $sc['observations']['criteria']['Clarity']['n'] === 2 && abs($sc['observations']['criteria']['Clarity']['value'] - 3.0) < 0.001
   && $sc['observations']['criteria']['Pacing']['n'] === 1);
$oc = $sc['outcomes'];
ck('assess: outcomes count the course\'s six learners', $oc['learners'] === 6);
ck('assess: completion: one of six finished every lesson', abs($oc['completion']['value'] - 16.67) < 0.01);
ck('assess: pass rate over the five whose grade is decided (one is held for moderation)', $oc['pass_rate']['n'] === 5 && abs($oc['pass_rate']['value'] - 100.0) < 0.001);
ck('assess: average grade over the decided learners', abs($oc['avg_grade']['value'] - ((70 + 80 * 4) / 5)) < 0.01);
$s3 = Scorecard::build($ins, $coord, $course, '3m');
ck('assess: a period preset narrows the figures', $s3['period']['key'] === '3m' && $s3['turnaround']['median']['n'] === 6);

/* ══ The instructor list ════════════════════════════════════════════════ */

$dir = Scorecard::directory($coord, '12m', ['course' => $course]);
$names = array_map(static fn($r) => $r['user']['name'], $dir['rows']);
ck('assess: the list has the course\'s team', $dir['ok'] && in_array('Ike Instructor', $names, true) && in_array('Ada Assistant', $names, true));
$dirAtt = Scorecard::directory($coord, '12m', ['course' => $course, 'attention' => 1]);
ck('assess: "needs attention" picks the assistant (median 11 days to mark)', in_array('Ada Assistant', array_map(static fn($r) => $r['user']['name'], $dirAtt['rows']), true));
$dirQ = Scorecard::directory($coord, '12m', ['q' => 'quinn']);
ck('assess: a name search narrows the list', count($dirQ['rows']) === 1 && $dirQ['rows'][0]['user']['name'] === 'Quinn Quiet');
$mem = fopen('php://memory', 'w+');
Scorecard::directoryCsv($dir, $mem);
rewind($mem); $csv = (string) stream_get_contents($mem); fclose($mem);
ck('assess: the CSV carries the headline figures', str_contains($csv, 'Median days to mark') && str_contains($csv, 'Ike Instructor'));
$csvLeak = false;
foreach ($L as $u) { $row = $db->query("SELECT name, email FROM lms_users WHERE id = $u")->fetch(PDO::FETCH_ASSOC); if (str_contains($csv, $row['name']) || str_contains($csv, $row['email'])) $csvLeak = true; }
ck('assess: the CSV names no learner', !$csvLeak);
ck('assess: the CSV carries feedback counts and no feedback text', str_contains($csv, 'Course feedback received') && !str_contains($csv, 'worked examples') && !str_contains($csv, 'More live'));

Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED=1');
Grading::setClock(null);
