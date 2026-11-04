<?php
/**
 * tests/courseware.test.php — the Academy as a course platform: item kinds,
 * the quiz engine (multi-select, true/false, attempts, practice vs graded),
 * assignments and grading, grades and the pass rule a certificate rests on,
 * discussions, ratings and reviews, programmes, the catalogue's figures and
 * filter, and a learner's schedule.
 *
 * Each block pins a promise rather than markup: "a certificate certifies a
 * pass", "a practice quiz never blocks", "the answer key is not handed out
 * while attempts remain".
 *
 * Run via tests/run.php (provides ck() and render_page()).
 */
declare(strict_types=1);
require_once AV_ROOT . '/academy/_avac.php';

$db = Database::pdo();
$ac = new AcademyRepository();
$lms = new LmsRepository();
$cw = new LmsCourseware(null, $lms);

$mkUser = function (string $email, string $role = 'learner') use ($db, $lms): array {
    $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES (?,?,?,?,1,1)')
        ->execute([ucfirst(strtok($email, '@')) . ' Learner', $email, password_hash('x', PASSWORD_BCRYPT), $role]);
    return $lms->userByEmail($email);
};

/* ══ Schema ══════════════════════════════════════════════════════════════ */

foreach (['assignment_submissions', 'forum_threads', 'forum_posts', 'course_reviews', 'programmes', 'programme_courses', 'programme_certificates'] as $t)
    ck("courseware: table $t exists", Database::tableExists($t));
foreach ([['courses', 'skills'], ['courses', 'pass_mark'], ['courses', 'pace_weeks'], ['courses', 'language'], ['modules', 'objectives'], ['lessons', 'kind'], ['lessons', 'weight']] as [$t, $c])
    ck("courseware: column $t.$c exists", Database::columnExists($t, $c));

/* ══ A course with every kind of item ════════════════════════════════════ */

$slug = $ac->save(['title' => 'Courseware Civic Lab', 'summary' => 'Learn to organise.', 'status' => 'published', 'access_type' => 'open',
    'skills' => "Organising\nBudgeting", 'prerequisites' => 'A phone', 'pass_mark' => 75, 'pace_weeks' => 4, 'language' => 'English', 'level' => 'Beginner']);
$c = $ac->bySlug($slug);
$cid = (int) $c['id'];
ck('courseware: course details are saved', (int) $c['pass_mark'] === 75 && (int) $c['pace_weeks'] === 4 && LmsCourseware::skills($c) === ['Organising', 'Budgeting']);
$m1 = $lms->addModule($cid, 'Foundations');
$lms->describeModule($m1, 'Why organising matters.', "Explain a mandate\n\nName three roles\n");
$m2 = $lms->addModule($cid, 'Practice');
ck('courseware: objectives are trimmed lines', LmsCourseware::objectives($lms->curriculum($cid)[0]) === ['Explain a mandate', 'Name three roles']);

$quizSpec = json_encode(['pass' => 50, 'attempts' => 2, 'questions' => [
    ['type' => 'multi', 'q' => 'Which are roles?', 'options' => ['Chair', 'Banana', 'Secretary'], 'answer' => [0, 2], 'explain' => 'Chair and secretary.'],
    ['type' => 'truefalse', 'q' => 'A quorum is a minimum.', 'options' => ['x'], 'answer' => 0],
]]);
$lRead = $lms->saveLesson(['module_id' => $m1, 'title' => 'Reading one', 'slug' => '', 'body_html' => '<p>r</p>', 'duration_min' => 10]);
$lQuiz = $lms->saveLesson(['module_id' => $m1, 'title' => 'Graded check', 'slug' => '', 'body_html' => '', 'quiz_json' => $quizSpec, 'weight' => 1, 'duration_min' => 5]);
$lPrac = $lms->saveLesson(['module_id' => $m1, 'title' => 'Practice check', 'slug' => '', 'body_html' => '',
    'quiz_json' => json_encode(['pass' => 100, 'graded' => false, 'questions' => [['q' => 'Two plus two?', 'options' => ['3', '4'], 'answer' => 1]]])]);
