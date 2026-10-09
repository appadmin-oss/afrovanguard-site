<?php
/**
 * tests/gateattendance.test.php — what the CACENTRE gate reports about members.
 *
 * Pinned here, because each is a way this goes wrong for a real person:
 *   • A passage delivered twice is one passage. The gate retries; a member
 *     must not be late twice, or fined twice, for one morning.
 *   • A departure that arrives before its arrival is not a fact yet — it is
 *     answered "not_checked_in" so the gate tries again, not dropped.
 *   • Nothing that costs money happens until leadership sets a figure.
 *   • An arrival held up by an outage beats the absence marked meanwhile,
 *     and the absence fine on it is voided, not left standing.
 *   • An excused day voids its fine; a declined excuse charges the fine it
 *     held back. Nobody is fined while their request is waiting.
 *   • A fine unpaid past the limit withholds the pass and refuses the card —
 *     and a recent fine alone does not.
 *   • Spreadsheet-era ID cards keep working; a replaced card stops.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$gaPdo = Database::pdo();
GateAttendance::ensure();
foreach (['gate_attendance', 'gate_member_cards', 'gate_excuses'] as $t) $gaPdo->exec('DELETE FROM ' . $t);
foreach (['ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) { try { NgvDb::pdo()->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
try { Database::metaSet('gate_absent_swept_through', ''); } catch (Throwable $e) {}
AvRules::save(['gate.late_fine' => '0', 'gate.absent_fine' => '0', 'gate.mark_absent' => '0', 'gate.block_overdue_days' => '0',
               'gate.programme_days' => 'mon,tue,wed,thu,fri,sat,sun', 'gate.holidays' => ''], 'test');

$gaUser = static function (string $name, string $role = 'member', string $status = 'active') use ($gaPdo): int {
    $email = strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test';
    $gaPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, 'x', $role, $status]);
    return (int) $gaPdo->lastInsertId();
};
$gaEnrol = static function (int $id, string $name): void {
    NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (?, ?, ?, 'active', '2026-01-05', '2026-01-05', '2026-01-05')")
        ->execute([$id, $name, 'p' . $id . '@example.test']);
};
$gaFines = static function (int $id): array {
    $st = NgvDb::pdo()->prepare("SELECT * FROM ngv_charges WHERE member_id = ? AND kind = 'fine' AND voided = 0 ORDER BY id");
    $st->execute([$id]);
    return $st->fetchAll();
};
$gaRow = static function (int $id, string $day) use ($gaPdo): ?array {
    $st = $gaPdo->prepare('SELECT * FROM gate_attendance WHERE member_id = ? AND day = ?');
    $st->execute([$id, $day]);
    return $st->fetch() ?: null;
};
$gaIn = static fn(string $pid, int $mid, string $day, string $status = 'present', int $late = 0): array =>
    ['id' => $pid, 'desk' => 'DESK', 'centre' => 'Egbeda', 'day' => $day, 'at' => $day . 'T07:' . sprintf('%02d', min(59, $late)) . ':00Z', 'action' => 'in',
     'ref' => (string) $mid, 'name' => 'x', 'kind' => 'member', 'status' => $status, 'late_minutes' => $late, 'method' => 'scan', 'operator' => 'desk:Front'];
$gaOut = static fn(string $pid, int $mid, string $day): array => ['id' => $pid, 'day' => $day, 'at' => $day . 'T15:00:00Z', 'action' => 'out', 'ref' => (string) $mid];

$ada = $gaUser('Ada Vanguard'); $gaEnrol($ada, 'Ada Vanguard');
$bola = $gaUser('Bola Member');                       // a member, not an NGV participant
$day = '2026-09-28';

/* ── Passages ────────────────────────────────────────────────────────────── */
$r = GateAttendance::report([$gaIn('p-1', $ada, $day, 'late', 12), ['id' => 'p-x', 'ref' => '999999', 'day' => $day, 'action' => 'in'], ['id' => '', 'ref' => (string) $ada]]);
ck('Gate: a passage is recorded, a stranger is "unknown", a malformed one "bad_event"', array_column($r, 'status') === ['recorded', 'unknown', 'bad_event']);
ck('Gate: each answer carries its passage id, so the gate can match it', $r[0]['id'] === 'p-1' && $r[1]['id'] === 'p-x');
$row = $gaRow($ada, $day);
ck('Gate: Ada is on the register, late by the gate’s reckoning', $row && $row['status'] === 'late' && (int) $row['late_minutes'] === 12 && $row['centre'] === 'Egbeda');
ck('Gate: …and with no late fine, because none is set', $gaFines($ada) === []);
ck('Gate: the same passage again is a duplicate, and the register still has one row',
   GateAttendance::report([$gaIn('p-1', $ada, $day, 'late', 12)])[0]['status'] === 'duplicate'
   && (int) $gaPdo->query("SELECT COUNT(*) FROM gate_attendance WHERE member_id = {$ada}")->fetchColumn() === 1);
