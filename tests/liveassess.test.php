<?php
/**
 * tests/liveassess.test.php — live assessment sessions (lib/LiveAssess.php).
 *
 * A live item is scored by a panel in a booked session, and every score is a
 * mark through GradingWork — so these tests also prove there is no second
 * scoring path: moderation, release gating and the latest-attempt rule are
 * the assignment's own.
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

$sent = [];
$cap = static function (array $m) use (&$sent): bool { $sent[] = $m; return true; };
Mailer::fakeRoads(['smtp' => $cap, 'resend' => $cap, 'relay' => $cap, 'host' => $cap]);
putenv('AV_MAIL_DISABLED=0');

$course = gt_course('Live Course ' . getmypid());
$lead = gt_user('Lu Lead'); $ins = gt_user('In Instructor'); $asst = gt_user('As Assistant'); $out = gt_user('Ou Outsider');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
CourseTeam::add($course, $ins, 'instructor', $lead);
CourseTeam::add($course, $asst, 'assistant', $lead);

$it = Grading::saveItem($course, ['kind' => 'live', 'title' => 'Viva', 'weight' => 100, 'pass_score' => 50,
    'rubric' => gt_rubric(), 'max_attempts' => 2, 'moderation_pct' => 15], $lead);
ck('live: a live item is created with a rubric', $it['ok']);
$item = (int) $it['id'];

/* ══ Sessions ═══════════════════════════════════════════════════════════ */

$sess = static fn(int $start, int $capN, int $by) => LiveAssess::saveSession($item, ['starts_at' => $local($start), 'ends_at' => $local($start + 2 * 3600),
    'place' => 'CACENTRE hall', 'link' => '', 'capacity' => $capN], $by);
ck('live: an assistant cannot schedule a session', !$sess($T0 + 3 * 86400, 2, $asst)['ok']);
ck('live: a session must end after it starts', !LiveAssess::saveSession($item, ['starts_at' => $local($T0 + 86400), 'ends_at' => $local($T0 + 86400 - 60), 'place' => 'x', 'capacity' => 2], $lead)['ok']);
ck('live: a session needs a place or a link', !LiveAssess::saveSession($item, ['starts_at' => $local($T0 + 86400), 'ends_at' => $local($T0 + 90000), 'capacity' => 2], $lead)['ok']);
$S1 = (int) $sess($T0 + 3 * 86400, 2, $ins)['id'];
$S2 = (int) $sess($T0 + 5 * 86400, 5, $lead)['id'];
ck('live: sessions are stored in Lagos time as UTC', LiveAssess::session($S1)['starts_at'] === gmdate('Y-m-d H:i:s', $T0 + 3 * 86400));

$A = gt_user('Ama Live'); $B = gt_user('Bayo Live'); $C = gt_user('Chi Live'); $D = gt_user('Dami Live'); $E = gt_user('Efe Live');
foreach ([$A, $B, $C, $D, $E] as $x) gt_enrol($x, $course);
$stranger = gt_user('Not Enrolled');

/* ══ Booking: one per learner per item, capacity refused ════════════════ */

ck('live: nothing can be booked on a draft item', !LiveAssess::book($S1, $A, $A)['ok']);
Grading::publish($course, $lead);
ck('live: somebody not on the course cannot book', !LiveAssess::book($S1, $stranger, $stranger)['ok']);
ck('live: a learner books a slot', LiveAssess::book($S1, $A, $A)['ok']);
ck('live: booking the same session again makes no second booking', LiveAssess::book($S1, $A, $A)['ok']
   && (int) $db->query("SELECT COUNT(*) FROM live_bookings WHERE item_id = $item AND user_id = $A")->fetchColumn() === 1);
$rb = LiveAssess::book($S2, $A, $A);
ck('live: booking another session MOVES the booking (one per learner per item)', $rb['ok']
   && (int) $db->query("SELECT COUNT(*) FROM live_bookings WHERE item_id = $item AND user_id = $A AND live_key = 'live'")->fetchColumn() === 1
   && (int) LiveAssess::bookingFor($item, $A)['session_id'] === $S2);
