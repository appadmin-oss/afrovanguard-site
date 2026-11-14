<?php
/**
 * tests/peerreview.test.php — peer review (lib/PeerReview.php).
 *
 * The rules: the system assigns reviews, never a learner's own work; the
 * allocation is balanced so every submission gets its reviews; a reviewer
 * must have submitted first; the score is the median of the peer scores; an
 * instructor may override; and a submission still short of reviews when the
 * grace period ends falls back to instructor marking.
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

$course = gt_course('Peer Course ' . getmypid());
$lead = gt_user('Pat Lead');
CourseTeam::add($course, $lead, 'lead', CourseTeam::STUDIO);
$peer = (int) Grading::saveItem($course, ['kind' => 'peer', 'title' => 'Pitch', 'weight' => 100, 'pass_score' => 50,
    'rubric' => gt_rubric(), 'due_at' => $local($T0 + 7 * 86400), 'peer_count' => 2, 'peer_grace_hours' => 72, 'max_attempts' => 3], $lead)['id'];
ck('peer: a peer item takes exactly one attempt (reviews attach to one submission)', (int) Grading::item($peer)['max_attempts'] === 1);
Grading::publish($course, $lead);

$names = ['Amaka', 'Bisi', 'Chuka', 'Dapo'];
$u = []; $s = [];
foreach ($names as $n) { $u[$n] = gt_user($n . ' Peer'); gt_enrol($u[$n], $course); }
$late = gt_user('Zara NoSubmit'); gt_enrol($late, $course);

$r = PeerReview::assign($peer, $late);
ck('peer: a learner who has not submitted is given nothing to review', !$r['ok'] && ($r['code'] ?? '') === 'submit_first');

// Everybody submits (submit() tops up the submitter's own reviews as it goes —
// which is what makes allocation order-dependent and worth testing).
foreach ($names as $n) {
    $res = GradingWork::submit($peer, $u[$n], ['text' => $n . "'s pitch"]);
    $s[$n] = (int) $res['id'];
}
foreach ($names as $n) PeerReview::assign($peer, $u[$n]);   // top up anyone short

$own = (int) $db->query('SELECT COUNT(*) FROM peer_reviews r JOIN grade_submissions s ON s.id = r.submission_id WHERE r.item_id = ' . $peer . ' AND s.user_id = r.reviewer_id')->fetchColumn();
ck('peer: nobody is ever assigned their own work', $own === 0);
$received = $db->query('SELECT submission_id, COUNT(*) c FROM peer_reviews WHERE item_id = ' . $peer . ' GROUP BY submission_id')->fetchAll(PDO::FETCH_KEY_PAIR);
ck('peer: every submission is assigned its reviews', count($received) === 4 && min(array_map('intval', $received)) >= 2);
ck('peer: and the allocation is balanced (each exactly 2)', array_values(array_unique(array_map('intval', $received))) === [2]);
$given = $db->query('SELECT reviewer_id, COUNT(*) FROM peer_reviews WHERE item_id = ' . $peer . ' GROUP BY reviewer_id')->fetchAll(PDO::FETCH_KEY_PAIR);
ck('peer: each reviewer has exactly peer_count reviews', array_values(array_unique(array_map('intval', $given))) === [2]);
ck('peer: asking again assigns nothing more', PeerReview::assign($peer, $u['Amaka'])['assigned'] === 0);

/* ══ Reviewing ══════════════════════════════════════════════════════════ */

$tasksA = PeerReview::tasks($peer, $u['Amaka']);
$someoneElse = $u['Bisi'];
ck('peer: a review can only be written by its reviewer', !PeerReview::review((int) $tasksA[0]['id'], $someoneElse, [5, 5], 'x')['ok']);
ck('peer: a reviewer may read the work they were given', GradingWork::canSee(GradingWork::submission((int) $tasksA[0]['submission_id']), $u['Amaka']));
// A reviewer whose own submission vanished cannot review (must have submitted).
$db->prepare('UPDATE grade_submissions SET user_id = ? WHERE id = ?')->execute([999999, $s['Amaka']]);
ck('peer: a reviewer must have submitted themselves', !PeerReview::review((int) $tasksA[0]['id'], $u['Amaka'], [5, 5], 'x')['ok']);
$db->prepare('UPDATE grade_submissions SET user_id = ? WHERE id = ?')->execute([$u['Amaka'], $s['Amaka']]);

/* The median of the peer scores. Find the submission Chuka is reviewed on. */
$target = $s['Chuka'];
$revs = $db->query('SELECT id, reviewer_id FROM peer_reviews WHERE submission_id = ' . $target . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
PeerReview::review((int) $revs[0]['id'], (int) $revs[0]['reviewer_id'], [10, 10], 'Brilliant');   // 100
ck('peer: one review of two is not a score yet', GradingWork::submission($target)['final_pct'] === null);
PeerReview::review((int) $revs[1]['id'], (int) $revs[1]['reviewer_id'], [5, 7], 'Solid');          // 60
ck('peer: with its reviews done, the score is their median (100, 60 → 80)', abs((float) GradingWork::submission($target)['final_pct'] - 80.0) < 0.01
   && GradingWork::submission($target)['final_source'] === 'peer');
ck('peer: the median of an odd count is the middle value', GradingWork::median([90.0, 10.0, 60.0]) === 60.0);
ck('peer: peer-marked work still waits for release', Grading::result(Grading::item($peer), $u['Chuka'])['score'] === null);
GradingWork::release($peer, $lead);
$res = Grading::result(Grading::item($peer), $u['Chuka']);
ck('peer: released, the learner sees it with the peers\' feedback, unnamed', $res['score'] === 80.0
   && str_contains($res['feedback'], 'Peer reviewer 1: Brilliant') && !str_contains($res['feedback'], 'Peer ' . "\n"));

/* An instructor may override. */
GradingWork::mark($target, $lead, [9, 9], 'Instructor view: 90.');
ck('peer: an instructor mark overrides the peer median', abs((float) GradingWork::submission($target)['final_pct'] - 90.0) < 0.01
   && GradingWork::submission($target)['final_source'] === 'single');

/* ══ Falling back to instructor marking ═════════════════════════════════ */

$short = $s['Dapo'];
$inQueue = static fn() => in_array($short, array_map(static fn($q) => (int) $q['id'], GradingWork::queue($course, 'unmarked')), true);
ck('peer: before the grace period ends, a peer submission is the peers\' job, not the queue\'s', !$inQueue());
Grading::setClock($T0 + 7 * 86400 + 73 * 3600);
ck('peer: after due + grace, a submission short of reviews is in the instructor queue', $inQueue());
ck('peer: and reviewing has closed', !PeerReview::review((int) PeerReview::tasks($peer, $u['Bisi'])[0]['id'], $u['Bisi'], [5, 5], 'late')['ok']);
GradingWork::mark($short, $lead, [6, 6], 'Marked by the instructor: not enough peer reviews arrived.');
ck('peer: an instructor mark gives it a score', abs((float) GradingWork::submission($short)['final_pct'] - 60.0) < 0.01 && !$inQueue());

Grading::setClock(null);
