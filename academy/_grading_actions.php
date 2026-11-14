<?php
/**
 * academy/_grading_actions.php — the POST handlers behind teach.php's grading
 * tabs and the learner's grades page.
 *
 * Kept apart from the pages so each action can be exercised directly (tests
 * call these functions with a $_POST-shaped array) and so both pages share
 * one CSRF check. Every handler:
 *
 *   1. refuses a request without a valid CSRF token (av_csrf_valid) before
 *      reading anything else — the forms carry it as a hidden `csrf` field,
 *      because these are plain forms that work without JavaScript;
 *   2. acts as the signed-in person's id, never an id from the form;
 *   3. leaves every permission decision to the library (CourseTeam::can via
 *      Grading / GradingWork / PeerReview / LiveAssess), which refuses with
 *      a sentence the page shows.
 *
 * Each returns ['ok' => bool, 'msg' => string, 'to' => query string to return to].
 */
declare(strict_types=1);

/** The hidden field every grading form carries. */
function avgr_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(function_exists('av_csrf_token') ? av_csrf_token(43200) : '') . '">';
}

/** Course-team actions from teach.php. $course is the course row; $uid the signed-in user. */
function avgr_teach_action(array $course, int $uid, array $post, array $files = []): array
{
    if (!av_csrf_valid((string) ($post['csrf'] ?? ''))) return ['ok' => false, 'msg' => 'Your session expired — reload the page and try again.', 'to' => ''];
    $cid = (int) $course['id'];
    $act = (string) ($post['act'] ?? '');
    $id = (int) ($post['id'] ?? 0);
    $res = ['ok' => false, 'error' => 'Unknown action.'];
    $to = 'tab=' . rawurlencode((string) ($post['tab'] ?? 'items'));
    $ok = '';
    switch ($act) {
        case 'item_save':
            $d = [
                'kind' => (string) ($post['kind'] ?? ''), 'title' => (string) ($post['title'] ?? ''),
                'instructions' => (string) ($post['instructions'] ?? ''), 'weight' => $post['weight'] ?? '',
                'pass_score' => $post['pass_score'] ?? '', 'due_at' => (string) ($post['due_at'] ?? ''),
                'late_hours' => $post['late_hours'] ?? '', 'late_penalty' => $post['late_penalty'] ?? '',
                'max_attempts' => $post['max_attempts'] ?? '', 'rubric' => (string) ($post['rubric'] ?? ''),
                'accepts' => (array) ($post['accepts'] ?? []), 'lesson_id' => (int) ($post['lesson_id'] ?? 0),
                'peer_count' => $post['peer_count'] ?? '', 'peer_grace_hours' => $post['peer_grace_hours'] ?? '',
                'moderation_pct' => $post['moderation_pct'] ?? '', 'reason' => (string) ($post['reason'] ?? ''),
            ];
            if ($id > 0) { $d['id'] = $id; unset($d['kind']); if ((Grading::item($id)['status'] ?? '') === 'published') unset($d['weight']); }
            $res = Grading::saveItem($cid, $d, $uid);
            $ok = 'Saved.';
            if (!empty($res['ok'])) $to = 'tab=items&item=' . (int) $res['id'];
            elseif ($id > 0) $to = 'tab=items&item=' . $id;
            else $to = 'tab=items&new=1';
            break;
        case 'publish':     $res = Grading::publish($cid, $uid); $ok = 'Published — learners can now see these items.'; break;
        case 'weights':     $res = Grading::setWeights($cid, (array) ($post['w'] ?? []), (string) ($post['reason'] ?? ''), $uid); $ok = 'Weights saved.'; break;
        case 'pass_mark':   $res = Grading::setPassMark($cid, $post['pass_mark'] ?? '', $uid); $ok = 'Pass mark saved.'; break;
        case 'retire':      $res = Grading::retire($id, $uid, (string) ($post['reason'] ?? '')); $ok = 'Retired.'; break;
        case 'delete':      $res = Grading::deleteItem($id, $uid); $ok = 'Deleted.'; break;
        case 'release':     $res = GradingWork::release($id, $uid); $to = 'tab=marking';
            if (!empty($res['ok'])) $ok = $res['released'] . ' grade' . ($res['released'] === 1 ? '' : 's') . ' released.' . ($res['held'] ? ' ' . $res['held'] . ' held for moderation.' : '');
            break;
        case 'mark':
            $res = GradingWork::mark($id, $uid, (array) ($post['score'] ?? []), (string) ($post['feedback'] ?? ''));
            $ok = !empty($res['flagged']) ? 'Saved. The marks disagree by more than the threshold, so this needs moderation.' : 'Mark saved.';
            $to = 'tab=marking&sub=' . $id;
            break;
        case 'agree':
            $res = GradingWork::agree($id, $uid, $post['points'] ?? '', (string) ($post['reason'] ?? ''));
            $ok = 'Final score agreed.'; $to = 'tab=marking&sub=' . $id;
            break;
        case 'team_add':
            $u = (new LmsRepository())->findUserByEmail((string) ($post['email'] ?? ''));
            $res = $u ? CourseTeam::add($cid, (int) $u['id'], (string) ($post['role'] ?? ''), $uid)
                      : ['ok' => false, 'error' => 'No Academy account uses that email yet — ask them to sign in once, then add them.'];
            $ok = 'Team updated.'; $to = 'tab=team';
            break;
        case 'team_role':   $res = CourseTeam::add($cid, $id, (string) ($post['role'] ?? ''), $uid); $ok = 'Team updated.'; $to = 'tab=team'; break;
        case 'team_remove': $res = CourseTeam::remove($cid, $id, $uid); $ok = 'Removed from the team.'; $to = 'tab=team'; break;
        case 'session_save':
            $res = LiveAssess::saveSession((int) ($post['item_id'] ?? 0), [
                'id' => $id ?: null, 'starts_at' => trim((string) ($post['date'] ?? '')) . ' ' . trim((string) ($post['start'] ?? '')),
                'ends_at' => trim((string) ($post['date'] ?? '')) . ' ' . trim((string) ($post['end'] ?? '')),
                'place' => (string) ($post['place'] ?? ''), 'link' => (string) ($post['link'] ?? ''), 'capacity' => (string) ($post['capacity'] ?? ''),
            ], $uid);
            $ok = 'Session saved.'; $to = 'tab=items&item=' . (int) ($post['item_id'] ?? 0);
            break;
        case 'session_cancel': $res = LiveAssess::cancelSession($id, $uid); $ok = 'Session cancelled; its bookings are released.'; $to = 'tab=items&item=' . (int) ($post['item_id'] ?? 0); break;
        case 'seat':
            $u = (new LmsRepository())->findUserByEmail((string) ($post['email'] ?? ''));
            $res = $u ? LiveAssess::book($id, (int) $u['id'], $uid) : ['ok' => false, 'error' => 'No Academy account uses that email.'];
            $ok = 'Booked in.'; $to = 'tab=live&session=' . $id;
            break;
        case 'presence':
            $b = LiveAssess::booking($id);
            $res = LiveAssess::presence($id, (string) ($post['state'] ?? ''), $uid);
            $ok = 'Recorded.'; $to = 'tab=live&session=' . (int) ($b['session_id'] ?? 0) . '#b' . $id;
            break;
        case 'live_score':
            $b = LiveAssess::booking($id);
            $res = LiveAssess::score($id, $uid, (array) ($post['score'] ?? []), (string) ($post['note'] ?? ''));
            $ok = !empty($res['unchanged']) ? 'Already saved.' : (!empty($res['flagged']) ? 'Saved — the panel disagrees by more than the threshold; a lead or instructor will agree the score.' : 'Saved.');
            $to = 'tab=live&session=' . (int) ($b['session_id'] ?? 0) . '#b' . $id;
            break;
        case 'excuse':
            $b = LiveAssess::booking($id);
            $res = LiveAssess::excuse($id, $uid, (string) ($post['reason'] ?? ''));
            $ok = 'Absence excused — the learner may book again.'; $to = 'tab=live&session=' . (int) ($b['session_id'] ?? 0);
            break;
    }
    return !empty($res['ok']) ? ['ok' => true, 'msg' => $ok, 'to' => $to] : ['ok' => false, 'msg' => (string) ($res['error'] ?? 'That did not work.'), 'to' => $to];
}

