<?php
/**
 * tests/grading.test.php — graded items, weights, the pass mark and a
 * learner's standing (lib/Grading.php).
 *
 * Everything here executes the code: items are created through saveItem(),
 * published through publish(), quiz attempts are taken through
 * LmsRepository::gradeQuiz() and back-dated where a test needs a clock.
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

$course = gt_course('Graded Course ' . getmypid());
$lead = gt_user('Gita Lead'); $ins = gt_user('Ivo Instructor'); $asst = gt_user('Abi Assistant'); $out = gt_user('Obi Outsider');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $asst, 'assistant', $lead);

/* ══ Rubrics ════════════════════════════════════════════════════════════ */

$r = Grading::parseRubric(gt_rubric());
ck('grading: a rubric parses into criteria with maxima', $r['ok'] && count($r['criteria']) === 2 && Grading::rubricMax($r['criteria']) === 20.0);
ck('grading: level descriptors are kept', ($r['criteria'][0]['levels'][2]['text'] ?? '') === 'clear and evidenced');
ck('grading: a rubric round-trips through its editor text', Grading::parseRubric(Grading::rubricText($r['criteria']))['criteria'] === $r['criteria']);
ck('grading: a criterion without a maximum is refused', !Grading::parseRubric("Argument | | thin")['ok']);
ck('grading: a level above its maximum is refused', !Grading::parseRubric("Argument | 5 | 9: too high")['ok']);
ck('grading: scoring must cover every criterion', !Grading::scoreRubric($r['criteria'], [5])['ok']);
ck('grading: and stay within each maximum', !Grading::scoreRubric($r['criteria'], [11, 5])['ok']);
ck('grading: a valid score adds up', Grading::scoreRubric($r['criteria'], [7, 8])['points'] === 15.0);

/* ══ Who may set items up ═══════════════════════════════════════════════ */

$quizLesson = gt_quiz_lesson($course, 'graded-quiz-' . getmypid());
$a = ['kind' => 'assignment', 'title' => 'Essay', 'weight' => 60, 'pass_score' => 50, 'rubric' => gt_rubric(),
      'due_at' => $local($T0 + 7 * 86400), 'late_hours' => 48, 'late_penalty' => 10, 'max_attempts' => 2];
ck('grading: an assistant cannot create an item', !Grading::saveItem($course, $a, $asst)['ok']);
ck('grading: an outsider cannot create an item', !Grading::saveItem($course, $a, $out)['ok']);
$essay = Grading::saveItem($course, $a, $ins);
ck('grading: an instructor can create an item', $essay['ok']);
$essayId = (int) $essay['id'];
$quiz = Grading::saveItem($course, ['kind' => 'quiz', 'title' => 'Check', 'weight' => 30, 'pass_score' => 50, 'lesson_id' => $quizLesson['id'],
                                    'due_at' => $local($T0 + 7 * 86400), 'late_hours' => 24, 'late_penalty' => 20, 'max_attempts' => 2], $lead);
ck('grading: a quiz item is made from a lesson quiz', $quiz['ok']);
$quizId = (int) $quiz['id'];
ck('grading: a quiz item must point at a lesson with a quiz',
   !Grading::saveItem($course, ['kind' => 'quiz', 'title' => 'X', 'weight' => 0, 'lesson_id' => 999999], $lead)['ok']);
ck('grading: an assignment needs a rubric', !Grading::saveItem($course, ['kind' => 'assignment', 'title' => 'X', 'rubric' => ''], $lead)['ok']);
ck('grading: a peer item needs a due date', !Grading::saveItem($course, ['kind' => 'peer', 'title' => 'P', 'rubric' => gt_rubric(), 'due_at' => ''], $lead)['ok']);

/* ══ Drafts are invisible; publishing needs weights of 100 ══════════════ */

