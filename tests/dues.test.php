<?php
/**
 * tests/dues.test.php — members OWE dues, and paying clears the oldest month.
 *
 *   • A member at an owing level owes a month's dues on the anchor day and
 *     every month after it; the anchor is the later of the start, the day
 *     they joined and the day paid dues ran out.
 *   • A payment covers the oldest month owed first: renewing after a lapse
 *     does not forgive the months between.
 *   • Learners, NextGen Vanguards (charged on the NGV account), suspended
 *     accounts, lifetime and office-ended dues owe nothing — with the reason.
 *   • Prices are the office's: one source for the portal, the offline check
 *     and the arrears.
 *   • The NGV ledger charges a membership YEAR from the day somebody began,
 *     and stops at the balance ceiling charge by charge.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$dPdo = Database::pdo();
MemberRoster::ensure(); Membership::ensure();
$dPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@dues.test')");
$dPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@dues.test')");
$dPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@dues.test'");
$keep = Database::metaGet(Membership::SETTINGS_KEY);

$mk = function (string $name, string $role = 'member', string $joined = '2026-01-15', string $level = 'O', string $status = 'active') use ($dPdo): int {
    $dPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$name, strtolower(str_replace(' ', '.', $name)) . '@dues.test', 'x', $role, $status, $joined . ' 09:00:00']);
    $id = (int) $dPdo->lastInsertId();
    $dPdo->prepare("UPDATE lms_users SET level = ? WHERE id = ?")->execute([$level, $id]);
    $dPdo->prepare("INSERT INTO member_profiles (user_id, joined_on, phone, centre, notes, work_roles, about, source, updated_at) VALUES (?, ?, '', '', '', '', '', '', '')")->execute([$id, $joined]);
    return $id;
};
$set = function (array $s): void {
    Database::metaSet(Membership::SETTINGS_KEY, (string) json_encode($s + ['enabled' => true, 'monthly_ngn' => 1000, 'annual_ngn' => 12000, 'from_level' => 'O']));
    Membership::forgetSettings();
};
$set(['start' => '2026-01-01']);
$at = static fn(string $d) => (int) strtotime($d . ' 12:00:00 UTC');

/* ── Who owes, and how much ─────────────────────────────────────────── */
$ada = $mk('Ada Never', 'member', '2026-03-05');
$a = Membership::arrearsFor([$ada], $at('2026-10-23'))[$ada] ?? null;
ck('dues: a member who never paid owes from the day they joined — 8 months, 5 Mar to 5 Oct',
   $a && $a['months'] === 8 && $a['since'] === '2026-03-05' && $a['next_due'] === '2026-11-05');
ck('dues: …priced by the month below a year', $a && $a['amount_ngn'] === 8000);
$old = $mk('Bola Before', 'member', '2020-06-01');
$b = Membership::arrearsFor([$old], $at('2026-10-23'))[$old] ?? null;
ck('dues: nobody owes for a month before dues began', $b && $b['since'] === '2026-01-01' && $b['months'] === 10);
ck('dues: a year owed is priced at the yearly rate', Membership::price(14) === 12000 + 2000 && Membership::price(12) === 12000);

$lap = $mk('Chidi Lapsed', 'member', '2025-01-10');
$dPdo->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at) VALUES (?, 'member', 'active', '2025-03-10 08:00:00', '2026-03-10 08:00:00')")->execute([$lap]);
$c = Membership::arrearsFor([$lap], $at('2026-10-23'))[$lap] ?? null;
ck('dues: a lapsed member owes from the day their dues ran out', $c && $c['since'] === '2026-03-10' && $c['months'] === 8);

/* ── Paying clears the oldest month first ───────────────────────────── */
$g = Membership::grant($lap, ['months' => 3, 'method' => 'waiver', 'note' => 'test', 'actor' => 't']);
ck('dues: three months paid after a lapse run from the lapse, not from today',
   $g['ok'] && substr($g['expires_at'], 0, 10) === '2026-06-10');
$c2 = Membership::arrears($lap);
ck('dues: …and leave the rest still owed', $c2['owes'] && $c2['since'] === '2026-06-10' && $c2['months'] >= 5);
$g2 = Membership::grant($lap, ['months' => 12, 'method' => 'waiver', 'note' => 'test', 'actor' => 't']);
ck('dues: a year paid clears the arrears and runs on from where they stood', $g2['ok'] && substr($g2['expires_at'], 0, 10) === '2027-06-10'
   && Membership::arrears($lap)['months'] === 0);
$lms = new LmsRepository();
$pay = $mk('Dayo Paystack', 'member', '2026-08-02');
$lms->grantMembership($pay, 1);
$exp = (string) $dPdo->query("SELECT expires_at FROM memberships WHERE user_id = $pay")->fetchColumn();
ck('dues: a Paystack payment clears the oldest month too (one rule for both paths)', substr($exp, 0, 10) === '2026-09-02');

