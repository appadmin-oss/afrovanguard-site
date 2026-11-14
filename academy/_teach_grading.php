<?php
/**
 * academy/_teach_grading.php — teach.php's grading tabs: graded items, the
 * marking queue and marking screen, the gradebook, the teaching team and the
 * live-session panel screen.
 *
 * Views only. Every form posts back to teach.php, which hands it to
 * avgr_teach_action() (academy/_grading_actions.php); every button shown or
 * hidden here is a convenience — the library refuses what CourseTeam::can()
 * does not allow, whatever a crafted form sends.
 */
declare(strict_types=1);

if (!function_exists('avgr_tabs')) {

/** The course's tab bar. */
function avgr_tabs(array $course, string $tab, int $uid): string
{
    $base = academy_url('teach/' . $course['slug'] . '/');
    $tabs = ['roster' => 'Learners', 'items' => 'Graded items', 'marking' => 'Marking', 'gradebook' => 'Gradebook', 'team' => 'Team'];
    $h = '<nav class="grd-tabs" aria-label="Course tools">';
    foreach ($tabs as $k => $label) {
        $h .= '<a href="' . e($base . ($k === 'roster' ? '' : '?tab=' . $k)) . '"' . ($tab === $k || ($tab === 'live' && $k === 'items') ? ' aria-current="page" class="is-on"' : '') . '>' . e($label) . '</a>';
    }
    $role = CourseTeam::roleOf((int) $course['id'], $uid);
    $h .= '<span class="grd-you">' . e($role ? 'You: ' . CourseTeam::label($role) : 'You: Academy staff') . '</span>';
    return $h . '</nav>';
}

function avgr_url(array $course, string $qs = ''): string
{
    return academy_url('teach/' . $course['slug'] . '/') . ($qs !== '' ? '?' . $qs : '');
}

/** Local datetime-local value for a stored UTC stamp. */
function avgr_dt(string $utc): string { return $utc === '' ? '' : Grading::toLocal($utc, 'Y-m-d\TH:i'); }

/** A one-field POST form button. */
function avgr_button(array $course, string $act, int $id, string $label, string $tab, string $class = 'btn btn-pill-ghost btn-sm', array $extra = []): string
{
    $h = '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-inline">' . avgr_csrf_field()
       . '<input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="tab" value="' . e($tab) . '">';
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    return $h . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}

function avgr_teach_page(array $course, array $user, string $tab): void
{
    $uid = (int) $user['id'];
    $cid = (int) $course['id'];
    $flash = avgr_flash_html(avgr_flash_take());
    echo '<main id="main" tabindex="-1"><div class="container teach grd">';
    echo '<nav class="breadcrumb"><a href="' . e(academy_url('teach/')) . '">Dashboard</a><span class="sep">/</span><span>' . e((string) $course['title']) . '</span></nav>';
    echo '<h1 class="teach-h1">' . e((string) $course['title']) . '</h1>';
    echo avgr_tabs($course, $tab, $uid) . $flash;
    switch ($tab) {
        case 'items':     avgr_view_items($course, $uid); break;
        case 'marking':   avgr_view_marking($course, $uid); break;
        case 'gradebook': avgr_view_gradebook($course, $uid); break;
        case 'team':      avgr_view_team($course, $uid); break;
        case 'live':      avgr_view_live($course, $uid); break;
        default:          echo '<p class="teach-empty">Unknown tab.</p>';
    }
    echo '</div></main>';
}

/* ══ Graded items ══════════════════════════════════════════════════════ */

function avgr_view_items(array $course, int $uid): void
{
    $cid = (int) $course['id'];
    $canManage = CourseTeam::can($cid, $uid, 'manage_items');
    $editId = (int) ($_GET['item'] ?? 0);
    if ($editId > 0 || !empty($_GET['new'])) {
        $item = $editId > 0 ? Grading::item($editId) : null;
        if ($editId > 0 && (!$item || (int) $item['course_id'] !== $cid)) { echo '<p class="teach-empty">No such item.</p>'; return; }
        avgr_item_form($course, $item, $uid, $canManage);
        return;
    }
    $items = Grading::items($cid, 'all');
    $team = array_values(array_filter($items, static fn($i) => $i['status'] !== 'retired'));
    $total = (int) array_sum(array_map(static fn($i) => (int) $i['weight'], $team));
    $drafts = count(array_filter($team, static fn($i) => $i['status'] === 'draft'));
    echo '<section class="grd-card"><div class="grd-card-h"><h2>Graded items</h2>';
    if ($canManage) echo '<a class="btn btn-pill btn-sm" href="' . e(avgr_url($course, 'tab=items&new=1')) . '">+ New item</a>';
    echo '</div><p class="grd-sub">Weights add to <strong>' . $total . '</strong> of 100. ' . ($drafts ? $drafts . ' in draft — learners cannot see drafts.' : 'Nothing in draft.') . '</p>';
    if (!$items) { echo '<p class="teach-empty">No graded items yet. Without any, this course keeps its certificate rule: every lesson complete.</p></section>'; }
    else {
        echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Item</th><th scope="col">Kind</th><th scope="col">Weight</th><th scope="col">Pass</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead><tbody>';
        foreach ($items as $i) {
            echo '<tr><td><a class="r-name" href="' . e(avgr_url($course, 'tab=items&item=' . (int) $i['id'])) . '">' . e((string) $i['title']) . '</a></td>'
               . '<td>' . e(Grading::kindLabel((string) $i['kind'])) . '</td><td>' . (int) $i['weight'] . '%</td><td>' . (int) $i['pass_score'] . '%</td>'
               . '<td>' . e($i['due_at'] !== '' ? Grading::toLocal((string) $i['due_at']) : '—') . '</td>'
               . '<td><span class="grd-pill grd-pill--' . e((string) $i['status']) . '">' . e((string) $i['status']) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
        if ($canManage && $drafts) {
            echo '<div class="grd-actions">' . avgr_button($course, 'publish', 0, 'Publish ' . $drafts . ' draft' . ($drafts === 1 ? '' : 's'), 'items', 'btn btn-pill btn-sm')
               . '<span class="grd-hint">Publishing needs the weights to add to exactly 100.</span></div>';
        }
        echo '</section>';
        if (CourseTeam::can($cid, $uid, 'change_weights') && $team) {
            echo '<section class="grd-card"><h2>Weights</h2><form method="post" action="' . e(avgr_url($course)) . '" class="grd-form">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="weights"><input type="hidden" name="tab" value="items"><div class="grd-weights">';
            foreach ($team as $i) {
                echo '<label><span>' . e((string) $i['title']) . '</span><input class="grd-num" type="number" min="0" max="100" step="1" name="w[' . (int) $i['id'] . ']" value="' . (int) $i['weight'] . '"> %</label>';
            }
            echo '</div><label class="grd-field"><span>Reason (needed when learners have already been graded on an item whose weight changes; recorded)</span><input name="reason" maxlength="300"></label>'
               . '<button type="submit" class="btn btn-pill-ghost btn-sm">Save weights</button></form></section>';
        }
    }
    echo '<section class="grd-card"><h2>Pass mark</h2>';
    $pm = Grading::passMark($cid);
    if (CourseTeam::can($cid, $uid, 'set_pass_mark')) {
        echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form grd-row">' . avgr_csrf_field()
           . '<input type="hidden" name="act" value="pass_mark"><input type="hidden" name="tab" value="items">'
           . '<label class="grd-field"><span>A learner passes the course with a weighted grade of at least</span><input class="grd-num" type="number" min="1" max="100" name="pass_mark" value="' . $pm . '"> %</label>'
           . '<button type="submit" class="btn btn-pill-ghost btn-sm">Save</button></form>';
    } else {
        echo '<p>' . $pm . '% — and every graded item passed. The course lead sets it.</p>';
    }
    echo '</section>';
}

function avgr_item_form(array $course, ?array $item, int $uid, bool $canManage): void
{
    $cid = (int) $course['id'];
    $v = static fn(string $k, $def = '') => $item[$k] ?? $def;
    $published = $item && $item['status'] === 'published';
    $kind = (string) $v('kind', (string) ($_GET['kind'] ?? 'assignment'));
    $lessons = Database::pdo()->prepare("SELECT id, title FROM lessons WHERE course_id = ? AND quiz_json IS NOT NULL AND quiz_json <> '' ORDER BY position, id");
    $lessons->execute([$cid]);
    $lessons = $lessons->fetchAll(PDO::FETCH_ASSOC);
    $used = $item ? Grading::usage($item) : 0;
    echo '<p><a href="' . e(avgr_url($course, 'tab=items')) . '">← All graded items</a></p>';
    echo '<section class="grd-card"><h2>' . e($item ? (string) $item['title'] : 'New graded item') . '</h2>';
    if ($item) echo '<p class="grd-sub">' . e(Grading::kindLabel($kind)) . ' · ' . e((string) $item['status']) . ($used ? ' · used by ' . $used . ($kind === 'quiz' ? ' quiz attempt(s)' : ' submission(s) or booking(s)') : '') . '</p>';
    if (!$canManage) { echo '<p class="grd-hint">Only a lead or instructor edits items.</p>'; }
    $dis = $canManage && (!$item || $item['status'] !== 'retired') ? '' : ' disabled';
    echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form">' . avgr_csrf_field()
       . '<input type="hidden" name="act" value="item_save"><input type="hidden" name="tab" value="items"><input type="hidden" name="id" value="' . (int) ($item['id'] ?? 0) . '"><fieldset' . $dis . '>';
    if (!$item) {
        echo '<label class="grd-field"><span>Kind</span><select name="kind">';
        foreach (Grading::KINDS as $k) echo '<option value="' . e($k) . '"' . ($k === $kind ? ' selected' : '') . '>' . e(Grading::kindLabel($k)) . '</option>';
        echo '</select></label>';
    }
    echo '<label class="grd-field"><span>Title</span><input name="title" required maxlength="191" value="' . e((string) $v('title')) . '"></label>'
       . '<label class="grd-field"><span>Instructions for learners</span><textarea name="instructions" rows="4">' . e((string) $v('instructions')) . '</textarea></label>'
       . '<div class="grd-grid">'
       . ($published ? '<p class="grd-field"><span>Weight</span><strong>' . (int) $item['weight'] . '%</strong> <small>change it under Weights</small></p>'
                     : '<label class="grd-field"><span>Weight (% of the course grade)</span><input class="grd-num" type="number" min="0" max="100" name="weight" value="' . (int) $v('weight', 0) . '"></label>')
       . '<label class="grd-field"><span>Passing score (%)</span><input class="grd-num" type="number" min="0" max="100" name="pass_score" value="' . (int) $v('pass_score', 50) . '"></label>'
       . '<label class="grd-field"><span>Due (Lagos time, optional)</span><input type="datetime-local" name="due_at" value="' . e(avgr_dt((string) $v('due_at'))) . '"></label>'
       . '<label class="grd-field"><span>Late window (hours after due)</span><input class="grd-num" type="number" min="0" max="720" name="late_hours" value="' . (int) $v('late_hours', 0) . '"></label>'
       . '<label class="grd-field"><span>Late penalty (%)</span><input class="grd-num" type="number" min="0" max="100" name="late_penalty" value="' . (int) $v('late_penalty', 0) . '"></label>'
       . '<label class="grd-field"><span>Attempts' . ($kind === 'quiz' ? ' (0 = unlimited)' : '') . '</span><input class="grd-num" type="number" min="0" max="20" name="max_attempts" value="' . (int) $v('max_attempts', 1) . '"></label>'
       . '<label class="grd-field"><span>Moderation threshold (% of the maximum)</span><input class="grd-num" type="number" min="0" max="100" name="moderation_pct" value="' . (int) $v('moderation_pct', Grading::DEFAULT_MODERATION) . '"></label>'
       . '</div>';
    echo '<fieldset class="grd-sub-fs"><legend>Quiz items</legend><label class="grd-field"><span>Lesson quiz that counts</span><select name="lesson_id"><option value="0">—</option>';
    foreach ($lessons as $l) echo '<option value="' . (int) $l['id'] . '"' . ((int) $v('lesson_id', 0) === (int) $l['id'] ? ' selected' : '') . '>' . e((string) $l['title']) . '</option>';
    echo '</select></label></fieldset>';
    echo '<fieldset class="grd-sub-fs"><legend>Assignments, peer review and live assessments</legend>'
       . '<label class="grd-field"><span>Rubric — one criterion per line: <code>Name | max points | 0: descriptor; 5: descriptor</code></span><textarea name="rubric" rows="5">' . e($item ? Grading::rubricText($item['rubric']) : '') . '</textarea></label>'
       . '<p class="grd-field"><span>Learners may submit (assignments and peer review)</span>';
    $acc = $item ? $item['accepts_list'] : Grading::ACCEPTS;
    foreach (Grading::ACCEPTS as $a) echo '<label class="grd-check"><input type="checkbox" name="accepts[]" value="' . e($a) . '"' . (in_array($a, $acc, true) ? ' checked' : '') . '> ' . e(['text' => 'Written answer', 'link' => 'A link', 'file' => 'One file (images, PDF, office documents, zip; up to 10 MB)'][$a]) . '</label>';
    echo '</p><div class="grd-grid"><label class="grd-field"><span>Peer reviews per submission</span><input class="grd-num" type="number" min="1" max="10" name="peer_count" value="' . (int) $v('peer_count', 3) . '"></label>'
       . '<label class="grd-field"><span>Peer grace after due (hours) — then instructors mark what is short</span><input class="grd-num" type="number" min="0" max="720" name="peer_grace_hours" value="' . (int) $v('peer_grace_hours', 72) . '"></label></div></fieldset>';
    if ($published && $used) echo '<label class="grd-field"><span>Reason (required to change how a used item is marked; recorded like a regrade)</span><input name="reason" maxlength="300"></label>';
    echo '<button type="submit" class="btn btn-pill btn-sm">Save item</button></fieldset></form>';
    if ($item && $canManage && $item['status'] !== 'retired') {
        echo '<div class="grd-actions">';
        if ($item['status'] === 'draft' && !$used) echo avgr_button($course, 'delete', (int) $item['id'], 'Delete draft', 'items');
        else echo avgr_button($course, 'retire', (int) $item['id'], 'Retire (keeps its marks; leaves the grade)', 'items');
        echo '</div>';
    }
    echo '</section>';
    if ($item && $item['kind'] === 'live') avgr_live_sessions_admin($course, $item, $canManage);
}

function avgr_live_sessions_admin(array $course, array $item, bool $canManage): void
{
    echo '<section class="grd-card"><h2>Live sessions</h2>';
    $ss = LiveAssess::sessions((int) $item['id']);
    if (!$ss) echo '<p class="teach-empty">No sessions scheduled yet.</p>';
    else {
        echo '<ul class="grd-sessions">';
        foreach ($ss as $s) {
            echo '<li class="grd-session' . ($s['status'] !== 'open' ? ' is-off' : '') . '"><div><strong>' . e(Grading::toLocal((string) $s['starts_at'], 'D j M, g:i a')) . ' – ' . e(Grading::toLocal((string) $s['ends_at'], 'g:i a')) . '</strong>'
               . '<span>' . e(trim($s['place'] . ($s['link'] !== '' ? ' · online' : ''))) . ' · ' . (int) $s['taken'] . '/' . (int) $s['capacity'] . ' booked' . ($s['status'] !== 'open' ? ' · cancelled' : '') . '</span></div>'
               . '<div class="grd-actions"><a class="btn btn-pill btn-sm" href="' . e(avgr_url($course, 'tab=live&session=' . (int) $s['id'])) . '">Open panel</a>'
               . ($canManage && $s['status'] === 'open' ? avgr_button($course, 'session_cancel', (int) $s['id'], 'Cancel', 'items', 'btn btn-pill-ghost btn-sm', ['item_id' => (int) $item['id']]) : '') . '</div></li>';
        }
        echo '</ul>';
    }
    if ($canManage) {
        echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form">' . avgr_csrf_field()
           . '<input type="hidden" name="act" value="session_save"><input type="hidden" name="tab" value="items"><input type="hidden" name="item_id" value="' . (int) $item['id'] . '">'
           . '<h3>Schedule a session</h3><div class="grd-grid">'
           . '<label class="grd-field"><span>Date</span><input type="date" name="date" required></label>'
           . '<label class="grd-field"><span>Starts (Lagos)</span><input type="time" name="start" required></label>'
           . '<label class="grd-field"><span>Ends</span><input type="time" name="end" required></label>'
           . '<label class="grd-field"><span>Capacity</span><input class="grd-num" type="number" min="1" max="500" name="capacity" value="10" required></label></div>'
           . '<label class="grd-field"><span>Place</span><input name="place" maxlength="300" placeholder="CACENTRE, Alimosho — Room 2"></label>'
           . '<label class="grd-field"><span>Meeting link (optional)</span><input name="link" type="url" maxlength="500" placeholder="https://meet.google.com/…"></label>'
           . '<button type="submit" class="btn btn-pill-ghost btn-sm">Add session</button></form>';
    }
    echo '</section>';
}

/* ══ Marking ═══════════════════════════════════════════════════════════ */

function avgr_view_marking(array $course, int $uid): void
{
    $cid = (int) $course['id'];
    $subId = (int) ($_GET['sub'] ?? 0);
    if ($subId > 0) { avgr_mark_screen($course, $uid, $subId); return; }
    $state = in_array($_GET['state'] ?? '', ['unmarked', 'moderation', 'unreleased', 'all'], true) ? (string) $_GET['state'] : 'unmarked';
    $itemF = (int) ($_GET['item'] ?? 0);
    $items = array_filter(Grading::items($cid, 'all'), static fn($i) => $i['kind'] !== 'quiz' && $i['status'] !== 'draft');
    echo '<section class="grd-card"><h2>Marking queue</h2><p class="grd-sub">Oldest first. Only each learner\'s latest attempt is listed.</p>'
       . '<form method="get" action="' . e(avgr_url($course)) . '" class="grd-filter"><input type="hidden" name="tab" value="marking">'
       . '<label><span>Item</span><select name="item"><option value="0">All items</option>';
    foreach ($items as $i) echo '<option value="' . (int) $i['id'] . '"' . ($itemF === (int) $i['id'] ? ' selected' : '') . '>' . e((string) $i['title']) . '</option>';
    echo '</select></label><label><span>Show</span><select name="state">';
    foreach (['unmarked' => 'Needs marking', 'moderation' => 'Needs moderation', 'unreleased' => 'Marked, not released', 'all' => 'Everything'] as $k => $l) echo '<option value="' . $k . '"' . ($state === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></label><button class="btn btn-pill-ghost btn-sm" type="submit">Filter</button></form>';
    $q = GradingWork::queue($cid, $state, $itemF, $uid);
    if (!$q) echo '<p class="teach-empty">Nothing here.</p>';
    else {
        echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Learner</th><th scope="col">Item</th><th scope="col">Submitted</th><th scope="col">Marks</th><th scope="col">State</th><th scope="col"><span class="av-sr">Open</span></th></tr></thead><tbody>';
        foreach ($q as $r) {
            $label = ['unmarked' => 'Needs marking', 'moderation' => 'Needs moderation', 'unreleased' => 'Ready to release', 'released' => 'Released'][$r['state']] ?? $r['state'];
            echo '<tr><td><span class="r-name">' . e((string) $r['learner_name']) . '</span></td>'
               . '<td>' . e((string) $r['item']['title']) . ($r['attempt'] > 1 ? ' · attempt ' . (int) $r['attempt'] : '') . '</td>'
               . '<td>' . e(Grading::toLocal((string) $r['submitted_at'], 'j M, g:i a')) . ((int) $r['is_late'] ? ' <span class="grd-pill grd-pill--late">Late</span>' : '') . '</td>'
               . '<td>' . (int) $r['mark_count'] . ($r['marked_by_me'] ? ' (incl. yours)' : '') . ($r['item']['kind'] === 'peer' ? ' · ' . (int) $r['reviews_done'] . '/' . (int) $r['item']['peer_count'] . ' peer' : '') . '</td>'
               . '<td>' . e($label) . '</td><td><a class="btn btn-pill-ghost btn-sm" href="' . e(avgr_url($course, 'tab=marking&sub=' . (int) $r['id'])) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    if (CourseTeam::can($cid, $uid, 'release')) {
        echo '<section class="grd-card"><h2>Release grades</h2><p class="grd-sub">Learners see a grade only when it is released. Flagged work waits for moderation.</p><ul class="grd-sessions">';
        foreach ($items as $i) {
            $ready = count(GradingWork::queue($cid, 'unreleased', (int) $i['id']));
            echo '<li class="grd-session"><div><strong>' . e((string) $i['title']) . '</strong><span>' . $ready . ' ready to release</span></div>'
               . ($ready ? avgr_button($course, 'release', (int) $i['id'], 'Release', 'marking', 'btn btn-pill btn-sm') : '') . '</li>';
        }
        echo '</ul></section>';
    }
}

/** The submission as the team reads it: text, link and file. */
function avgr_submission_body(array $sub): string
{
    $h = '';
    if (trim((string) $sub['body']) !== '') $h .= '<div class="grd-answer">' . nl2br(e((string) $sub['body'])) . '</div>';
    if ((string) $sub['link'] !== '') $h .= '<p><a href="' . e((string) $sub['link']) . '" target="_blank" rel="noopener nofollow noreferrer">' . e((string) $sub['link']) . '</a> <span class="av-sr">(opens in a new tab)</span></p>';
    if ((string) $sub['file_store'] !== '') $h .= '<p><a class="btn btn-pill-ghost btn-sm" href="/academy/submission-file.php?id=' . (int) $sub['id'] . '">↓ ' . e((string) $sub['file_name']) . '</a> <small>' . e(number_format((int) $sub['file_size'] / 1024, 0)) . ' KB</small></p>';
    return $h !== '' ? $h : '<p class="grd-hint">Scored in a live session.</p>';
}

function avgr_mark_screen(array $course, int $uid, int $subId): void
{
    $cid = (int) $course['id'];
    $sub = GradingWork::submission($subId);
    if (!$sub || (int) $sub['course_id'] !== $cid) { echo '<p class="teach-empty">No such submission.</p>'; return; }
    $item = Grading::item((int) $sub['item_id']);
    $learner = (new LmsRepository())->memberById((int) $sub['user_id']);
    $marks = GradingWork::marks($subId);
    $mine = null; foreach ($marks as $m) if ((int) $m['marker_id'] === $uid) $mine = $m;
    $canModerate = CourseTeam::can($cid, $uid, 'moderate');
    echo '<p><a href="' . e(avgr_url($course, 'tab=marking')) . '">← Marking queue</a></p>';
    echo '<section class="grd-card"><h2>' . e((string) $item['title']) . ' — ' . e((string) ($learner['name'] ?? 'Learner')) . '</h2>'
       . '<p class="grd-sub">Attempt ' . (int) $sub['attempt'] . ' · submitted ' . e(Grading::toLocal((string) $sub['submitted_at'])) . ((int) $sub['is_late'] ? ' · <strong>late</strong> (' . (int) $item['late_penalty'] . '% penalty on release)' : '') . '</p>'
       . avgr_submission_body($sub) . '</section>';
    if ($item['kind'] === 'peer') {
        $done = PeerReview::doneReviews($subId);
        echo '<section class="grd-card"><h2>Peer reviews</h2><p class="grd-sub">' . count($done) . ' of ' . (int) $item['peer_count'] . ' done' . (count($done) ? ' · median ' . e(Grading::num(GradingWork::median(PeerReview::doneScores($subId)))) . '%' : '') . '. An instructor mark overrides the peer median.</p></section>';
    }
    echo '<section class="grd-card"><h2>' . ($mine ? 'Your mark' : 'Mark this') . '</h2>'
       . '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form">' . avgr_csrf_field()
       . '<input type="hidden" name="act" value="mark"><input type="hidden" name="tab" value="marking"><input type="hidden" name="id" value="' . $subId . '">'
       . avgr_rubric_inputs($item['rubric'], $mine ? (array) json_decode((string) $mine['scores_json'], true) : [], 'm' . $subId)
       . '<label class="grd-field"><span>Feedback for the learner (shown when released)</span><textarea name="feedback" rows="4">' . e((string) ($mine['feedback'] ?? '')) . '</textarea></label>'
       . '<button type="submit" class="btn btn-pill btn-sm">Save mark</button></form></section>';
    // Marking is blind: other markers' scores show once you have marked, or to a moderator.
    if ($marks && ($mine || $canModerate)) {
        echo '<section class="grd-card"><h2>All marks</h2><ul class="grd-marks">';
        foreach ($marks as $m) {
            echo '<li><strong>' . e((string) ($m['marker_name'] ?? 'Marker')) . '</strong> · ' . e(Grading::num((float) $m['points'])) . '/' . e(Grading::num((float) $m['max_points']))
               . ' · ' . e(Grading::toLocal((string) ($m['updated_at'] ?: $m['created_at']), 'j M, g:i a')) . (trim((string) $m['feedback']) !== '' ? '<br><span>' . nl2br(e((string) $m['feedback'])) . '</span>' : '') . '</li>';
        }
        echo '</ul>';
        $final = $sub['final_pct'] === null ? null : (float) $sub['final_pct'];
        echo '<p class="grd-sub">' . ((int) $sub['flagged'] ? '<strong>Needs moderation</strong>: the marks differ by more than ' . (int) $item['moderation_pct'] . '% of the maximum.'
               : ($final !== null ? 'Final: ' . e(Grading::num($final)) . '% (' . e((string) $sub['final_source']) . ')' . ((string) $sub['released_at'] !== '' ? ' · released' : ' · not yet released') : 'No final score yet.')) . '</p>';
        if ($canModerate) {
            echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form grd-row">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="agree"><input type="hidden" name="tab" value="marking"><input type="hidden" name="id" value="' . $subId . '">'
               . '<label class="grd-field"><span>Agreed score (out of ' . e(Grading::num((float) $item['rubric_max'])) . ')</span><input class="grd-num" type="number" step="0.5" min="0" max="' . e(Grading::num((float) $item['rubric_max'])) . '" name="points" required></label>'
               . '<label class="grd-field grd-grow"><span>Reason (recorded)</span><input name="reason" required maxlength="500"></label>'
               . '<button type="submit" class="btn btn-pill-ghost btn-sm">Agree final score</button></form>';
        }
        echo '</section>';
    }
}

/* ══ Live session panel (phone-friendly) ═══════════════════════════════ */

function avgr_view_live(array $course, int $uid): void
{
    $s = LiveAssess::session((int) ($_GET['session'] ?? 0));
    if (!$s || (int) $s['course_id'] !== (int) $course['id']) { echo '<p class="teach-empty">No such session.</p>'; return; }
    $item = Grading::item((int) $s['item_id']);
    $canModerate = CourseTeam::can((int) $course['id'], $uid, 'moderate');
    echo '<p><a href="' . e(avgr_url($course, 'tab=items&item=' . (int) $item['id'])) . '">← ' . e((string) $item['title']) . '</a></p>'
       . '<section class="grd-card grd-live-h"><h2>' . e((string) $item['title']) . '</h2><p class="grd-sub">' . e(Grading::toLocal((string) $s['starts_at'], 'l j M, g:i a')) . ' – ' . e(Grading::toLocal((string) $s['ends_at'], 'g:i a'))
       . ' · ' . e((string) $s['place']) . ' · ' . LiveAssess::taken((int) $s['id']) . '/' . (int) $s['capacity'] . '</p>'
       . '<p class="grd-hint">Each panelist saves their own scores for the learner in front of them. Saving twice changes nothing; marks further apart than ' . (int) $item['moderation_pct'] . '% are sent for moderation.</p>';
    echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form grd-row">' . avgr_csrf_field()
       . '<input type="hidden" name="act" value="seat"><input type="hidden" name="tab" value="live"><input type="hidden" name="id" value="' . (int) $s['id'] . '">'
       . '<label class="grd-field grd-grow"><span>Seat a learner (their email)</span><input type="email" name="email" required></label><button class="btn btn-pill-ghost btn-sm" type="submit">Book in</button></form></section>';
    $roster = LiveAssess::roster((int) $s['id']);
    if (!$roster) { echo '<p class="teach-empty">Nobody is booked yet.</p>'; return; }
    echo '<ol class="grd-panel">';
    foreach ($roster as $n => $b) {
        $bid = (int) $b['id'];
        $mine = null;
        if ((int) $b['submission_id'] > 0) foreach (GradingWork::marks((int) $b['submission_id']) as $m) if ((int) $m['marker_id'] === $uid) $mine = $m;
        echo '<li class="grd-pcard is-' . e((string) $b['status']) . '" id="b' . $bid . '"><div class="grd-pcard-h"><span class="grd-pnum">' . ($n + 1) . '</span><strong>' . e((string) $b['name']) . '</strong><span class="grd-pill grd-pill--' . e((string) $b['status']) . '">' . e((string) $b['status']) . '</span></div>';
        if (in_array($b['status'], ['booked', 'present', 'absent'], true)) {
            echo '<div class="grd-actions">' . avgr_button($course, 'presence', $bid, 'Present', 'live', 'btn btn-pill-ghost btn-sm', ['state' => 'present'])
               . avgr_button($course, 'presence', $bid, 'Absent', 'live', 'btn btn-pill-ghost btn-sm', ['state' => 'absent']) . '</div>';
        }
        if (in_array($b['status'], ['booked', 'present'], true)) {
            echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="live_score"><input type="hidden" name="tab" value="live"><input type="hidden" name="id" value="' . $bid . '">'
               . avgr_rubric_inputs($item['rubric'], $mine ? (array) json_decode((string) $mine['scores_json'], true) : [], 'b' . $bid)
               . '<label class="grd-field"><span>Note for the learner</span><textarea name="note" rows="2">' . e((string) ($mine['feedback'] ?? '')) . '</textarea></label>'
               . '<button type="submit" class="btn btn-pill btn-sm grd-wide">' . ($mine ? 'Update my scores' : 'Save my scores') . '</button></form>';
        }
        if ($b['status'] === 'absent' && $canModerate) {
            echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form grd-row">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="excuse"><input type="hidden" name="tab" value="live"><input type="hidden" name="id" value="' . $bid . '">'
               . '<label class="grd-field grd-grow"><span>Excuse with a reason (gives the attempt back)</span><input name="reason" required maxlength="500"></label><button class="btn btn-pill-ghost btn-sm" type="submit">Excuse</button></form>';
        }
        if ($b['status'] === 'excused') echo '<p class="grd-hint">Excused: ' . e((string) $b['excuse_reason']) . '</p>';
        echo '</li>';
    }
    echo '</ol>';
}

/* ══ Gradebook ═════════════════════════════════════════════════════════ */

function avgr_cell(array $item, array $r): string
{
    if ($r['score'] !== null) return Grading::num((float) $r['score']) . '%';
    if ($r['pending'] !== null) return '(' . Grading::num((float) $r['pending']) . '% unreleased)';
    return avgr_status_label((string) $r['status']);
}

function avgr_view_gradebook(array $course, int $uid): void
{
    $cid = (int) $course['id'];
    $items = Grading::items($cid, 'published');
    $book = Grading::gradebook($cid);
    echo '<section class="grd-card"><div class="grd-card-h"><h2>Gradebook</h2>'
       . ($book ? '<a class="btn btn-pill-ghost btn-sm" href="' . e(avgr_url($course, 'tab=gradebook&export=csv')) . '">↓ Export CSV</a>' : '') . '</div>'
       . '<p class="grd-sub">Released scores count; unreleased finals are shown in brackets for the team only. Pass mark ' . Grading::passMark($cid) . '%, and every item passed.</p>';
    if (!$items) { echo '<p class="teach-empty">No published graded items yet.</p></section>'; return; }
    if (!$book) { echo '<p class="teach-empty">No learners yet.</p></section>'; return; }
    echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Learner</th>';
    foreach ($items as $i) echo '<th scope="col">' . e((string) $i['title']) . ' <small>' . (int) $i['weight'] . '%</small></th>';
    echo '<th scope="col">Grade</th><th scope="col">Result</th></tr></thead><tbody>';
    foreach ($book as $row) {
        $st = $row['standing'];
        echo '<tr><td><span class="r-name">' . e((string) $row['user']['name']) . '</span><span class="r-email">' . e((string) $row['user']['email']) . '</span></td>';
        foreach ($st['items'] as $x) echo '<td>' . e(avgr_cell($x['item'], $x['result'])) . ($x['result']['late'] ? ' <span class="grd-pill grd-pill--late">Late</span>' : '') . '</td>';
        echo '<td><strong>' . e(Grading::num((float) $st['grade'])) . '%</strong></td><td>' . ($st['passed'] ? '<span class="r-cert">Passed</span>' : ($st['complete'] ? 'Not passed' : 'In progress')) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

/** The gradebook as CSV, formula-safe through Csv. Headers are the page's job unless $out is given. */
function avgr_gradebook_csv(array $course, $out = null): void
{
    $cid = (int) $course['id'];
    $items = Grading::items($cid, 'published');
    $own = $out === null;
    if ($own) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="afrovanguard-' . $course['slug'] . '-gradebook-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
    }
    $head = ['Name', 'Email'];
    foreach ($items as $i) $head[] = $i['title'] . ' (' . (int) $i['weight'] . '%)';
    Csv::put($out, array_merge($head, ['Grade %', 'Pass mark %', 'Result']));
    foreach (Grading::gradebook($cid) as $row) {
        $st = $row['standing'];
        $cells = [$row['user']['name'], $row['user']['email']];
        foreach ($st['items'] as $x) $cells[] = $x['result']['score'] !== null ? Grading::num((float) $x['result']['score']) : avgr_status_label((string) $x['result']['status']);
        $cells[] = Grading::num((float) $st['grade']); $cells[] = $st['pass_mark'];
        $cells[] = $st['passed'] ? 'Passed' : ($st['complete'] ? 'Not passed' : 'In progress');
        Csv::put($out, $cells);
    }
    if ($own) fclose($out);
}

/* ══ Team ══════════════════════════════════════════════════════════════ */

function avgr_view_team(array $course, int $uid): void
{
    $cid = (int) $course['id'];
    $can = CourseTeam::can($cid, $uid, 'manage_team');
    echo '<section class="grd-card"><h2>Teaching team</h2><p class="grd-sub">Leads run the course and its team; instructors set up items, mark, moderate and release; assistants mark.</p><ul class="grd-team">';
    foreach (CourseTeam::members($cid) as $m) {
        echo '<li><div><strong>' . e((string) $m['name']) . '</strong><span>' . e((string) $m['email']) . '</span></div>';
        if ($can) {
            echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-inline">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="team_role"><input type="hidden" name="tab" value="team"><input type="hidden" name="id" value="' . (int) $m['user_id'] . '">'
               . '<label><span class="av-sr">Role for ' . e((string) $m['name']) . '</span><select name="role">';
            foreach (CourseTeam::ROLES as $r) echo '<option value="' . $r . '"' . ($r === $m['role'] ? ' selected' : '') . '>' . e(CourseTeam::label($r)) . '</option>';
            echo '</select></label><button class="btn btn-pill-ghost btn-sm" type="submit">Change</button></form>'
               . avgr_button($course, 'team_remove', (int) $m['user_id'], 'Remove', 'team');
        } else {
            echo '<span class="grd-pill">' . e((string) $m['label']) . '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
    if ($can) {
        echo '<form method="post" action="' . e(avgr_url($course)) . '" class="grd-form grd-row">' . avgr_csrf_field()
           . '<input type="hidden" name="act" value="team_add"><input type="hidden" name="tab" value="team">'
           . '<label class="grd-field grd-grow"><span>Add by email (they need an Academy account)</span><input type="email" name="email" required></label>'
           . '<label class="grd-field"><span>Role</span><select name="role"><option value="assistant">Teaching assistant</option><option value="instructor">Instructor</option><option value="lead">Lead instructor</option></select></label>'
           . '<button class="btn btn-pill btn-sm" type="submit">Add to team</button></form>';
    } else {
        echo '<p class="grd-hint">Only the course lead (or Academy staff) changes the team.</p>';
    }
    echo '</section>';
}

}