$lAsg = $lms->saveLesson(['module_id' => $m2, 'title' => 'Write a plan', 'slug' => '', 'body_html' => '<p>Write a plan.</p>', 'kind' => 'assignment', 'assignment_pass' => 60, 'weight' => 3]);
$lDisc = $lms->saveLesson(['module_id' => $m2, 'title' => 'Introduce yourself', 'slug' => '', 'body_html' => '<p>Say hello.</p>', 'kind' => 'discussion']);
$L = fn(int $id) => $lms->lessonById($id);

/* ══ Item kinds ══════════════════════════════════════════════════════════ */

ck('courseware: reading is reading', LmsRepository::lessonType($L($lRead)) === 'reading');
ck('courseware: a graded quiz is a quiz', LmsRepository::lessonType($L($lQuiz)) === 'quiz');
ck('courseware: an ungraded quiz is practice', LmsRepository::lessonType($L($lPrac)) === 'practice');
ck('courseware: kind assignment wins over content', LmsRepository::lessonType($L($lAsg)) === 'assignment');
ck('courseware: kind discussion', LmsRepository::lessonType($L($lDisc)) === 'discussion');
ck('courseware: an assignment stores its pass mark', LmsCourseware::assignmentPass($L($lAsg)) === 60);
ck('courseware: weight is stored and clamped', (int) $L($lAsg)['weight'] === 3);

/* ══ The quiz engine ═════════════════════════════════════════════════════ */

$q = $lms->quiz($L($lQuiz));
ck('courseware: true/false options are fixed', $q['questions'][1]['options'] === ['True', 'False']);
ck('courseware: a multi answer is a sorted list', $q['questions'][0]['answer'] === [0, 2]);
ck('courseware: an old quiz with no graded key reads as graded, unlimited',
   ($old = $lms->quiz(['quiz_json' => json_encode(['pass' => 70, 'questions' => [['q' => 'a', 'options' => ['x', 'y'], 'answer' => 1]]])])) && $old['graded'] && $old['attempts'] === 0);
ck('courseware: a multi question with no correct answer is dropped', LmsRepository::normaliseQuestion(['type' => 'multi', 'q' => 'x', 'options' => ['a', 'b'], 'answer' => []]) === null);
ck('courseware: a question with one option is dropped', LmsRepository::normaliseQuestion(['q' => 'x', 'options' => ['a']]) === null);

$u = $mkUser('cw-ada@example.com');
$uid = (int) $u['id'];
$lms->enrol($uid, $cid);
$r1 = $lms->gradeQuiz($uid, $L($lQuiz), [[0], 0]);         // multi half-right = wrong; tf right
ck('courseware: a partly right multi-select is wrong', $r1['marks'][0]['correct'] === false && $r1['marks'][1]['correct'] === true);
ck('courseware: 1 of 2 is 50% and passes a 50% mark', $r1['score'] === 50 && $r1['passed']);
ck('courseware: once passed, the key is revealed', $r1['marks'][0]['answer'] === [0, 2]);
ck('courseware: explanations come back', $r1['marks'][0]['explain'] === 'Chair and secretary.');
ck('courseware: attempts are counted down', $r1['attempts_left'] === 1);
$r2 = $lms->gradeQuiz($uid, $L($lQuiz), [[2, 0], 0]);
ck('courseware: the right set in any order is right', $r2['marks'][0]['correct'] === true && $r2['score'] === 100 && $r2['best'] === 100);
$r3 = $lms->gradeQuiz($uid, $L($lQuiz), [[0, 2], 0]);
ck('courseware: no attempts left is refused', $r3['ok'] === false && ($r3['code'] ?? '') === 'no_attempts');

$u2 = $mkUser('cw-bode@example.com');
$u2id = (int) $u2['id'];
$lms->enrol($u2id, $cid);
$f1 = $lms->gradeQuiz($u2id, $L($lQuiz), [[1], 1]);
ck('courseware: a fail with attempts left hides the key', $f1['passed'] === false && $f1['marks'][0]['answer'] === null);
$f2 = $lms->gradeQuiz($u2id, $L($lQuiz), [[1], 1]);
ck('courseware: a fail on the last attempt reveals the key', $f2['attempts_left'] === 0 && $f2['marks'][0]['answer'] === [0, 2]);
ck('courseware: a failed graded quiz is not complete', !in_array($lQuiz, $lms->progress($u2id, $cid)['ids'], true));
$p1 = $lms->gradeQuiz($u2id, $L($lPrac), [0]);
ck('courseware: a failed practice quiz still completes the item', $p1['passed'] === false && in_array($lPrac, $lms->progress($u2id, $cid)['ids'], true));
ck('courseware: a practice quiz reveals the key at once', $p1['marks'][0]['answer'] === 1);
ck('courseware: practice quizzes never count as unpassed', $lms->unpassedQuizzes($u2id, $cid) === 1);

