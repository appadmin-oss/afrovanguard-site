<?php
/**
 * tests/memberstatement.test.php — one statement, three ledgers, three units.
 *
 * A member's money lived in places that did not know about each other, and
 * no screen could answer "what do I owe and what have I paid?". This reads
 * them together. It writes nothing: Membership and NgvLedger stay
 * authoritative for their own charges.
 *
 * THE UNITS ARE THE POINT. The three sources do not agree, and nothing in
 * the codebase said so:
 *
 *   payments.amount_kobo        KOBO
 *   ngv_charges.amount          NAIRA
 *   offline_payments.amount_ngn NAIRA
 *
 * Added as they stand, ₦10,000 of dues and ₦10,000 of NGV charges read as
 * 1,010,000. The statement works in kobo and converts at the edge, and the
 * assertions below are mostly about that, because it is the error that
 * would be invisible: a wrong total still looks like a total.
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/MemberStatement.php';

/* ── naira() is the only place this divides by 100 ─────────────────────── */

ck('statement: whole naira', MemberStatement::naira(1000000) === '₦10,000');
ck('statement: kobo are not lost', MemberStatement::naira(1000050) === '₦10,000.50');
ck('statement: a credit balance reads as negative', MemberStatement::naira(-250000) === '−₦2,500');
ck('statement: zero', MemberStatement::naira(0) === '₦0');

/* ── the shape, on a member with nothing ───────────────────────────────── */

$empty = MemberStatement::for(0);
ck('statement: a member with nothing gets an empty statement, not an error',
   is_array($empty['entries']) && $empty['entries'] === []);
ck('statement: and a zero balance', $empty['balance_kobo'] === 0);
ck('statement: and it is complete', $empty['complete'] === true && $empty['problems'] === []);

/* ── the arithmetic, against real rows in all three ledgers ────────────
   Source assertions cannot catch a wrong total, and a wrong total still
   looks like a total. Every figure below is one the three tables disagree
   about the unit of. */

$msPdo = Database::pdo();
Membership::ensure();
OfflinePayments::ensure();
$msPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role) VALUES ('Stmt Member', 'stmt@ms.test', 'x', 'member')")->execute();
$ms = (int) $msPdo->query("SELECT id FROM lms_users WHERE email = 'stmt@ms.test'")->fetchColumn();
$msNow = gmdate('Y-m-d H:i:s');

/* dues: ₦10,000, stored in KOBO */
$msPdo->prepare("INSERT INTO payments (user_id, kind, reference, provider, amount_kobo, status, created_at, paid_at, months)
                 VALUES (?, 'membership', 'MS-DUES', 'paystack', 1000000, 'paid', ?, ?, 12)")
      ->execute([$ms, $msNow, $msNow]);

/* NGV: ₦10,000 charged, ₦2,500 paid — stored in NAIRA */
NgvDb::pdo()->prepare("INSERT INTO ngv_charges (member_id, kind, amount, period, created_at) VALUES (?, 'membership', 10000, '2026', ?)")
            ->execute([$ms, $msNow]);
NgvDb::pdo()->prepare("INSERT INTO ngv_payments (member_id, amount, method, reference, created_at) VALUES (?, 2500, 'transfer', 'MS-NGV', ?)")
            ->execute([$ms, $msNow]);

/* offline: ₦3,000 reported, unconfirmed — stored in NAIRA */
$msPdo->prepare("INSERT INTO offline_payments (user_id, purpose, months, amount_ngn, method, reference, paid_on, status, created_at)
                 VALUES (?, 'dues', 3, 3000, 'transfer', 'MS-OFF', ?, 'pending', ?)")
      ->execute([$ms, gmdate('Y-m-d'), $msNow]);

$st = MemberStatement::for($ms);

ck('statement: all three ledgers are read', $st['sources'] === ['dues', 'ngv', 'offline']);
ck('statement: ₦10,000 of kobo-dues and ₦10,000 of naira-NGV total ₦20,000, not 1,010,000',
   $st['charged_kobo'] === 2000000);
ck('statement: payments total ₦12,500 across two units', $st['paid_kobo'] === 1250000);
ck('statement: the balance is ₦7,500', $st['balance_kobo'] === 750000);
ck('statement: and reads as ₦7,500', MemberStatement::naira($st['balance_kobo']) === '₦7,500');

ck('statement: the unconfirmed ₦3,000 is held separately', $st['pending_kobo'] === 300000);
ck('statement: and is NOT in the balance', $st['balance_kobo'] === 750000);

/* The charge must precede the money that pays it when both land in the same
   second, or the statement shows a credit for something not yet owed. */
$kinds = array_map(static fn($e) => $e['source'] . ':' . ($e['charge_kobo'] > 0 ? 'charge' : 'credit'), $st['entries']);
ck('statement: a charge sorts before its payment at the same timestamp',
   array_search('ngv:charge', $kinds, true) < array_search('ngv:credit', $kinds, true));

/* A running balance that never goes negative here: nothing was overpaid. */
$neg = array_filter($st['entries'], static fn($e) => $e['balance_kobo'] < 0);
ck('statement: no line shows a credit balance that was never earned', $neg === []);

/* NGV history does not depend on the accrual switch. */
ck('statement: NGV charges are read whether or not accrual is enabled',
   NgvLedger::enabled() === false && $st['charged_kobo'] === 2000000);

$msPdo->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$ms]);
$msPdo->prepare('DELETE FROM offline_payments WHERE user_id = ?')->execute([$ms]);
$msPdo->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$ms]);
NgvDb::pdo()->prepare('DELETE FROM ngv_charges WHERE member_id = ?')->execute([$ms]);
NgvDb::pdo()->prepare('DELETE FROM ngv_payments WHERE member_id = ?')->execute([$ms]);

/* ── it stays a reader ─────────────────────────────────────────────────── */

$src = (string) file_get_contents(AV_ROOT . '/lib/MemberStatement.php');
ck('statement: it writes nothing',
   !preg_match('~\b(INSERT|UPDATE|DELETE)\b~i', preg_replace('~/\*.*?\*/~s', '', $src)));
ck('statement: every money field is named for its unit',
   !preg_match('~[\'"](charge|credit|balance|charged|paid)[\'"]\s*=>~', $src));
ck('statement: an unreadable source is named, not skipped',
   str_contains($src, '$problems[] = $source;') && str_contains($src, "'complete'"));