ck('Gate: a second arrival the same day (another desk) is a duplicate too', GateAttendance::report([$gaIn('p-2', $ada, $day)])[0]['status'] === 'duplicate');
ck('Gate: a departure before any arrival asks to be sent again', GateAttendance::report([$gaOut('p-3', $bola, $day)])[0]['status'] === 'not_checked_in');
ck('Gate: a departure is stamped, once', GateAttendance::report([$gaOut('p-4', $ada, $day), $gaOut('p-4', $ada, $day)]) === [['id' => 'p-4', 'status' => 'recorded'], ['id' => 'p-4', 'status' => 'duplicate']]
   && $gaRow($ada, $day)['out_at'] === $day . 'T15:00:00Z');
$movedOut = $gaOut('p-4', $ada, $day); $movedOut['at'] = $day . 'T16:30:00Z';
ck('Gate: the same departure delivered later (a terminal\'s last punch) moves the check-out',
   GateAttendance::report([$movedOut])[0]['status'] === 'recorded' && $gaRow($ada, $day)['out_at'] === $day . 'T16:30:00Z');
$earlier = $gaOut('p-4', $ada, $day); $earlier['at'] = $day . 'T12:00:00Z';
ck('Gate: …but never back to an earlier one', GateAttendance::report([$earlier])[0]['status'] === 'duplicate' && $gaRow($ada, $day)['out_at'] === $day . 'T16:30:00Z');
ck('Gate: any member is recorded, not only NGV participants', GateAttendance::report([$gaIn('p-5', $bola, $day)])[0]['status'] === 'recorded');

/* ── Late fines, once leadership sets one ────────────────────────────────── */
AvRules::save(['gate.late_fine' => '1000'], 'test');
$d2 = '2026-09-29';
GateAttendance::report([$gaIn('p-6', $ada, $d2, 'late', 5), $gaIn('p-7', $bola, $d2, 'late', 5)]);
GateAttendance::report([$gaIn('p-6', $ada, $d2, 'late', 5)]);
$f = $gaFines($ada);
ck('Gate: late with a fine set: one ₦1,000 fine on the NGV account, for that day', count($f) === 1 && (int) $f[0]['amount'] === 1000 && $f[0]['period'] === 'late:' . $d2 && $f[0]['reason'] === 'late');
ck('Gate: …linked from the register row', (int) $gaRow($ada, $d2)['fine_id'] === (int) $f[0]['id']);
ck('Gate: a member who is not an NGV participant is never fined — there is no account to fine', $gaFines($bola) === []);
AvRules::save(['gate.late_fine' => '0'], 'test');

/* ── Signing in on the phone: the card was left at home ──────────────────── */
$gaPass = static function (string $pid, int $mid, string $day) use ($gaIn): array { $p = $gaIn($pid, $mid, $day); $p['method'] = 'pass'; return $p; };
$dn = '2026-09-26';
GateAttendance::report([$gaPass('p-n1', $ada, $dn)]);
ck('Gate: a phone pass from a member with NO printed card is not fined — only somebody who has a card can forget it',
   array_filter($gaFines($ada), static fn($c) => str_starts_with((string) $c['period'], 'nocard:')) === []);