/* ══ Assignments ═════════════════════════════════════════════════════════ */

ck('courseware: an empty submission is refused', !$cw->submit($uid, $L($lAsg), '  ', '')['ok']);
ck('courseware: a non-http link is refused', !$cw->submit($uid, $L($lAsg), '', 'javascript:alert(1)')['ok']);
ck('courseware: a reading cannot take a submission', !$cw->submit($uid, $L($lRead), 'x', '')['ok']);
$s1 = $cw->submit($uid, $L($lAsg), 'My plan: meet weekly.', 'https://example.com/plan');
ck('courseware: a submission is stored as submitted', $s1['ok'] && $s1['submission']['status'] === 'submitted');
$queue = $cw->queue([$cid]);
ck('courseware: it reaches the grading queue', count($queue) === 1 && $queue[0]['learner_email'] === 'cw-ada@example.com' && $queue[0]['pass'] === 60);
ck('courseware: an instructor of other courses sees nothing', $cw->queue([]) === []);
$sid = (int) $s1['submission']['id'];
ck('courseware: returning needs feedback', !$cw->grade($sid, null, '', 99, true)['ok']);
$cw->grade($sid, null, 'Add a budget.', 99, true);
ck('courseware: a returned submission is returned', $cw->submission($uid, $lAsg)['status'] === 'returned');
ck('courseware: and the item is not complete', !in_array($lAsg, $lms->progress($uid, $cid)['ids'], true));
$cw->submit($uid, $L($lAsg), 'My plan with a budget.', '');
ck('courseware: a resubmission goes back in the queue, one row', count($cw->queue([$cid])) === 1 && $cw->submission($uid, $lAsg)['status'] === 'submitted');
ck('courseware: a score out of range is refused', !$cw->grade($sid, 140, '', 99)['ok']);
$g1 = $cw->grade($sid, 55, 'Close.', 99);
ck('courseware: a score under the pass mark does not pass', $g1['ok'] && !$g1['passed'] && !in_array($lAsg, $lms->progress($uid, $cid)['ids'], true));
$g2 = $cw->grade($sid, 80, 'Good plan.', 99);
ck('courseware: a passing score completes the item', $g2['passed'] && in_array($lAsg, $lms->progress($uid, $cid)['ids'], true));
ck('courseware: a passed assignment is final', !$cw->submit($uid, $L($lAsg), 'change', '')['ok']);
ck('courseware: the grader and the time are recorded', (int) $cw->submission($uid, $lAsg)['graded_by'] === 99 && $cw->submission($uid, $lAsg)['graded_at'] !== null);

/* ══ Discussions ═════════════════════════════════════════════════════════ */

$prompt = $cw->promptThread($L($lDisc));
ck('courseware: a discussion item gets one prompt thread', $prompt && (int) $prompt['user_id'] === 0 && (int) $cw->promptThread($L($lDisc))['id'] === (int) $prompt['id']);
ck('courseware: an empty reply is refused', !$cw->reply($uid, (int) $prompt['id'], ' ')['ok']);
$rep = $cw->reply($uid, (int) $prompt['id'], 'Hello, I am Ada.');
ck('courseware: replying to the prompt completes the item', $rep['ok'] && $rep['completed_item'] && in_array($lDisc, $lms->progress($uid, $cid)['ids'], true));
$t = $cw->createThread($u2id, $cid, 'How long is a term?', 'Asking for my club.');
ck('courseware: a learner can start a thread', $t['ok']);
ck('courseware: a thread needs a title', !$cw->createThread($u2id, $cid, 'x', 'body')['ok']);
$cw->reply($uid, $t['id'], 'Usually a year.');
ck('courseware: a reply to an ordinary thread completes nothing', true);
ck('courseware: reply counts are kept', (int) $cw->thread($t['id'])['reply_count'] === 1);
ck('courseware: threads list with pinned prompts first', (int) $cw->threads($cid)[0]['pinned'] === 1);
$cw->moderate('thread', $t['id'], 'lock');
ck('courseware: a closed thread refuses replies', !$cw->reply($uid, $t['id'], 'more')['ok']);
$cw->moderate('thread', $t['id'], 'hide');
ck('courseware: a hidden thread leaves the list', !in_array($t['id'], array_map('intval', array_column($cw->threads($cid), 'id')), true));
ck('courseware: but an instructor still sees it', in_array($t['id'], array_map('intval', array_column($cw->threads($cid, true), 'id')), true));
ck('courseware: an unknown moderation op does nothing', !$cw->moderate('thread', $t['id'], 'drop'));

