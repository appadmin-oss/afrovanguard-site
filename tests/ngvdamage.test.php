<?php
/**
 * tests/ngvdamage.test.php — damage recording, and the statement email.
 *
 * The properties that make the difference between a process and a threat:
 *
 *   • RECORDING DAMAGE CHARGES NOTHING. Not on report, not on assessment, not
 *     ever until a staff member types an amount. Somebody who is told "damage
 *     has been recorded" and finds a bill has learned not to tell anybody next
 *     time.
 *   • What it COST and what somebody is ASKED FOR are different numbers, and
 *     both are stored, so a programme absorbing two thirds of a laptop screen
 *     can show that it did.
 *   • A charge raised for damage is an ordinary FINE in the ledger — same line,
 *     same void, same waiver — not a private balance of its own.
 *   • Waiving a charged record settles the charge too. Otherwise the account
 *     keeps asking for money the programme has just said it is not asking for.
 *   • A STATEMENT is not a reminder. It goes to somebody who owes nothing and
 *     tells them so, which the reminder rules can never do.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$dmReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_damages', 'ngv_charges', 'ngv_payments', 'ngv_participants'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};
$dmPerson = static function (int $id, string $name, string $email = ''): void {
    NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id,name,email,status,start_date,created_at,updated_at)
                           VALUES (?,?,?,'active','2026-03-01','2026-03-01','2026-03-01')")
        ->execute([$id, $name, $email !== '' ? $email : strtolower(str_replace(' ', '', $name)) . '@example.test']);
};

/* ══ Recording ═════════════════════════════════════════════════════════════ */

$dmReset();
$dmPerson(301, 'Ada Obi');
ck('ngv damage: a report needs to say what was damaged',
   empty(NgvDamage::report(301, ['description' => 'It fell off the desk somehow'], 1)['ok']));
ck('ngv damage: …and enough about what happened to act on',
   empty(NgvDamage::report(301, ['item' => 'Laptop screen', 'description' => 'broke'], 1)['ok']));

$rep = NgvDamage::report(301, [
    'item' => 'Laptop screen', 'occurred_on' => '2026-09-02', 'place' => 'Lab 2', 'severity' => 'major',
    'description' => 'Knocked off the desk while packing up. Cracked across the corner.',
    'estimate' => 40000,
], 1);
ck('ngv damage: a report with the facts is recorded', !empty($rep['ok']));

$d = NgvDamage::get((int) $rep['id']);
ck('ngv damage: it starts at reported, and says so in words a participant reads',
   $d['status'] === 'reported' && $d['statusLabel'] === 'Recorded — nothing charged' && $d['open']);
/* THE property. An estimate is not a charge, and a report is not a bill. */
ck('ngv damage: recording it charges NOTHING, estimate or no estimate',
   (int) NgvLedger::balance(301)['charged'] === 0
   && (int) NgvLedger::balance(301)['due']['fine'] === 0
   && (int) $d['estimate'] === 40000 && (int) $d['charged'] === 0);

// A date in the future is a typo, not an incident — clamped rather than refused,
// so a slip does not lose the report somebody has just typed out.
$fut = NgvDamage::report(301, ['item' => 'Projector', 'occurred_on' => '2099-01-01',
    'description' => 'Bulb blew during the Friday session.'], 1);
ck('ngv damage: a future date is clamped to today rather than losing the report',
   !empty($fut['ok']) && NgvDamage::get((int) $fut['id'])['occurred_on'] <= gmdate('Y-m-d'));

/* ══ Self-reporting ════════════════════════════════════════════════════════ */

$self = NgvDamage::report(301, ['item' => 'Office chair', 'severity' => 'minor',
    'description' => 'A castor came off while I was moving it.'], 0, true);
ck('ngv damage: a participant can report their own, and the record says so',
   !empty($self['ok']) && NgvDamage::get((int) $self['id'])['selfReport'] === true);
ck('ngv damage: …and that still charges nothing',
   (int) NgvLedger::balance(301)['charged'] === 0);

/* ══ Assessing ═════════════════════════════════════════════════════════════ */

