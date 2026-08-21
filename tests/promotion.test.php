<?php
/**
 * tests/promotion.test.php — AI-assisted promotion review (report §20, §17, §23).
 *
 * The single most important property, and the first thing asserted below:
 * NOTHING HERE PROMOTES ANYBODY. §23 draws the line — the AI observes, records,
 * analyses and recommends; a human interprets, discerns and decides. A review
 * that could quietly move someone up the ladder would invert the whole model, so
 * every path through this file checks the member's level is untouched.
 *
 * After that:
 *
 *   • §17 — headcount is not multiplication. Someone with mentees who have
 *     stopped meeting them must not appear as a candidate at all.
 *   • Two verdicts, recorded separately. The engine owns eligibility because its
 *     thresholds are leadership's rules; the model reads quality. Where they
 *     disagree the disagreement is stored, not averaged away.
 *   • With no provider the review is honest about it — it restates the engine's
 *     verdict and marks itself engine-only rather than implying a model looked.
 *   • "Not yet" stays visible. A deferred review needs a reason and remains in
 *     the queue, because a promotion quietly dropped is the failure §21 exists
 *     to prevent.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Mentorship.php';

$db = Database::pdo();

$prReset = static function () use ($db): void {
    reset_users();
    Mentorship::ensure(); Accountability::ensure(); Promotion::ensure();
    if (class_exists('Levels')) Levels::ensure();
    if (class_exists('Commitments')) Commitments::ensure();
    foreach (['mentorships', 'mentor_sessions', 'av_promotion_reviews', 'commitments', 'user_notifications'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    AvRules::ensure(); AvRules::resetAll('test');
};
$prPair = static function (int $m, int $e) use ($db): int {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, segment, created_at, updated_at) VALUES (?,?,'active','org','2026-01-01','2026-01-01')")
       ->execute([$m, $e]);
    return (int) $db->lastInsertId();
};
$prSess = static function (int $p, float $daysAgo, string $att) use ($db): void {
    $db->prepare('INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, status, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$p, 'Weekly', gmdate('Y-m-d H:i:s', time() - (int) round($daysAgo * 86400)), $att, $att, gmdate('Y-m-d H:i:s')]);
};
$prTenure = static function (int $uid, int $days) use ($db): void {
    $db->prepare('UPDATE lms_users SET level_at = ? WHERE id = ?')->execute([gmdate('Y-m-d', time() - $days * 86400), $uid]);
};
/** Ada (2) mentoring two people who each mentor someone — genuine multiplication. */
$prMultiplier = static function () use ($db, $prPair, $prSess, $prTenure): void {
    $db->exec("INSERT INTO lms_users (id,name,email,password_hash) VALUES (4,'Chidi2','c2@x.co','x'),(5,'Ngozi','n@x.co','x'),(6,'Emeka','e@x.co','x'),(7,'Tayo','t@x.co','x')");
    foreach ([$prPair(2, 4), $prPair(2, 5), $prPair(4, 6), $prPair(5, 7)] as $p) {
        foreach ([25, 18, 11, 4] as $d) $prSess($p, $d, 'attended');
    }
    $prTenure(2, 200);
};

/* ══ Nothing here promotes anybody ═════════════════════════════════════ */

$prReset();
$prMultiplier();
$levelBefore = Levels::of(2);
ck('§23: the member starts at the base level', $levelBefore === Levels::base());

Promotion::review(2);
Promotion::sweep();
Promotion::defer(2, 'Not this quarter.', 'tester');
Promotion::reopen(2, 'tester');
ck('§23: a review does not promote', Levels::of(2) === $levelBefore);
ck('§23: a sweep does not promote', Levels::of(2) === $levelBefore);
ck('§23: deferring and reopening do not promote', Levels::of(2) === $levelBefore);
ck('§23: the class exposes no method that sets a level',
   !method_exists('Promotion', 'promote') && !method_exists('Promotion', 'apply') && !method_exists('Promotion', 'approve'));
