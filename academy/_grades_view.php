<?php
/**
 * academy/_grades_view.php — the learner's grades views (academy/grades.php).
 * Shows released results only; every form posts to avgr_learner_action().
 */
declare(strict_types=1);

function avgr_learner_summary(array $course, int $uid, string $base): void
{
    $cid = (int) $course['id'];
    $lms = new LmsRepository();
    $st = Grading::standing($uid, $cid);
    echo '<h1 class="teach-h1">Your grades</h1><div class="teach-stats">'
       . '<div class="tstat"><span class="tn">' . e(Grading::num((float) $st['grade'])) . '%</span><span class="tl">Grade so far</span></div>'
       . '<div class="tstat"><span class="tn">' . (int) $st['pass_mark'] . '%</span><span class="tl">Pass mark</span></div>'
       . '<div class="tstat"><span class="tn">' . e(Grading::num((float) $st['remaining'])) . '%</span><span class="tl">Still to be graded</span></div></div>';
    if ($st['passed'] || $lms->getCertificate($uid, $cid)) {
        echo '<p class="grd-flash is-ok">You have passed this course. <a href="' . e(academy_url($course['slug'] . '/certificate')) . '" target="_blank" rel="noopener">Get your certificate →</a></p>';
    } elseif ($st['need_avg'] !== null) {
        echo '<p class="grd-need">' . ($st['need_avg'] > 100
            ? 'The pass mark is now out of reach on the weighted grade — talk to your instructor.'
            : 'To reach the pass mark you need an average of <strong>' . e(Grading::num((float) $st['need_avg'])) . '%</strong> on the work still to be graded' . ($st['need_avg'] <= 0 ? ' — you are already there on weight' : '') . ', and to pass every item.') . '</p>';
    } elseif ($st['complete']) {
        echo '<p class="grd-need">' . (!$st['all_items_passed'] ? 'Every item must be passed as well as the pass mark — at least one is below its passing score.' : 'Your final grade is below the pass mark.') . '</p>';
    }
    echo '<div class="teach-table-wrap"><table class="teach-table grd-table"><thead><tr><th scope="col">Item</th><th scope="col">Due</th><th scope="col">Weight</th><th scope="col">Status</th><th scope="col">Score</th></tr></thead><tbody>';
    foreach ($st['items'] as $x) {
        $i = $x['item']; $r = $x['result'];
        $when = $i['due_at'] !== '' ? Grading::toLocal((string) $i['due_at'], 'j M, g:i a') : '—';
        if ($i['kind'] === 'live' && !empty($r['booking'])) $when = 'Session ' . Grading::toLocal((string) $r['booking']['starts_at'], 'j M, g:i a');
        echo '<tr><td><a class="r-name" href="' . e($base . '?item=' . (int) $i['id']) . '">' . e((string) $i['title']) . '</a><span class="r-email">' . e(Grading::kindLabel((string) $i['kind'])) . '</span></td>'
           . '<td>' . e($when) . '</td><td>' . (int) $i['weight'] . '%</td>'
           . '<td>' . e(avgr_status_label((string) $r['status'])) . ($r['late'] ? ' <span class="grd-pill grd-pill--late">Late</span>' : '') . '</td>'
           . '<td>' . ($r['score'] !== null ? '<strong>' . e(Grading::num((float) $r['score'])) . '%</strong>' . ($r['passed'] ? '' : ' <small>(pass ' . (int) $i['pass_score'] . '%)</small>') : '—') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function avgr_learner_item(array $course, array $item, int $uid, string $base): void
{
    $r = Grading::result($item, $uid);
    $w = Grading::window($item);
    echo '<p><a href="' . e($base) . '">← All grades</a></p><h1 class="teach-h1">' . e((string) $item['title']) . '</h1>';
    echo '<section class="grd-card"><p class="grd-sub">' . e(Grading::kindLabel((string) $item['kind'])) . ' · ' . (int) $item['weight'] . '% of the course · pass at ' . (int) $item['pass_score'] . '%'
       . ($w['due'] !== null ? ' · due ' . e(Grading::toLocal((string) $item['due_at'])) . ((int) $item['late_hours'] ? ', accepted up to ' . (int) $item['late_hours'] . ' hours late with a ' . (int) $item['late_penalty'] . '% penalty' : '') : '') . '</p>';
    if (trim((string) $item['instructions']) !== '') echo '<div class="grd-answer">' . nl2br(e((string) $item['instructions'])) . '</div>';
    echo '</section>';

    // The result: released scores only, with any late penalty shown on its own line.
    echo '<section class="grd-card"><h2>Your result</h2><p><strong>' . e(avgr_status_label((string) $r['status'])) . '</strong>' . ($r['late'] ? ' <span class="grd-pill grd-pill--late">Late</span>' : '') . '</p>';
    if ($r['score'] !== null && $r['status'] !== 'missed') {
        echo '<p class="grd-score">' . e(Grading::num((float) $r['score'])) . '%' . ($r['passed'] ? ' — passed' : ' — below the passing score') . '</p>';
        if ($r['penalty']) echo '<p class="grd-sub">Marked ' . e(Grading::num((float) $r['raw'])) . '%, less a ' . (int) $r['penalty'] . '% late penalty.</p>';
        if ($item['kind'] !== 'quiz' && (int) $item['max_attempts'] > 1) echo '<p class="grd-hint">Your latest marked attempt counts (attempt ' . (int) $r['counts_attempt'] . '), even if an earlier one scored higher.' . ($r['latest_newer'] ? ' Your newer attempt is waiting to be marked.' : '') . '</p>';
        if ($item['kind'] === 'quiz') echo '<p class="grd-hint">Your best counted attempt is the score.</p>';
        if (trim((string) $r['feedback']) !== '') echo '<h3>Feedback</h3><div class="grd-answer">' . nl2br(e((string) $r['feedback'])) . '</div>';
    } elseif ($r['status'] === 'missed') {
        echo '<p class="grd-sub">Nothing was handed in before this closed, so it counts as 0.</p>';
    } elseif ($r['status'] === 'marked') {
        echo '<p class="grd-sub">Your work has been marked. You will see your grade when your instructor releases it.</p>';
    }
    echo '</section>';

    if ($item['kind'] === 'quiz') {
        $l = Database::pdo()->prepare('SELECT slug FROM lessons WHERE id = ?'); $l->execute([(int) $item['lesson_id']]);
        echo '<p><a class="btn btn-pill btn-sm" href="' . e(academy_url($course['slug'] . '/learn/' . (string) $l->fetchColumn())) . '">Go to the quiz →</a></p>';
        return;
    }
    if ($item['kind'] === 'live') { avgr_learner_live($course, $item, $uid, $base); return; }

    $can = GradingWork::canSubmit($item, $uid);
    echo '<section class="grd-card"><h2>Submit</h2><p class="grd-sub">' . GradingWork::attemptsUsed((int) $item['id'], $uid) . ' of ' . (int) $item['max_attempts'] . ' attempt' . ((int) $item['max_attempts'] === 1 ? '' : 's') . ' used.</p>';
    if ($can['ok']) {
        $acc = $item['accepts_list'];
        echo '<form method="post" action="' . e($base) . '" enctype="multipart/form-data" class="grd-form">' . avgr_csrf_field()
           . '<input type="hidden" name="act" value="submit"><input type="hidden" name="item" value="' . (int) $item['id'] . '">'
           . (!empty($can['late']) ? '<p class="grd-flash is-err">The due date has passed: this will be marked late and the ' . (int) $item['late_penalty'] . '% penalty will apply.</p>' : '')
           . (in_array('text', $acc, true) ? '<label class="grd-field"><span>Your answer</span><textarea name="text" rows="8" maxlength="' . GradingWork::MAX_TEXT . '"></textarea></label>' : '')
           . (in_array('link', $acc, true) ? '<label class="grd-field"><span>A link (optional)</span><input type="url" name="link" maxlength="500" placeholder="https://…"></label>' : '')
           . (in_array('file', $acc, true) ? '<label class="grd-field"><span>One file — image, PDF, office document or zip, up to ' . (GradingWork::MAX_FILE_BYTES / 1048576) . ' MB</span><input type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.zip"></label>' : '')
           . '<button type="submit" class="btn btn-pill btn-sm">Submit attempt ' . (int) $can['attempt'] . '</button></form>';
    } else {
        echo '<p>' . e((string) $can['error']) . '</p>';
    }
    $mine = Database::pdo()->prepare('SELECT * FROM grade_submissions WHERE item_id = ? AND user_id = ? ORDER BY attempt DESC');
    $mine->execute([(int) $item['id'], $uid]);
    foreach ($mine->fetchAll(PDO::FETCH_ASSOC) as $s) {
        echo '<details class="grd-attempt"><summary>Attempt ' . (int) $s['attempt'] . ' · ' . e(Grading::toLocal((string) $s['submitted_at'])) . ((int) $s['is_late'] ? ' · late' : '') . '</summary>' . avgr_learner_sub($s) . '</details>';
    }
    echo '</section>';

    if ($item['kind'] === 'peer') {
        $a = PeerReview::assign((int) $item['id'], $uid);
        echo '<section class="grd-card"><h2>Peer reviews to do</h2>';
        if (!$a['ok']) { echo '<p>' . e((string) $a['error']) . '</p></section>'; return; }
        if (!$a['tasks']) echo '<p class="grd-sub">No submissions to review yet — come back once others have submitted.</p>';
        foreach ($a['tasks'] as $n => $t) {
            $sub = GradingWork::submission((int) $t['submission_id']);
            $done = (string) $t['done_at'] !== '';
            echo '<details class="grd-attempt"' . ($done ? '' : ' open') . '><summary>Review ' . ($n + 1) . ($done ? ' · done' : ' · to do') . '</summary>' . avgr_learner_sub($sub)
               . '<form method="post" action="' . e($base) . '" class="grd-form">' . avgr_csrf_field()
               . '<input type="hidden" name="act" value="review"><input type="hidden" name="item" value="' . (int) $item['id'] . '"><input type="hidden" name="review" value="' . (int) $t['id'] . '">'
               . avgr_rubric_inputs($item['rubric'], $done ? (array) json_decode((string) $t['scores_json'], true) : [], 'p' . (int) $t['id'])
               . '<label class="grd-field"><span>Feedback for the author (they will not see your name)</span><textarea name="feedback" rows="3">' . e((string) ($t['feedback'] ?? '')) . '</textarea></label>'
               . '<button type="submit" class="btn btn-pill-ghost btn-sm">' . ($done ? 'Update review' : 'Save review') . '</button></form></details>';
        }
        echo '</section>';
    }
}

/** A submission as its author (or a peer reviewer) reads it. Peer reviewers never see the author's name. */
function avgr_learner_sub(?array $s): string
{
    if (!$s) return '';
    $h = '';
    if (trim((string) $s['body']) !== '') $h .= '<div class="grd-answer">' . nl2br(e((string) $s['body'])) . '</div>';
    if ((string) $s['link'] !== '') $h .= '<p><a href="' . e((string) $s['link']) . '" target="_blank" rel="noopener nofollow noreferrer">' . e((string) $s['link']) . '</a></p>';
    if ((string) $s['file_store'] !== '') $h .= '<p><a href="/academy/submission-file.php?id=' . (int) $s['id'] . '">↓ ' . e((string) $s['file_name']) . '</a></p>';
    return $h;
}

function avgr_learner_live(array $course, array $item, int $uid, string $base): void
{
    $b = LiveAssess::bookingFor((int) $item['id'], $uid);
    $used = LiveAssess::attemptsUsed((int) $item['id'], $uid);
    echo '<section class="grd-card"><h2>Your session</h2>';
    if ($b) {
        echo '<p class="grd-score">' . e(Grading::toLocal((string) $b['starts_at'], 'l j F, g:i a')) . ' – ' . e(Grading::toLocal((string) $b['ends_at'], 'g:i a')) . ' <small>Lagos time</small></p>'
           . '<p>' . e((string) $b['place']) . ((string) $b['link'] !== '' ? ' · <a href="' . e((string) $b['link']) . '" target="_blank" rel="noopener nofollow">Join online</a>' : '') . '</p>'
           . '<p class="grd-hint">We will remind you the day before. Your result appears here once it is released.</p>'
           . '<form method="post" action="' . e($base) . '" class="grd-inline">' . avgr_csrf_field() . '<input type="hidden" name="act" value="unbook"><input type="hidden" name="item" value="' . (int) $item['id'] . '"><button class="btn btn-pill-ghost btn-sm" type="submit">Cancel booking</button></form>';
    } else {
        echo '<p class="grd-sub">' . $used . ' of ' . (int) $item['max_attempts'] . ' attempt' . ((int) $item['max_attempts'] === 1 ? '' : 's') . ' used. An absence uses an attempt unless your instructor excuses it.</p>';
    }
    $open = array_filter(LiveAssess::sessions((int) $item['id'], true), static fn($s) => strtotime($s['starts_at'] . ' UTC') > Grading::now());
    if ($open && ($b || $used < (int) $item['max_attempts'])) {
        echo '<h3>' . ($b ? 'Move to another session' : 'Book a session') . '</h3><ul class="grd-sessions">';
        foreach ($open as $s) {
            $full = (int) $s['taken'] >= (int) $s['capacity'];
            $here = $b && (int) $b['session_id'] === (int) $s['id'];
            echo '<li class="grd-session"><div><strong>' . e(Grading::toLocal((string) $s['starts_at'], 'D j M, g:i a')) . ' – ' . e(Grading::toLocal((string) $s['ends_at'], 'g:i a')) . '</strong>'
               . '<span>' . e(trim($s['place'] . ($s['link'] !== '' ? ' · online' : ''))) . ' · ' . max(0, (int) $s['capacity'] - (int) $s['taken']) . ' seat(s) left</span></div>'
               . ($here ? '<span class="grd-pill">Booked</span>' : ($full ? '<span class="grd-pill">Full</span>'
                 : '<form method="post" action="' . e($base) . '" class="grd-inline">' . avgr_csrf_field() . '<input type="hidden" name="act" value="book"><input type="hidden" name="item" value="' . (int) $item['id'] . '"><input type="hidden" name="session" value="' . (int) $s['id'] . '"><button class="btn btn-pill btn-sm" type="submit">' . ($b ? 'Move here' : 'Book') . '</button></form>'))
               . '</li>';
        }
        echo '</ul>';
    } elseif (!$b) {
        echo '<p class="grd-hint">No sessions are open for booking right now.</p>';
    }
    echo '</section>';
}