$id = (int) $rep['id'];
$as = NgvDamage::advance($id, 'assessing', ['assessed' => 45000, 'outcome' => 'Quote from the repairer.'], 1);
ck('ngv damage: an assessment records the cost', !empty($as['ok'])
   && (int) NgvDamage::get($id)['assessed'] === 45000);
ck('ngv damage: knowing what it cost still charges nothing',
   (int) NgvLedger::balance(301)['charged'] === 0);
ck('ngv damage: a record cannot be pushed back to just-reported',
   empty(NgvDamage::advance($id, 'reported', [], 1)['ok']));

/* ══ Charging ══════════════════════════════════════════════════════════════ */

ck('ngv damage: charging without an amount is refused — the figure is a decision',
   empty(NgvDamage::advance($id, 'charged', ['assessed' => 45000], 1)['ok']));

$ch = NgvDamage::advance($id, 'charged', ['assessed' => 45000, 'charged' => 15000,
    'outcome' => 'Agreed a third with your track lead.'], 1);
ck('ngv damage: charging puts the agreed amount on the account, not the cost',
   !empty($ch['ok']) && (int) $ch['charged'] === 15000
   && (int) NgvLedger::balance(301)['due']['fine'] === 15000);

// It is an ordinary fine: same line, same reason vocabulary, same paths.
$fine = null;
foreach (NgvLedger::entries(301) as $en) { if ($en['kind'] === 'fine' && !$en['void']) $fine = $en; }
ck('ngv damage: the charge is an ordinary fine with reason `equipment`, not a private balance',
   $fine !== null && (int) $fine['amount'] === 15000 && $fine['reason'] === 'equipment'
   && strpos((string) $fine['note'], 'Laptop screen') !== false);
ck('ngv damage: the record and the charge point at each other',
   (int) NgvDamage::get($id)['entry_id'] === (int) $fine['id']);
ck('ngv damage: the money moves once — a second charge is refused',
   empty(NgvDamage::advance($id, 'charged', ['charged' => 5000], 1)['ok']));

// What the programme absorbed is a fact it should be able to state.
$tot = NgvDamage::totals();
ck('ngv damage: the totals name what the programme absorbed rather than hiding it',
   (int) $tot['assessed'] === 45000 && (int) $tot['charged'] === 15000 && (int) $tot['absorbed'] === 30000);

/* ══ Waiving a charged record ══════════════════════════════════════════════ */

$dmReset();
$dmPerson(302, 'Bode Ade');
$w = NgvDamage::report(302, ['item' => 'Keyboard', 'severity' => 'lost',
    'description' => 'Went missing from the lab over the weekend.'], 1);
NgvDamage::advance((int) $w['id'], 'charged', ['assessed' => 8000, 'charged' => 8000, 'outcome' => 'Replacement cost.'], 1);
ck('ngv damage: the charge is on the account before the waiver',
   (int) NgvLedger::balance(302)['due']['fine'] === 8000);
ck('ngv damage: waiving a charged record needs a reason — they see it',
   empty(NgvDamage::advance((int) $w['id'], 'waived', [], 1)['ok']));
$wv = NgvDamage::advance((int) $w['id'], 'waived', ['outcome' => 'Turned up in the store cupboard.'], 1);
ck('ngv damage: waiving settles the charge, so the account stops asking',
   !empty($wv['ok']) && (int) NgvLedger::balance(302)['due']['fine'] === 0);
ck('ngv damage: …and the charge stays on the record rather than being erased',
   (int) NgvLedger::balance(302)['chargedBy']['fine'] === 8000
   && (int) NgvLedger::balance(302)['waived'] === 8000);

/* ══ Closing with nothing owed ═════════════════════════════════════════════ */

$dmReset();
$dmPerson(303, 'Chidi Eze');
$cl = NgvDamage::report(303, ['item' => 'Desk lamp', 'severity' => 'minor',
    'description' => 'Switch stopped working, it is quite old.'], 1);
$cr = NgvDamage::advance((int) $cl['id'], 'closed', ['assessed' => 3000, 'outcome' => 'Wear and tear — nothing owed.'], 1);
ck('ngv damage: a record can be closed with nothing owed, and still say what it cost',
   !empty($cr['ok']) && (int) NgvLedger::balance(303)['charged'] === 0
   && (int) NgvDamage::get((int) $cl['id'])['assessed'] === 3000
   && NgvDamage::get((int) $cl['id'])['open'] === false);
