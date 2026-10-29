<?php
/**
 * tests/ngvprogrammefee.test.php — the NGV programme fee, as the MoU states it.
 *
 * Each case below is one of the faults an audit of NGG's training fee found
 * (29 Oct 2026), held here so NGV cannot repeat it:
 *   · no fixed total, and a basis switch that kept the rate;
 *   · editing the settings rewrote people's figures;
 *   · somebody who left kept working it off, and what they owed was never due;
 *   · the notional fee counted against the balance ceiling.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pfPdo = Database::pdo();
$pfNgv = NgvDb::pdo();
MemberRoster::ensure(); Centres::ensure(); GateAttendance::ensure();
$pfFeesWas = NgvLedger::settings();
$pfDaysWas = AvRules::get('gate.programme_days');
$pfCacWas = Database::metaGet(Centres::CAC_META);
$pfPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@pfee.test')");
$pfPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@pfee.test'");
AvRules::save(['gate.programme_days' => 'mon,tue,wed,thu,fri', 'gate.holidays' => ''], 'test');
GateAttendance::forgetCentres();

$pfMember = static function (string $name, string $centre = '') use ($pfNgv): int {
    $email = strtolower(str_replace(' ', '.', $name)) . '@pfee.test';
    $id = (int) MemberRoster::create(array_filter(['name' => $name, 'email' => $email, 'centre' => $centre]), 'tester')['id'];
    NgvMember::ensureParticipant($id, ['name' => $name, 'email' => $email]);
    $pfNgv->prepare('DELETE FROM ngv_status_log WHERE member_id = ?')->execute([$id]);
    foreach (['ngv_charges', 'ngv_payments'] as $t) $pfNgv->prepare("DELETE FROM $t WHERE member_id = ?")->execute([$id]);
    return $id;
};
$pfStatus = static function (int $id, string $status) use ($pfNgv): void {
    $pfNgv->prepare('UPDATE ngv_participants SET status = ? WHERE member_id = ?')->execute([$status, $id]);
};
$pfPos = static function (int $id, string $asOf): array {
    $b = NgvLedger::balance($id);
    return NgvProgrammeFee::position(NgvMember::participant($id), (int) ($b['chargedBy']['programme'] ?? 0), (int) ($b['paidBy']['programme'] ?? 0), $asOf);
};

/* ── Settings: a basis switch has to come with its rate ─────────────────── */
NgvLedger::saveSettings(['enabled' => true, 'programmeBasis' => 'total', 'programmeRate' => '', 'balanceCap' => 100000], 'test');
$t = NgvProgrammeFee::terms();
ck('ngv fee: by default it is ₦240,000 for the year, read off the public page', $t['basis'] === 'total' && $t['rate'] === 240000 && $t['source'] === 'page');
ck('ngv fee: …six months of training, then six of internship', $t['trainingMonths'] === 6 && $t['internshipMonths'] === 6);
$r = NgvLedger::saveSettings(['programmeBasis' => 'day'], 'test');
ck('ngv fee: switching to "per day" without a day rate is refused — ₦240,000 a day is how NGG reached ₦5.25m', isset($r['error']) && NgvProgrammeFee::terms()['basis'] === 'total');
NgvLedger::saveSettings(['programmeRate' => 240000], 'test');
$r = NgvLedger::saveSettings(['programmeBasis' => 'day', 'programmeRate' => 240000], 'test');
ck('ngv fee: …nor with the old figure left in the box', isset($r['error']) && NgvProgrammeFee::terms()['basis'] === 'total');
$r = NgvLedger::saveSettings(['programmeBasis' => 'week', 'programmeRate' => 9000], 'test');
ck('ngv fee: with its rate, the switch is taken', !isset($r['error']) && NgvProgrammeFee::terms()['basis'] === 'week' && NgvProgrammeFee::terms()['rate'] === 9000);
NgvLedger::saveSettings(['programmeBasis' => 'total', 'programmeRate' => ''], 'test');

/* ── Total: one charge, frozen, and not a bill ──────────────────────────── */
$ada = $pfMember('Ada Total');
NgvProgrammeFee::freeze($ada, '2025-01-06', 'MoU v3');
NgvProgrammeFee::logStatus($ada, 'active', '2025-01-06');
ck('ngv fee: the terms are frozen on the participant at registration, with the MoU version',
   ($s = NgvProgrammeFee::snapshot(NgvMember::participant($ada))) && $s['rate'] === 240000 && $s['trainingEnds'] === '2025-07-06'
   && $s['internshipEnds'] === '2026-01-06' && $s['mouVersion'] === 'MoU v3');
