<?php
/**
 * tests/instructorpages.test.php — instructors being assessed, on screen:
 * the learner's feedback form, the course page's testimonials, the scorecard, the
 * instructor list and its CSV, the moderation queue and observations —
 * rendered and posted to. Plus the schema: the runtime DDL and every schema
 * file declare the same tables.
 *
 * Run via tests/run.php (provides ck(), render_page()).
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading.php';
require_once AV_ROOT . '/academy/_instructor_actions.php';
require_once AV_ROOT . '/academy/_instructor_views.php';

$db = Database::pdo();
Grading::reset(); Grading::ensure(); LearnerFeedback::reset(); LearnerFeedback::ensure(); Observations::reset(); Observations::ensure();
Grading::setClock(null);
$csrf = av_csrf_token();
$lms = new LmsRepository();

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
$runtime = $tablesIn(LearnerFeedback::ddl() . "\n" . Observations::ddl());
ck('ipages: the runtime DDL covers every table the classes name', array_diff(array_merge(LearnerFeedback::TABLES, Observations::TABLES), array_keys($runtime)) === [] && count($runtime) === 3);
foreach (['schema.sql', 'schema.mysql.sql', 'schema.pgsql.sql'] as $f) {
    $file = $tablesIn((string) file_get_contents(AV_ROOT . '/db/' . $f));
    $same = true;
    foreach ($runtime as $t => $cols) if (($file[$t] ?? null) !== $cols) $same = false;
    ck("ipages: db/$f declares every feedback and observation table with the runtime columns", $same);
}
foreach (['idx_course_feedback_state', 'idx_observations_instructor', 'idx_observations_followup'] as $ix) {
    ck("ipages: index $ix exists", (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='$ix'")->fetchColumn());
    ck("ipages: index $ix is in db/schema.mysql.sql without IF NOT EXISTS", (bool) preg_match('/CREATE INDEX ' . $ix . ' ON/', (string) file_get_contents(AV_ROOT . '/db/schema.mysql.sql')));
}
ck('ipages: no TEXT column is indexed', !preg_match('/INDEX[^;]*\((?:[^)]*\b(helped|clearer|anything_else|scores_json|strengths|develop|action_text|reflection)\b)/', LearnerFeedback::ddl() . Observations::ddl()));

/* ── Fixtures: one course, a team, learners who qualify ── */
$course = gt_course('Rated Course ' . getmypid());
$crow = $db->query('SELECT * FROM courses WHERE id = ' . $course)->fetch(PDO::FETCH_ASSOC);
$slug = (string) $crow['slug'];
$lead = gt_user('Lara Lead'); $ins = gt_user('=HYPERLINK("x") Ivan'); $col = gt_user('Cole Colleague');
$coord = gt_user('Coral Coordinator', 'coordinator');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $col, 'instructor', $lead);
$mid = $lms->addModule($course, 'M');
$lesson = $lms->lessonById($lms->saveLesson(['module_id' => $mid, 'slug' => 'ip-' . getmypid(), 'title' => 'Only lesson', 'body_html' => '<p>x</p>', 'video_url' => '']));
$learners = [];
foreach (['Nkem Okafor', 'Tobi Adeyemi', 'Zara Bako'] as $n) { $u = gt_user($n); gt_enrol($u, $course); $lms->markComplete($u, $lesson); $learners[] = $u; }
$fresh = gt_user('Fola Fresh'); gt_enrol($fresh, $course);

/* ══ The learner's side ═════════════════════════════════════════════════ */