ck('ngv damage: which is counted as absorbed, not as nothing',
   (int) NgvDamage::totals()['absorbed'] === 3000);

/* ══ The emails ════════════════════════════════════════════════════════════
 *
 * Asserted on what the record makes available and on the notify stamp rather
 * than on rendered HTML: there is no mail transport in CI, and a test that
 * pattern-matched the body would break on every wording change while proving
 * nothing about what the reader is told. The one thing worth pinning literally
 * is the first email's job — saying that nothing has been charged.
 */

$dmReset();
$dmPerson(304, 'Dami Ola');
$e1 = NgvDamage::report(304, ['item' => 'Monitor', 'severity' => 'major',
    'description' => 'Fell when the extension cable was pulled.'], 1);
ck('ngv damage: reporting stamps a notification attempt, however the mailer fared',
   NgvDamage::get((int) $e1['id'])['notified_at'] !== '');
$src = (string) @file_get_contents(AV_ROOT . '/lib/NgvDamage.php');
ck('ngv damage: the first email says in as many words that nothing has been charged',
   strpos($src, 'Nothing has been charged.') !== false);

NgvDamage::setNotify((int) $e1['id'], false);
ck('ngv damage: emails can be turned off per record for a conversation already happening',
   NgvDamage::get((int) $e1['id'])['notify'] === false
   && NgvDamage::notify((int) $e1['id'], 'assessing') === false);

// Assessments that stall. Nobody waits more patiently than somebody told "we
// are finding out what it costs" who has heard nothing since — and they have no
// way to chase it, so the cron says so on their behalf.
NgvDb::pdo()->prepare("UPDATE ngv_damages SET status = 'assessing', updated_at = '2026-01-05 09:00:00' WHERE id = ?")
    ->execute([(int) $e1['id']]);
ck('ngv damage: a stalled assessment is found and named',
   count(NgvDamage::stale('2026-09-07')) === 1 && !empty(NgvDamage::noteStale('2026-09-07')['ok']));
ck('ngv damage: …and nothing about saying so changes a figure',
   (int) NgvLedger::balance(304)['charged'] === 0);
ck('ngv damage: a fresh report is not stale',
   NgvDamage::stale('2026-01-06') === []);

/* ══ Statements ════════════════════════════════════════════════════════════ */

$dmReset();
$dmPerson(310, 'Grace Umeh');
NgvLedger::accrueParticipant(NgvMember::participant(310), '2026-09-07');
NgvLedger::startTrainingFee(310, 12, 1, 240000, '2026-09-07');
NgvLedger::charge(310, 'fine', 2000, 'late', 'Four times in August', 1);
// One line settled and one behind, so the statement has both to report — a
// letter that only listed arrears would be a reminder with a new subject line.
NgvLedger::payment(310, 'membership', 10000, ['method' => 'transfer'], 1);
$dg = NgvDamage::report(310, ['item' => 'Laptop screen', 'occurred_on' => '2026-09-02', 'severity' => 'major',
    'description' => 'Knocked off the desk while packing up.'], 1);
NgvDamage::advance((int) $dg['id'], 'charged', ['assessed' => 45000, 'charged' => 15000, 'outcome' => 'A third, agreed.'], 1);

$st = NgvLedger::statementRows(310);
$body = strip_tags(implode("\n", $st['rows']));
ck('ngv statement: it states what is outstanding',
   (int) $st['payable'] > 0 && strpos($body, 'Outstanding') !== false);
ck('ngv statement: every fee line, the settled ones included',
   strpos($body, 'Membership') !== false && strpos($body, 'Monthly commitment') !== false
   && strpos($body, 'settled') !== false && strpos($body, 'to pay') !== false);
ck('ngv statement: the training schedule month by month, not one total',
   strpos($body, 'instalments charged') !== false
   && strpos($body, '2026-09') !== false && strpos($body, '2027-08') !== false);
/* A fines TOTAL is the one figure on an account nobody accepts. "Late arrival,
   12 August" settles an argument that "₦17,000 of fines" starts. */