/** Learner actions from grades.php. */
function avgr_learner_action(array $course, int $uid, array $post, array $files = []): array
{
    if (!av_csrf_valid((string) ($post['csrf'] ?? ''))) return ['ok' => false, 'msg' => 'Your session expired — reload the page and try again.', 'to' => ''];
    $act = (string) ($post['act'] ?? '');
    $itemId = (int) ($post['item'] ?? 0);
    $item = Grading::item($itemId);
    $to = 'item=' . $itemId;
    if (!$item || (int) $item['course_id'] !== (int) $course['id'] || $item['status'] !== 'published') return ['ok' => false, 'msg' => 'That item is not on this course.', 'to' => ''];
    $res = ['ok' => false, 'error' => 'Unknown action.']; $ok = '';
    switch ($act) {
        case 'submit':
            $file = null;
            $f = $files['file'] ?? null;
            if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $f['error'] !== UPLOAD_ERR_OK) { $res = ['ok' => false, 'error' => (int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'That file is too large.' : 'The file did not arrive. Try again.']; break; }
                $tmp = (string) ($f['tmp_name'] ?? '');
                $bytes = ($tmp !== '' && (is_uploaded_file($tmp) || !empty($f['_test']))) ? (string) file_get_contents($tmp) : '';
                $file = ['name' => (string) ($f['name'] ?? ''), 'bytes' => $bytes];
            }
            $res = GradingWork::submit($itemId, $uid, ['text' => (string) ($post['text'] ?? ''), 'link' => (string) ($post['link'] ?? '')], $file);
            $ok = !empty($res['late']) ? 'Submitted — after the due date, so the late penalty will apply.' : 'Submitted. You will be told when your grade is released.';
            break;
        case 'review':
            $res = PeerReview::review((int) ($post['review'] ?? 0), $uid, (array) ($post['score'] ?? []), (string) ($post['feedback'] ?? ''));
            $ok = 'Review saved — thank you.';
            break;
        case 'book':
            $s = LiveAssess::session((int) ($post['session'] ?? 0));
            $res = ($s && (int) $s['item_id'] === $itemId) ? LiveAssess::book((int) $s['id'], $uid, $uid) : ['ok' => false, 'error' => 'That session is not part of this assessment.'];
            $ok = !empty($res['moved']) ? 'Your booking has moved.' : 'Booked. We will remind you the day before.';
            break;
        case 'unbook':
            $b = LiveAssess::bookingFor($itemId, $uid);
            $res = $b ? LiveAssess::cancelBooking((int) $b['id'], $uid) : ['ok' => false, 'error' => 'You have no booking to cancel.'];
            $ok = 'Booking cancelled.';
            break;
    }
    return !empty($res['ok']) ? ['ok' => true, 'msg' => $ok, 'to' => $to] : ['ok' => false, 'msg' => (string) ($res['error'] ?? 'That did not work.'), 'to' => $to];
}