gt_sign_in($learners[0]);
$cp = render_page(AV_ROOT . '/academy/course.php', ['slug' => $slug]);
ck('ipages: the course page invites a qualifying learner to give feedback', str_contains($cp, 'grades/?feedback=1') && !str_contains($cp, 'render_page: threw'));
$form = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug, 'feedback' => '1']);
ck('ipages: the feedback form renders on a course with no graded work', str_contains($form, 'Feedback on this course') && str_contains($form, 'name="helped"'));
ck('ipages: it asks the three course prompts and nothing else', substr_count($form, '<textarea name=') === 3 && str_contains($form, 'What could be clearer?'));
ck('ipages: no stars, no scores, no instructor to address', !str_contains($form, 'name="stars"') && !str_contains($form, 'name="ins[') && !str_contains($form, 'type="radio"') && !str_contains($form, '<select'));
ck('ipages: quoting publicly is a box, unticked by default', str_contains($form, 'name="share" value="1">'));
ck('ipages: it says the team never sees who wrote it', str_contains($form, 'never sees who wrote it'));
ck('ipages: every form on it carries a CSRF field', substr_count($form, '<form method="post"') === substr_count($form, 'name="csrf"') && substr_count($form, '<form method="post"') === 1);
$bad = avin_feedback_action($crow, $learners[0], ['helped' => 'x']);
ck('ipages: feedback without a CSRF token is refused', !$bad['ok'] && LearnerFeedback::mine($course, $learners[0]) === null);
$r = avin_feedback_action($crow, $learners[0], ['csrf' => $csrf, 'helped' => 'Loved it <script>alert(1)</script>', 'clearer' => 'Lara could <b>slow down</b>', 'share' => '1']);
ck('ipages: with the token, the feedback is saved', $r['ok'] && LearnerFeedback::mine($course, $learners[0]) !== null);
$cp = render_page(AV_ROOT . '/academy/course.php', ['slug' => $slug]);
ck('ipages: once given, no invitation', !str_contains($cp, 'grades/?feedback=1'));
ck('ipages: unapproved feedback is not on the course page', !str_contains($cp, 'Loved it'));
gt_sign_in($fresh);
$form = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug, 'feedback' => '1']);
ck('ipages: a learner who does not qualify is told why, and gets no form', str_contains($form, 'half its lessons') && !str_contains($form, 'name="helped"'));
ck('ipages: posting anyway is refused', !avin_feedback_action($crow, $fresh, ['csrf' => $csrf, 'helped' => 'x'])['ok']);
foreach ([1, 2] as $k) LearnerFeedback::save($course, $learners[$k], ['anything_else' => 'Fine course ' . $k]);

/* ══ Staff moderate; the course page then shows the testimonial ═════════ */

gt_sign_in($ins);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'feedback']);
ck('ipages: an instructor has no moderation queue', str_contains($h, 'Only Academy staff moderate') && !str_contains($h, 'Loved it'));
$fid = (int) LearnerFeedback::mine($course, $learners[0])['id'];
ck('ipages: and cannot moderate by posting', !avin_action($ins, ['csrf' => $csrf, 'act' => 'moderate', 'id' => $fid, 'decision' => 'approve'])['ok']);
gt_sign_in($coord);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'feedback']);
ck('ipages: the coordinator sees the queue, escaped', str_contains($h, 'Loved it &lt;script&gt;') && !str_contains($h, '<script>alert(1)'));
ck('ipages: the queue does not name the learner, and says when one allowed quoting', !str_contains($h, 'Nkem') && str_contains($h, 'May be quoted publicly'));
ck('ipages: every form on the queue carries a CSRF field', substr_count($h, '<form method="post"') === substr_count($h, 'name="csrf"') && substr_count($h, '<form method="post"') >= 6);
ck('ipages: moderating without a token is refused', !avin_action($coord, ['act' => 'moderate', 'id' => $fid, 'decision' => 'approve'])['ok']);
ck('ipages: hiding without a reason is refused', !avin_action($coord, ['csrf' => $csrf, 'act' => 'moderate', 'id' => $fid, 'decision' => 'hide'])['ok']);
ck('ipages: approve it', avin_action($coord, ['csrf' => $csrf, 'act' => 'moderate', 'id' => $fid, 'decision' => 'approve'])['ok']);
gt_sign_in(0);
$cp = render_page(AV_ROOT . '/academy/course.php', ['slug' => $slug]);
ck('ipages: the permitted quote is public, escaped, as "Nkem O.", shared with permission', str_contains($cp, 'Loved it &lt;script&gt;') && str_contains($cp, 'Nkem O.') && str_contains($cp, 'Shared with permission') && !str_contains($cp, 'Nkem Okafor'));
ck('ipages: what they wrote for the team is not', !str_contains($cp, 'slow down'));
ck('ipages: and the page shows no stars or averages', !str_contains($cp, '★') && !str_contains($cp, 'learner ratings'));

