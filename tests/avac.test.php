<?php
/**
 * tests/avac.test.php — the redesigned Academy pages (catalogue, course,
 * lesson player): the view helpers in academy/_avac.php and the contracts the
 * three pages must keep from v1.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/academy/_avac.php';

/* ══ Labels ══════════════════════════════════════════════════════════════ */

ck('avac: open and tracked courses read as free', avac_access('open')['label'] === 'Free' && avac_access('tracked')['tone'] === 'free');
ck('avac: a members course reads as members only', avac_access('membership')['label'] === 'Members only');
ck('avac: an unknown access model falls back to free', avac_access('zzz')['label'] === 'Free');
ck('avac: a paid course shows its naira price', avac_price(['access_type' => 'paid', 'price_ngn' => 15000]) === '₦15,000');
ck('avac: a paid course with no amount falls back to its price text', avac_price(['access_type' => 'paid', 'price_ngn' => 0, 'price' => 'Ask us']) === 'Ask us');
ck('avac: membership shows Members', avac_price(['access_type' => 'membership']) === 'Members');
ck('avac: plurals', avac_plural(1, 'lesson') === '1 lesson' && avac_plural(6, 'lesson') === '6 lessons');
ck('avac: state CTAs', avac_cta('done') === 'Review' && avac_cta('continue') === 'Continue' && avac_cta('') === 'View course');

/* ══ A learner's state on a course ═══════════════════════════════════════ */

$avAc = new AcademyRepository();
$avLms = new LmsRepository();
$avSlug = $avAc->save(['title' => 'Avac State Course', 'summary' => 's', 'status' => 'published', 'access_type' => 'membership', 'price_ngn' => 0]);
$avC = $avAc->bySlug($avSlug);
$avM = $avLms->addModule((int) $avC['id'], 'One');
$avLms->saveLesson(['module_id' => $avM, 'title' => 'First', 'slug' => '', 'video_url' => '', 'body_html' => '<p>x</p>']);
$avLms->saveLesson(['module_id' => $avM, 'title' => 'Second', 'slug' => '', 'video_url' => '', 'body_html' => '<p>y</p>']);
Database::pdo()->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES (?,?,?,?,1,1)')
    ->execute(['Avac Learner', 'avac-learner@example.com', password_hash('x', PASSWORD_BCRYPT), 'learner']);
$avU = $avLms->userByEmail('avac-learner@example.com');

ck('avac: no state for a visitor', avac_state($avC, $avLms, null, 2)['state'] === '');
ck('avac: no state for a learner without access who has not started', avac_state($avC, $avLms, $avU, 2)['state'] === '');
$avLms->grantMembership((int) $avU['id'], 12);
ck('avac: a member is enrolled on a members course', avac_state($avC, $avLms, $avU, 2)['state'] === 'enrolled');
$avL = $avLms->orderedLessons((int) $avC['id']);
$avLms->markComplete((int) $avU['id'], $avLms->lesson((int) $avC['id'], $avL[0]['slug']));
$avS = avac_state($avC, $avLms, $avU, 2);
ck('avac: one lesson done is continue at 50%', $avS['state'] === 'continue' && $avS['pct'] === 50);
$avLms->markComplete((int) $avU['id'], $avLms->lesson((int) $avC['id'], $avL[1]['slug']));
ck('avac: every lesson done is done', avac_state($avC, $avLms, $avU, 2)['state'] === 'done');
$avRows = avac_decorate([$avC], $avLms, $avU);
ck('avac: decorate carries lesson count, enrolment count and state',
   $avRows[0]['lessons'] === 2 && isset($avRows[0]['enrolled']) && $avRows[0]['state'] === 'done');

/* ══ Contracts the pages keep from v1 ════════════════════════════════════ */

$avIndex = (string) file_get_contents(AV_ROOT . '/academy/index.php');
$avCourse = (string) file_get_contents(AV_ROOT . '/academy/course.php');
$avLearn = (string) file_get_contents(AV_ROOT . '/academy/learn.php');
$avJs = (string) file_get_contents(AV_ROOT . '/academy/avac.js') . (string) file_get_contents(AV_ROOT . '/academy/avle.js');
ck('avac: catalogue and course use the Home chrome', str_contains($avIndex, 'avh_nav()') && str_contains($avIndex, 'avh_footer()')
   && str_contains($avCourse, 'avh_nav()') && str_contains($avCourse, 'avh_footer()'));
ck('avac: the lesson player is focused (no marketing nav)', !str_contains($avLearn, 'avh_nav()') && !str_contains($avLearn, 'render_nav('));
ck('avac: the summit band reads the shared facts', str_contains($avIndex, 'Summit::facts'));
ck('avac: membership checkout is offered from the catalogue', str_contains($avIndex, 'data-avac-pay="membership"'));
ck('avac: the course page keeps every access branch',
   str_contains($avCourse, 'data-avac-pay="course"') && str_contains($avCourse, 'data-avac-pass=')
   && str_contains($avCourse, 'data-avac-lead=') && str_contains($avCourse, '/certificate'));
ck('avac: a locked lesson still asks whether money can be taken', str_contains($avLearn, 'Payments::canCollect()'));
foreach (['pay_init', 'pass_redeem', 'enroll', 'lesson_complete', 'lesson_uncomplete', 'quiz_submit', 'note_save', 'notes_all'] as $avAct) {
    ck("avac: the pages still call the {$avAct} action", str_contains($avJs, "'" . $avAct . "'") || str_contains($avJs, 'action=' . $avAct));
}
ck('avac: no native dialogs or scrollIntoView in the new scripts', !preg_match('/\b(alert|confirm|prompt)\(|scrollIntoView/', $avJs));
ck('avac: academy.css stays for the pages that still use it',
   is_file(AV_ROOT . '/academy/academy.css') && str_contains((string) file_get_contents(AV_ROOT . '/academy/teach.php'), 'academy.css'));

$avR = $avAc->save(['title' => 'Avac Restricted', 'summary' => 's', 'status' => 'published', 'access_type' => 'restricted', 'pass_code' => 'avac-pass']);
$avRc = $avAc->bySlug($avR);
$avRm = $avLms->addModule((int) $avRc['id'], 'One');
$avLms->saveLesson(['module_id' => $avRm, 'title' => 'Only', 'slug' => '', 'video_url' => '', 'body_html' => '<p>x</p>']);
ck('avac: membership alone does not mark a restricted course as enrolled', avac_state($avRc, $avLms, $avU, 1)['state'] === '');
$avLms->grantPass((int) $avU['id'], 'avac-pass', 'Avac', '', 0);
ck('avac: holding the pass does', avac_state($avRc, $avLms, $avU, 1)['state'] === 'enrolled');