/** Flash a result across the redirect that follows a POST (a short-lived cookie the person set themselves). */
function avgr_flash_set(array $r): void
{
    if (headers_sent()) { $GLOBALS['__avgr_flash'] = $r; return; }
    setcookie('av_gr_flash', base64_encode(json_encode(['ok' => (bool) $r['ok'], 'm' => mb_substr((string) $r['msg'], 0, 400)])),
        ['expires' => time() + 60, 'path' => '/academy/', 'httponly' => true, 'samesite' => 'Lax',
         'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
}

function avgr_flash_take(): ?array
{
    if (isset($GLOBALS['__avgr_flash'])) { $f = $GLOBALS['__avgr_flash']; unset($GLOBALS['__avgr_flash']); return $f; }
    $raw = (string) ($_COOKIE['av_gr_flash'] ?? '');
    if ($raw === '') return null;
    if (!headers_sent()) setcookie('av_gr_flash', '', ['expires' => time() - 3600, 'path' => '/academy/']);
    $d = json_decode((string) base64_decode($raw, true), true);
    return is_array($d) ? ['ok' => !empty($d['ok']), 'msg' => (string) ($d['m'] ?? '')] : null;
}

/** The flash, as a status line. */
function avgr_flash_html(?array $f): string
{
    if (!$f || $f['msg'] === '') return '';
    return '<p class="grd-flash ' . ($f['ok'] ? 'is-ok' : 'is-err') . '" role="' . ($f['ok'] ? 'status' : 'alert') . '">' . e($f['msg']) . '</p>';
}

/** Status words a learner reads. */
function avgr_status_label(string $s): string
{
    return ['not_started' => 'Not started', 'submitted' => 'Submitted', 'marked' => 'Marked — awaiting release',
            'released' => 'Released', 'missed' => 'Missed'][$s] ?? ucfirst($s);
}

/** The rubric as score inputs (shared by marking, peer review and the live panel). */
function avgr_rubric_inputs(array $rubric, array $values = [], string $idPrefix = 'r'): string
{
    $h = '<div class="grd-rubric">';
    foreach ($rubric as $i => $c) {
        $fid = $idPrefix . '-' . $i;
        $h .= '<div class="grd-crit"><label for="' . e($fid) . '"><span class="grd-crit-n">' . e((string) $c['name']) . '</span> <span class="grd-crit-m">out of ' . e(Grading::num((float) $c['max'])) . '</span></label>';
        if (!empty($c['levels'])) {
            $h .= '<ul class="grd-levels">';
            foreach ($c['levels'] as $l) $h .= '<li>' . ($l['points'] !== null ? '<b>' . e(Grading::num((float) $l['points'])) . '</b> ' : '') . e((string) $l['text']) . '</li>';
            $h .= '</ul>';
        }
        $v = isset($values[$i]) ? Grading::num((float) $values[$i]) : '';
        $h .= '<input id="' . e($fid) . '" class="grd-num" type="number" name="score[' . (int) $i . ']" min="0" max="' . e(Grading::num((float) $c['max'])) . '" step="0.5" inputmode="decimal" required value="' . e($v) . '"></div>';
    }
    return $h . '</div>';
}
