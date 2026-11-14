<?php
/**
 * tests/courseteam.test.php — teaching teams (lib/CourseTeam.php).
 *
 * The rule pinned here is the one every other Academy screen leans on: what a
 * lead, an instructor and an assistant may each do, answered in ONE place.
 * Each assertion executes the code — the permission table is exercised through
 * can(), the last-lead guard through add()/remove(), and the legacy column
 * through a course that only ever had courses.instructor_id.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';

$db = Database::pdo();
CourseTeam::reset();

/* ══ Legacy single instructors become leads ═════════════════════════════ */

$oldIns = gt_user('Olu Legacy', 'instructor');
$legacy = gt_course('Legacy Course ' . getmypid());
$db->prepare('UPDATE courses SET instructor_id = ? WHERE id = ?')->execute([$oldIns, $legacy]);
CourseTeam::reset();
CourseTeam::ensure();
ck('team: a course that only had courses.instructor_id gets that person as its lead',
   CourseTeam::roleOf($legacy, $oldIns) === 'lead');
ck('team: adopting legacy instructors is idempotent', CourseTeam::adoptLegacy() === 0
   && (int) $db->query('SELECT COUNT(*) FROM course_instructors WHERE course_id = ' . $legacy)->fetchColumn() === 1);
ck('team: ownsCourse() reads the team', (new LmsRepository())->ownsCourse($oldIns, (string) $db->query('SELECT slug FROM courses WHERE id = ' . $legacy)->fetchColumn()) !== null);

/* ══ Building a team ════════════════════════════════════════════════════ */

$course = gt_course('Team Course ' . getmypid());
$lead = gt_user('Lara Lead');
$ins  = gt_user('Ike Instructor');
$asst = gt_user('Ada Assistant');
$out  = gt_user('Oscar Outsider');
$coord = gt_user('Cora Coordinator', 'coordinator');

$r = CourseTeam::add($course, $asst, 'assistant', CourseTeam::STUDIO);
ck('team: the first person on a team must be its lead', !$r['ok'] && CourseTeam::roleOf($course, $asst) === null);
ck('team: the Studio can put a lead on a course', CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO)['ok']);
ck('team: courses.instructor_id follows the lead for older readers',
   (int) $db->query('SELECT instructor_id FROM courses WHERE id = ' . $course)->fetchColumn() === $lead);
ck('team: a lead may add an instructor', CourseTeam::add($course, $ins, 'instructor', $lead)['ok']);
ck('team: a lead may add an assistant', CourseTeam::add($course, $asst, 'assistant', $lead)['ok']);
ck('team: an instructor may not change the team', !CourseTeam::add($course, $out, 'assistant', $ins)['ok']
   && CourseTeam::roleOf($course, $out) === null);
ck('team: an assistant may not change the team', !CourseTeam::remove($course, $ins, $asst)['ok']
   && CourseTeam::roleOf($course, $ins) === 'instructor');
ck('team: an outsider may not change the team', !CourseTeam::add($course, $out, 'lead', $out)['ok']);
ck('team: a coordinator may manage any team', CourseTeam::add($course, $out, 'assistant', $coord)['ok']
   && CourseTeam::remove($course, $out, $coord)['ok']);
ck('team: an unknown role is refused', !CourseTeam::add($course, $out, 'owner', $lead)['ok']);
ck('team: adding an instructor gives a learner the instructor console', (string) $db->query('SELECT role FROM lms_users WHERE id = ' . $ins)->fetchColumn() === 'instructor');
ck('team: an assistant is not promoted to instructor', (string) $db->query('SELECT role FROM lms_users WHERE id = ' . $asst)->fetchColumn() === 'learner');

/* ══ The permission table, executed ═════════════════════════════════════ */

$expect = [
    // action            lead   instr  asst   outsider
    'view'           => [true,  true,  true,  false],
    'mark'           => [true,  true,  true,  false],
    'manage_items'   => [true,  true,  false, false],
    'change_weights' => [true,  true,  false, false],
    'moderate'       => [true,  true,  false, false],
    'release'        => [true,  true,  false, false],
    'edit_course'    => [true,  false, false, false],
    'manage_team'    => [true,  false, false, false],
    'set_pass_mark'  => [true,  false, false, false],
];
foreach ($expect as $action => [$l, $i, $a, $o]) {
    ck("team: lead may $action = " . ($l ? 'yes' : 'no'), CourseTeam::can($course, $lead, $action) === $l);
    ck("team: instructor may $action = " . ($i ? 'yes' : 'no'), CourseTeam::can($course, $ins, $action) === $i);
    ck("team: assistant may $action = " . ($a ? 'yes' : 'no'), CourseTeam::can($course, $asst, $action) === $a);
    ck("team: outsider may $action = " . ($o ? 'yes' : 'no'), CourseTeam::can($course, $out, $action) === $o);
    ck("team: a coordinator may $action on any course", CourseTeam::can($course, $coord, $action));
}
ck('team: an unknown action fails closed, even for staff', !CourseTeam::can($course, $coord, 'anything'));
ck('team: a role on one course grants nothing on another', !CourseTeam::can($legacy, $lead, 'view'));

/* ══ At least one lead, always ══════════════════════════════════════════ */

$r = CourseTeam::remove($course, $lead, $lead);
ck('team: the last lead cannot be removed', !$r['ok'] && CourseTeam::roleOf($course, $lead) === 'lead');
$r = CourseTeam::add($course, $lead, 'instructor', $lead);
ck('team: the last lead cannot be demoted', !$r['ok'] && CourseTeam::roleOf($course, $lead) === 'lead');
ck('team: promoting a second lead works', CourseTeam::add($course, $ins, 'lead', $lead)['ok']);
ck('team: with two leads, one may step down', CourseTeam::add($course, $lead, 'instructor', $ins)['ok']
   && CourseTeam::roleOf($course, $lead) === 'instructor');
ck('team: instructor_id moves to the remaining lead',
   (int) $db->query('SELECT instructor_id FROM courses WHERE id = ' . $course)->fetchColumn() === $ins);

/* ══ Reading the team ═══════════════════════════════════════════════════ */

$m = CourseTeam::members($course);
ck('team: members() lists the lead first', ($m[0]['user_id'] ?? 0) === $ins && $m[0]['role'] === 'lead');
ck('team: and assistants last', (end($m)['role'] ?? '') === 'assistant');
ck('team: coursesFor() lists an assistant\'s course', in_array($course, array_map('intval', array_column(CourseTeam::coursesFor($asst), 'id')), true));
ck('team: coursesForInstructor() reads the team, not the column',
   in_array($course, array_map('intval', array_column((new LmsRepository())->coursesForInstructor($lead), 'id')), true));

/* ══ Every change is on the audit trail ═════════════════════════════════ */

$n = (int) $db->query("SELECT COUNT(*) FROM lms_audit WHERE action LIKE 'team.%' AND target LIKE 'course#" . $course . " %'")->fetchColumn();
ck('team: team changes are audited', $n >= 5);