/* ══ Scorecards ═════════════════════════════════════════════════════════ */

gt_sign_in($lead);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'scorecard']);
ck('ipages: the lead\'s own scorecard renders', str_contains($h, 'Your scorecard') && str_contains($h, 'Marking') && !str_contains($h, 'render_page: threw'));
ck('ipages: it shows the course\'s approved feedback, escaped, as the course\'s', str_contains($h, 'Lara could &lt;b&gt;slow down&lt;/b&gt;') && str_contains($h, 'Learners\' feedback on the course') && str_contains($h, 'never attributed to one instructor'));
$leak = false;
foreach ($learners as $u) { $row = $db->query("SELECT name, email FROM lms_users WHERE id = $u")->fetch(PDO::FETCH_ASSOC); if (str_contains($h, $row['name']) || str_contains($h, $row['email']) || str_contains($h, explode(' ', $row['name'])[0])) $leak = true; }
ck('ipages: no rater is named anywhere on the instructor\'s page', !$leak);
ck('ipages: outcomes are labelled as the course\'s, not personal', str_contains($h, 'not a measure of one person'));
ck('ipages: the figures show their counts', str_contains($h, '<td>3</td><td>1</td><td>1</td>') && str_contains($h, 'from 0 marks'));
ck('ipages: no rating figures anywhere', !str_contains($h, 'Not enough ratings') && !str_contains($h, 'Learner ratings'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'scorecard', 'u' => (string) $ins, 'cid' => (string) $course]);
ck('ipages: the lead sees a team member\'s scorecard on their course', str_contains($h, 'Scorecard: =HYPERLINK'));
ck('ipages: with the same course feedback the lead sees — it is the course\'s', str_contains($h, 'Lara could &lt;b&gt;slow down&lt;/b&gt;'));
$team = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'team']);
ck('ipages: the team tab links the lead to each member\'s scorecard', str_contains($team, 'view=scorecard&amp;u=' . $ins . '&amp;cid=' . $course));
gt_sign_in($ins);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'scorecard', 'u' => (string) $col]);
ck('ipages: an instructor cannot open a colleague\'s scorecard', str_contains($h, 'You cannot see this scorecard') && !str_contains($h, 'Marking'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'scorecard', 'u' => (string) $lead]);
ck('ipages: nor the lead\'s', str_contains($h, 'You cannot see this scorecard'));
$team = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'team']);
ck('ipages: on the team tab an instructor gets a scorecard link for themselves only', str_contains($team, 'u=' . $ins . '&amp;') && !str_contains($team, 'u=' . $col . '&amp;') && !str_contains($team, 'u=' . $lead . '&amp;'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'instructors']);
ck('ipages: an instructor cannot open the instructor list', str_contains($h, 'Only Academy staff see every instructor'));
$mem = fopen('php://memory', 'w+');
ck('ipages: nor export it', avin_instructors_csv($ins, $mem) === false);
fclose($mem);

/* ══ The instructor list (staff) and its CSV ════════════════════════════ */

gt_sign_in($coord);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'instructors', 'cid' => (string) $course]);
ck('ipages: the coordinator sees the list with the team', str_contains($h, 'Lara Lead') && str_contains($h, 'Cole Colleague') && str_contains($h, 'Export CSV'));
ck('ipages: a name typed as a formula is escaped on the page', str_contains($h, '=HYPERLINK(&quot;x&quot;) Ivan'));
$_GET = ['cid' => (string) $course];
$mem = fopen('php://memory', 'w+');
ck('ipages: the coordinator exports it', avin_instructors_csv($coord, $mem) === true);
rewind($mem); $csv = (string) stream_get_contents($mem); fclose($mem);
$_GET = [];
ck('ipages: a name starting with = is neutralised in the CSV', str_contains($csv, "'=HYPERLINK") && !preg_match('/(^|,)"?=HYPERLINK/m', $csv));
ck('ipages: the CSV names no learner', !str_contains($csv, 'Nkem') && !str_contains($csv, 'Tobi') && !str_contains($csv, 'Zara'));
ck('ipages: the lead\'s row carries the course feedback counts (3 received, 1 approved, 1 shared)', (bool) preg_match('/"?Lara Lead"?,[^\n]*,3,1,1,/', $csv));
ck('ipages: and no feedback text', !str_contains($csv, 'slow down') && !str_contains($csv, 'Loved it'));

