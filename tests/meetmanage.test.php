<?php
/**
 * tests/meetmanage.test.php — meetings can be changed after they are made, and
 * say before they are made whether they transcribe themselves.
 *
 *   • Suite meetings: the organiser, an administrator, and a team's lead or
 *     secretary may change or cancel one; anybody else may not.
 *   • The schedule form is told whether transcripts are automatic, and why not.
 *   • Mentorship sessions: their mentor moves or changes one still to come;
 *     a logged one, a past time and somebody else's session are refused.
 *   • A session on Google Meet switches on Google's transcript, and the cron
 *     reads it back as the session's minutes.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$me = Database::pdo();
Meetings::ensure();
Mentorship::ensure();
$me->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9959');
foreach ([9951 => ['Ada Organiser', 'member'], 9952 => ['Bola Admin', 'admin'], 9953 => ['Chi Member', 'member'], 9954 => ['Dayo Mentor', 'member'], 9955 => ['Efe Mentee', 'member']] as $id => [$n, $role]) {
    $me->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, 'me' . $id . '@afrovanguard.org.ng', 'x', $role, 'active']);
}

/* ── Suite meetings ──────────────────────────────────────────────────── */
$r = Meetings::schedule(9951, ['title' => 'ME Planning', 'when' => date('Y-m-d\TH:i', time() + 3 * 86400), 'duration' => 45, 'frequency' => 'once', 'attendees' => ['me9953@afrovanguard.org.ng']]);
$mid = (int) ($r['id'] ?? 0);
ck('suite: a meeting is made', $mid > 0);
ck('suite: its organiser may change it', Meetings::mayManage(9951, $mid));
ck('suite: so may an administrator', Meetings::mayManage(9952, $mid));
ck('suite: a member who did not make it may not', !Meetings::mayManage(9953, $mid));
$up = Meetings::update(9952, $mid, ['title' => 'ME Planning (moved)', 'duration' => 60], Meetings::mayManage(9952, $mid));
ck('suite: an administrator’s change is saved', !empty($up['ok']) && (string) $me->query('SELECT title FROM meetings WHERE id = ' . $mid)->fetchColumn() === 'ME Planning (moved)');
ck('suite: a member’s change is refused', empty(Meetings::update(9953, $mid, ['title' => 'Mine now'], Meetings::mayManage(9953, $mid))['ok']));
ck('suite: a member may not cancel it', empty(Meetings::cancel(9953, $mid, Meetings::mayManage(9953, $mid))['ok']));
ck('suite: an administrator may', !empty(Meetings::cancel(9952, $mid, Meetings::mayManage(9952, $mid))['ok']));
$api = (string) file_get_contents(AV_ROOT . '/portal/meetings.php');
ck('suite: the API passes who may manage to update and cancel, and tells the list', substr_count($api, 'Meetings::mayManage($uid') >= 3 && str_contains($api, "'can_edit'"));
ck('suite: the cards offer Edit and Cancel to whoever may', str_contains((string) file_get_contents(AV_ROOT . '/portal/meetings.js'), '!!m.can_edit'));

$cap = Meetings::captureReadiness();
ck('schedule form: without Google it says transcripts are not automatic, and why', !GoogleWorkspace::meetEnabled() ? ($cap['ready'] === false && $cap['why'] !== '') : isset($cap['ready']));
ck('schedule form: the list carries it, and the form says it before a meeting is made', str_contains($api, "'capture' => Meetings::captureReadiness()")
   && str_contains((string) file_get_contents(AV_ROOT . '/portal/meetings.js'), 'Transcripts are automatic'));

/* ── Mentorship sessions ─────────────────────────────────────────────── */
$me->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, segment, created_at, updated_at) VALUES (?,?,'active','org',?,?)")->execute([9954, 9955, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
$pair = (int) $me->lastInsertId();
$s = Mentorship::addSession(9954, $pair, 'Check-in', date('Y-m-d\TH:i', time() + 2 * 86400), 'Goals', 'https://meet.google.com/abc-defg-hij', 60, 'checkin');
$sid = (int) ($s['id'] ?? 0);
ck('mentorship: a session is scheduled', $sid > 0);
$newWhen = date('Y-m-d\TH:i', time() + 4 * 86400);
$e = Mentorship::editSession(9954, $sid, ['when' => $newWhen, 'duration_min' => 90, 'notes' => 'CV review', 'type' => 'skills', 'title' => 'Skills']);
$row = $me->query('SELECT * FROM mentor_sessions WHERE id = ' . $sid)->fetch(PDO::FETCH_ASSOC);
ck('mentorship: its mentor moves it, and changes its length, agenda and type', !empty($e['ok']) && $e['changed'] === true
   && $row['scheduled_at'] === gmdate('Y-m-d H:i:s', strtotime($newWhen)) && (int) $row['duration_min'] === 90 && $row['notes'] === 'CV review' && $row['session_type'] === 'skills');
$list = array_values(array_filter(Mentorship::sessions($pair), fn($x) => $x['id'] === $sid))[0] ?? [];
ck('mentorship: the edit form is filled with the time as it was typed', ($list['when_local'] ?? '') === $newWhen);
ck('mentorship: and the session says what Google captures in it', array_key_exists('capture', $list) && array_key_exists('capture_note', $list));
ck('mentorship: nothing changed is said', Mentorship::editSession(9954, $sid, ['when' => $newWhen])['changed'] === false);
ck('mentorship: somebody else’s session is refused', empty(Mentorship::editSession(9951, $sid, ['notes' => 'x'])['ok']));
ck('mentorship: a time that has passed is refused', empty(Mentorship::editSession(9954, $sid, ['when' => date('Y-m-d\TH:i', time() - 86400)])['ok']));
$me->prepare("UPDATE mentor_sessions SET attendance = 'attended' WHERE id = ?")->execute([$sid]);
ck('mentorship: a logged session is not moved', empty(Mentorship::editSession(9954, $sid, ['notes' => 'later'])['ok']));
ck('mentorship: without Google nothing is read back, and nothing fails', !GoogleWorkspace::meetEnabled() ? Mentorship::pullGoogleTranscripts() === 0 : true);
ck('mentorship: the cron reads Google’s transcripts back', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'Mentorship::pullGoogleTranscripts()'));
ck('mentorship: a session given a Meet switches on Google’s transcript', substr_count((string) file_get_contents(AV_ROOT . '/lib/Mentorship.php'), 'self::captureSession(') >= 3);
$view = (string) file_get_contents(AV_ROOT . '/mentorship/mentor/views/_case_sessions.php');
ck('mentor portal: a session to come has Edit, wired to session-edit', str_contains($view, 'data-avm-edit') && str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avm.js'), "api('session-edit'")
   && str_contains((string) file_get_contents(AV_ROOT . '/mentorship/api.php'), "case 'session-edit':"));
$portal = (string) file_get_contents(AV_ROOT . '/lib/MentorPortal.php');
ck('mentor portal: only on the mentor’s own session', (bool) preg_match('/function editSession\(int \$sessionId, array \$in\): array\s*\{\s*if \(!\$this->ownsSession\(\$sessionId\)\)/', $portal));

$me->exec('DELETE FROM mentor_sessions WHERE mentorship_id = ' . $pair);
$me->exec('DELETE FROM mentorships WHERE id = ' . $pair);
$me->exec("DELETE FROM meetings WHERE creator_id BETWEEN 9950 AND 9959");
$me->exec('DELETE FROM lms_users WHERE id BETWEEN 9950 AND 9959');