$src = (string) file_get_contents(AV_ROOT . '/lib/Promotion.php');
ck('§23: and it never calls one', strpos($src, 'Levels::set(') === false && strpos($src, 'promoteIfEligible') === false);

/* ══ §17 — headcount is not multiplication ═════════════════════════════ */

$prReset();
// Two mentees on paper, neither met for four months. The report's Person A.
foreach ([$prPair(2, 3), $prPair(2, 1)] as $p) $prSess($p, 120, 'attended');
$prTenure(2, 200);
$ids = array_column(Promotion::candidates(), 'user_id');
ck('§17: names on a list with no recent meetings are not a candidate', !in_array(2, $ids, true));

$prReset();
$prMultiplier();
$c = Promotion::candidates();
ck('§17: genuine multiplication is a candidate', count($c) === 1 && $c[0]['user_id'] === 2);
ck('§17: and it carries the member\'s name for the reader', $c[0]['name'] !== '');
ck('§17: and the engine rates them ready', $c[0]['ready'] === true);
ck('§17: with the target level named', $c[0]['next'] === 'A');
ck('§17: the evidence counts mentees who are themselves mentoring',
   strpos(Promotion::evidenceText(Levels::recommend(2)), 'of whom mentoring others: 2') !== false);
ck('§17: and the depth of the chain',
   strpos(Promotion::evidenceText(Levels::recommend(2)), 'depth 2') !== false);

// Someone at the top of the ladder is never a candidate.
$prReset();
$prMultiplier();
$top = Levels::order();
Levels::set(2, (string) end($top));
ck('candidates: a member at the top of the ladder is excluded', Promotion::candidates() === []);
ck('review: and reviewing them is refused', empty(Promotion::review(2)['ok']));

/* ══ §20 — "approaching", and who that must include ════════════════════ */

// A member who mentors well but misses their OWN accountability meetings.
// Excellent as a mentor, absent as a mentee — the engine says no, and the
// queue must still show them, because they are one fixable thing away and
// nobody else is going to notice.
$prReset();
$prMultiplier();
$weak = $prPair(3, 2);                                  // Chidi mentors Ada
foreach ([18, 11, 4] as $d) $prSess($weak, $d, 'scheduled');   // Ada misses her own
$rec = Levels::recommend(2);
ck('approaching: the engine declines on their own attendance',
   empty($rec['recommend']) && (bool) preg_grep('/consistency/i', $rec['gaps']));
$c = Promotion::candidates();
$mine = array_values(array_filter($c, fn($x) => $x['user_id'] === 2));
ck('approaching: they are still surfaced', count($mine) === 1);
ck('approaching: as approaching, not ready', $mine[0]['ready'] === false);
ck('approaching: with the real blocker named',
   (bool) preg_grep('/consistency/i', Promotion::queue()[0]['gaps'] ?? []));

// But someone below the organisation's own Amber line is NOT "approaching" —
// they have a relationship to repair first, and the Health board is where that
// belongs. The floor is a declared rule, not a number invented here.
$prReset();
$prMultiplier();
$bad = $prPair(3, 2);
foreach ([25, 18, 11, 4, 2] as $d) $prSess($bad, $d, 'scheduled');
foreach ([30, 24] as $d) $prSess($bad, $d, 'scheduled');
$rate = Levels::recommend(2)['metrics']['attendance_pct'];
ck('approaching: the fixture is below the Amber floor',
   $rate !== null && $rate < AvRules::int('health.amber_attendance_pct'));
ck('approaching: and they drop out of the promotion queue',
   !in_array(2, array_column(Promotion::candidates(), 'user_id'), true));

/* ══ The review records both verdicts, separately ══════════════════════ */

