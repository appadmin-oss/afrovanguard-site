<?php
/**
 * tests/accountability.test.php — the engine that acts on the rules.
 *
 * This subsystem sends real messages to real people and walks up the mentorship
 * chain when a relationship keeps failing, so the properties worth pinning are
 * the ones whose failure is embarrassing rather than merely wrong:
 *
 *   • It must not escalate history. Switching the engine on must not mail every
 *     mentor in the organisation about misses from six months ago.
 *   • It must not escalate twice for the same miss, or twice in one day, or
 *     inside the cooldown.
 *   • It must count a meeting nobody recorded as a miss — §6's worked example is
 *     exactly that case, and it is the one a naive query misses.
 *   • It must reach the mentor's own mentor ONLY on the final step.
 *   • It must still say something useful when no AI provider is configured,
 *     because silence is the failure this whole subsystem exists to prevent.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Mentorship.php';

$db = Database::pdo();

$freshRules = static function (array $over = []): void {
    AvRules::ensure();
    AvRules::resetAll('test');
    if ($over) AvRules::save($over, 'test');
};

/** Wipe every table this engine reads or writes, and start from a known day. */
$reset = static function () use ($db): void {
    reset_users();
    Mentorship::ensure();
    Accountability::ensure();
    foreach (['mentorships', 'mentor_sessions', 'av_escalations', 'user_notifications'] as $t) {
        try { $db->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    Database::metaSet('accountability_from', '2020-01-01');   // history is fair game unless a test says otherwise
    Database::metaSet('accountability_last_run', '');
};

/** An active pairing, returning its id. */
$pair = static function (int $mentor, int $mentee) use ($db): int {
    $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (?,?,'active',?,?)")
       ->execute([$mentor, $mentee, '2026-01-01', '2026-01-01']);
    return (int) $db->lastInsertId();
};

/** A session $daysAgo in the past (negative = future) with a given attendance. */
$session = static function (int $pairId, float $daysAgo, string $attendance) use ($db): int {
    $db->prepare('INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, attendance, status, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$pairId, 'Check-in', gmdate('Y-m-d H:i:s', time() - (int) round($daysAgo * 86400)),
                  $attendance, $attendance, gmdate('Y-m-d H:i:s')]);
    return (int) $db->lastInsertId();
};

$notifCount = static function (int $uid) use ($db): int {
    $s = $db->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ?');
    $s->execute([$uid]);
    return (int) $s->fetchColumn();
};

/** Escalation notices only — NOT the §13 scheduling nudge, which is a different
 *  thing that a quiet pairing legitimately earns on its own account. */
$escCount = static function (int $uid) use ($db): int {
    $s = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND dedupe_key LIKE 'acct:esc:%'");
    $s->execute([$uid]);
    return (int) $s->fetchColumn();
};

$freshRules();
$reset();

/* ══ §15 — health reads the thresholds, and shows its working ═══════════ */

$p = $pair(1, 2);
foreach ([40, 33, 26, 19, 12] as $d) $session($p, $d, 'attended');
$session($p, 5, 'attended');
$session($p, -3, 'scheduled');                          // something is booked
$h = Accountability::health($p);
ck('health: a pairing meeting reliably is green', $h['status'] === 'green');
ck('health: green reports 100% attendance', $h['rate'] === 100);
ck('health: it gives a reason, not just a grade', $h['reasons'] !== []);

$reset();
$p = $pair(1, 2);
foreach ([40, 33, 26] as $d) $session($p, $d, 'attended');
$session($p, 5, 'missed');
$session($p, -3, 'scheduled');
$h = Accountability::health($p);
ck('health: one miss drops it to amber', $h['status'] === 'amber');
ck('health: the miss streak is 1', $h['miss_streak'] === 1);
ck('health: the reason names the missed meeting',
   (bool) preg_grep('/not attended/', $h['reasons']));

$reset();
$p = $pair(1, 2);
$session($p, 40, 'attended');
foreach ([26, 19, 12] as $d) $session($p, $d, 'missed');  // red_missed_streak default 3
$h = Accountability::health($p);
ck('health: three misses in a row is red', $h['status'] === 'red');
ck('health: the streak is counted', $h['miss_streak'] === 3);
ck('health: attendance rate is computed alongside', $h['rate'] === 25);

// §6's case: the meeting simply never got recorded. A query looking only for
// attendance='missed' finds nothing here, which is how it stays invisible.
$reset();
$p = $pair(1, 2);
$session($p, 40, 'attended');
foreach ([26, 19, 12] as $d) $session($p, $d, 'scheduled');   // past, never recorded
$h = Accountability::health($p);
ck('health: meetings nobody recorded count as misses', $h['miss_streak'] === 3);
ck('health: and they turn it red like any other miss', $h['status'] === 'red');

// A cancelled session is not a miss — it was called off, not skipped.
$reset();
$p = $pair(1, 2);
$session($p, 20, 'attended');
$session($p, 10, 'cancelled');
$session($p, -2, 'scheduled');
$h = Accountability::health($p);
ck('health: a cancelled meeting is not a miss', $h['miss_streak'] === 0);
ck('health: so the pairing stays green', $h['status'] === 'green');

// The thresholds are read, not hardcoded.
$reset();
$freshRules(['health.red_missed_streak' => 2]);
$p = $pair(1, 2);
$session($p, 30, 'attended');
$session($p, 20, 'missed');
$session($p, 10, 'missed');
ck('health: a tightened red streak rule takes effect', Accountability::health($p)['status'] === 'red');
$freshRules();

/* ══ Cold start — history is never escalated retroactively ═════════════ */

$reset();
Database::metaSet('accountability_from', gmdate('Y-m-d'));   // engine switched on today
$p = $pair(1, 2);
foreach ([90, 60, 30] as $d) $session($p, $d, 'missed');     // all before the watermark
$e = Accountability::escalationDue($p);
ck('cold start: misses predating the watermark do not escalate', $e['due'] === false);
ck('cold start: and it says why', strpos($e['why_not'], 'no unattended session') === 0);

$out = Accountability::sweep(true);
ck('cold start: a first sweep escalates nothing', $out['escalations'] === 0);
ck('cold start: and nobody is escalated about history', $escCount(1) === 0 && $escCount(2) === 0);

/* ══ §14 — the ladder ══════════════════════════════════════════════════ */

$reset();
$p = $pair(1, 2);                    // 1 mentors 2
$leaderPair = $pair(3, 1);           // 3 mentors 1  → 3 is the mentor's own leader
ck('ladder: the mentor\'s own leader is found by walking the tree', Accountability::leaderOf(1) === 3);
ck('ladder: a mentor with no mentor of their own has no leader', Accountability::leaderOf(3) === 0);

$session($p, 2, 'missed');
$e = Accountability::escalationDue($p);
ck('ladder: an unattended meeting is due for step 1', $e['due'] === true && $e['step'] === 1);

$out = Accountability::sweep(true);
ck('ladder: step 1 escalates', $out['escalations'] === 1);
ck('ladder: both people in the pair are told', $escCount(1) >= 1 && $escCount(2) >= 1);
ck('ladder: the leader is NOT told at step 1', $out['chain_notices'] === 0);
ck('ladder: it is recorded', count(Accountability::historyFor($p)) === 1);

// Same miss, next day: nothing further. The session is already escalated.
Database::metaSet('accountability_last_run', '');
$out = Accountability::sweep(true);
ck('ladder: the same miss never escalates twice', $out['escalations'] === 0);
ck('ladder: and the reason is the session, not the cooldown',
   strpos(Accountability::escalationDue($p)['why_not'], 'already escalated') === 0);

/* ══ Cooldown ══════════════════════════════════════════════════════════ */

$session($p, 1, 'missed');                       // a second, different miss
$e = Accountability::escalationDue($p);
ck('cooldown: a fresh miss inside the window is held', $e['due'] === false);
ck('cooldown: and it says so', strpos($e['why_not'], 'within the') === 0);

// Age the recorded escalation past the cooldown and it proceeds — to step 2.
$db->exec("UPDATE av_escalations SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 86400) . "'");
$e = Accountability::escalationDue($p);
ck('cooldown: once elapsed, the next miss escalates', $e['due'] === true);
ck('cooldown: and it is step 2, not step 1 again', $e['step'] === 2);

Database::metaSet('accountability_last_run', '');
$out = Accountability::sweep(true);
ck('ladder: step 2 still does not involve the leader', $out['chain_notices'] === 0);

/* ══ The final rung reaches the leader ═════════════════════════════════ */

$db->exec("UPDATE av_escalations SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 86400) . "'");
$session($p, 0.25, 'missed');
$before = $escCount(3);
Database::metaSet('accountability_last_run', '');
$out = Accountability::sweep(true);
ck('ladder: step 3 is the final step', $out['escalations'] === 1);
ck('ladder: the mentor\'s own leader is told', $out['chain_notices'] === 1);
ck('ladder: and actually receives it', $escCount(3) > $before);

// escalation.notify_chain off means the chain is never walked.
$reset();
$freshRules(['escalation.notify_chain' => '0']);
$p = $pair(1, 2); $pair(3, 1);
$db->exec('DELETE FROM av_escalations');
for ($i = 0; $i < 3; $i++) {
    $session($p, 3 - $i - 0.25, 'missed');
    $db->exec("UPDATE av_escalations SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 86400) . "'");
    Database::metaSet('accountability_last_run', '');
    $out = Accountability::sweep(true);
}
ck('ladder: with notify_chain off the leader is never told', $escCount(3) === 0);
$freshRules();

/* ══ Idempotence — cron ticks every few minutes ════════════════════════ */

$reset();
$p = $pair(1, 2);
$session($p, 2, 'missed');
$first = Accountability::sweep();                 // not forced: claims today
ck('sweep: the first run of the day works', $first['skipped'] === false);
$second = Accountability::sweep();
ck('sweep: a second run the same day is skipped', $second['skipped'] === true);
ck('sweep: and it says why', ($second['why'] ?? '') === 'already ran today');
ck('sweep: so nothing is sent twice', count(Accountability::historyFor($p)) === 1);

/* ══ §13 — reminders and scheduling nudges ═════════════════════════════ */

$reset();
$p = $pair(1, 2);
$session($p, 20, 'attended');
$session($p, -1, 'scheduled');                    // ~24h away, inside the default lead
ck('cycle: a meeting inside the reminder window is found', count(Accountability::dueSoon()) === 1);
$out = Accountability::sweep(true);
ck('cycle: both people are reminded', $out['reminders'] === 2);
ck('cycle: a booked pairing is not nudged to schedule', $out['schedule_nudges'] === 0);

// A meeting well outside the window is left alone.
$reset();
$p = $pair(1, 2);
$session($p, 20, 'attended');
$session($p, -30, 'scheduled');
ck('cycle: a distant meeting is not reminded yet', Accountability::dueSoon() === []);

// Nothing booked and the cadence has elapsed → nudge the mentor.
$reset();
$p = $pair(1, 2);
$session($p, 20, 'attended');                     // last met 20 days ago, nothing next
ck('cycle: a pairing with nothing booked is found', count(Accountability::needsScheduling()) === 1);
$out = Accountability::sweep(true);
ck('cycle: the mentor is nudged to schedule', $out['schedule_nudges'] === 1);

// Met yesterday, nothing booked yet — inside the cadence, so leave them be.
$reset();
$p = $pair(1, 2);
$session($p, 1, 'attended');
ck('cycle: a pairing that just met is not nudged', Accountability::needsScheduling() === []);

/* ══ The master switch, and the no-provider path ═══════════════════════ */

$reset();
$freshRules(['ai.enabled' => '0']);
$p = $pair(1, 2);
$session($p, 2, 'missed');
$out = Accountability::sweep(true);
ck('switch: ai.enabled off stops the sweep', $out['skipped'] === true);
ck('switch: and nothing is sent', $notifCount(1) === 0 && $notifCount(2) === 0);
ck('switch: and nothing is recorded', Accountability::historyFor($p) === []);
$freshRules();

// No provider is configured in the suite, so every nudge above took the
// deterministic path. That is the point: the messages still went out.
$reset();
$p = $pair(1, 2);
$session($p, 2, 'missed');
Accountability::sweep(true);
$row = $db->query('SELECT body FROM user_notifications WHERE user_id = 2 ORDER BY id DESC LIMIT 1')->fetchColumn();
ck('fallback: a nudge is written with no AI provider at all', is_string($row) && trim($row) !== '');
ck('fallback: it names what has not happened', stripos((string) $row, 'attendance') !== false);
ck('fallback: and it asks for the concrete next step', stripos((string) $row, 'reschedule') !== false);
ck('fallback: it does not threaten', stripos((string) $row, 'warning') === false && stripos((string) $row, 'fail') === false);

$reset();

/* ══ The Studio surface is management-only ═════════════════════════════ */

// mentorship_health names individuals and their attendance record. The Studio
// action list is a DENY-editors list, so an action missing from it is readable
// by an editor — which is how a reporting endpoint quietly becomes a leak.
$apiSrc = (string) file_get_contents(AV_ROOT . '/admin/api.php');
$mgmt   = '';
if (preg_match('~\$managementOnly = \[(.*?)\n    \];~s', $apiSrc, $m)) $mgmt = $m[1];
ck('studio: the management-only list was found', $mgmt !== '');
ck('studio: mentorship_health is management-only', strpos($mgmt, "'mentorship_health'") !== false);
ck('studio: and it is actually implemented', strpos($apiSrc, "case 'mentorship_health'") !== false);