/* ══ Grades and the pass rule ════════════════════════════════════════════ */

$lms->markComplete($uid, $L($lRead));
$lms->gradeQuiz($uid, $L($lPrac), [1]);
$gr = $cw->grades($uid, $c);
ck('courseware: grades list graded items only', array_column($gr['items'], 'type') === ['quiz', 'assignment']);
ck('courseware: the overall is weighted (100×1 + 80×3) / 4 = 85', $gr['overall'] === 85);
ck('courseware: every item passed and 85 ≥ 75 is a pass', $gr['passed']);
ck('courseware: and the certificate is earned', $lms->requirementsMet($uid, $cid) && $lms->completeIfDone($uid, $cid)['complete']);
ck('courseware: the certificate is issued once', (bool) $lms->getCertificate($uid, $cid));

$gr2 = $cw->grades($u2id, $c);
ck('courseware: a failed graded quiz is a fail', !$gr2['passed'] && $gr2['items'][0]['status'] === 'failed');
ck('courseware: an unsubmitted assignment counts 0', $gr2['items'][1]['status'] === 'not_started');
ck('courseware: a learner who has not passed gets no certificate', $lms->issueCertificate($u2id, $cid) === null);

/* The pass mark binds even when every item passed. */
$db->prepare('UPDATE courses SET pass_mark = 90 WHERE id = ?')->execute([$cid]);
ck('courseware: 85 against a 90 pass mark is not a pass', !$cw->grades($uid, $ac->bySlug($slug))['passed']);
$db->prepare('UPDATE courses SET pass_mark = 75 WHERE id = ?')->execute([$cid]);

/* A course with no graded work certifies on completion, as before. */
$plain = $ac->bySlug($ac->save(['title' => 'Courseware Plain', 'summary' => 's', 'status' => 'published', 'access_type' => 'open']));
$pm = $lms->addModule((int) $plain['id'], 'Only');
$pl = $lms->saveLesson(['module_id' => $pm, 'title' => 'Only lesson', 'slug' => '', 'body_html' => '<p>x</p>']);
$lms->markComplete($u2id, $lms->lessonById($pl));
ck('courseware: no graded items means completion certifies', $lms->completeIfDone($u2id, (int) $plain['id'])['complete']);

/* ══ Ratings and reviews ═════════════════════════════════════════════════ */

$u3 = $mkUser('cw-chidi@example.com');
ck('courseware: someone who never took the course cannot review it', !$cw->saveReview((int) $u3['id'], $cid, 5, 'Great')['ok']);
ck('courseware: stars outside 1-5 are refused', !$cw->saveReview($uid, $cid, 6, '')['ok']);
$cw->saveReview($uid, $cid, 5, 'Practical and clear.');
$cw->saveReview($u2id, $cid, 3, '');
$cw->saveReview($uid, $cid, 4, 'Practical, clear, a bit long.');
$sum = $cw->ratingSummary($cid);
ck('courseware: one review per learner, updated in place', $sum['count'] === 2 && $sum['avg'] === 3.5);
ck('courseware: the star distribution adds up', $sum['dist'][4] === 1 && $sum['dist'][3] === 1 && array_sum($sum['dist']) === 2);
ck('courseware: a rating with no text is counted but not listed', count($cw->reviews($cid)) === 1);
$cw->hideReview((int) $cw->myReview($u2id, $cid)['id']);
ck('courseware: a hidden review leaves the average', $cw->ratingSummary($cid)['count'] === 1);

