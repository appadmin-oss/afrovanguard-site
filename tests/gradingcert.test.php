<?php
/**
 * tests/gradingcert.test.php — certificates under grading.
 *
 *   • A course with no graded items keeps the original rule: all lessons.
 *   • A course with graded items: every item passed AND the course grade at
 *     or above the pass mark. Lessons alone do not earn it.
 *   • The certificate records the grade.
 *   • An issued certificate is never revoked by anything grading does.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';

$db = Database::pdo();
Grading::reset(); Grading::ensure();
Grading::setClock(strtotime('2026-11-10 09:00:00 UTC'));
$lms = new LmsRepository();

/* ══ No graded items: today's rule ══════════════════════════════════════ */

$plain = gt_course('Plain Course ' . getmypid());
$pl = gt_quiz_lesson($plain, 'plain-lesson-' . getmypid());
$p = gt_user('Pam Plain'); gt_enrol($p, $plain);
ck('cert: an ungraded course with lessons outstanding issues nothing', $lms->issueCertificate($p, $plain) === null);
$lms->markComplete($p, $pl);
$c = $lms->issueCertificate($p, $plain);
ck('cert: all lessons complete still earns an ungraded course\'s certificate', $c !== null && $c['grade_pct'] === null);

/* ══ Graded: pass every item and the pass mark ══════════════════════════ */

$course = gt_course('Cert Course ' . getmypid());
$lead = gt_user('Cy Lead');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
$lesson = gt_quiz_lesson($course, 'cert-quiz-' . getmypid());
$quiz = (int) Grading::saveItem($course, ['kind' => 'quiz', 'title' => 'Quiz', 'weight' => 50, 'pass_score' => 50, 'lesson_id' => $lesson['id'], 'max_attempts' => 0], $lead)['id'];
$task = (int) Grading::saveItem($course, ['kind' => 'assignment', 'title' => 'Task', 'weight' => 50, 'pass_score' => 60, 'rubric' => gt_rubric()], $lead)['id'];
Grading::publish($course, $lead);

$x = gt_user('Xena Cert'); gt_enrol($x, $course);
$lms->gradeQuiz($x, $lesson, [0, 1]);                // 100, and the only lesson is now complete
ck('cert: completing every lesson is not enough on a graded course', $lms->progress($x, $course)['complete'] && $lms->issueCertificate($x, $course) === null);
$sx = (int) GradingWork::submit($task, $x, ['text' => 'work'])['id'];
GradingWork::mark($sx, $lead, [5, 6], '');            // 55 — below the item's 60
ck('cert: an unreleased mark earns nothing', $lms->issueCertificate($x, $course) === null);
GradingWork::release($task, $lead);
$st = Grading::standing($x, $course);
ck('cert: grade 77.5 meets the pass mark, but one item is failed', abs($st['grade'] - 77.5) < 0.01 && !$st['all_items_passed']);
ck('cert: so no certificate', $lms->issueCertificate($x, $course) === null && !$lms->certificateEligible($x, $course));

$y = gt_user('Yemi Cert'); gt_enrol($y, $course);
$lms->gradeQuiz($y, $lesson, [0, 0]);                // 50: passes the quiz item (pass 50)
$sy = (int) GradingWork::submit($task, $y, ['text' => 'work'])['id'];
GradingWork::mark($sy, $lead, [7, 7], '');            // 70
GradingWork::release($task, $lead);
$st = Grading::standing($y, $course);
ck('cert: every item passed but grade 60 is below the pass mark of 70', $st['all_items_passed'] && abs($st['grade'] - 60.0) < 0.01 && !$st['passed']);
ck('cert: so no certificate', $lms->issueCertificate($y, $course) === null);
Grading::setPassMark($course, 60, $lead);
ck('cert: at a pass mark of 60, the same learner passes', Grading::standing($y, $course)['passed']);
$cy = $lms->issueCertificate($y, $course);
ck('cert: and the certificate is issued with the grade on it', $cy !== null && abs((float) $cy['grade_pct'] - 60.0) < 0.01);
Grading::setPassMark($course, 70, $lead);

$z = gt_user('Zed Cert'); gt_enrol($z, $course);
$lms->gradeQuiz($z, $lesson, [0, 1]);                // 100
$sz = (int) GradingWork::submit($task, $z, ['text' => 'work'])['id'];
GradingWork::mark($sz, $lead, [9, 9], '');            // 90
$rel = GradingWork::release($task, $lead);
ck('cert: the release that completes a pass issues the certificate itself', $lms->getCertificate($z, $course) !== null
   && abs((float) $lms->getCertificate($z, $course)['grade_pct'] - 95.0) < 0.01);

/* ══ Never revoked ══════════════════════════════════════════════════════ */

GradingWork::agree($sz, $lead, 2, 'Plagiarism found after release');   // 10%
GradingWork::release($task, $lead);
ck('cert: a regrade that would now fail the learner leaves the certificate standing',
   !Grading::standing($z, $course)['passed'] && $lms->getCertificate($z, $course) !== null && $lms->certificateEligible($z, $course));
ck('cert: and its recorded grade is the one it was issued on', abs((float) $lms->getCertificate($z, $course)['grade_pct'] - 95.0) < 0.01);
Grading::setPassMark($course, 100, $lead);
ck('cert: raising the pass mark revokes nothing either', $lms->issueCertificate($z, $course) !== null);

/* The certificate page shows the grade. */
gt_sign_in($z);
$slug = (string) $db->query('SELECT slug FROM courses WHERE id = ' . $course)->fetchColumn();
$html = render_page(AV_ROOT . '/academy/certificate.php', ['course' => $slug, 'format' => 'txt']);
ck('cert: the certificate states the grade it was issued on', str_contains($html, 'with a final grade of 95%'));
$png = render_page(AV_ROOT . '/academy/certificate.php', ['course' => $slug]);
ck('cert: the image certificate still renders', str_contains($png, 'IHDR') || str_contains($png, 'final grade'));
gt_sign_in(0);

Grading::setClock(null);