ck('ngv fee: and cannot be frozen twice', empty(NgvProgrammeFee::freeze($ada, '2025-02-01')['ok']));
NgvLedger::saveSettings(['programmeRate' => 300000], 'test');
ck('ngv fee: a later price change moves nothing for somebody who already signed', NgvProgrammeFee::snapshot(NgvMember::participant($ada))['rate'] === 240000);
NgvLedger::saveSettings(['programmeRate' => ''], 'test');
NgvLedger::accrueParticipant(NgvMember::participant($ada), '2025-03-01');
NgvLedger::accrueParticipant(NgvMember::participant($ada), '2025-04-01');
$b = NgvLedger::balance($ada);
ck('ngv fee: on the total basis it is one charge of the year\'s fee, however often accrual runs', (int) ($b['chargedBy']['programme'] ?? 0) === 240000 && (int) $b['countBy']['programme'] === 1);
ck('ngv fee: nobody is asked for it while they are on the programme', (int) $b['due']['programme'] === 0 && (int) $b['payable'] === 0);
ck('ngv fee: and it does not count against the balance ceiling (₦100,000 here) — NGG\'s did, and stopped real dues', empty($b['atCap']));
ck('ngv fee: so the arrears list does not carry them', !in_array($ada, array_map(static fn($r) => (int) ($r['memberId'] ?? $r['member_id'] ?? 0), NgvLedger::arrears()['rows'] ?? []), true));

$p = $pfPos($ada, '2025-07-01');
ck('ngv fee: nothing is worked off in the training half', $p['earned'] === 0 && $p['phase'] === 'training');
$p = $pfPos($ada, '2025-10-06');
ck('ngv fee: in the internship it is worked off by the day — half the window, half the fee', $p['phase'] === 'internship' && abs($p['earned'] - 120000) <= 2000);

/* A pause takes those days out, and they are not credited back on return. */
NgvProgrammeFee::logStatus($ada, 'paused', '2025-08-01');
NgvProgrammeFee::logStatus($ada, 'active', '2025-09-01');
$q = $pfPos($ada, '2025-10-06');
ck('ngv fee: a month paused is a month not worked off, even after they are back', $q['activeDays'] === $p['activeDays'] - 31 && $q['earned'] < $p['earned']);

/* Withdrawal: the clock stops, and what is left becomes due. */
NgvProgrammeFee::logStatus($ada, 'withdrawn', '2025-10-06');
$pfStatus($ada, 'withdrawn');
$w1 = $pfPos($ada, '2025-10-06'); $w2 = $pfPos($ada, '2025-12-31');
ck('ngv fee: after withdrawing, nothing more is worked off by the calendar — NGG\'s kept going', $w2['earned'] === $w1['earned']);
$b = NgvLedger::balance($ada);
ck('ngv fee: what was not worked off becomes money they owe, and is payable',
   $w2['owed'] > 0 && $w2['owed'] === $w2['remaining'] && (int) $b['due']['programme'] === (int) NgvLedger::balance($ada)['programmeFee']['owed'] && (int) $b['payable'] >= $w2['owed']);
ck('ngv fee: the account says so in words', str_contains(implode(' ', array_column(NgvLedger::account($ada)['lines'], 'detail')), 'when you withdrew'));

/* Finishing writes it off, on the record. */
$bola = $pfMember('Bola Done');
NgvProgrammeFee::freeze($bola, '2025-01-06');
NgvProgrammeFee::logStatus($bola, 'active', '2025-01-06');
NgvProgrammeFee::accrue(NgvMember::participant($bola), '2025-02-01');
NgvProgrammeFee::logStatus($bola, 'completed', '2026-01-06');
$pfStatus($bola, 'completed');
$off = NgvProgrammeFee::discharge($bola);
$b = NgvLedger::balance($bola);
ck('ngv fee: completing the year writes off what is left, as a ledger entry', $off === 240000 && (int) ($b['paidBy']['programme'] ?? 0) === 240000
   && $pfPos($bola, '2026-02-01')['remaining'] === 0 && (int) $b['payable'] === 0);

/* ── Per day and per week: only days the centre meets, while active ─────── */
NgvLedger::saveSettings(['programmeBasis' => 'day', 'programmeRate' => 1000], 'test');
$dayo = $pfMember('Dayo Daily');
NgvProgrammeFee::freeze($dayo, '2025-01-06');
NgvProgrammeFee::logStatus($dayo, 'active', '2025-01-06');
NgvProgrammeFee::logStatus($dayo, 'paused', '2025-01-13');
NgvProgrammeFee::logStatus($dayo, 'active', '2025-01-15');
NgvProgrammeFee::accrue(NgvMember::participant($dayo), '2025-01-19');
$b = NgvLedger::balance($dayo);
ck('ngv fee: per day — ten weekdays in two weeks, less the two paused, at the day rate',
   (int) $b['countBy']['programme'] === 8 && (int) $b['chargedBy']['programme'] === 8000);