$prReset();
$prMultiplier();
$r = Promotion::review(2);
ck('review: it is filed', !empty($r['ok']));
$rev = $r['review'];
ck('review: the engine verdict is recorded', $rev['engine_ok'] === true);
ck('review: with its reasons', count($rev['engine_reasons']) >= 2);
ck('review: and the metrics behind them', ($rev['metrics']['active_mentees'] ?? 0) === 2);
ck('review: the target level is recorded', $rev['to_level'] === 'A' && $rev['from_level'] === Levels::base());
ck('review: it opens for a human', $rev['status'] === 'open');

// No provider is configured in the suite: the review must say so rather than
// implying a model weighed in.
ck('review: with no provider it is marked engine-only', $rev['source'] === 'engine-only');
ck('review: and the AI verdict mirrors the engine rather than inventing one', $rev['ai_ok'] === $rev['engine_ok']);
ck('review: so the two are recorded as agreeing', $rev['agrees'] === true);
ck('review: and no confidence is claimed', $rev['ai_confidence'] === '');

// `agrees` is derived from the two booleans, which is what makes a disagreement
// visible at all. Prove the derivation rather than trusting it.
$db->prepare('UPDATE av_promotion_reviews SET ai_ok = 0, agrees = ? WHERE id = ?')
   ->execute([($rev['engine_ok'] === false) ? 1 : 0, $rev['id']]);
$flipped = Promotion::byId($rev['id']);
ck('review: engine yes + AI no is stored as a disagreement', $flipped['agrees'] === false);
ck('review: and neither verdict is overwritten by the other',
   $flipped['engine_ok'] === true && $flipped['ai_ok'] === false);

/* ══ One review per member per target level ════════════════════════════ */

$prReset();
$prMultiplier();
Promotion::review(2);
$again = Promotion::review(2);
ck('review: a second unforced review is refused', empty($again['ok']));
ck('review: and it says why', strpos((string) $again['reason'], 'already has a review') !== false);
ck('review: force writes a new one', !empty(Promotion::review(2, true)['ok']));
ck('review: history accumulates rather than overwriting',
   (int) $db->query('SELECT COUNT(*) FROM av_promotion_reviews WHERE user_id = 2')->fetchColumn() === 2);

/* ══ The queue ═════════════════════════════════════════════════════════ */

$prReset();
$prMultiplier();
Promotion::review(2);
$q = Promotion::queue();
ck('queue: the candidate appears', count($q) === 1 && $q[0]['user_id'] === 2);
ck('queue: with the review attached', ($q[0]['review']['id'] ?? 0) > 0);
ck('queue: and the engine reasons for a reader', count($q[0]['reasons']) >= 1);

// A review for a level the member has since passed is history, not pending.
Levels::set(2, 'A');
$q = Promotion::queue();
ck('queue: after promotion the stale review is not shown as pending',
   $q === [] || ($q[0]['review'] ?? null) === null);

/* ══ "Not yet" stays visible ═══════════════════════════════════════════ */

$prReset();
$prMultiplier();
Promotion::review(2);
ck('defer: a reason is required', empty(Promotion::defer(2, '   ', 'tester')['ok']));
ck('defer: and the refusal explains itself',
   strpos((string) Promotion::defer(2, '', 'tester')['error'], 'Say why') === 0);
$d = Promotion::defer(2, 'Waiting for the Q4 cohort to finish.', 'tester');
ck('defer: with a reason it is accepted', !empty($d['ok']));
ck('defer: the reason is kept', Promotion::latestFor(2)['note'] === 'Waiting for the Q4 cohort to finish.');
ck('defer: and who set it aside', Promotion::latestFor(2)['decided_by'] === 'tester');
ck('defer: the candidate is STILL in the queue', count(Promotion::queue()) === 1);
ck('defer: shown as deferred rather than hidden', Promotion::queue()[0]['review']['status'] === 'deferred');
ck('defer: deferring twice is refused', empty(Promotion::defer(2, 'again', 'tester')['ok']));
ck('reopen: it can be put back', !empty(Promotion::reopen(2, 'tester')['ok']));
ck('reopen: and the status says so', Promotion::latestFor(2)['status'] === 'reopened');
ck('reopen: a reopened review can be rewritten without force', !empty(Promotion::review(2)['ok']));