ck('grading: drafts are not part of the published list', Grading::items($course, 'published') === []);
ck('grading: a course whose items are all drafts is not graded', !Grading::hasGradedItems($course));
ck('grading: the team sees its drafts', count(Grading::items($course, 'team')) === 2);
$p = Grading::publish($course, $ins);
ck('grading: publishing is refused when weights add to 90', !$p['ok'] && ($p['code'] ?? '') === 'weights' && str_contains($p['error'], '90'));
ck('grading: and nothing was published', Grading::items($course, 'published') === []);
ck('grading: an assistant cannot change weights', !Grading::setWeights($course, [$essayId => 70], '', $asst)['ok']);
ck('grading: an instructor can, while nothing is published', Grading::setWeights($course, [$essayId => 70], '', $ins)['ok']);
ck('grading: an assistant cannot publish', !Grading::publish($course, $asst)['ok']);
$p = Grading::publish($course, $ins);
ck('grading: at 100, publishing works', $p['ok'] && $p['published'] === 2);
ck('grading: the course is now graded', Grading::hasGradedItems($course));

/* Once published, the weights must still add to 100. */
$w = Grading::setWeights($course, [$essayId => 50], '', $lead);
ck('grading: a weight change that breaks 100 is refused once published', !$w['ok'] && ($w['code'] ?? '') === 'weights');
ck('grading: a published weight cannot be slipped through the item editor',
   !Grading::saveItem($course, ['id' => $essayId, 'title' => 'Essay', 'weight' => 50], $lead)['ok']);
ck('grading: moving weight between items keeps 100 and is allowed', Grading::setWeights($course, [$essayId => 60, $quizId => 40], '', $lead)['ok']);
ck('grading: the weights now read 60/40', (int) Grading::item($essayId)['weight'] === 60 && (int) Grading::item($quizId)['weight'] === 40);

/* A new draft must be rebalanced into the 100 before it can be published. */
$extra = Grading::saveItem($course, ['kind' => 'assignment', 'title' => 'Reflection', 'weight' => 10, 'rubric' => "Insight | 5"], $lead);
$p = Grading::publish($course, $lead);
ck('grading: a draft that takes the total past 100 cannot be published', !$p['ok'] && str_contains($p['error'], '110'));
ck('grading: the learner still cannot see the draft', count(Grading::items($course, 'published')) === 2);
ck('grading: an unused draft may be deleted', Grading::deleteItem((int) $extra['id'], $lead)['ok'] && Grading::item((int) $extra['id']) === null);

/* ══ Pass mark ══════════════════════════════════════════════════════════ */

ck('grading: the pass mark defaults to 70', Grading::passMark($course) === 70);
ck('grading: an instructor cannot change it', !Grading::setPassMark($course, 60, $ins)['ok'] && Grading::passMark($course) === 70);
ck('grading: the lead can', Grading::setPassMark($course, 60, $lead)['ok'] && Grading::passMark($course) === 60);
ck('grading: it must be 1–100', !Grading::setPassMark($course, 0, $lead)['ok'] && !Grading::setPassMark($course, 101, $lead)['ok']);
Grading::setPassMark($course, 70, $lead);

/* ══ Quiz items: best counted attempt, late penalty, attempt cap ════════ */

$lms = new LmsRepository();
$learner = gt_user('Lola Learner'); gt_enrol($learner, $course);
$quizItem = Grading::item($quizId);
$backdate = static function (int $ts) use ($db): void {
    $db->exec("UPDATE quiz_attempts SET created_at = '" . gmdate('Y-m-d H:i:s', $ts) . "' WHERE id = (SELECT MAX(id) FROM quiz_attempts)");
};
ck('grading: before any attempt the quiz is not started', Grading::result($quizItem, $learner)['status'] === 'not_started');
$lms->gradeQuiz($learner, $quizLesson, [0, 0]); $backdate($T0 + 3600);            // 50, on time
$lms->gradeQuiz($learner, $quizLesson, [0, 1]); $backdate($T0 + 8 * 86400);       // 100, a day late (inside the 24h window)
$lms->gradeQuiz($learner, $quizLesson, [0, 1]); $backdate($T0 + 2 * 3600);        // 100 on time — but the third attempt
$res = Grading::result($quizItem, $learner);
ck('grading: a late quiz attempt loses the late penalty (100 → 80)', $res['score'] === 80.0 && $res['penalty'] === 20 && $res['late']);
ck('grading: attempts beyond the cap do not count', $res['attempts'] === 2);
ck('grading: the best counted attempt is the score', $res['counts_attempt'] === 2);
$lms->gradeQuiz($learner, $quizLesson, [0, 1]);
$db->exec("UPDATE quiz_attempts SET created_at = '" . gmdate('Y-m-d H:i:s', $T0 + 30 * 86400) . "' WHERE id = (SELECT MAX(id) FROM quiz_attempts)");
$learner2 = gt_user('Tunde Late'); gt_enrol($learner2, $course);
$lms->gradeQuiz($learner2, $quizLesson, [0, 1]); $backdate($T0 + 30 * 86400);    // after the window entirely
Grading::setClock($T0 + 40 * 86400);
ck('grading: an attempt after the window closes does not count, and the item is missed',
   Grading::result($quizItem, $learner2)['status'] === 'missed' && Grading::result($quizItem, $learner2)['score'] === 0.0);