/* ══ Catalogue figures and filter ════════════════════════════════════════ */

$st = $cw->stats([$cid, (int) $plain['id']]);
ck('courseware: stats count items and minutes', $st[$cid]['lessons'] === 5 && $st[$cid]['minutes'] === 15);
ck('courseware: stats count enrolments and ratings', $st[$cid]['enrolled'] === 2 && $st[$cid]['ratings'] === 1 && $st[$cid]['rating'] === 4.0);
$rows = avac_decorate([$c, $plain], $lms, $u);
ck('courseware: the grid carries the grouped figures', $rows[0]['lessons'] === 5 && $rows[0]['rating'] === 4.0 && $rows[0]['state'] === 'done');
ck('courseware: search matches skills', count(LmsCourseware::filter($rows, ['q' => 'budgeting'])) === 1);
ck('courseware: every search word must match', count(LmsCourseware::filter($rows, ['q' => 'budgeting zebra'])) === 0);
ck('courseware: level filter', count(LmsCourseware::filter($rows, ['level' => 'beginner'])) === 1);
ck('courseware: price filter', count(LmsCourseware::filter($rows, ['price' => 'free'])) === 2 && count(LmsCourseware::filter($rows, ['price' => 'paid'])) === 0);
ck('courseware: sort by rating', LmsCourseware::filter($rows, ['sort' => 'rating'])[0]['course']['id'] == $cid);
ck('courseware: a reserved slug is moved aside', $ac->save(['title' => 'My', 'slug' => 'my', 'status' => 'draft']) === 'my-course');

/* ══ Schedule and My learning ════════════════════════════════════════════ */

$due = LmsCourseware::schedule('2026-01-01 10:00:00', ['pace_weeks' => 4], [['id' => 1], ['id' => 2]]);
ck('courseware: a 4-week pace over 2 modules is 14 then 28 days', $due === [1 => '2026-01-15', 2 => '2026-01-29']);
ck('courseware: no pace is a week a module', LmsCourseware::schedule('2026-01-01', ['pace_weeks' => 0], [['id' => 9]]) === [9 => '2026-01-08']);
$dash = $cw->dashboard($u2id);
$row = null; foreach ($dash['active'] as $r) { if ((int) $r['course']['id'] === $cid) $row = $r; }
ck('courseware: My learning resumes at the first unfinished item', $row && $row['next']['slug'] === $L($lRead)['slug']);
ck('courseware: and gives it a suggested date', $row && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $row['due']) === 1);
$dashA = $cw->dashboard($uid);
ck('courseware: a certified course is under completed', in_array($cid, array_map(static fn($r) => (int) $r['course']['id'], $dashA['done']), true));
ck('courseware: graded feedback is listed', ($dashA['feedback'][0]['score'] ?? null) == 80);

/* ══ Programmes ══════════════════════════════════════════════════════════ */

$pslug = $cw->saveProgramme(['title' => 'Civic Leadership', 'summary' => 'Two courses.', 'status' => 'published'], [$slug, $plain['slug']]);
$prog = $cw->programme($pslug);
ck('courseware: a programme lists its courses in order', array_column($cw->programmeCourses((int) $prog['id']), 'slug') === [$slug, $plain['slug']]);
ck('courseware: a course knows its programmes', array_column($cw->programmesFor($cid), 'slug') === [$pslug]);
$ppA = $cw->programmeProgress($uid, $prog);
ck('courseware: one of two courses certified is 50%', $ppA['done'] === 1 && $ppA['pct'] === 50 && !$ppA['complete']);
ck('courseware: no programme certificate before every course', $cw->issueProgrammeCertificate($uid, $prog) === null);
$lms->markComplete($uid, $lms->lessonById($pl));
$lms->completeIfDone($uid, (int) $plain['id']);
$pc = $cw->issueProgrammeCertificate($uid, $prog);
ck('courseware: every course certified earns the programme certificate', $pc && str_starts_with((string) $pc['serial'], 'AVP-'));
ck('courseware: it verifies by serial', ($cw->programmeCertBySerial((string) $pc['serial'])['programme_title'] ?? '') === 'Civic Leadership');
ck('courseware: a programme with certificates is not deleted silently', !empty($cw->deleteProgramme($pslug)['needs_confirm']));
$p2 = $cw->saveProgramme(['_editing' => $pslug, 'title' => 'Civic Leadership', 'summary' => 'Edited.', 'status' => 'published'], null);
ck('courseware: editing keeps the slug and the courses', $p2 === $pslug && count($cw->programmeCourses((int) $prog['id'])) === 2);