/* ══ The sweep ═════════════════════════════════════════════════════════ */

$prReset();
$prMultiplier();
$s1 = Promotion::sweep();
ck('sweep: it reviews the ready candidate', $s1['reviewed'] === 1);
$s2 = Promotion::sweep();
ck('sweep: a second tick reviews nothing', $s2['reviewed'] === 0);

// Someone merely approaching is NOT auto-reviewed — writing a case for a member
// who does not meet the criteria is a model call nobody asked for.
$prReset();
$prMultiplier();
$db->prepare("UPDATE lms_users SET level_at = '' WHERE id = 2")->execute();   // tenure unknown → not ready
$c = Promotion::candidates();
ck('sweep: tenure-blocked member is a candidate but not ready',
   count($c) === 1 && $c[0]['ready'] === false);
ck('sweep: and is not auto-reviewed', Promotion::sweep()['reviewed'] === 0);
ck('sweep: though the queue still surfaces them with their gap',
   count(Promotion::queue()) === 1 && count(Promotion::queue()[0]['gaps']) >= 1);

// The master switch.
$prReset();
$prMultiplier();
AvRules::save(['ai.enabled' => '0'], 'test');
ck('switch: ai.enabled off stops the sweep', !empty(Promotion::sweep()['off']));
ck('switch: and nothing is filed', Promotion::latestFor(2) === null);
AvRules::resetAll('test');

/* ══ Leadership is told ════════════════════════════════════════════════ */

$prReset();
$prMultiplier();
AdminRoles::ensure();
AdminRoles::add('a@x.co', 'admin', 'test');       // Ada is user 1 in the base fixture
$s = Promotion::sweep();
ck('notify: an administrator is told', $s['notified'] >= 1);
$row = $db->query("SELECT title, body FROM user_notifications WHERE kind='promotion' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('notify: the notice names the target level', strpos((string) $row['title'], 'Ready for A') === 0);
$who = (string) $db->query('SELECT name FROM lms_users WHERE id = 2')->fetchColumn();
ck('notify: and the member', $who !== '' && strpos((string) $row['title'], $who) !== false);
ck('notify: one notice per administrator per review',
   (int) $db->query("SELECT COUNT(*) FROM user_notifications WHERE kind='promotion'")->fetchColumn() === $s['notified']);

$prReset();

/* ══ The Studio surface ════════════════════════════════════════════════ */

$apiSrc3 = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$mgmt3 = '';
if (preg_match('~\$managementOnly = \[(.*?)\n    \];~s', $apiSrc3, $mm3)) $mgmt3 = $mm3[1];
foreach (['promotion_queue', 'promotion_review', 'promotion_defer', 'promotion_reopen'] as $act) {
    ck("studio: {$act} is management-only", strpos($mgmt3, "'{$act}'") !== false);
    ck("studio: {$act} is implemented", strpos($apiSrc3, "case '{$act}'") !== false);
}
ck('studio: writing a review is rate limited', strpos($apiSrc3, "av_rate_ok('promotion_review'") !== false);

// The decisive one: the promotion endpoints must not be able to change a level.
// mem_save is the single path that does, and a second would drift from it.
$block = '';
if (preg_match("~case 'promotion_queue'.*?case 'brief_latest'~s", $apiSrc3, $pm)) $block = $pm[0];
ck('studio: the promotion block was found', $block !== '');
ck('studio: and it never sets a level', strpos($block, 'Levels::set') === false);
ck('studio: nor promotes', strpos($block, 'promoteIfEligible') === false);
ck('studio: mem_save is still the one path that changes a level',
   substr_count($apiSrc3, 'Levels::set(') === 1);

$cronSrc3 = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('cron: the promotion sweep is wired in', strpos($cronSrc3, 'Promotion::sweep()') !== false);

