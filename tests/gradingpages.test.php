<?php
/**
 * tests/gradingpages.test.php — the grading screens, rendered and posted to.
 *
 *   • every POST handler refuses a missing or forged CSRF token before acting;
 *   • the handlers act as the signed-in user, so an assistant's crafted
 *     "release" form is refused by CourseTeam, not by a hidden button;
 *   • teach.php's tabs and the learner's grades page render, escape what
 *     people typed, and show a learner only released scores;
 *   • a submitted file is served to the learner and the team and to nobody else;
 *   • the gradebook CSV is formula-safe;
 *   • the course page lists every instructor, lead first.
 *
 * Run via tests/run.php (provides ck(), render_page()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';
require_once AV_ROOT . '/academy/_grading_actions.php';
require_once AV_ROOT . '/academy/_teach_grading.php';

$db = Database::pdo();
Grading::reset(); Grading::ensure();
Grading::setClock(null);
$csrf = av_csrf_token();

$course = gt_course('Pages Course ' . getmypid());
$crow = $db->query('SELECT * FROM courses WHERE id = ' . $course)->fetch(PDO::FETCH_ASSOC);
$lead = gt_user('Leah Lead'); $asst = gt_user('Andy Assistant'); $ins = gt_user('Ines Instructor');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $asst, 'assistant', $lead);
$learner = gt_user('=HYPERLINK("x") <b>Bold</b>'); gt_enrol($learner, $course);
$other = gt_user('Olu Other'); gt_enrol($other, $course);

/* ══ CSRF on every POST ═════════════════════════════════════════════════ */

$item = ['act' => 'item_save', 'kind' => 'assignment', 'title' => 'Essay <script>', 'weight' => 100, 'pass_score' => 50, 'rubric' => gt_rubric(), 'accepts' => ['text', 'link', 'file'], 'max_attempts' => 2];
$r = avgr_teach_action($crow, $lead, $item);
ck('pages: a teach form without a CSRF token is refused', !$r['ok'] && Grading::items($course, 'team') === []);
$r = avgr_teach_action($crow, $lead, $item + ['csrf' => 'forged.token.value']);
ck('pages: a forged token is refused too', !$r['ok'] && Grading::items($course, 'team') === []);
$r = avgr_teach_action($crow, $lead, $item + ['csrf' => $csrf]);
ck('pages: with the token, the lead creates an item', $r['ok'] && count(Grading::items($course, 'team')) === 1);
$itemId = (int) Grading::items($course, 'team')[0]['id'];
ck('pages: publish via the form', avgr_teach_action($crow, $ins, ['act' => 'publish', 'csrf' => $csrf])['ok']);
ck('pages: a learner form without a CSRF token is refused',
   !avgr_learner_action($crow, $learner, ['act' => 'submit', 'item' => $itemId, 'text' => 'x'])['ok'] && GradingWork::attemptsUsed($itemId, $learner) === 0);

/* A file upload through the learner's form (a real temp file, as PHP would hand it over). */
$tmp = tempnam(sys_get_temp_dir(), 'gtup');
file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$r = avgr_learner_action($crow, $learner, ['act' => 'submit', 'item' => $itemId, 'text' => "My <i>answer</i>\nline two", 'csrf' => $csrf],
    ['file' => ['name' => 'answer.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), '_test' => true]]);
@unlink($tmp);
ck('pages: a learner submits text and a file through the form', $r['ok'] && GradingWork::attemptsUsed($itemId, $learner) === 1);
$sub = GradingWork::queue($course, 'unmarked')[0] ?? null;
$subId = (int) ($sub['id'] ?? 0);

/* The form acts as the signed-in person: an assistant cannot release, whatever they post. */
ck('pages: an assistant can mark through the form', avgr_teach_action($crow, $asst, ['act' => 'mark', 'id' => $subId, 'score' => [8, 8], 'feedback' => 'Good <work>', 'csrf' => $csrf])['ok']);
$r = avgr_teach_action($crow, $asst, ['act' => 'release', 'id' => $itemId, 'csrf' => $csrf]);
ck('pages: an assistant posting "release" is refused', !$r['ok'] && GradingWork::submission($subId)['released_at'] === '');
ck('pages: an assistant cannot add to the team', !avgr_teach_action($crow, $asst, ['act' => 'team_role', 'id' => $other, 'role' => 'lead', 'csrf' => $csrf])['ok']);

/* ══ The learner sees only released work ════════════════════════════════ */

$slug = (string) $crow['slug'];
gt_sign_in($learner);
$html = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug]);
ck('pages: the grades page renders', str_contains($html, 'Your grades') && !str_contains($html, 'render_page: threw'));
ck('pages: an item title is escaped', str_contains($html, 'Essay &lt;script&gt;') && !str_contains($html, 'Essay <script>'));
ck('pages: a marked but unreleased item shows no score', str_contains($html, 'Marked — awaiting release') && !str_contains($html, '80%'));
$detail = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug, 'item' => (string) $itemId]);
ck('pages: the item page hides the unreleased feedback', !str_contains($detail, 'Good &lt;work&gt;') && str_contains($detail, 'when your instructor releases it'));
ck('pages: the learner\'s own answer is escaped', str_contains($detail, 'My &lt;i&gt;answer&lt;/i&gt;'));
ck('pages: resubmission is offered within attempts, saying the latest marked attempt counts later', str_contains($detail, 'Submit attempt 2'));
avgr_teach_action($crow, $ins, ['act' => 'release', 'id' => $itemId, 'csrf' => $csrf]);
$detail = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug, 'item' => (string) $itemId]);
ck('pages: after release the score and escaped feedback show', str_contains($detail, '80%') && str_contains($detail, 'Good &lt;work&gt;'));
ck('pages: and the page says the latest marked attempt counts', str_contains($detail, 'latest marked attempt counts'));

