<?php
/**
 * academy/_instructor_views.php — instructors being assessed, on screen.
 *
 *   teach.php ?view=scorecard[&u=&cid=&period=]   an instructor's scorecard
 *   teach.php ?view=instructors[&…&export=csv]       every instructor (staff)
 *   teach.php ?view=feedback                         learners' course feedback to moderate (staff)
 *   teach.php ?view=observations                     observations (staff: all; instructor: own, final)
 *   teach.php ?view=observation&id=N | &new=1        one observation / a new one
 *   grades.php ?feedback=1                           a learner's feedback on the course (avin_feedback_form)
 *
 * The course filter is `cid`, never `course`: teach.php reads `course` as a
 * course slug and answers 404 for one it does not know.
 *
 * Views only. Every form posts back and is handled by
 * academy/_instructor_actions.php; whatever a button here shows or hides,
 * the library refuses what CourseTeam does not allow. Nothing on an
 * instructor-facing screen or in the CSV names a learner who gave feedback,
 * and no learner rates or scores anyone: feedback is written, and it is on
 * the course.
 */
declare(strict_types=1);
require_once __DIR__ . '/_grading_actions.php';

if (!function_exists('avin_page')) {

function avin_url(string $qs = ''): string { return academy_url('teach/') . ($qs !== '' ? '?' . $qs : ''); }

/** One figure: the value, what it was worked out from, and a flag when that is few. */
function avin_fig(array $fig, string $unit, string $of): string
{
    $v = $fig['value'] === null ? '—' : Grading::num((float) $fig['value']) . $unit;
    return '<div class="ins-fig"><span class="ins-v">' . e($v) . '</span><span class="ins-n">from ' . (int) $fig['n'] . ' ' . e($of) . '</span>'
         . ($fig['small'] ? '<span class="ins-small">Small sample</span>' : '') . '</div>';
}

/** The assessment tools' own tab bar. */
function avin_subnav(int $uid, string $view): string
{
    $tabs = ['scorecard' => 'My scorecard', 'observations' => 'Observations'];
    if (CourseTeam::canAnywhere($uid, 'view_all_scorecards')) $tabs['instructors'] = 'Instructors';
    if (CourseTeam::canAnywhere($uid, 'moderate_feedback')) $tabs['feedback'] = 'Feedback to moderate (' . count(LearnerFeedback::pending($uid)) . ')';
    $h = '<nav class="grd-tabs" aria-label="Instructor assessment"><a href="' . e(academy_url('teach/')) . '">Dashboard</a>';
    foreach ($tabs as $k => $label) {
        $on = $view === $k || ($view === 'observation' && $k === 'observations');
        $h .= '<a href="' . e(avin_url('view=' . $k)) . '"' . ($on ? ' aria-current="page" class="is-on"' : '') . '>' . e($label) . '</a>';
    }
    return $h . '</nav>';
}

function avin_page(array $user, string $view): void
{
    $uid = (int) $user['id'];
    echo '<main id="main" tabindex="-1"><div class="container teach grd ins">';
    echo avin_subnav($uid, $view) . avgr_flash_html(avgr_flash_take());
    switch ($view) {
        case 'scorecard':    avin_view_scorecard($uid); break;
        case 'instructors':  avin_view_instructors($uid); break;
        case 'feedback':     avin_view_feedback($uid); break;
        case 'observations': avin_view_observations($uid); break;
        case 'observation':  avin_view_observation($uid); break;
        default:             echo '<p class="teach-empty">Unknown page.</p>';
    }
    echo '</div></main>';
}

/* ══ The scorecard ═════════════════════════════════════════════════════ */

function avin_view_scorecard(int $uid): void
{
    $who = (int) ($_GET['u'] ?? 0) ?: $uid;
    $courseId = (int) ($_GET['cid'] ?? 0);
    $period = (string) ($_GET['period'] ?? '12m');
    $sc = Scorecard::build($who, $uid, $courseId, $period);
    if (!$sc['ok']) {
        echo '<h1 class="teach-h1">Scorecard</h1><p class="teach-empty">' . e($who === $uid ? 'Your scorecard appears once you are on a teaching team.' : 'You cannot see this scorecard. Instructors see their own; a lead sees their team\'s on the courses they lead; Academy staff see everyone\'s.') . '</p>';
        return;
    }
    $name = (string) $sc['instructor']['name'];
    echo '<h1 class="teach-h1">' . e($who === $uid ? 'Your scorecard' : 'Scorecard: ' . $name) . '</h1>';
    // Period and course: a plain GET form.
    echo '<form method="get" action="' . e(academy_url('teach/')) . '" class="grd-filter"><input type="hidden" name="view" value="scorecard">'
       . ($who !== $uid ? '<input type="hidden" name="u" value="' . $who . '">' : '')
       . '<label>Period<select name="period">';
    foreach (Scorecard::PERIODS as $k => $l) echo '<option value="' . e($k) . '"' . ($sc['period']['key'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></label><label>Course<select name="cid"><option value="0">' . e($sc['limited'] ? 'Courses you lead' : 'All courses') . '</option>';
    foreach (Scorecard::visibleCourses($uid, $who) as $c) echo '<option value="' . $c['id'] . '"' . ($courseId === $c['id'] ? ' selected' : '') . '>' . e($c['title'] . ' · ' . CourseTeam::label($c['team_role'])) . '</option>';
    echo '</select></label><button class="btn btn-pill-ghost btn-sm" type="submit">Show</button></form>';
    if ($sc['limited']) echo '<p class="grd-hint">As a course lead you see this scorecard only for the courses you lead.</p>';
    echo '<p class="grd-sub">Every figure is worked out from the record when this page loads and shows what it was worked out from. A figure from fewer than ' . Scorecard::SMALL_N . ' is flagged as a small sample — read it as such.</p>';

    // Learners' feedback — on the course, shared by its team.
    $fb = $sc['feedback'];
    echo '<section class="grd-card"><h2>Learners\' feedback on the course</h2><p class="grd-sub">Learners give written feedback on a course, not on a person. It belongs to the whole course team and is never attributed to one instructor. Staff approve each piece first, and the team reads it only once ' . LearnerFeedback::MIN_LEARNERS . ' learners on the course have given feedback, so nobody can tell who wrote what.</p>';
    echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Course</th><th scope="col">Received</th><th scope="col">Approved</th><th scope="col">Shared with the team</th></tr></thead><tbody>';
    foreach ($fb['courses'] as $f) {
        echo '<tr><td>' . e((string) $f['title']) . '</td><td>' . (int) $f['received'] . '</td><td>' . (int) $f['approved'] . '</td><td>' . (int) $f['shared']
           . ($f['threshold_met'] ? '' : ' <small>(waiting for ' . LearnerFeedback::MIN_LEARNERS . ' learners; ' . (int) $f['givers'] . ' so far)</small>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    foreach ($sc['feedback_items'] as $cid => $f) {
        if (!$f['shown'] || !$f['items']) continue;
        echo '<h3>' . e((string) $fb['courses'][$cid]['title']) . '</h3><ul class="ins-quotes">';
        foreach ($f['items'] as $a) {
            echo '<li>';
            foreach (LearnerFeedback::PROMPTS as $k => $q) if (trim((string) $a[$k]) !== '') echo '<small>' . e($q) . '</small><blockquote>' . nl2br(e((string) $a[$k])) . '</blockquote>';
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</section>';

    // Observations.
    $o = $sc['observations'];
    echo '<section class="grd-card"><div class="grd-card-h"><h2>Teaching observations</h2>';
    if (CourseTeam::canAnywhere($uid, 'observe') && $who !== $uid) echo '<a class="btn btn-pill btn-sm" href="' . e(avin_url('view=observation&new=1&u=' . $who . ($courseId ? '&cid=' . $courseId : ''))) . '">+ Record an observation</a>';
    echo '</div>';
    if (!$o['n']) echo '<p class="teach-empty">No final observations in this period.</p>';
    else {
        $l = $o['latest'];
        echo '<p class="grd-sub">' . (int) $o['n'] . ' final observation' . ($o['n'] === 1 ? '' : 's') . ', scored 1 (not yet) to 4 (excellent).' . ($o['small'] ? ' <span class="ins-small">Small sample</span>' : '') . '</p>'
           . '<div class="ins-grid">' . avin_fig($o['average'], '', $o['n'] === 1 ? 'observation' : 'observations') . '</div>'
           . '<h3>Latest — ' . e($l['observed_on']) . '</h3><ul class="ins-trend">';
        foreach ($l['scores'] as $s) echo '<li><span>' . e((string) $s['name']) . '</span><strong>' . (int) $s['score'] . '</strong><small>' . e(Observations::SCALE[(int) $s['score']] ?? '') . '</small></li>';
        echo '</ul><h3>Average per criterion</h3><ul class="ins-trend">';
        foreach ($o['criteria'] as $name => $f) echo '<li><span>' . e($name) . '</span><strong>' . e(Grading::num((float) $f['value'])) . '</strong><small>from ' . (int) $f['n'] . '</small></li>';
        echo '</ul>';
    }
    echo '</section>';

    // Marking.
    $t = $sc['turnaround'];
    echo '<section class="grd-card"><h2>Marking</h2><p class="grd-sub">Days from a learner submitting to this instructor\'s mark, on assignments and peer items they marked (live panels score in the room).</p><div class="ins-grid">'
       . '<div><span class="ins-k">Median days to mark</span>' . avin_fig($t['median'], ' days', 'marks') . '</div>'
       . '<div><span class="ins-k">90th percentile</span>' . avin_fig($t['p90'], ' days', 'marks') . '</div>'
       . '<div><span class="ins-k">Marked within ' . Scorecard::WITHIN_DAYS . ' days</span>' . avin_fig($t['within'], '%', 'marks') . '</div>'
       . '<div><span class="ins-k">Moderation rate</span>' . avin_fig($sc['moderation'], '%', 'marks') . '</div>'
       . '<div><span class="ins-k">Release lag (median)</span>' . avin_fig($sc['release_lag'], ' days', 'releases') . '</div>'
       . '</div><p class="grd-hint">Moderation rate: the share of their marks on work that was flagged for moderation or settled by an agreed score. Release lag: days from a final score to its release, for the releases they pressed.</p></section>';

    // Course outcomes.
    $oc = $sc['outcomes'];
    echo '<section class="grd-card"><h2>Course outcomes</h2><p class="grd-sub">These are the course\'s results, shared by everyone who teaches it — not a measure of one person. Learners who enrolled in the period: ' . (int) $oc['learners'] . '.</p><div class="ins-grid">'
       . '<div><span class="ins-k">Completion rate</span>' . avin_fig($oc['completion'], '%', 'learners') . '</div>';
    if ($oc['graded']) {
        echo '<div><span class="ins-k">Pass rate</span>' . avin_fig($oc['pass_rate'], '%', 'learners with a final grade') . '</div>'
           . '<div><span class="ins-k">Average course grade</span>' . avin_fig($oc['avg_grade'], '%', 'learners with a final grade') . '</div>';
    } else {
        echo '<div><span class="ins-k">Pass rate</span><p class="grd-hint">No graded work on these courses.</p></div>';
    }
    echo '</div></section>';
}

/* ══ The instructor list (staff) ═══════════════════════════════════════ */

function avin_filters(): array
{
    return ['q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80), 'course' => (int) ($_GET['cid'] ?? 0),
            'role' => (string) ($_GET['role'] ?? ''), 'attention' => !empty($_GET['attention'])];
}

function avin_instructors_csv(int $uid, $out = null): bool
{
    $dir = Scorecard::directory($uid, (string) ($_GET['period'] ?? '12m'), avin_filters());
    if (!$dir['ok']) return false;
    $own = $out === null;
    if ($own) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="afrovanguard-instructors-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
    }
    Scorecard::directoryCsv($dir, $out);
    if ($own) fclose($out);
    return true;
}

function avin_view_instructors(int $uid): void
{
    $f = avin_filters();
    $period = (string) ($_GET['period'] ?? '12m');
    $dir = Scorecard::directory($uid, $period, $f);
    echo '<h1 class="teach-h1">Instructors</h1>';
    if (!$dir['ok']) { echo '<p class="teach-empty">' . e($dir['error']) . '</p>'; return; }
    $courses = Database::pdo()->query('SELECT DISTINCT c.id, c.title FROM courses c JOIN course_instructors t ON t.course_id = c.id ORDER BY c.title')->fetchAll(PDO::FETCH_ASSOC);
    echo '<form method="get" action="' . e(academy_url('teach/')) . '" class="grd-filter" role="search"><input type="hidden" name="view" value="instructors">'
       . '<label>Name or email<input type="search" name="q" value="' . e($f['q']) . '"></label><label>Course<select name="cid"><option value="0">Any</option>';
    foreach ($courses as $c) echo '<option value="' . (int) $c['id'] . '"' . ($f['course'] === (int) $c['id'] ? ' selected' : '') . '>' . e((string) $c['title']) . '</option>';
    echo '</select></label><label>Role<select name="role"><option value="">Any</option>';
    foreach (CourseTeam::ROLES as $r) echo '<option value="' . $r . '"' . ($f['role'] === $r ? ' selected' : '') . '>' . e(CourseTeam::label($r)) . '</option>';
    echo '</select></label><label>Period<select name="period">';
    foreach (Scorecard::PERIODS as $k => $l) echo '<option value="' . e($k) . '"' . ($dir['period']['key'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></label><label class="grd-check"><input type="checkbox" name="attention" value="1"' . ($f['attention'] ? ' checked' : '') . '> Needs attention</label>'
       . '<button class="btn btn-pill-ghost btn-sm" type="submit">Filter</button></form>';
    $qs = http_build_query(['view' => 'instructors', 'export' => 'csv', 'period' => $dir['period']['key'], 'q' => $f['q'], 'cid' => $f['course'] ?: '', 'role' => $f['role'], 'attention' => $f['attention'] ? 1 : '']);
    echo '<section class="grd-card"><div class="grd-card-h"><p class="grd-sub">' . count($dir['rows']) . ' instructor' . (count($dir['rows']) === 1 ? '' : 's') . ' · ' . e($dir['period']['label'])
       . '. Needs attention: median marking above ' . Scorecard::WITHIN_DAYS . ' days, or observations averaging below 2.5. Course feedback is the courses\', shared by each team.</p>'
       . ($dir['rows'] ? '<a class="btn btn-pill-ghost btn-sm" href="' . e(avin_url($qs)) . '">↓ Export CSV</a>' : '') . '</div>';
    if (!$dir['rows']) { echo '<p class="teach-empty">Nobody matches.</p></section>'; return; }
    echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Instructor</th><th scope="col">Courses</th><th scope="col">Feedback on their courses (received / approved / shared)</th>'
       . '<th scope="col">Observations</th><th scope="col">Median days to mark</th><th scope="col">Within 7 days</th><th scope="col">Moderation rate</th><th scope="col"></th></tr></thead><tbody>';
    foreach ($dir['rows'] as $row) {
        $c = $row['card']; $id = (int) $row['user']['id'];
        $cell = static fn(array $fig, string $unit): string => $fig['value'] === null ? '—' : e(Grading::num((float) $fig['value']) . $unit) . ' <small>(' . (int) $fig['n'] . ')</small>' . ($fig['small'] ? ' <span class="ins-small">Small</span>' : '');
        echo '<tr><td><a class="r-name" href="' . e(avin_url('view=scorecard&u=' . $id . '&period=' . $dir['period']['key'])) . '">' . e((string) $row['user']['name']) . '</a><span class="r-email">' . e((string) $row['user']['email']) . '</span></td>'
           . '<td>' . count($c['courses']) . '</td>'
           . '<td>' . (int) $c['feedback']['total']['received'] . ' / ' . (int) $c['feedback']['total']['approved'] . ' / ' . (int) $c['feedback']['total']['shared'] . '</td>'
           . '<td>' . $cell($c['observations']['average'], ' / 4') . '</td><td>' . $cell($c['turnaround']['median'], '') . '</td><td>' . $cell($c['turnaround']['within'], '%') . '</td>'
           . '<td>' . $cell($c['moderation'], '%') . '</td><td>' . ($row['attention'] ? '<span class="grd-pill grd-pill--late">Needs attention</span>' : '') . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/* ══ Feedback to moderate (staff) ═════════════════════════════════════ */

function avin_view_feedback(int $uid): void
{
    echo '<h1 class="teach-h1">Feedback to moderate</h1>';
    if (!CourseTeam::canAnywhere($uid, 'moderate_feedback')) { echo '<p class="teach-empty">Only Academy staff moderate feedback.</p>'; return; }
    echo '<p class="grd-sub">Approved feedback is read by the whole course team once ' . LearnerFeedback::MIN_LEARNERS . ' learners on the course have given feedback. Where the learner allowed it, approved feedback may also be quoted on the course page with their first name and last initial. Who wrote it is not shown here either, and feedback on a course you teach is not in your queue.</p>';
    $q = LearnerFeedback::pending($uid);
    if (!$q) { echo '<p class="teach-empty">Nothing waiting.</p>'; return; }
    echo '<ul class="grd-panel">';
    foreach ($q as $x) {
        $hidden = '<input type="hidden" name="act" value="moderate"><input type="hidden" name="id" value="' . (int) $x['id'] . '">';
        echo '<li class="grd-pcard"><div class="grd-pcard-h"><strong>' . e($x['course_title']) . '</strong><span class="grd-hint">' . e($x['month']) . '</span>'
           . ($x['share'] ? '<span class="grd-pill grd-pill--published">May be quoted publicly</span>' : '') . '</div>';
        foreach (LearnerFeedback::PROMPTS as $k => $p) if (trim((string) $x['answers'][$k]) !== '') echo '<p class="grd-hint">' . e($p) . '</p><blockquote class="grd-answer">' . nl2br(e((string) $x['answers'][$k])) . '</blockquote>';
        echo '<div class="grd-actions"><form method="post" action="' . e(avin_url()) . '" class="grd-inline">' . avgr_csrf_field() . $hidden . '<input type="hidden" name="decision" value="approve"><button class="btn btn-pill btn-sm" type="submit">Approve</button></form>'
           . '<form method="post" action="' . e(avin_url()) . '" class="grd-inline">' . avgr_csrf_field() . $hidden . '<input type="hidden" name="decision" value="hide">'
           . '<label><span class="av-sr">Reason for hiding</span><select name="reason" required><option value="">Hide because…</option>';
        foreach (LearnerFeedback::HIDE_REASONS as $k => $l) echo '<option value="' . e($k) . '">' . e($l) . '</option>';
        echo '</select></label><button class="btn btn-pill-ghost btn-sm" type="submit">Hide</button></form></div></li>';
    }
    echo '</ul>';
}

/* ══ Observations ══════════════════════════════════════════════════════ */

function avin_view_observations(int $uid): void
{
    $staff = CourseTeam::canAnywhere($uid, 'observe');
    echo '<h1 class="teach-h1">' . ($staff ? 'Teaching observations' : 'Observations of your teaching') . '</h1>';
    $list = Observations::listFor($uid, $staff ? (int) ($_GET['u'] ?? 0) : $uid);
    if ($staff) {
        $pairs = Database::pdo()->query('SELECT c.id AS course_id, c.title, u.id AS user_id, u.name, t.role FROM course_instructors t JOIN courses c ON c.id = t.course_id JOIN lms_users u ON u.id = t.user_id ORDER BY c.title, u.name')->fetchAll(PDO::FETCH_ASSOC);
        echo '<section class="grd-card"><h2>Record an observation</h2><form method="get" action="' . e(academy_url('teach/')) . '" class="grd-form grd-row"><input type="hidden" name="view" value="observation"><input type="hidden" name="new" value="1">'
           . '<label class="grd-field grd-grow"><span>Who, on which course</span><select name="pair" required><option value="">Choose…</option>';
        foreach ($pairs as $p) if ((int) $p['user_id'] !== $uid) echo '<option value="' . (int) $p['course_id'] . '-' . (int) $p['user_id'] . '">' . e($p['title'] . ' — ' . $p['name'] . ' (' . CourseTeam::label((string) $p['role']) . ')') . '</option>';
        echo '</select></label><button class="btn btn-pill btn-sm" type="submit">Start</button></form></section>';
    } else {
        echo '<p class="grd-sub">Observations appear here once the observer marks them final. You can add your own reflection to each.</p>';
    }
    echo '<section class="grd-card"><h2>' . ($staff ? 'All observations' : 'Shared with you') . '</h2>';
    if (!$list) echo '<p class="teach-empty">None yet.</p>';
    else {
        echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Date</th><th scope="col">Course</th>' . ($staff ? '<th scope="col">Instructor</th><th scope="col">Observer</th>' : '') . '<th scope="col">Average</th><th scope="col">Follow-up</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach ($list as $o) {
            echo '<tr><td><a class="r-name" href="' . e(avin_url('view=observation&id=' . $o['id'])) . '">' . e($o['observed_on']) . '</a></td><td>' . e((string) $o['course_title']) . '</td>'
               . ($staff ? '<td>' . e((string) $o['instructor_name']) . '</td><td>' . e((string) $o['observer_name']) . '</td>' : '')
               . '<td>' . ($o['average'] === null ? '—' : e(Grading::num((float) $o['average'])) . ' / 4') . '</td><td>' . e($o['follow_up_on'] ?: '—') . '</td>'
               . '<td><span class="grd-pill grd-pill--' . ($o['status'] === 'final' ? 'published' : 'draft') . '">' . e($o['status']) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    if ($staff) {
        echo '<section class="grd-card"><h2>Teaching criteria</h2><p class="grd-sub">The one list every new observation is scored against, 1 to 4. A final observation keeps the criteria it was scored on.</p>'
           . '<form method="post" action="' . e(avin_url()) . '" class="grd-form">' . avgr_csrf_field() . '<input type="hidden" name="act" value="criteria">'
           . '<label class="grd-field"><span>One criterion per line</span><textarea name="criteria" rows="6">' . e(implode("\n", Observations::criteria())) . '</textarea></label>'
           . '<button class="btn btn-pill-ghost btn-sm" type="submit">Save criteria</button></form></section>';
    }
}

function avin_view_observation(int $uid): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $o = $id > 0 ? Observations::get($id) : null;
    if ($id > 0 && (!$o || !Observations::canSee($o, $uid))) { echo '<p class="teach-empty">No such observation, or it has not been shared with you yet.</p>'; return; }
    if ($o && $o['status'] === 'final') { avin_observation_read($o, $uid); return; }
    // A new observation, or a draft: the form (staff only).
    if ($o) { $courseId = $o['course_id']; $insId = $o['instructor_id']; }
    else {
        $pair = explode('-', (string) ($_GET['pair'] ?? ''));
        $courseId = (int) ($_GET['cid'] ?? ($pair[0] ?? 0)); $insId = (int) ($_GET['u'] ?? ($pair[1] ?? 0));
        if ($courseId <= 0 && $insId > 0) { $cs = CourseTeam::coursesFor($insId); $courseId = $cs ? (int) $cs[0]['id'] : 0; }
    }
    if (!CourseTeam::can($courseId, $uid, 'observe')) { echo '<p class="teach-empty">Only Academy staff record observations.</p>'; return; }
    if (CourseTeam::roleOf($courseId, $insId) === null) { echo '<p class="teach-empty">Choose someone on the course\'s teaching team.</p>'; return; }
    $db = Database::pdo();
    $c = $db->prepare('SELECT title FROM courses WHERE id = ?'); $c->execute([$courseId]);
    $u = $db->prepare('SELECT name FROM lms_users WHERE id = ?'); $u->execute([$insId]);
    echo '<h1 class="teach-h1">' . ($o ? 'Draft observation' : 'New observation') . '</h1><p class="grd-sub">' . e((string) $u->fetchColumn()) . ' · ' . e((string) $c->fetchColumn())
       . '. A draft is yours alone; marking it final shares it with the instructor and fixes it.</p>';
    $sessions = $db->prepare('SELECT s.id, s.starts_at, i.title FROM live_sessions s JOIN graded_items i ON i.id = s.item_id WHERE s.course_id = ? ORDER BY s.starts_at DESC'); $sessions->execute([$courseId]);
    $lessons = $db->prepare('SELECT id, title FROM lessons WHERE course_id = ? ORDER BY position, id'); $lessons->execute([$courseId]);
    $v = static fn(string $k, string $d = ''): string => (string) ($o[$k] ?? $d);
    $kind = $v('target_kind', 'live');
    echo '<form method="post" action="' . e(avin_url()) . '" class="grd-card grd-form">' . avgr_csrf_field()
       . '<input type="hidden" name="act" value="obs_save"><input type="hidden" name="id" value="' . ($o ? $o['id'] : 0) . '"><input type="hidden" name="course_id" value="' . $courseId . '"><input type="hidden" name="instructor_id" value="' . $insId . '">'
       . '<div class="grd-grid"><label class="grd-field"><span>Date observed</span><input type="date" name="observed_on" required value="' . e($v('observed_on', Grading::toLocal(Grading::stamp(), 'Y-m-d'))) . '"></label>'
       . '<label class="grd-field"><span>What was observed</span><select name="target_kind">';
    foreach (Observations::TARGETS as $k => $l) echo '<option value="' . $k . '"' . ($kind === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></label><label class="grd-field"><span>Live session (if a live session)</span><select name="target_live"><option value="0">—</option>';
    foreach ($sessions->fetchAll(PDO::FETCH_ASSOC) as $s) echo '<option value="' . (int) $s['id'] . '"' . ($kind === 'live' && (int) $v('target_id') === (int) $s['id'] ? ' selected' : '') . '>' . e($s['title'] . ' · ' . Grading::toLocal((string) $s['starts_at'], 'j M Y, g:i a')) . '</option>';
    echo '</select></label><label class="grd-field"><span>Lesson (if a lesson)</span><select name="target_lesson"><option value="0">—</option>';
    foreach ($lessons->fetchAll(PDO::FETCH_ASSOC) as $l) echo '<option value="' . (int) $l['id'] . '"' . ($kind === 'lesson' && (int) $v('target_id') === (int) $l['id'] ? ' selected' : '') . '>' . e((string) $l['title']) . '</option>';
    echo '</select></label></div><label class="grd-field"><span>Description (needed for "Other"; optional otherwise)</span><input name="target_note" maxlength="300" value="' . e($v('target_note')) . '"></label>'
       . '<fieldset class="grd-sub-fs"><legend>Teaching criteria, 1 (not yet) to 4 (excellent)</legend><div class="grd-grid">';
    $prev = [];
    foreach ($o['scores'] ?? [] as $s) $prev[(string) $s['name']] = (int) $s['score'];
    foreach (Observations::criteria() as $n => $name) {
        echo '<label class="grd-field"><span>' . e($name) . '</span><select name="score[' . $n . ']"><option value="">—</option>';
        foreach (Observations::SCALE as $k => $l) echo '<option value="' . $k . '"' . (($prev[$name] ?? 0) === $k ? ' selected' : '') . '>' . $k . ' — ' . e($l) . '</option>';
        echo '</select></label>';
    }
    echo '</div></fieldset>'
       . '<label class="grd-field"><span>Strengths</span><textarea name="strengths" rows="3">' . e($v('strengths')) . '</textarea></label>'
       . '<label class="grd-field"><span>Areas to develop</span><textarea name="develop" rows="3">' . e($v('develop')) . '</textarea></label>'
       . '<label class="grd-field"><span>Agreed action</span><textarea name="action_text" rows="2">' . e($v('action_text')) . '</textarea></label>'
       . '<label class="grd-field"><span>Follow-up date (both of you are reminded the day before)</span><input type="date" name="follow_up_on" value="' . e($v('follow_up_on')) . '"></label>'
       . '<label class="grd-check"><input type="checkbox" name="final" value="1"> Mark final and share with the instructor (it can no longer be changed)</label>'
       . '<div class="grd-actions"><button class="btn btn-pill btn-sm" type="submit">Save</button></div></form>';
    if ($o) echo '<form method="post" action="' . e(avin_url()) . '" class="grd-inline">' . avgr_csrf_field() . '<input type="hidden" name="act" value="obs_delete"><input type="hidden" name="id" value="' . $o['id'] . '"><button class="btn btn-pill-ghost btn-sm" type="submit">Delete this draft</button></form>';
}

function avin_observation_read(array $o, int $uid): void
{
    $target = Observations::TARGETS[$o['target_kind']] ?? 'Other';
    $db = Database::pdo();
    if ($o['target_kind'] === 'live') {
        $s = $db->prepare('SELECT s.starts_at, i.title FROM live_sessions s JOIN graded_items i ON i.id = s.item_id WHERE s.id = ?'); $s->execute([$o['target_id']]);
        if ($r = $s->fetch(PDO::FETCH_ASSOC)) $target .= ': ' . $r['title'] . ', ' . Grading::toLocal((string) $r['starts_at'], 'j M Y, g:i a');
    } elseif ($o['target_kind'] === 'lesson') {
        $s = $db->prepare('SELECT title FROM lessons WHERE id = ?'); $s->execute([$o['target_id']]);
        if ($t = $s->fetchColumn()) $target .= ': ' . $t;
    }
    if ($o['target_note'] !== '') $target .= ' — ' . $o['target_note'];
    echo '<h1 class="teach-h1">Observation, ' . e($o['observed_on']) . '</h1><p class="grd-sub">' . e((string) $o['instructor_name']) . ' · ' . e((string) $o['course_title'])
       . ' · observed by ' . e((string) $o['observer_name']) . ' · ' . e($target) . '</p>';
    echo '<section class="grd-card"><h2>Scores</h2><ul class="ins-trend">';
    foreach ($o['scores'] as $s) echo '<li><span>' . e((string) $s['name']) . '</span><strong>' . (int) $s['score'] . '</strong><small>' . e(Observations::SCALE[(int) $s['score']] ?? '') . '</small></li>';
    echo '</ul>' . ($o['average'] !== null ? '<p class="grd-hint">Average ' . e(Grading::num((float) $o['average'])) . ' of 4.</p>' : '');
    foreach (['strengths' => 'Strengths', 'develop' => 'Areas to develop', 'action_text' => 'Agreed action'] as $k => $l) {
        if (trim((string) $o[$k]) !== '') echo '<h3>' . e($l) . '</h3><div class="grd-answer">' . nl2br(e((string) $o[$k])) . '</div>';
    }
    if ($o['follow_up_on'] !== '') echo '<p class="grd-hint">Follow-up on ' . e($o['follow_up_on']) . '. You will both be reminded the day before.</p>';
    echo '</section><section class="grd-card"><h2>Reflection</h2>';
    if ($o['instructor_id'] === $uid) {
        echo '<form method="post" action="' . e(avin_url()) . '" class="grd-form">' . avgr_csrf_field() . '<input type="hidden" name="act" value="reflect"><input type="hidden" name="id" value="' . $o['id'] . '">'
           . '<label class="grd-field"><span>Your own reflection on this observation</span><textarea name="reflection" rows="4">' . e((string) $o['reflection']) . '</textarea></label>'
           . '<button class="btn btn-pill btn-sm" type="submit">Save reflection</button></form>';
    } else {
        echo trim((string) $o['reflection']) !== '' ? '<div class="grd-answer">' . nl2br(e((string) $o['reflection'])) . '</div>' : '<p class="teach-empty">The instructor has not added a reflection.</p>';
    }
    echo '</section>';
}

/* ══ The learner's side ════════════════════════════════════════════════ */

/** The invitation to give feedback, for a learner who qualifies and has not. '' otherwise. */
function avin_invite(array $course, int $uid): string
{
    if ($uid <= 0 || !LearnerFeedback::shouldInvite((int) $course['id'], $uid)) return '';
    return '<p class="grd-flash is-ok ins-invite">Tell the course team what is working and what could be clearer. <a href="' . e(academy_url($course['slug'] . '/grades/?feedback=1')) . '">Give feedback on this course →</a></p>';
}

/** grades.php?feedback=1 — the learner's feedback on the course. */
function avin_feedback_form(array $course, int $uid, string $base): void
{
    $cid = (int) $course['id'];
    echo '<p><a href="' . e($base) . '">← Grades</a></p><h1 class="teach-h1">Feedback on this course</h1>';
    $el = LearnerFeedback::eligibility($cid, $uid);
    $mine = LearnerFeedback::mine($cid, $uid);
    if (!$el['ok']) { echo '<p class="teach-empty">' . e((string) $el['error']) . '</p>'; return; }
    echo '<p class="grd-sub">Your feedback is on the course and goes to its whole teaching team — nobody is scored. The team never sees who wrote it: Academy staff read it first, and the team reads it only once ' . LearnerFeedback::MIN_LEARNERS . ' learners on the course have given feedback. Every box is optional.</p>';
    if ($mine && !$mine['editable']) {
        echo '<section class="grd-card"><p>Thank you for your feedback. It can be changed for ' . LearnerFeedback::EDIT_DAYS . ' days after it is given, so it is now fixed.</p></section>';
        return;
    }
    if ($mine) echo '<p class="grd-hint">You can change your feedback until ' . e(Grading::toLocal(gmdate('Y-m-d H:i:s', $mine['editable_until']), 'j F Y')) . '.</p>';
    echo '<form method="post" action="' . e($base . '?feedback=1') . '" class="grd-card grd-form">' . avgr_csrf_field() . '<input type="hidden" name="act" value="feedback">';
    foreach (LearnerFeedback::PROMPTS as $k => $q) {
        echo '<label class="grd-field"><span>' . e($q) . '</span><textarea name="' . e($k) . '" rows="3" maxlength="' . LearnerFeedback::MAX_TEXT . '">' . e((string) ($mine[$k] ?? '')) . '</textarea></label>';
    }
    echo '<label class="grd-check"><input type="checkbox" name="share" value="1"' . (!empty($mine['share_public']) ? ' checked' : '') . '> The Academy may quote my answer to the first question on the course page, with my first name and last initial</label>'
       . '<div class="grd-actions"><button class="btn btn-pill btn-sm" type="submit">' . ($mine ? 'Update my feedback' : 'Send my feedback') . '</button></div></form>';
}

}