MemberCards::issue($ada, 'test', 'test');
$dn2 = '2026-09-25';
GateAttendance::report([$gaPass('p-n2', $ada, $dn2), $gaIn('p-n3', $ada, '2026-09-24')]);
$nc = array_values(array_filter($gaFines($ada), static fn($c) => str_starts_with((string) $c['period'], 'nocard:')));
ck('Gate: a carded NGV participant who signs in with the phone is fined the Fines desk’s “Uniform or ID card” amount, once, for that day',
   count($nc) === 1 && $nc[0]['period'] === 'nocard:' . $dn2 && $nc[0]['reason'] === 'uniform'
   && (int) $nc[0]['amount'] === (int) NgvFines::catalogue()['uniform']['amount'] && (int) $nc[0]['amount'] > 0);
ck('Gate: …and the card scanned the next day costs nothing', count($nc) === 1);
MemberCards::issue($bola, 'test', 'test');
GateAttendance::report([$gaPass('p-n4', $bola, $dn2)]);
ck('Gate: a member who is not an NGV participant is not fined for the phone either', $gaFines($bola) === []);
AvRules::save(['gate.fine_phone_signin' => '0'], 'test');
GateAttendance::report([$gaPass('p-n5', $ada, '2026-09-23')]);
ck('Gate: with the rule switched off, the phone costs nothing',
   count(array_filter($gaFines($ada), static fn($c) => str_starts_with((string) $c['period'], 'nocard:'))) === 1);
AvRules::save(['gate.fine_phone_signin' => '1'], 'test');
foreach ($gaFines($ada) as $c) if (str_starts_with((string) $c['period'], 'nocard:')) NgvDb::pdo()->exec('DELETE FROM ngv_charges WHERE id = ' . (int) $c['id']);

/* ── Absences ────────────────────────────────────────────────────────────── */
ck('Gate: absences are not marked until switched on', GateAttendance::sweep('2026-10-02')['off'] ?? false);
AvRules::save(['gate.mark_absent' => '1', 'gate.absent_fine' => '2000', 'gate.holidays' => '2026-09-30'], 'test');
Database::metaSet('gate_absent_swept_through', '2026-09-29');
$chi = $gaUser('Chi Vanguard'); $gaEnrol($chi, 'Chi Vanguard');
GateAttendance::requestExcuse($chi, '2026-10-01', 'Exam at school');
$s = GateAttendance::sweep('2026-10-02');
ck('Gate: the sweep covers the days since the last one, up to yesterday', $s['days'] === 2 && $s['through'] === '2026-10-01');
ck('Gate: nobody is absent on a holiday', $gaRow($ada, '2026-09-30') === null && $gaRow($chi, '2026-09-30') === null);
$a1 = $gaRow($ada, '2026-10-01');
ck('Gate: an active participant with no passage is absent, and fined the absence fine', $a1 && $a1['status'] === 'absent' && (int) $a1['fine_id'] > 0
   && count(array_filter($gaFines($ada), fn($c) => $c['period'] === 'absent:2026-10-01' && (int) $c['amount'] === 2000)) === 1);
ck('Gate: a plain member is not expected, so never absent', $gaRow($bola, '2026-10-01') === null);
ck('Gate: somebody whose excuse is waiting is absent, but not fined yet', $gaRow($chi, '2026-10-01')['status'] === 'absent'
   && (int) $gaRow($chi, '2026-10-01')['fine_id'] === 0 && $gaFines($chi) === []);
ck('Gate: sweeping again does nothing', GateAttendance::sweep('2026-10-02')['days'] === 0);

$ex = $gaPdo->query("SELECT id FROM gate_excuses WHERE member_id = {$chi}")->fetchColumn();
GateAttendance::decideExcuse((int) $ex, false, 'Bring the exam slip next time', 1);
ck('Gate: a declined excuse charges the fine it held back', count($gaFines($chi)) === 1);
$r = GateAttendance::excuseDay($ada, '2026-10-01', 'Told the office in person', 1);
ck('Gate: staff excusing a day makes it excused and voids its fine', $r['ok'] && $gaRow($ada, '2026-10-01')['status'] === 'excused'
   && array_filter($gaFines($ada), fn($c) => $c['period'] === 'absent:2026-10-01') === []);