ck('ngv statement: each fine with the reason it was issued and the date it was',
   strpos($body, 'Late arrival') !== false && strpos($body, 'Four times in August') !== false
   && strpos($body, 'Equipment lost or damaged') !== false);
ck('ngv statement: every damage report and where it stands',
   strpos($body, 'Damage reports') !== false && strpos($body, 'On your account') !== false);
ck('ngv statement: where to pay, and the programme\'s own promise',
   strpos($body, 'UBA') !== false && strpos($body, 'turned away') !== false);

/* The distinction that matters. A reminder chases money and only reaches
   somebody who owes; a statement says where you stand and reaches somebody who
   owes nothing — who under the reminder rules could never be told so. */
$dmReset();
$dmPerson(311, 'Henry Obi');
NgvLedger::accrueParticipant(NgvMember::participant(311), '2026-09-07');
NgvLedger::payment(311, 'membership', 10000, [], 1);
NgvLedger::payment(311, 'commitment', 7000, [], 1);
$sq = NgvLedger::statementRows(311);
ck('ngv statement: somebody square with the programme is told they are square',
   (int) $sq['payable'] === 0 && strpos(strip_tags(implode("\n", $sq['rows'])), 'Nothing outstanding') !== false);
NgvLedger::saveSettings(['enabled' => true, 'remindEnabled' => true], 'test');
ck('ngv statement: …which the reminder machinery can never do',
   count(NgvLedger::reminderCandidates()['due']) === 0);

$sent = NgvLedger::sendStatement(311, true);
ck('ngv statement: a participant may ask for their own', !empty($sent['ok']));
ck('ngv statement: …but not four times in an afternoon',
   empty(NgvLedger::sendStatement(311, true)['ok']));
ck('ngv statement: staff sending one is never rate-limited — it is a deliberate act',
   !empty(NgvLedger::sendStatement(311, false)['ok'])
   && !empty(NgvLedger::sendStatement(311, false)['ok']));
ck('ngv statement: somebody with no email address is refused, and told why', (function () {
    NgvDb::pdo()->prepare("UPDATE ngv_participants SET email = '' WHERE member_id = ?")->execute([311]);
    $r = NgvLedger::sendStatement(311, false);
    return empty($r['ok']) && strpos((string) $r['error'], 'email') !== false;
})());

/* ══ What must never happen ════════════════════════════════════════════════ */

// A participant may REPORT damage and ASK for a statement. Neither is allowed to
// price anything, charge anything, or close a record.
$dashSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
$forbidden = ['NgvDamage::advance', 'NgvDamage::setNotify', 'NgvDamage::noteStale',
              'NgvLedger::sendStatements', 'NgvLedger::charge'];
$leaks = [];
foreach ($forbidden as $f) { if (strpos($dashSrc, $f) !== false) $leaks[] = $f; }
ck('ngv damage: a member\'s dashboard can report and read, never price or charge', $leaks === []);
/* An allowlist, not a count. The permitted set grows — reporting damage, and
   attaching a photo to it — and a bare `=== 1` fails on the addition rather than
   on the thing it was written to catch. What must stay true is that every
   member-side damage call is one of the two a participant is allowed to make. */
$allowedDamage = ['NgvDamage::report', 'NgvDamage::addPhotos', 'NgvDamage::get',
                  'NgvDamage::forMember', 'NgvDamage::SEVERITIES', 'NgvDamage::PHOTOS_MAX'];
preg_match_all('/NgvDamage::[A-Za-z_]+/', $dashSrc, $dmCalls);
ck('ngv damage: every damage call the dashboard makes is one a member may make',
   array_values(array_diff(array_unique($dmCalls[0]), $allowedDamage)) === []);

// Damage is between a participant and their team. None of it reaches the public
// pages, and no fee or damage state gates a certification.
$publicSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/index.php')
           . (string) @file_get_contents(AV_ROOT . '/academy/ngv/certificate.php')
           . (string) @file_get_contents(AV_ROOT . '/academy/ngv/register.php');
ck('ngv damage: nothing about damage reaches a public NGV page',
   strpos($publicSrc, 'NgvDamage') === false && strpos($publicSrc, 'ngv_damages') === false);

$dmReset();