/* ══ Observations ═══════════════════════════════════════════════════════ */

$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observation', 'new' => '1', 'u' => (string) $col, 'cid' => (string) $course]);
ck('ipages: staff get the observation form with every criterion', str_contains($h, 'New observation') && substr_count($h, 'name="score[') === count(Observations::criteria()));
ck('ipages: it offers the course\'s lessons', str_contains($h, 'Only lesson'));
$post = ['csrf' => $csrf, 'act' => 'obs_save', 'course_id' => $course, 'instructor_id' => $col, 'observed_on' => '2026-11-12', 'target_kind' => 'lesson',
         'target_lesson' => (int) $lesson['id'], 'score' => [3, 3, 4, 2, 3], 'strengths' => 'Calm <em>and</em> clear', 'develop' => 'Pace', 'action_text' => 'Shorter segments', 'follow_up_on' => '2026-11-30'];
ck('ipages: an observation without a token is refused', !avin_action($coord, ['csrf' => ''] + $post)['ok']);
$r = avin_action($coord, $post);
ck('ipages: the coordinator saves a draft through the form', $r['ok'] && str_contains($r['to'], 'view=observation&id='));
parse_str($r['to'], $q); $oid = (int) $q['id'];
ck('ipages: the lesson picked is the one recorded', Observations::get($oid)['target_id'] === (int) $lesson['id'] && Observations::get($oid)['status'] === 'draft');
gt_sign_in($col);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observation', 'id' => (string) $oid]);
ck('ipages: the instructor cannot open a draft', str_contains($h, 'has not been shared with you yet') && !str_contains($h, 'Calm'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observations']);
ck('ipages: nor see it listed', !str_contains($h, '2026-11-12'));
ck('ipages: a crafted save by the instructor is refused', !avin_action($col, ['id' => $oid, 'final' => 1] + $post)['ok']);
gt_sign_in($coord);
ck('ipages: the coordinator marks it final', avin_action($coord, ['id' => $oid, 'final' => '1'] + $post)['ok'] && Observations::get($oid)['status'] === 'final');
gt_sign_in($col);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observation', 'id' => (string) $oid]);
ck('ipages: now the instructor reads it, escaped', str_contains($h, 'Calm &lt;em&gt;and&lt;/em&gt; clear') && str_contains($h, 'Only lesson'));
ck('ipages: with a reflection form', str_contains($h, 'name="reflection"') && substr_count($h, '<form method="post"') === substr_count($h, 'name="csrf"'));
ck('ipages: the reflection saves', avin_action($col, ['csrf' => $csrf, 'act' => 'reflect', 'id' => $oid, 'reflection' => 'Fair <i>points</i>'])['ok']);
gt_sign_in($ins);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observation', 'id' => (string) $oid]);
ck('ipages: a colleague cannot read it', str_contains($h, 'has not been shared with you'));
gt_sign_in($coord);
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observation', 'id' => (string) $oid]);
ck('ipages: staff read the instructor\'s reflection, escaped, with no form to change it', str_contains($h, 'Fair &lt;i&gt;points&lt;/i&gt;') && !str_contains($h, 'name="reflection"'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'scorecard', 'u' => (string) $col]);
ck('ipages: the final observation is on the scorecard', str_contains($h, '1 final observation') && str_contains($h, 'Latest — 2026-11-12'));
$h = render_page(AV_ROOT . '/academy/teach.php', ['view' => 'observations']);
ck('ipages: staff see the criteria editor and the list', str_contains($h, 'name="criteria"') && str_contains($h, '2026-11-12'));
$h = render_page(AV_ROOT . '/academy/teach.php', []);
ck('ipages: the dashboard links the assessment pages for staff', str_contains($h, 'view=instructors') && str_contains($h, 'view=feedback') && str_contains($h, 'view=scorecard'));
gt_sign_in(0);
