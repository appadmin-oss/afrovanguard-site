<?php
/**
 * tests/questpoints.test.php — the Quest's points are not the gate's.
 *
 * The defect this exists for: one points column carried two currencies.
 * gate_points grants for arriving on time, for three- and five-day runs, for a
 * perfect week and for a birthday — and Conduct's approved awards landed in
 * the same table, so the number a rank would sit on was mostly punctuality and
 * nothing could tell the two apart except by parsing a string.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

QuestPoints::ensure();
GateAttendance::ensure();
$db = Database::pdo();
$M = 9410; $N = 9411;
$db->exec("DELETE FROM quest_points WHERE member_id BETWEEN 9400 AND 9499");
$db->exec("DELETE FROM gate_points  WHERE member_id BETWEEN 9400 AND 9499");

/* ── a grant, and the once-only promise ───────────────────────────────── */

ck('quest: a member with nothing earned has nothing', QuestPoints::total($M) === 0);
ck('quest: a grant lands', QuestPoints::grant($M, 10, 'award:501', 'leadership', 'Led the clean-up', 77));
ck('quest: and totals', QuestPoints::total($M) === 10);

ck('quest: the same decision grants once, and says so rather than throwing',
   !QuestPoints::grant($M, 10, 'award:501', 'leadership', 'again', 77));
ck('quest: …and the total did not move', QuestPoints::total($M) === 10);
ck('quest: granted() answers for a decision already taken',
   QuestPoints::granted($M, 'award:501') && !QuestPoints::granted($M, 'award:999'));

ck('quest: a different decision for the same member adds',
   QuestPoints::grant($M, 5, 'challenge:' . $M . ':individuation.3', 'challenge', 'Chose their own path', 77, '', 'individuation')
   && QuestPoints::total($M) === 15);

ck('quest: the same period for a DIFFERENT member is a different grant',
   QuestPoints::grant($N, 10, 'award:501', 'leadership', '', 77) && QuestPoints::total($N) === 10);

/* ── what it refuses ──────────────────────────────────────────────────── */

ck('quest: zero points is not a grant', !QuestPoints::grant($M, 0, 'award:502'));
ck('quest: negative points is not a grant', !QuestPoints::grant($M, -5, 'award:503'));
ck('quest: a grant above the cap is refused whole', !QuestPoints::grant($M, QuestPoints::MAX_PER_GRANT + 1, 'award:504'));
ck('quest: a grant with no member is refused', !QuestPoints::grant(0, 5, 'award:505'));
ck('quest: a bare label is not a period — a grant must name the decision that caused it',
   !QuestPoints::grant($M, 5, 'leadership') && !QuestPoints::grant($M, 5, ''));
ck('quest: none of the refusals moved the total', QuestPoints::total($M) === 15);

/* ── THE POINT OF ALL THIS: two ledgers ───────────────────────────────── */

GateAttendance::grant($M, gmdate('Y-m-d'), 'day:' . gmdate('Y-m-d'), 5, 'On time');
$gate = (int) $db->query("SELECT COALESCE(SUM(points),0) FROM gate_points WHERE member_id = $M")->fetchColumn();
ck('quest: the gate still grants its own points for turning up', $gate === 5);
ck('quest: …and they are NOT Quest points — attendance is not evidence', QuestPoints::total($M) === 15);
$q = (int) $db->query("SELECT COALESCE(SUM(points),0) FROM quest_points WHERE member_id = $M")->fetchColumn();
ck('quest: nothing the gate granted reached the Quest ledger', $q === 15);

/* ── reading it back ──────────────────────────────────────────────────── */

$recent = QuestPoints::recent($M);
ck('quest: recent reads newest first', count($recent) === 2 && (int) $recent[0]['points'] === 5);

$byValue = QuestPoints::byValue($M);
ck('quest: points by value name the seven, with nothing earned showing zero',
   count($byValue) === count(MentorPortal::VALUES) && $byValue['individuation'] === 5 && $byValue['diligence'] === 0);
ck('quest: a grant with no value is counted in the total but attributed to none',
   array_sum($byValue) === 5 && QuestPoints::total($M) === 15);

$top = QuestPoints::top(50);
$mine = array_values(array_filter($top, static fn($r) => (int) $r['member_id'] === $M));
ck('quest: the board ranks by evidence', $mine !== [] && (int) $mine[0]['points'] === 15);

/* ── adopting the awards granted before the split ─────────────────────── */

$L = 9412;
$db->exec("DELETE FROM quest_points WHERE member_id = $L");
$db->exec("DELETE FROM gate_points  WHERE member_id = $L");
GateAttendance::grant($L, '2026-09-01', 'award:777', 12, 'Award: led the clean-up');
GateAttendance::grant($L, '2026-09-02', 'day:2026-09-02', 5, 'On time');

$dry = QuestPoints::adoptLegacyAwards();
ck('quest: the dry run finds the award sitting in the gate ledger',
   $dry['rows'] >= 1 && $dry['applied'] === false);
ck('quest: …and a dry run writes nothing', QuestPoints::total($L) === 0
   && (int) $db->query("SELECT COALESCE(SUM(points),0) FROM gate_points WHERE member_id = $L")->fetchColumn() === 17);

QuestPoints::adoptLegacyAwards(true);
ck('quest: applying moves the award to the Quest ledger', QuestPoints::total($L) === 12);
ck('quest: …and takes it off the gate total, leaving what was earned by turning up',
   (int) $db->query("SELECT COALESCE(SUM(points),0) FROM gate_points WHERE member_id = $L")->fetchColumn() === 5);

$again = QuestPoints::adoptLegacyAwards(true);
ck('quest: adopting twice moves nothing the second time',
   $again['rows'] === 0 && QuestPoints::total($L) === 12);

$db->exec("DELETE FROM quest_points WHERE member_id BETWEEN 9400 AND 9499");
$db->exec("DELETE FROM gate_points  WHERE member_id BETWEEN 9400 AND 9499");