Grading::setClock($T0);

/* ══ Standing: weighted grade and what is still needed ══════════════════ */

$st = Grading::standing($learner, $course);
ck('grading: the quiz share counts at once (40% × 80 = 32)', abs($st['grade'] - 32.0) < 0.01);
ck('grading: the essay is still to come', abs($st['remaining'] - 60.0) < 0.01 && !$st['complete'] && !$st['passed']);
ck('grading: what is needed on the rest to pass (38 of 60 → 63.3%)', abs((float) $st['need_avg'] - 63.3) < 0.05);

/* ══ Retiring rather than deleting once used ════════════════════════════ */

$d = Grading::deleteItem($quizId, $lead);
ck('grading: a used item cannot be deleted', !$d['ok'] && ($d['code'] ?? '') === 'retire' && Grading::item($quizId) !== null);
$q2 = Grading::saveItem($course, ['id' => $quizId, 'title' => 'Check', 'lesson_id' => gt_quiz_lesson($course, 'other-quiz-' . getmypid())['id']], $lead);
ck('grading: changing how a used item is marked needs a reason', !$q2['ok'] && ($q2['code'] ?? '') === 'reason_required');
ck('grading: changing a used item\'s weight needs a reason', !Grading::setWeights($course, [$essayId => 50, $quizId => 50], '', $lead)['ok']);
ck('grading: with a reason it goes through', Grading::setWeights($course, [$essayId => 50, $quizId => 50], 'quiz carried more of the course than planned', $lead)['ok']);
$row = $db->query("SELECT detail FROM lms_audit WHERE action = 'grading.weights' ORDER BY id DESC LIMIT 1")->fetchColumn();
ck('grading: and the reason is on the audit trail', str_contains((string) $row, 'carried more of the course'));
Grading::setWeights($course, [$essayId => 60, $quizId => 40], 'restore', $lead);

/* ══ The schema: one definition, in the runtime DDL and in every schema file ══ */

$tablesIn = static function (string $sql): array {
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z_]+)\s*\((.*?)\n\s*\)/is', $sql, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $t) {
        $cols = [];
        foreach (preg_split('/,\s*\n/', $t[2]) as $line) {
            if (preg_match('/^\s*([a-z_]+)\s+[A-Z]/', $line, $c) && !in_array(strtoupper($c[1]), ['UNIQUE', 'PRIMARY'], true)) $cols[] = $c[1];
        }
        $out[strtolower($t[1])] = $cols;
    }
    return $out;
};
$runtime = $tablesIn(CourseTeam::ddl() . "\n" . Grading::ddl());
foreach (['schema.sql', 'schema.mysql.sql', 'schema.pgsql.sql'] as $f) {
    $file = $tablesIn((string) file_get_contents(AV_ROOT . '/db/' . $f));
    $same = true;
    foreach ($runtime as $t => $cols) { if (($file[$t] ?? null) !== $cols) { $same = false; error_log("[test] $f: $t differs"); } }
    ck("grading: db/$f declares every teaching and grading table with the runtime columns", $same);
}
ck('grading: the runtime DDL covers every table Grading names', array_diff(Grading::TABLES, array_keys($runtime)) === []);
ck('grading: certificates carry the grade they were issued on', Database::columnExists('certificates', 'grade_pct'));
foreach (['idx_graded_items_course', 'idx_grade_sub_course', 'idx_peer_reviews_reviewer', 'idx_course_instructors_user'] as $ix) {
    ck("grading: index $ix exists", (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='$ix'")->fetchColumn());
}

Grading::setClock(null);