$dup = false;
try { $db->prepare("INSERT INTO live_bookings (session_id, item_id, user_id, live_key, status) VALUES (?,?,?,'live','booked')")->execute([$S1, $item, $A]); }
catch (Throwable $e) { $dup = true; }
ck('live: the database itself refuses a second active booking', $dup);
ck('live: the freed seat counts again', LiveAssess::taken($S1) === 0);
ck('live: an outsider cannot book somebody in', !LiveAssess::book($S1, $B, $out)['ok']);
ck('live: the team can assign a learner to a session', LiveAssess::book($S1, $B, $asst)['ok']);
ck('live: a second learner fills the session', LiveAssess::book($S1, $C, $C)['ok']);
$full = LiveAssess::book($S1, $D, $D);
ck('live: a full session refuses the next booking', !$full['ok'] && ($full['code'] ?? '') === 'full');
ck('live: and the team cannot overfill it either', !LiveAssess::book($S1, $E, $lead)['ok']);
LiveAssess::book($S2, $D, $D); LiveAssess::book($S2, $E, $E);

/* ══ On the day: the panel scores, moderation applies ═══════════════════ */

Grading::setClock($T0 + 3 * 86400 + 600);
ck('live: a session that has started cannot be booked', !LiveAssess::book($S1, $E, $E)['ok']);
$roster = LiveAssess::roster($S1);
ck('live: the roster lists the session\'s learners in booking order', array_map(static fn($r) => (int) $r['user_id'], $roster) === [$B, $C]);
$bB = (int) $roster[0]['id']; $bC = (int) $roster[1]['id'];
ck('live: an outsider cannot score', !LiveAssess::score($bB, $out, [5, 5], 'x')['ok']);
$p1 = LiveAssess::score($bB, $asst, [10, 10], 'Confident and clear.');    // 100
ck('live: a panelist (even an assistant) scores the learner in front of them', $p1['ok']);
ck('live: scoring marks the learner present', LiveAssess::booking($bB)['status'] === 'present');
$p2 = LiveAssess::score($bB, $ins, [8, 8], 'Good, a little rushed.');     // 80 — 20 apart
ck('live: two panelists further apart than the threshold are flagged — the assignment\'s moderation rule', $p2['ok'] && $p2['flagged']);
ck('live: one mark per panelist', (int) $db->query('SELECT COUNT(*) FROM grade_marks WHERE submission_id = ' . (int) LiveAssess::booking($bB)['submission_id'])->fetchColumn() === 2);

/* Idempotent save: the same panelist sending the same mark again. */
$auditBefore = (int) $db->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'grading.mark'")->fetchColumn();
$again = LiveAssess::score($bB, $ins, [8, 8], 'Good, a little rushed.');
ck('live: a retried save changes nothing — no second mark, no second submission', $again['ok'] && !empty($again['unchanged'])
   && (int) $db->query('SELECT COUNT(*) FROM grade_marks WHERE submission_id = ' . (int) LiveAssess::booking($bB)['submission_id'])->fetchColumn() === 2
   && (int) $db->query("SELECT COUNT(*) FROM grade_submissions WHERE item_id = $item AND user_id = $B")->fetchColumn() === 1);
ck('live: and writes no second audit entry', (int) $db->query("SELECT COUNT(*) FROM lms_audit WHERE action = 'grading.mark'")->fetchColumn() === $auditBefore);

GradingWork::agree((int) LiveAssess::booking($bB)['submission_id'], $lead, 18, 'Panel agreed after discussion');
$liveItem = Grading::item($item);
ck('live: the agreed score is not seen before release', Grading::result($liveItem, $B)['score'] === null && Grading::result($liveItem, $B)['status'] === 'marked');
GradingWork::release($item, $ins);
$res = Grading::result($liveItem, $B);
ck('live: after release the learner sees it, with the panel\'s notes', $res['score'] === 90.0 && str_contains($res['feedback'], 'Confident'));
ck('live: and it is their course grade — the same gradebook path', abs(Grading::standing($B, $course)['grade'] - 90.0) < 0.01);

/* ══ Absent, and excused ════════════════════════════════════════════════ */

