<?php
/**
 * tests/ngvledger.test.php — NextGen Vanguard money.
 *
 * The properties pinned here are the ones that make a ledger trustworthy, and
 * every one of them is a mistake this subsystem could plausibly make:
 *
 *   • The figures come off the PUBLIC PAGE. Two hard-coded constants and an
 *     editable page is how a participant ends up holding a receipt that
 *     disagrees with the website.
 *   • Accrual is IDEMPOTENT. A cron that overlaps a staff button must not charge
 *     August twice, and the property lives in a unique index rather than in
 *     everybody remembering not to press twice.
 *   • Switching fees on does NOT back-charge history. The rollout guard is the
 *     difference between a new feature and a cohort of young people opening
 *     their dashboard to a year of debt nobody discussed with them.
 *   • The TRAINING FEE is not accrued from a self-selected plan. A participant
 *     clicking "Full Programme" out of curiosity must not be able to give
 *     themselves a ₦240,000 debt — and once agreed, its total is FROZEN, so a
 *     price edit on the public page cannot re-price somebody already paying.
 *   • A fine carries a REASON, a waiver carries a REASON, and a void carries a
 *     REASON — and none of the three deletes anything.
 *   • Paying ahead on one line never hides arrears on another.
 *   • The cap stops the MACHINE, not the person standing in front of somebody.
 *   • Nothing on a cron path can change an amount.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$nl = static function (): PDO { return NgvDb::pdo(); };

/** A clean NGV database and a clean fee settings document. */
$nlReset = static function () use ($nl): void {
    $pdo = $nl();
    foreach (['ngv_charges', 'ngv_payments', 'ngv_certifications', 'ngv_participants', 'ngv_applications'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
};

/** An enrolled participant with a known start date and plan. */
$nlPerson = static function (int $id, string $name, string $start, string $plan = '', string $status = 'active') use ($nl): array {
    $pdo = $nl();
    $pdo->prepare('INSERT INTO ngv_participants (member_id,name,email,plan,status,start_date,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $name, strtolower(str_replace(' ', '', $name)) . '@example.test', $plan, $status, $start, $start, $start]);
    $st = $pdo->prepare('SELECT * FROM ngv_participants WHERE member_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
};

$nlOn = static function (array $over = []): array {
    return NgvLedger::saveSettings($over + ['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};

/* ══ Where the amounts come from ═══════════════════════════════════════════ */

$nlReset();
$amt = NgvLedger::amounts();
ck('ngv fees: the membership figure is read off the public page, not a constant',
   (int) $amt['membership']['amount'] === 10000 && $amt['membership']['source'] === 'page');
ck('ngv fees: …and so is the monthly commitment, with the page\'s own cadence',
   (int) $amt['commitment']['amount'] === 1000 && $amt['commitment']['cadence'] === 'month'
   && $amt['commitment']['source'] === 'page');
ck('ngv fees: the training fee is the plan price from the same page',
   (int) ($amt['plans']['Full Programme']['fee'] ?? 0) === 240000
   && ($amt['plans']['Full Programme']['cadence'] ?? '') === 'year'
   && (int) ($amt['plans']['Training Only']['fee'] ?? -1) === 0);

// Rows are matched by NAME, so reordering the two on the page cannot swap the
// yearly and the monthly charge on everybody's account.
$ngvDoc = Ngv::get();
$flipped = $ngvDoc;
$flipped['fees'] = array_reverse($ngvDoc['fees']);
Ngv::save($flipped);
$amtFlipped = NgvLedger::amounts();
ck('ngv fees: reordering the fee rows on the page does not swap the charges',
   (int) $amtFlipped['membership']['amount'] === 10000 && (int) $amtFlipped['commitment']['amount'] === 1000);
Ngv::save($ngvDoc);

// An admin can pin a figure the page cannot express, and it says it is pinned.
$nlOn(['membershipYearly' => 12500]);
$amtPinned = NgvLedger::amounts();
ck('ngv fees: a pinned amount overrides the page and admits that it did',
   (int) $amtPinned['membership']['amount'] === 12500 && $amtPinned['membership']['source'] === 'pinned');
$nlOn(['membershipYearly' => '']);
ck('ngv fees: clearing the pin goes back to the page',
   NgvLedger::amounts()['membership']['source'] === 'page');

/* ══ Accrual ═══════════════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$ada = $nlPerson(101, 'Ada Obi', '2026-03-10', 'Full Programme');
$r = NgvLedger::accrueParticipant($ada, '2026-09-07');
ck('ngv fees: accrual charges membership once a year and the commitment once a month',
   $r['membership'] === 1 && $r['commitment'] === 7 && $r['skipped'] === '');

$before = NgvLedger::balance(101);
NgvLedger::accrueParticipant($ada, '2026-09-07');
NgvLedger::accrueAll(100, '2026-09-07');
$after = NgvLedger::balance(101);
ck('ngv fees: running accrual again does NOT charge the same month twice',
   (int) $before['charged'] === (int) $after['charged'] && (int) $after['charged'] === 17000);

// The guard. Somebody enrolled two years before the ledger existed must not be
// handed twenty-four months of arrears the first time the cron runs.
$nlReset();
$nlOn(['accrueFrom' => '2026-08-01']);
$old = $nlPerson(102, 'Bode Ade', '2024-01-05');
$g = NgvLedger::accrueParticipant($old, '2026-09-07');
ck('ngv fees: switching fees on does not back-charge a member\'s whole history',
   $g['commitment'] === 2 && $g['membership'] === 1);
ck('ngv fees: …and the guard is stamped automatically the first time fees go on', (function () {
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    $cfg = NgvLedger::saveSettings(['enabled' => true], 'test');     // no accrueFrom given
    return (string) $cfg['accrueFrom'] !== '' && substr((string) $cfg['accrueFrom'], -2) === '01';
})());

// Only the enrolled accrue: an applicant has not started and a withdrawal has
// stopped, and charging either is charging for a place nobody is taking up.
$nlReset();
$nlOn();
foreach (['applicant', 'paused', 'withdrawn', 'completed'] as $i => $st) {
    $nlPerson(200 + $i, 'Person ' . $i, '2026-01-05', '', $st);
}
$anyCharged = false;
foreach ([200, 201, 202, 203] as $id) {
    $p = NgvMember::participant($id);
    $rr = NgvLedger::accrueParticipant($p, '2026-09-07');
    if ($rr['skipped'] !== 'not_active') $anyCharged = true;
}
ck('ngv fees: only currently-enrolled participants accrue anything', !$anyCharged);

/* ══ The training fee ══════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$paid = $nlPerson(110, 'Chidi Eze', '2026-03-10', 'Full Programme');
NgvLedger::accrueParticipant($paid, '2026-09-07');
ck('ngv fees: a self-selected paid plan does NOT charge itself to the account',
   (int) (NgvLedger::balance(110)['chargedBy']['programme'] ?? 0) === 0);

// Agreed in full: one charge, this month.
$full = NgvLedger::startTrainingFee(110, 1, 1, null, '2026-09-07');
ck('ngv fees: staff agree the training fee, priced from the plan',
   !empty($full['ok']) && (int) $full['total'] === 240000 && (int) $full['posted'] === 1);
ck('ngv fees: a second commitment cannot be stacked on an unfinished one',
   empty(NgvLedger::startTrainingFee(110, 12, 1, null, '2026-09-07')['ok']));

$free = $nlPerson(111, 'Dami Ola', '2026-03-10', 'Training Only');
ck('ngv fees: a free plan has no training fee to agree',
   empty(NgvLedger::startTrainingFee(111, 1, 1, null, '2026-09-07')['ok']));

/* ── Instalments ────────────────────────────────────────────────────────────
 * ₦240,000 posted as one charge is a wall: the dashboard reads ₦240,000
 * outstanding from the first day to the last, the arrears list pins that person
 * to the top, and a reminder quotes a figure nobody could pay this month. */
$nlReset();
$nlOn();
$ins = $nlPerson(113, 'Grace Umeh', '2026-03-10', 'Full Programme');
$st = NgvLedger::startTrainingFee(113, 12, 1, null, '2026-09-07');
ck('ngv fees: a training fee can be agreed as monthly instalments',
   !empty($st['ok']) && (int) $st['months'] === 12 && (int) $st['each'] === 20000);
ck('ngv fees: only the instalments whose month has arrived are charged',
   (int) $st['posted'] === 1 && (int) NgvLedger::balance(113)['due']['programme'] === 20000);

$sched = NgvLedger::trainingSchedule(113, '2026-09-07');
ck('ngv fees: the schedule states every instalment, charged and still to come',
   count($sched['instalments']) === 12
   && $sched['instalments'][0]['state'] === 'charged'
   && $sched['instalments'][1]['state'] === 'upcoming'
   && $sched['instalments'][0]['period'] === '2026-09'
   && $sched['instalments'][11]['period'] === '2027-08');

// The instalments must sum to exactly the total, whatever the division does, and
// the remainder rides on the LAST one — a bigger opening bill is exactly
// backwards for somebody deciding whether they can start at all.
$nlReset();
$nlOn();
$nlPerson(114, 'Henry Obi', '2026-03-10', 'Full Programme');
NgvLedger::startTrainingFee(114, 7, 1, 100000, '2026-09-07');
$s7 = NgvLedger::trainingSchedule(114, '2026-09-07');
$sum7 = 0; foreach ($s7['instalments'] as $i) $sum7 += (int) $i['amount'];
ck('ngv fees: instalments sum to the agreed total even when it does not divide',
   $sum7 === 100000 && (int) $s7['instalments'][0]['amount'] === 14285
   && (int) $s7['instalments'][6]['amount'] === 14290);

// Accrual walks the schedule forward, once per month, idempotently.
$r6 = NgvLedger::accrueParticipant(NgvMember::participant(114), '2027-01-07');
ck('ngv fees: accrual posts each instalment as its month arrives',
   $r6['programme'] === 4 && (int) NgvLedger::balance(114)['chargedBy']['programme'] === 71425);
ck('ngv fees: …and running it again posts none of them twice',
   NgvLedger::accrueParticipant(NgvMember::participant(114), '2027-01-07')['programme'] === 0);

// The total is FROZEN at the moment it is agreed. This is the property that
// makes a schedule trustworthy: a price edit on the public page changes what the
// next person is quoted and moves nothing for somebody already paying.
$doc = Ngv::get();
$dearer = $doc;
foreach ($dearer['plans'] as $k => $pl) { if (($pl['name'] ?? '') === 'Full Programme') $dearer['plans'][$k]['price'] = '₦480,000'; }
Ngv::save($dearer);
$c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
$afterRise = NgvLedger::trainingSchedule(114, '2027-01-07');
ck('ngv fees: doubling the plan price on the page does not move an agreed schedule',
   (int) $afterRise['total'] === 100000
   && (int) NgvLedger::amounts()['plans']['Full Programme']['fee'] === 480000);
ck('ngv fees: …nor does it re-price an instalment already charged',
   NgvLedger::accrueParticipant(NgvMember::participant(114), '2027-01-07')['programme'] === 0
   && (int) NgvLedger::balance(114)['chargedBy']['programme'] === 71425);
Ngv::save($doc);
$c->setValue(null, null);

// Stopping is what a withdrawal leaves behind. Future instalments stop; what has
// already been charged stays, because it happened.
$stopped = NgvLedger::stopTrainingFee(114);
$was = (int) NgvLedger::balance(114)['chargedBy']['programme'];
ck('ngv fees: stopping a schedule halts future instalments and keeps the past',
   !empty($stopped['ok'])
   && NgvLedger::accrueParticipant(NgvMember::participant(114), '2027-08-07')['programme'] === 0
   && (int) NgvLedger::balance(114)['chargedBy']['programme'] === $was);
ck('ngv fees: stopping a schedule that is not running says so',
   empty(NgvLedger::stopTrainingFee(114)['ok']));

// Switched on deliberately, the accrual agrees the commitment itself.
$nlReset();
$nlOn(['trainingAuto' => true, 'trainingInstalments' => 6]);
$auto = $nlPerson(112, 'Efe Uche', '2026-03-10', 'Full Programme');
$ra = NgvLedger::accrueParticipant($auto, '2026-09-07');
ck('ngv fees: turned on deliberately, the accrual agrees the schedule itself',
   $ra['programme'] === 1
   && (int) NgvLedger::trainingSchedule(112, '2026-09-07')['months'] === 6);
$nlOn(['trainingAuto' => false]);

/* ══ Fines ═════════════════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$nlPerson(120, 'Femi Bala', '2026-03-10');
ck('ngv fees: a fine cannot be posted without a reason from the list',
   empty(NgvLedger::charge(120, 'fine', 2000, 'because', '', 1)['ok']));
ck('ngv fees: "other" is the escape hatch, so it cannot also be the silent one',
   empty(NgvLedger::charge(120, 'fine', 2000, 'other', '', 1)['ok']));
ck('ngv fees: an adjustment always needs a written reason',
   empty(NgvLedger::charge(120, 'adjustment', 2000, '', '', 1)['ok']));
$fine = NgvLedger::charge(120, 'fine', 2000, 'late', 'Four times in August', 1);
ck('ngv fees: a fine with a reason posts, and the reason is on the row',
   !empty($fine['ok'])
   && (function () {
       foreach (NgvLedger::entries(120) as $e) {
           if ($e['kind'] === 'fine') return $e['reason'] === 'late' && $e['reasonLabel'] === 'Late arrival';
       }
       return false;
   })());
$twice = NgvLedger::charge(120, 'fine', 500, 'late', 'And again', 1);
ck('ngv fees: two fines in one month are two events, not one deduplicated row',
   !empty($twice['ok']) && (int) NgvLedger::balance(120)['chargedBy']['fine'] === 2500);
ck('ngv fees: a fine\'s collision token never reaches a screen', (function () {
    foreach (NgvLedger::entries(120) as $e) {
        if ($e['kind'] === 'fine' && strpos((string) $e['period'], 'fine:') !== false) return false;
    }
    return true;
})());

/* ══ Payments, per line ════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$g = $nlPerson(130, 'Grace Nwosu', '2026-03-10');
NgvLedger::accrueParticipant($g, '2026-09-07');       // 10,000 membership + 7,000 commitment
NgvLedger::payment(130, 'membership', 10000, ['method' => 'transfer'], 1);
$b = NgvLedger::balance(130);
ck('ngv fees: a payment settles the line it was for, and only that line',
   (int) $b['due']['membership'] === 0 && (int) $b['due']['commitment'] === 7000 && (int) $b['payable'] === 7000);

NgvLedger::payment(130, 'membership', 5000, [], 1);   // paid ahead on a settled line
$b = NgvLedger::balance(130);
ck('ngv fees: paying ahead on one line never hides arrears on another',
   (int) $b['payable'] === 7000 && (int) $b['paidAhead'] === 5000);

// A payment nobody could allocate reduces the ACCOUNT rather than a line — which
// is also how every payment recorded before this ledger existed is treated.
NgvLedger::payment(130, 'other', 2000, [], 1);
ck('ngv fees: an unallocated payment reduces the account, not a line',
   (int) NgvLedger::balance(130)['payable'] === 5000
   && (int) NgvLedger::balance(130)['due']['commitment'] === 7000);

/* ══ Waivers ═══════════════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$h = $nlPerson(140, 'Halima Sani', '2026-03-10');
NgvLedger::accrueParticipant($h, '2026-09-07');
ck('ngv fees: a waiver without a reason is refused',
   empty(NgvLedger::waive(140, 'commitment', 1000, '   ', 1)['ok']));
$w = NgvLedger::waive(140, 'commitment', 999999, 'Lost her job in July', 1);
ck('ngv fees: a waiver is clamped to what is outstanding, and says it clamped',
   !empty($w['ok']) && (int) $w['waived'] === 7000 && !empty($w['clamped']));
ck('ngv fees: the waived charge stays on the account rather than being erased', (function () {
    $charged = 0; $waived = 0;
    foreach (NgvLedger::entries(140) as $e) {
        if ($e['side'] === 'charge' && $e['kind'] === 'commitment') $charged += (int) $e['amount'];
        if ($e['creditKind'] === 'waiver') $waived += (int) $e['amount'];
    }
    return $charged === 7000 && $waived === 7000 && (int) NgvLedger::balance(140)['due']['commitment'] === 0;
})());

// A write-off is the same arithmetic and a different sentence, so it is stored
// as a different kind rather than as a waiver with a note on it.
$nlPerson(141, 'Ijeoma Nnaji', '2026-03-10');
NgvLedger::accrueParticipant(NgvMember::participant(141), '2026-09-07');
$wo = NgvLedger::writeOff(141, 'commitment', 999999, 'Withdrew in June', 1);
ck('ngv fees: a write-off is clamped like a waiver and recorded as its own kind',
   !empty($wo['ok']) && (int) $wo['waived'] === 7000 && (function () {
       foreach (NgvLedger::entries(141) as $e) { if ($e['creditKind'] === 'writeoff') return true; }
       return false;
   })());
ck('ngv fees: a write-off needs a reason too',
   empty(NgvLedger::writeOff(141, 'membership', 100, '', 1)['ok']));
ck('ngv fees: neither is money received',
   (int) NgvLedger::balance(141)['received'] === 0 && (int) NgvLedger::balance(141)['credited'] === 7000);

// "You have paid ₦12,000" when ₦2,000 of it was the programme deciding not to
// ask is a sentence that is not true of the person reading it.
$b = NgvLedger::balance(140);
ck('ngv fees: what a waiver settled is never reported as money the person paid',
   (int) $b['received'] === 0 && (int) $b['waived'] === 7000 && (int) $b['credited'] === 7000);

/* ══ Voids ═════════════════════════════════════════════════════════════════ */

$nlReset();
$nlOn();
$i = $nlPerson(150, 'Ibrahim Yusuf', '2026-03-10');
NgvLedger::accrueParticipant($i, '2026-09-07');
$pay = NgvLedger::payment(150, 'commitment', 3000, ['method' => 'cash'], 1);
ck('ngv fees: a void without a reason is refused — that would be a deletion',
   empty(NgvLedger::void('credit', (int) $pay['id'], '', 1)['ok']));
ck('ngv fees: a void with a reason succeeds, and a second one is refused',
   !empty(NgvLedger::void('credit', (int) $pay['id'], 'Entered twice', 1)['ok'])
   && empty(NgvLedger::void('credit', (int) $pay['id'], 'again', 1)['ok']));
ck('ngv fees: the voided row leaves the arithmetic but stays on the account', (function () {
    $b = NgvLedger::balance(150);
    $seen = false;
    foreach (NgvLedger::entries(150) as $e) {
        if ($e['void'] && $e['voidReason'] === 'Entered twice') $seen = true;
    }
    return $seen && (int) $b['due']['commitment'] === 7000;
})());

/* ══ The ceiling ═══════════════════════════════════════════════════════════ */

$nlReset();
$nlOn(['balanceCap' => 5000]);
$j = $nlPerson(160, 'Joy Mba', '2026-01-05');
NgvLedger::accrueParticipant($j, '2026-09-07');
$capped = NgvLedger::accrueParticipant(NgvMember::participant(160), '2027-06-07');
ck('ngv fees: a capped account stops accruing instead of growing without limit',
   $capped['skipped'] === 'at_cap');
$overrule = NgvLedger::charge(160, 'fine', 1000, 'late', '', 1);
ck('ngv fees: the ceiling stops the machine, not the person — but it says so',
   !empty($overrule['ok']) && !empty($overrule['overCap']));

/* ══ Reminders ═════════════════════════════════════════════════════════════ */

$nlReset();
$cfg = NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01',
                                'remindEnabled' => true, 'remindEveryDays' => 1], 'test');
ck('ngv fees: the reminder cadence has a floor, and typing under it gets the floor',
   (int) $cfg['remindEveryDays'] === NgvLedger::REMIND_MIN_DAYS);

$k = $nlPerson(170, 'Kemi Alao', '2026-03-10');
NgvLedger::accrueParticipant($k, '2026-09-07');
$nlPerson(171, 'Lanre Ojo', '2026-03-10');            // charged nothing, so owes nothing
$c = NgvLedger::reminderCandidates();
ck('ngv fees: only people who actually owe something are chased',
   count($c['due']) === 1 && (int) $c['due'][0]['member_id'] === 170);
// "It would send 1 of 2" has to reconcile against the roster in front of staff,
// so somebody with nothing charged is counted rather than silently absent.
ck('ngv fees: and the preview accounts for everybody, not only the candidates',
   (int) $c['skipped']['notCharged'] === 1
   && 1 + array_sum($c['skipped']) === 2);

NgvLedger::runReminders();
$c2 = NgvLedger::reminderCandidates();
ck('ngv fees: a reminder is stamped whether or not the mail got out, so nobody is chased every tick',
   count($c2['due']) === 0 && (int) $c2['skipped']['tooSoon'] === 1);

NgvLedger::setRemindOff(170, true);
$c3 = NgvLedger::reminderCandidates();
ck('ngv fees: somebody who asked not to be chased is excluded, by name',
   count($c3['due']) === 0 && (int) $c3['skipped']['optedOut'] === 1);
NgvLedger::setRemindOff(170, false);

NgvLedger::saveSettings(['enabled' => true, 'remindEnabled' => false], 'test');
ck('ngv fees: reminders off means nothing is sent and the refusal says which switch',
   empty(NgvLedger::runReminders()['ok']) && (int) NgvLedger::reminderCandidates()['skipped']['off'] === 1);

// The message itself. It goes to a young person the programme exists to lift, so
// it states the figure, says where to pay, and repeats the page's own promise —
// no deadline, no consequence, no capitals.
$nlReset();
NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01', 'remindEnabled' => true], 'test');
$mm = $nlPerson(180, 'Musa Bello', '2026-03-10');
NgvLedger::accrueParticipant($mm, '2026-09-07');
NgvLedger::charge(180, 'fine', 1500, 'uniform', '', 1);
/* Asserted on the ACCOUNT the message is composed from rather than on the
   rendered HTML: there is no mail transport in CI, and a test that pattern-
   matched the body would break on every wording change while proving nothing
   about what the reader is actually told. */
$acctFor = NgvLedger::account(180);
ck('ngv fees: the reminder names each outstanding line rather than one bare total', (function () use ($acctFor) {
    $named = 0;
    foreach ($acctFor['lines'] as $ln) { if ((int) $ln['due'] > 0) $named++; }
    return $named >= 3;      // membership, commitment and the fine all stand separately
})());
ck('ngv fees: the account carries the page\'s promise and its bank details, so a reminder can repeat them',
   strpos((string) $acctFor['note'], 'turned away') !== false
   && strpos((string) $acctFor['payTo'], 'UBA') !== false);

/* ══ Asking for consideration ══════════════════════════════════════════════
 *
 * "No one is turned away for lack — speak to your track lead or send a letter
 * requesting consideration" is on the public page. Repeating that on a dashboard
 * and stopping there makes a promise into a dead end. These pin the two things
 * that keep the channel honest: a request cannot move money, and it cannot
 * vanish unanswered.
 */

$nlReset();
$nlOn();
$nlPerson(190, 'Ngozi Eke', '2026-03-10');
NgvLedger::accrueParticipant(NgvMember::participant(190), '2026-09-07');
$owedBefore = (int) NgvLedger::balance(190)['payable'];

ck('ngv fees: a request needs enough words to act on',
   empty(NgvLedger::raiseRequest(190, 'consideration', 'help')['ok']));
$req = NgvLedger::raiseRequest(190, 'consideration', 'Lost my Saturday job, can I pay in October?');
ck('ngv fees: a participant can ask for consideration from their own dashboard', !empty($req['ok']));
ck('ngv fees: asking changes NOTHING about what they owe',
   (int) NgvLedger::balance(190)['payable'] === $owedBefore);
ck('ngv fees: one open request at a time — a second does not get anybody helped faster',
   empty(NgvLedger::raiseRequest(190, 'query', 'And another thing about the figures')['ok']));
ck('ngv fees: it lands on the queue staff work from',
   NgvLedger::openRequestCount() === 1 && count(NgvLedger::requests(true)) === 1);

ck('ngv fees: answering without saying anything is refused — they see the reply',
   empty(NgvLedger::resolveRequest((int) $req['id'], 'resolved', '  ', 1)['ok']));
$ans = NgvLedger::resolveRequest((int) $req['id'], 'resolved', 'October is fine. Waived September, nothing to do.', 1);
ck('ngv fees: answering closes it and the outcome is stored where they can read it',
   !empty($ans['ok']) && NgvLedger::openRequestCount() === 0
   && NgvLedger::requestsFor(190)[0]['outcome'] !== ''
   && NgvLedger::requestsFor(190)[0]['status'] === 'resolved');
ck('ngv fees: answering it also moves no money — a waiver is posted separately',
   (int) NgvLedger::balance(190)['payable'] === $owedBefore);
ck('ngv fees: an answered request cannot be answered twice',
   empty(NgvLedger::resolveRequest((int) $req['id'], 'declined', 'changed my mind', 1)['ok']));
ck('ngv fees: …and once answered they may ask again',
   !empty(NgvLedger::raiseRequest(190, 'query', 'The October figure still looks wrong to me')['ok']));
ck('ngv fees: "answered, no change" is a real answer and says so',
   !empty(NgvLedger::resolveRequest(NgvLedger::requests(true)[0]['id'], 'declined',
        'Spoke on Tuesday — the figure is right.', 1)['ok'])
   && NgvLedger::requestsFor(190)[0]['status'] === 'declined');

/* ══ What must never happen ════════════════════════════════════════════════ */

$nlReset();
$nlOn(['accrueFrom' => '2025-01-01']);
$amountsBefore = NgvLedger::amounts();
$note = NgvLedger::noteReviewDue('2027-06-01');
$amountsAfter = NgvLedger::amounts();
ck('ngv fees: the review nudge fires when the amounts are a year stale',
   !empty($note['ok']));
ck('ngv fees: …and it cannot change a single amount — only a human does that',
   $amountsBefore['membership']['amount'] === $amountsAfter['membership']['amount']
   && $amountsBefore['commitment']['amount'] === $amountsAfter['commitment']['amount']);
ck('ngv fees: …and it is spaced, so a cron that ticks all day nudges once',
   empty(NgvLedger::noteReviewDue('2027-06-02')['ok']));
ck('ngv fees: marking the amounts reviewed clears the nudge',
   !empty(NgvLedger::markReviewed('test')) && empty(NgvLedger::reviewDue()['due']));

// Money is a private matter between a participant and their team. Nothing in the
// ledger may reach the public NGV page or the public certificate page.
$publicSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/index.php')
           . (string) @file_get_contents(AV_ROOT . '/academy/ngv/certificate.php')
           . (string) @file_get_contents(AV_ROOT . '/academy/ngv/register.php');
ck('ngv fees: no balance, charge or ledger read reaches a public NGV page',
   strpos($publicSrc, 'NgvLedger') === false
   && strpos($publicSrc, 'ngv_charges') === false
   && strpos($publicSrc, '::account(') === false);

// The member's own view is read-only by construction: the dashboard may render
// an account but must never post, waive, void or price one.
$dashSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
/* `raiseRequest` is deliberately absent from this list: it is the one
   member-side write, and it writes a MESSAGE. Everything that moves a figure
   stays staff-only, and the assertion above proves a request moves none. */
$writes = ['NgvLedger::payment', 'NgvLedger::charge', 'NgvLedger::waive', 'NgvLedger::writeOff',
           'NgvLedger::void', 'NgvLedger::postCharge', 'NgvLedger::startTrainingFee',
           'NgvLedger::stopTrainingFee', 'NgvLedger::accrue', 'NgvLedger::resolveRequest',
           'NgvLedger::saveSettings'];
$dashWrites = [];
foreach ($writes as $w) { if (strpos($dashSrc, $w) !== false) $dashWrites[] = $w; }
ck('ngv fees: a member\'s own dashboard can read an account but never move money',
   $dashWrites === []);

/* Nothing financial gates learning, attendance or certification. Read the ONE
   function's body rather than the rest of the file: a lazy `.*?` from the
   function name onwards matches the first "payable" anywhere below it, which
   passes and fails for reasons that have nothing to do with the claim. */
$gates = (string) @file_get_contents(AV_ROOT . '/lib/NgvMember.php');
$bodyOf = static function (string $src, string $fn): string {
    $at = strpos($src, 'function ' . $fn . '(');
    if ($at === false) return '';
    $next = strpos($src, "\n    public static function ", $at + 1);
    $end = strpos($src, "\n    private static function ", $at + 1);
    if ($end !== false && ($next === false || $end < $next)) $next = $end;
    return substr($src, $at, ($next === false ? strlen($src) : $next) - $at);
};
$certBody = $bodyOf($gates, 'addCertification');
ck('ngv fees: no fee state gates a certification',
   $certBody !== ''
   && strpos($certBody, 'payable') === false
   && strpos($certBody, 'NgvLedger') === false);

$nlReset();