/* ── Who does not owe, and why ──────────────────────────────────────── */
$lrn = $mk('Efe Learner', 'learner');
$sus = $mk('Femi Suspended', 'member', '2026-01-15', 'O', 'suspended');
$low = $mk('Gbenga Low', 'member', '2026-01-15', 'A');
$life = $mk('Halima Lifetime');
Membership::lifetime($life, 'Founder', 't');
$ended = $mk('Ike Ended');
Membership::grant($ended, ['months' => 12, 'method' => 'waiver', 'note' => 'x', 'actor' => 't']);
Membership::cancel($ended, 'Left', 't');
$codes = array_map(static fn($i) => Membership::obligation($i)['code'], [$lrn, $sus, $life, $ended]);
ck('dues: learners, suspended, lifetime and office-ended owe nothing, each with its reason', $codes === ['learner', 'suspended', 'lifetime', 'cancelled']);
ck('dues: …and none of them is on the arrears list', Membership::arrearsFor([$lrn, $sus, $life, $ended]) === []);
$set(['start' => '2026-01-01', 'from_level' => 'B']);
ck('dues: below the level dues start at, nothing is owed', Membership::obligation($low)['code'] === 'level' && Membership::obligation($ada)['code'] === 'level');
$set(['start' => '2026-01-01']);

/* ── The roster filter and the dashboard agree ──────────────────────── */
[$sql, $args] = Membership::owingSql();
$st = $dPdo->prepare("SELECT u.id FROM lms_users u WHERE u.email LIKE '%@dues.test' AND $sql ORDER BY u.id"); $st->execute($args);
$owingIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
ck('dues: "behind on dues" is the never-paid, the lapsed, and nobody exempt',
   in_array($ada, $owingIds, true) && in_array($old, $owingIds, true) && !in_array($lap, $owingIds, true) && !in_array($lrn, $owingIds, true) && !in_array($life, $owingIds, true));
$sum = Membership::owingSummary(5);
$all = array_filter(Membership::arrearsFor($owingIds), static fn($x) => $x['months'] > 0);
ck('dues: the total owed is the sum of what each member owes', $sum['members'] >= count($all) && $sum['amount_ngn'] >= array_sum(array_column($all, 'amount_ngn'))
   && array_sum(array_column($sum['top'], 'amount_ngn')) <= $sum['amount_ngn']);
$r = MemberRoster::roster(['q' => 'ada never']);
ck('dues: the roster row carries what the member owes', (($r['members'][0]['owes']['months'] ?? 0) > 0));

/* ── Prices are the office's, everywhere ────────────────────────────── */
$bad = Membership::saveSettings(['monthly_ngn' => '0'], 't');
ck('dues: a price of nothing is refused', !$bad['ok'] && isset($bad['fields']['monthly_ngn']));
$bad2 = Membership::saveSettings(['monthly_ngn' => '1000', 'annual_ngn' => '20000'], 't');
ck('dues: a year dearer than twelve months is refused', !$bad2['ok'] && isset($bad2['fields']['annual_ngn']));
$ok = Membership::saveSettings(['monthly_ngn' => '1500', 'annual_ngn' => '15000'], 't');
ck('dues: a saved price is the price the offline check and the arrears use',
   $ok['ok'] && OfflinePayments::duesPrice(1) === 1500 && OfflinePayments::duesPrice(12) === 15000 && Membership::arrears($ada)['amount_ngn'] === Membership::arrears($ada)['months'] * 1500);
$off = Membership::saveSettings(['enabled' => false], 't');
ck('dues: switched off, nobody owes', $off['ok'] && Membership::arrearsFor([$ada, $old]) === [] && Membership::obligation($ada)['code'] === 'off');

/* ── NGV: a membership year is a year from the day they began ───────── */
ck('ngv periods: a December start is not charged a second year in January',
   NgvLedger::periods('2026-12-05', '2027-01-02', 'year') === ['2026']);
ck('ngv periods: …it is on the anniversary', NgvLedger::periods('2026-12-05', '2027-12-05', 'year') === ['2026', '2027']);
ck('ngv periods: a guard date inside a year still owes that year', NgvLedger::periods('2026-08-01', '2026-09-07', 'year', 120, '2026-01-05') === ['2026']);
ck('ngv periods: …but not years that ended before it', NgvLedger::periods('2027-02-01', '2027-03-01', 'year', 120, '2025-01-05') === ['2027']);
ck('ngv periods: 29 February falls on the 28th', NgvLedger::periods('2024-02-29', '2025-02-28', 'year') === ['2024', '2025']);

/* ── Leave the shared database as it was ────────────────────────────── */
$dPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@dues.test')");
$dPdo->exec("DELETE FROM payments WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@dues.test')");
$dPdo->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@dues.test')");
$dPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@dues.test'");
Database::metaSet(Membership::SETTINGS_KEY, (string) ($keep ?? ''));
Membership::forgetSettings();