ck('live: an assistant may record an absence', LiveAssess::presence($bC, 'absent', $asst)['ok']);
ck('live: an absent learner cannot be scored', !LiveAssess::score($bC, $ins, [5, 5], 'x')['ok']);
ck('live: an absence uses an attempt', LiveAssess::attemptsUsed($item, $C) === 1);
ck('live: an assistant cannot excuse it', !LiveAssess::excuse($bC, $asst, 'ill')['ok']);
ck('live: excusing needs a reason', !LiveAssess::excuse($bC, $ins, '')['ok']);
ck('live: an instructor excuses it with a reason', LiveAssess::excuse($bC, $ins, 'Hospital appointment, letter seen')['ok']);
ck('live: an excused absence uses no attempt', LiveAssess::attemptsUsed($item, $C) === 0);
ck('live: and the learner can rebook', LiveAssess::book($S2, $C, $C)['ok']);

/* Two unexcused absences exhaust two attempts: missed, scoring 0. */
Grading::setClock($T0 + 5 * 86400 + 600);
$bD = (int) LiveAssess::bookingFor($item, $D)['id'];
LiveAssess::presence($bD, 'absent', $lead);
ck('live: one absence of two attempts is not yet missed', Grading::result($liveItem, $D)['status'] === 'not_started');
Grading::setClock($T0 + 4 * 86400);
$S3 = (int) $sess($T0 + 6 * 86400, 5, $lead)['id'];
ck('live: after an absence the learner may book again while attempts remain', LiveAssess::book($S3, $D, $D)['ok']);
Grading::setClock($T0 + 6 * 86400 + 600);
LiveAssess::presence((int) LiveAssess::bookingFor($item, $D)['id'], 'absent', $lead);
$rd = Grading::result($liveItem, $D);
ck('live: absent for every attempt is a missed item scoring 0', $rd['status'] === 'missed' && $rd['score'] === 0.0);
Grading::setClock($T0 + 4 * 86400);
ck('live: and no attempt is left to book', !LiveAssess::book($S3, $D, $D)['ok']);

/* ══ The reminder the day before, once ══════════════════════════════════ */

$sent = [];
Grading::setClock($T0 + 5 * 86400 - 20 * 3600);   // 20 hours before session 2
$n = LiveAssess::remind();
$who = array_map(static fn($m) => strtolower((string) $m['to']), $sent);
ck('live: the day before, every learner booked into the session is reminded', $n === 3 && count($sent) === 3);
ck('live: the reminder names the time and place', str_contains((string) $sent[0]['html'], 'CACENTRE hall'));
ck('live: the cron running again sends nothing more', LiveAssess::remind() === 0 && count($sent) === 3);

/* ══ The screens: the panel and the learner's booking ═══════════════════ */

$slug = (string) $db->query('SELECT slug FROM courses WHERE id = ' . $course)->fetchColumn();
gt_sign_in($ins);
$panel = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'live', 'session' => (string) $S2]);
ck('live: the panel screen lists the session roster with score forms', str_contains($panel, 'Ama Live') && str_contains($panel, 'value="live_score"') && !str_contains($panel, 'render_page: threw'));
ck('live: every panel form carries a CSRF field', substr_count($panel, '<form method="post"') === substr_count($panel, 'name="csrf"'));
$edit = render_page(AV_ROOT . '/academy/teach.php', ['course' => $slug, 'tab' => 'items', 'item' => (string) $item]);
ck('live: the item page lists its sessions and schedules more', str_contains($edit, 'Live sessions') && str_contains($edit, 'value="session_save"'));
gt_sign_in($A);
$mine = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug, 'item' => (string) $item]);
ck('live: the learner sees their session, its time and place', str_contains($mine, 'Your session') && str_contains($mine, 'CACENTRE hall') && str_contains($mine, 'Lagos time'));
$sum = render_page(AV_ROOT . '/academy/grades.php', ['course' => $slug]);
ck('live: and the session time on their grades list', str_contains($sum, 'Session '));
gt_sign_in(0);

Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED=1');
Grading::setClock(null);