/* A delivery held up by an outage: the absence was marked first. */
Database::metaSet('gate_absent_swept_through', '2026-10-01');
GateAttendance::sweep('2026-10-03');
ck('Gate: (Chi absent on the 2nd, fined)', $gaRow($chi, '2026-10-02')['status'] === 'absent' && count($gaFines($chi)) === 2);
ck('Gate: the late arrival beats the absence…', GateAttendance::report([$gaIn('p-8', $chi, '2026-10-02')])[0]['status'] === 'recorded' && $gaRow($chi, '2026-10-02')['status'] === 'present');
ck('Gate: …and its absence fine is voided', count($gaFines($chi)) === 1);
AvRules::save(['gate.mark_absent' => '0', 'gate.absent_fine' => '0', 'gate.holidays' => ''], 'test');

/* ── Excuse requests ─────────────────────────────────────────────────────── */
ck('Gate: an excuse needs a reason', !GateAttendance::requestExcuse($ada, '2026-10-05', '')['ok']);
ck('Gate: nobody is excused from a day the gate has them in', !GateAttendance::requestExcuse($ada, $day, 'I was ill')['ok']);
ck('Gate: and one request per day', GateAttendance::requestExcuse($ada, '2026-10-05', 'Hospital')['ok'] && !GateAttendance::requestExcuse($ada, '2026-10-05', 'Again')['ok']);