/* ══ The file is served to the learner and the team only ════════════════ */

$file = static function (int $uid) use ($subId): string {
    gt_sign_in($uid);
    return render_page(AV_ROOT . '/academy/submission-file.php', ['id' => (string) $subId]);
};
ck('pages: the learner can download their file', str_starts_with($file($learner), '%PDF-1.4'));
ck('pages: an assistant on the team can', str_starts_with($file($asst), '%PDF-1.4'));
ck('pages: another learner gets a 404', $file($other) === 'Not found.');
ck('pages: nobody signed in gets a 404', $file(0) === 'Not found.');

/* ══ teach.php's tabs render for the team ═══════════════════════════════ */

gt_sign_in($lead);
foreach (['items' => 'Graded items', 'marking' => 'Marking queue', 'gradebook' => 'Gradebook', 'team' => 'Teaching team'] as $tab => $want) {
    $h = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => $tab]);
    ck("pages: teach.php ?tab=$tab renders", str_contains($h, $want) && !str_contains($h, 'render_page: threw'));
}
$h = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'marking', 'sub' => (string) $subId]);
ck('pages: the marking screen shows the rubric and the submission', str_contains($h, 'name="score[1]"') && str_contains($h, 'answer.pdf'));
ck('pages: every form on the marking screen carries a CSRF field',
   substr_count($h, '<form method="post"') === substr_count($h, 'name="csrf"') && substr_count($h, '<form method="post"') > 0);
gt_sign_in($asst);
$h = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'marking']);
ck('pages: an assistant sees no release button', !str_contains($h, 'name="act" value="release"'));
$h = render_page(AV_ROOT . '/academy/teach.php', []);
ck('pages: an assistant (not an instructor by role) still reaches the console', str_contains($h, (string) $crow['title']));
gt_sign_in(0);

/* ══ Gradebook CSV is formula-safe ══════════════════════════════════════ */

$mem = fopen('php://memory', 'w+');
avgr_gradebook_csv($crow, $mem);
rewind($mem); $csv = (string) stream_get_contents($mem); fclose($mem);
ck('pages: the gradebook CSV lists the item with its weight', str_contains($csv, 'Essay <script> (100%)'));
ck('pages: a learner name starting with = is neutralised', str_contains($csv, "'=HYPERLINK") && !preg_match('/(^|,)"?=HYPERLINK/m', $csv));
ck('pages: the CSV carries the grade and result', str_contains($csv, ',80,70,Passed'));

/* ══ The course page shows the whole team, lead first ═══════════════════ */

$cp = render_page(AV_ROOT . '/academy/course.php', ['slug' => $slug]);
$pl = strpos($cp, 'Leah Lead'); $pi = strpos($cp, 'Ines Instructor'); $pa = strpos($cp, 'Andy Assistant');
ck('pages: the course page names every instructor', $pl !== false && $pi !== false && $pa !== false);
ck('pages: lead first, then instructor, then assistant', $pl < $pi && $pi < $pa && str_contains($cp, 'Lead instructor'));

// Leave nothing in the private store.
$__s = GradingWork::submission($subId);
if ($__s && ($p = GradingWork::filePath($__s))) @unlink($p);