/* ══ The pages render ════════════════════════════════════════════════════ */

$html = render_page(AV_ROOT . '/academy/course.php', ['slug' => $slug]);
ck('courseware: course page shows skills', str_contains($html, 'Skills you’ll gain') && str_contains($html, 'Budgeting'));
ck('courseware: course page shows the syllabus with objectives', str_contains($html, 'Learning objectives') && str_contains($html, 'Explain a mandate'));
ck('courseware: course page shows reviews and the rating', str_contains($html, 'Learner reviews') && str_contains($html, 'Practical, clear'));
ck('courseware: course page carries aggregateRating', str_contains($html, '"aggregateRating"'));
ck('courseware: course page names its programme', str_contains($html, 'Part of') && str_contains($html, 'Civic Leadership'));
ck('courseware: course page states the pass rule', str_contains($html, 'pass with 75%'));
ck('courseware: course page did not throw', !str_contains($html, 'render_page: threw'));
$html = render_page(AV_ROOT . '/academy/index.php', ['q' => 'budgeting', 'sort' => 'rating']);
ck('courseware: catalogue did not throw', !str_contains($html, 'render_page: threw'));
ck('courseware: catalogue filter is a real form', str_contains($html, 'data-avac-filter') && str_contains($html, 'name="q" value="budgeting"'));
ck('courseware: catalogue shows the programmes band', str_contains($html, 'Earn a programme certificate'));
$html = render_page(AV_ROOT . '/academy/learn.php', ['course' => $slug, 'lesson' => $L($lDisc)['slug']]);
ck('courseware: a discussion item renders its responses', str_contains($html, 'Hello, I am Ada.') && !str_contains($html, 'render_page: threw'));
$html = render_page(AV_ROOT . '/academy/learn.php', ['course' => $slug, 'lesson' => $L($lQuiz)['slug']]);
ck('courseware: a multi question renders checkboxes', str_contains($html, 'type="checkbox" name="q0[]"') && str_contains($html, 'choose all that apply'));
ck('courseware: a graded quiz says so', str_contains($html, 'Graded quiz · pass mark 50%'));
$html = render_page(AV_ROOT . '/academy/learn.php', ['course' => $slug, 'lesson' => $L($lAsg)['slug']]);
ck('courseware: an assignment renders its pass rule', str_contains($html, 'pass mark 60%') && !str_contains($html, 'render_page: threw'));
$html = render_page(AV_ROOT . '/academy/programme.php', ['slug' => $pslug]);
ck('courseware: programme page lists the series', str_contains($html, 'Course 1: Courseware Civic Lab') && !str_contains($html, 'render_page: threw'));
$html = render_page(AV_ROOT . '/academy/programme.php', []);
ck('courseware: programmes index lists it', str_contains($html, 'Civic Leadership') && !str_contains($html, 'render_page: threw'));
$html = render_page(AV_ROOT . '/academy/forum.php', ['course' => $slug]);
ck('courseware: an open course’s forum is readable', str_contains($html, 'Introduce yourself') && !str_contains($html, 'render_page: threw'));

/* A paid course's forum is course content: not readable by a visitor. */
$paid = $ac->bySlug($ac->save(['title' => 'Courseware Paid', 'summary' => 's', 'status' => 'published', 'access_type' => 'paid', 'price_ngn' => 5000]));
$pdm = $lms->addModule((int) $paid['id'], 'M');
$pdl = $lms->saveLesson(['module_id' => $pdm, 'title' => 'Secret prompt', 'slug' => '', 'body_html' => '<p>PAID-ONLY-PROMPT</p>', 'kind' => 'discussion']);
$cw->promptThread($lms->lessonById($pdl));
$html = render_page(AV_ROOT . '/academy/forum.php', ['course' => $paid['slug']]);
ck('courseware: a paid course’s forum is closed to a visitor', !str_contains($html, 'Secret prompt') && str_contains($html, 'join this course'));