/* ── Leave: a request with a duration ────────────────────────────────────── */
$lvDay = fn(int $n) => date('Y-m-d', strtotime((function_exists('av_today_tz') ? av_today_tz() : date('Y-m-d')) . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
$lvMe = $gaUser('Lade Leave');
$lv = GateAttendance::requestLeave($lvMe, $lvDay(20), $lvDay(24), 'Family travel');
ck('Leave: a request covers every day from the first to the last', $lv['ok'] && $lv['days'] === 5
   && (int) $gaPdo->query("SELECT COUNT(*) FROM gate_excuses WHERE member_id = {$lvMe}")->fetchColumn() === 5);
$lvMine = GateAttendance::excusesFor($lvMe);
ck('Leave: …and is shown as one leave with its duration', count($lvMine) === 1 && $lvMine[0]['from'] === $lvDay(20) && $lvMine[0]['to'] === $lvDay(24)
   && (int) $lvMine[0]['days'] === 5 && str_contains(GateAttendance::leaveLabel($lvMine[0]), '5 days'));
$lvWait = array_values(array_filter(GateAttendance::pendingExcuses(), fn($x) => (int) $x['member_id'] === $lvMe));
ck('Leave: staff see it once, with its days', count($lvWait) === 1 && (int) $lvWait[0]['days'] === 5);
ck('Leave: an overlapping request is refused, and writes nothing', !GateAttendance::requestLeave($lvMe, $lvDay(23), $lvDay(26), 'More travel')['ok']
   && (int) $gaPdo->query("SELECT COUNT(*) FROM gate_excuses WHERE member_id = {$lvMe}")->fetchColumn() === 5);
ck('Leave: the last day cannot come before the first', !GateAttendance::requestLeave($lvMe, $lvDay(40), $lvDay(39), 'Backwards')['ok']);
ck('Leave: a leave longer than the limit is refused', !GateAttendance::requestLeave($lvMe, $lvDay(30), $lvDay(30 + GateAttendance::LEAVE_MAX_DAYS), 'Long trip')['ok']);
ck('Leave: an empty last day is a single day', GateAttendance::requestLeave($lvMe, $lvDay(50), '', 'Clinic')['ok']
   && GateAttendance::leaveLabel(GateAttendance::excusesFor($lvMe)[0]) === date('D j M', strtotime($lvDay(50) . 'T12:00:00')));
$dec = GateAttendance::decideExcuse((int) $lvWait[0]['id'], true, 'Safe travels', 1);
ck('Leave: deciding it decides every day in it', $dec['ok'] && $dec['days'] === 5
   && (int) $gaPdo->query("SELECT COUNT(*) FROM gate_excuses WHERE member_id = {$lvMe} AND status = 'approved'")->fetchColumn() === 5);

/* ── What the member sees ────────────────────────────────────────────────── */
ck('Gate: grades are the spreadsheet’s', GateAttendance::grade(96) === 'A+' && GateAttendance::grade(85) === 'A' && GateAttendance::grade(70) === 'C' && GateAttendance::grade(10) === 'F');
$sum = GateAttendance::summary($chi, 3650);
ck('Gate: the summary counts excused days for nothing either way', $sum['counted'] === 2 && $sum['absent'] === 1 && $sum['present'] === 1 && $sum['rate'] === 50);
$reg = GateAttendance::register($day);
ck('Gate: the staff register lists the day and who was expected but not in', count($reg['rows']) === 2 && in_array($chi, array_column($reg['not_in'], 'member_id'), true));

/* ── Who may come in ─────────────────────────────────────────────────────── */
ck('Gate: an active member may', GateAttendance::whyNot($gaPdo->query("SELECT * FROM lms_users WHERE id = {$ada}")->fetch()) === null);
$sus = $gaUser('Sus Pended', 'member', 'suspended');
ck('Gate: a suspended account may not, and is told so', str_contains((string) GateAttendance::whyNot($gaPdo->query("SELECT * FROM lms_users WHERE id = {$sus}")->fetch()), 'suspended'));
$lea = $gaUser('Lee Learner', 'learner');
ck('Gate: a learner gets no pass', GateAttendance::whyNot($gaPdo->query("SELECT * FROM lms_users WHERE id = {$lea}")->fetch()) !== null);

AvRules::save(['gate.block_overdue_days' => '14'], 'test');
ck('Gate: a fine raised this week does not withhold anything', GateAttendance::overdueFine($chi) === 0);
NgvDb::pdo()->exec("UPDATE ngv_charges SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 30 * 86400) . "' WHERE member_id = {$chi}");
$chiU = $gaPdo->query("SELECT * FROM lms_users WHERE id = {$chi}")->fetch();
ck('Gate: a fine unpaid past the limit withholds the pass, with the amount', GateAttendance::overdueFine($chi) === 2000 && str_contains((string) GateAttendance::whyNot($chiU), '₦2,000'));
ck('Gate: …so the portal will not mint one', !GatePass::eligible($chiU));
NgvLedger::payment($chi, 'fine', 2000, ['method' => 'cash'], 1);
ck('Gate: paying it lifts that', GateAttendance::overdueFine($chi) === 0 && GatePass::eligible($chiU));
AvRules::save(['gate.block_overdue_days' => '0'], 'test');

/* ── Member ID cards ─────────────────────────────────────────────────────── */
$c1 = GateAttendance::assignCard($ada, 'b-ngv-24-0007', 1);
ck('Gate: a spreadsheet-era card number is taken as printed', $c1['ok'] && $c1['code'] === 'B-NGV-24-0007');
$r = GateAttendance::resolveCard('b-ngv-24-0007');
ck('Gate: and resolves to the member, with the same ref as their pass', $r['ok'] && $r['person']['ref'] === (string) $ada && $r['person']['kind'] === 'member' && $r['person']['status'] === 'active');
ck('Gate: …saying no more than a desk needs', array_keys($r['person']) === ['ref', 'name', 'kind', 'status', 'detail'] && str_contains($r['person']['detail'], 'NextGen Vanguard'));
$c2 = GateAttendance::assignCard($ada, '', 1);
ck('Gate: a new card is the next free number', $c2['ok'] && (bool) preg_match('/^A-NGV-\d{2}-0001$/', $c2['code']));
ck('Gate: and the replaced one stops working', GateAttendance::resolveCard('B-NGV-24-0007')['code'] === 'void_card');
ck('Gate: somebody else’s card cannot be given out', !GateAttendance::assignCard($chi, $c2['code'], 1)['ok']);
ck('Gate: a number that is not a card is refused', !GateAttendance::assignCard($chi, 'NGV-1', 1)['ok'] && GateAttendance::resolveCard('hello')['code'] === 'unknown');
ck('Gate: a suspended member’s card resolves with the reason, not as active',
   GateAttendance::assignCard($sus, '', 1)['ok'] && GateAttendance::resolveCard((string) GateAttendance::cardFor($sus))['person']['status'] !== 'active');
ck('Gate: search finds active members by name, and never a suspended one',
   in_array((string) $ada, array_column(GateAttendance::search('vanguard'), 'ref'), true) && GateAttendance::search('pended') === []);

/* ── Probation ───────────────────────────────────────────────────────────── */
foreach (['gate_probation', 'gate_points'] as $t) $gaPdo->exec('DELETE FROM ' . $t);
AvRules::save(['gate.late_fine' => '1000', 'gate.late_fine_probation' => '10000', 'gate.probation_levels' => 'O', 'gate.programme_days' => 'mon,tue,wed,thu,fri'], 'test');
$eke = $gaUser('Eke Newcomer'); $gaEnrol($eke, 'Eke Newcomer');
ck('Gate: a member never promoted past O is on probation, as the spreadsheet had it', GateAttendance::onProbation($eke, '2026-10-05')
   && str_contains((string) GateAttendance::probationWhy($eke), 'level O'));
GateAttendance::report([$gaIn('pr-1', $eke, '2026-10-05', 'late', 4)]);
$f = $gaFines($eke);
ck('Gate: late on probation: the probation fine, and the note says why it is bigger', count($f) === 1 && (int) $f[0]['amount'] === 10000 && str_contains((string) $f[0]['note'], 'on probation'));
Levels::set($eke, 'A', 'test');
GateAttendance::report([$gaIn('pr-2', $eke, '2026-10-06', 'late', 4)]);
ck('Gate: promoted past O, the ordinary fine', (int) end($gaFines($eke))['amount'] === 1000);
ck('Gate: probation needs a reason', !GateAttendance::setProbation($eke, '', '', 1)['ok']);
ck('Gate: and an end date that has not passed', !GateAttendance::setProbation($eke, '2020-01-01', 'Late three times', 1)['ok']);
ck('Gate: staff can put a member on probation by name', GateAttendance::setProbation($eke, '', 'Late three times in a week', 1)['ok']
   && str_contains((string) GateAttendance::probationWhy($eke), 'Late three times in a week'));
/* Probation set by name starts TODAY, so the arrival that tests it has to be
   dated today or later — a fixed date here passes until the day it doesn't. */
$prDay = gmdate('Y-m-d');
while (in_array(gmdate('D', strtotime($prDay)), ['Sat', 'Sun'], true)) $prDay = gmdate('Y-m-d', strtotime($prDay . ' +1 day'));
GateAttendance::report([$gaIn('pr-3', $eke, $prDay, 'late', 4)]);
ck('Gate: …and it applies to the next late arrival', (int) end($gaFines($eke))['amount'] === 10000);
ck('Gate: lifting it ends it', GateAttendance::liftProbation($eke, 1)['ok'] && !GateAttendance::onProbation($eke));
Levels::set($eke, 'O', 'test');
$lift = GateAttendance::liftProbation($eke, 1);
ck('Gate: lifting probation that comes from a level says how it is really ended', !$lift['ok'] && str_contains($lift['error'], 'promoting'));
AvRules::save(['gate.probation_levels' => 'none'], 'test');
ck('Gate: with no probation levels, only those named are on probation', !GateAttendance::onProbation($eke));
AvRules::save(['gate.late_fine_probation' => '0'], 'test');
GateAttendance::setProbation($eke, '', 'Again', 1);
GateAttendance::report([$gaIn('pr-4', $eke, '2026-10-08', 'late', 4)]);
ck('Gate: with no probation fine set, probation pays the ordinary one', (int) end($gaFines($eke))['amount'] === 1000);
AvRules::save(['gate.late_fine' => '0', 'gate.probation_levels' => 'O'], 'test');

/* ── Points ──────────────────────────────────────────────────────────────── */
AvRules::save(['gate.points_on_time' => '5', 'gate.points_streak3' => '15', 'gate.points_streak5' => '30', 'gate.points_perfect_week' => '30'], 'test');
$week = ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'];   // Mon–Fri
$pa = $gaUser('Perfect Attender'); $pb = $gaUser('Late Wednesday'); $pc = $gaUser('Excused Wednesday');
foreach ($week as $i => $d) GateAttendance::report([$gaIn('pa-' . $i, $pa, $d)]);
ck('Gate: a perfect week: 5 a day, 15 at three, 30 at five, and 30 for the week', GateAttendance::points($pa)['total'] === 25 + 15 + 30 + 30);
foreach ($week as $i => $d) GateAttendance::report([$gaIn('pa-' . $i, $pa, $d)]);
ck('Gate: delivered again, not a point more', GateAttendance::points($pa)['total'] === 100);
foreach ($week as $i => $d) GateAttendance::report([$i === 2 ? $gaIn('pb-' . $i, $pb, $d, 'late', 3) : $gaIn('pb-' . $i, $pb, $d)]);
ck('Gate: a late Wednesday earns nothing that day and breaks the run and the week', GateAttendance::points($pb)['total'] === 20);
$gaPdo->prepare("INSERT INTO gate_attendance (member_id, day, status, created_at, updated_at) VALUES (?, '2026-10-14', 'excused', '', '')")->execute([$pc]);
foreach ($week as $i => $d) if ($i !== 2) GateAttendance::report([$gaIn('pc-' . $i, $pc, $d)]);
ck('Gate: an excused day neither breaks the run nor spoils the week', GateAttendance::points($pc)['total'] === 20 + 15 + 30);
ck('Gate: …and four on time is not five', !in_array('Five days on time in a row', array_column(GateAttendance::points($pc)['recent'], 'note'), true));
$weekend = GateAttendance::report([$gaIn('pa-sat', $pa, '2026-10-17')]);
ck('Gate: a Saturday visit is recorded but earns nothing — it is not a programme day', $weekend[0]['status'] === 'recorded' && GateAttendance::points($pa)['total'] === 100);
GateAttendance::report([$gaIn('pa-mon', $pa, '2026-10-19')]);
ck('Gate: the run carries over the weekend, and a run of six pays its three and five only once', GateAttendance::points($pa)['total'] === 105);
$board = GateAttendance::leaderboard('2026-10');
ck('Gate: the month’s leaderboard puts the most points first', (int) $board[0]['member_id'] === $pa && (int) $board[0]['points'] === 105);
AvRules::save(['gate.points_on_time' => '0', 'gate.points_streak3' => '0', 'gate.points_streak5' => '0', 'gate.points_perfect_week' => '0'], 'test');
GateAttendance::report([$gaIn('pa-tue', $pa, '2026-10-20')]);
ck('Gate: points set to 0 award nothing', GateAttendance::points($pa)['total'] === 105);
AvRules::save(['gate.points_on_time' => '5', 'gate.points_streak3' => '15', 'gate.points_streak5' => '30', 'gate.points_perfect_week' => '30'], 'test');

/* ── The door the gate knocks on ─────────────────────────────────────────── */
putenv('GATE_PASS_SECRET=' . str_repeat('g', 48));
$body = '{"op":"search","q":"ada"}'; $ts = (string) time();
$sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, hash_hmac('sha256', 'cacentre-gate/v1/resolve', str_repeat('g', 48)));
ck('Gate: a request signed under the resolve key verifies for resolve', GatePass::verify('resolve', $ts, $body, $sig));
ck('Gate: …and not for report, so a delivery log cannot be replayed as a search', !GatePass::verify('report', $ts, $body, $sig));
ck('Gate: …and not five minutes later', !GatePass::verify('resolve', (string) (time() - 400), $body, 'sha256=' . hash_hmac('sha256', (time() - 400) . '.' . $body, GatePass::key('resolve'))));
$src = (string) file_get_contents(AV_ROOT . '/integrations/cacentre-gate.php');
ck('Gate: the endpoint picks the key from what is asked, and checks it before reading anything', str_contains($src, "\$purpose = isset(\$in['op']) ? 'resolve' : 'report';")
   && strpos($src, 'GatePass::verify(') < strpos($src, 'GateAttendance::report($passages)'));
ck('Gate: suspending a member withdraws their passes', str_contains((string) file_get_contents(AV_ROOT . '/lib/LmsRepository.php'), 'GatePass::revoke($id)'));
ck('Gate: the cron marks absences', str_contains((string) file_get_contents(AV_ROOT . '/tasks/cron.php'), 'GateAttendance::sweep()'));
putenv('GATE_PASS_SECRET');
AvRules::save(['gate.programme_days' => 'mon,tue,wed,thu,fri'], 'test');