ck('ngv fee: …and never a weekend, when the centre does not meet', !$pfNgv->query("SELECT 1 FROM ngv_charges WHERE member_id = $dayo AND period IN ('P:D2025-01-11','P:D2025-01-12')")->fetchColumn());

NgvLedger::saveSettings(['programmeBasis' => 'week', 'programmeRate' => 5000], 'test');
$wale = $pfMember('Wale Weekly');
NgvProgrammeFee::freeze($wale, '2025-01-06');
NgvProgrammeFee::logStatus($wale, 'active', '2025-01-06');
NgvProgrammeFee::accrue(NgvMember::participant($wale), '2025-01-26');
ck('ngv fee: per week — one charge for each week it met', (int) NgvLedger::balance($wale)['countBy']['programme'] === 3);
NgvProgrammeFee::accrue(NgvMember::participant($wale), '2025-12-31');
ck('ngv fee: and nothing after the training half ends', (int) NgvLedger::balance($wale)['countBy']['programme'] === 26);

/* ── Per session: the terms their CACENTRE runs ─────────────────────────── */
$pfPdo->exec('DELETE FROM av_centres');
Database::metaSet(Centres::CAC_META, '');
Centres::syncFromCacentre(['version' => 'v1-pfeepfee', 'locations' => [['id' => 'L0a0a0a0a0a', 'name' => 'Feehall', 'status' => 'active', 'aliases' => [],
    'calendar' => ['weekdays' => [1, 2, 3, 4, 5], 'holidays' => [], 'term_start' => '', 'term_end' => '',
        'terms' => [['start' => '2025-01-06', 'end' => '2025-03-28'], ['start' => '2025-04-22', 'end' => '2025-07-04'], ['start' => '2025-09-08', 'end' => '2025-12-12']]]]]]);
NgvLedger::saveSettings(['programmeBasis' => 'session', 'programmeRate' => 80000], 'test');
$seun = $pfMember('Seun Session', 'Feehall');
GateAttendance::forgetCentres();
NgvProgrammeFee::freeze($seun, '2025-01-06');
NgvProgrammeFee::logStatus($seun, 'active', '2025-01-06');
NgvProgrammeFee::logStatus($seun, 'paused', '2025-04-20');
NgvProgrammeFee::logStatus($seun, 'active', '2025-05-01');
NgvProgrammeFee::accrue(NgvMember::participant($seun), '2025-12-31');
$b = NgvLedger::balance($seun);
ck('ngv fee: per session — the terms starting in the training half, not one that starts after it', (int) $b['countBy']['programme'] === 1 && (int) $b['chargedBy']['programme'] === 80000);
ck('ngv fee: …and not a term that began while they were paused', !$pfNgv->query("SELECT 1 FROM ngv_charges WHERE member_id = $seun AND period = 'P:T2025-04-22'")->fetchColumn());

/* ── The page: one plan, no uniform ─────────────────────────────────────── */
Ngv::save(['plans' => [['name' => 'Training Only', 'price' => 'Free', 'enabled' => true], ['name' => 'Full Programme', 'price' => '₦240,000', 'enabled' => true]],
           'fees' => [['name' => 'Membership fee', 'amount' => '₦10,000 / year', 'desc' => 'Covers your uniform package, ID card & file setup.']]]);
$doc = Ngv::get();
ck('ngv fee: a page saved with the retired half-year plans offers only the full programme', array_column($doc['plans'], 'name') === ['Full Programme']);
ck('ngv fee: and does not promise a uniform NGV does not issue', !str_contains(strtolower((string) $doc['fees'][0]['desc']), 'uniform'));
Ngv::reset();

/* Put things back. */
foreach ([$ada, $bola, $dayo, $wale, $seun] as $id) {
    foreach (['ngv_charges', 'ngv_payments', 'ngv_status_log', 'ngv_participants'] as $tb) $pfNgv->prepare("DELETE FROM $tb WHERE member_id = ?")->execute([$id]);
}
$pfPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@pfee.test')");
$pfPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@pfee.test'");
$pfPdo->exec('DELETE FROM av_centres');
Database::metaSet(Centres::CAC_META, (string) ($pfCacWas ?? ''));
NgvLedger::saveSettings($pfFeesWas + ['programmeRate' => $pfFeesWas['programmeRate'] ?? null], 'test');
AvRules::save(['gate.programme_days' => is_array($pfDaysWas) ? implode(',', $pfDaysWas) : (string) $pfDaysWas], 'test');
GateAttendance::forgetCentres();
