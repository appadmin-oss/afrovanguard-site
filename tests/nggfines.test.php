<?php
/**
 * tests/nggfines.test.php — Afrovanguard's fines taken off NGG volunteer pay
 * (lib/NggFineSettle.php, integrations/ngg-fines.php), and the wallet's NGG
 * bank details (lib/NggPay.php, portal/wallet.js).
 *
 * What it promises:
 *   · NGG learns what each person owes in fines here, nobody else's figures
 *   · a settlement is a payment on their fines line, never more than is owing,
 *     recorded once however often NGG sends it
 *   · NGG voiding the payout voids the settlement: the fine is owed again
 *   · only NGG's signature for this door opens it
 *   · the member's statement says where the money came from
 *   · the member sets where NGG pays them; the number is never shown back
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$nfPdo = Database::pdo();
Membership::ensure();
$nfPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role, status) VALUES ('Fined Volunteer', 'fined@nf.test', 'x', 'member', 'active')")->execute();
$nf = (int) $nfPdo->query("SELECT id FROM lms_users WHERE email = 'fined@nf.test'")->fetchColumn();
$nfIssued = NgvFines::issue($nf, 'late', 1500, 'test', date('Y-m-d'), false, 0);
ck('ngg fines: a fine to settle exists', !empty($nfIssued['ok']));

$nfOwed = NggFineSettle::owed([' Fined@NF.test ', 'nobody@nf.test']);
$nfO = (array) $nfOwed['owing'];
ck('ngg fines: NGG learns what each email owes in fines, and an unknown email owes nothing', ($nfO['fined@nf.test'] ?? -1) === 1500 && ($nfO['nobody@nf.test'] ?? -1) === 0);

$nfRef = 'VP-ABCDEF1234';
$nfS = (array) NggFineSettle::settle([['email' => 'fined@nf.test', 'amount' => 9000, 'reference' => $nfRef, 'note' => 'Taken from NGG volunteer pay, October 2026']])['settled'];
ck('ngg fines: a settlement takes no more than is owing', ($nfS[$nfRef] ?? 0) === 1500 && (int) (NgvFines::forMember($nf)['owing'] ?? -1) === 0);
$nfS2 = (array) NggFineSettle::settle([['email' => 'fined@nf.test', 'amount' => 1500, 'reference' => $nfRef]])['settled'];
$nfRows = NgvLedger::paymentsByReference($nfRef);
ck('ngg fines: sent twice, it is recorded once', ($nfS2[$nfRef] ?? 0) === 1500 && count($nfRows) === 1);
ck('ngg fines: it is a payment on the fines line, marked as from NGG pay', ($nfRows[0]['kind'] ?? '') === 'fine' && ($nfRows[0]['method'] ?? '') === 'ngg_pay' && str_contains((string) $nfRows[0]['note'], 'Held by NGG'));
ck('ngg fines: a reference that is not an NGG payout is ignored', ((array) NggFineSettle::settle([['email' => 'fined@nf.test', 'amount' => 1, 'reference' => 'X1']])['settled']) === []);
ck('ngg fines: the desk can see what NGG holds for Afrovanguard', NggFineSettle::heldByNgg() >= 1500);

$nfSt = MemberStatement::for($nf);
ck('ngg fines: the member’s statement says the fine was paid from their NGG pay',
   (bool) array_filter($nfSt['entries'], static fn($e) => ($e['label'] ?? '') === 'Fine paid from your NGG volunteer pay' && (int) $e['credit_kobo'] === 150000));

$nfU = NggFineSettle::unsettle($nfRef, 'Paid twice');
ck('ngg fines: NGG voiding the payout voids the settlement, and the fine is owed again', ($nfU['voided'] ?? 0) === 1 && (int) (NgvFines::forMember($nf)['owing'] ?? 0) === 1500);
ck('ngg fines: …and that cannot be undone a second time', (NggFineSettle::unsettle($nfRef, 'again')['voided'] ?? -1) === 0);

$nfSec = str_repeat('k', 40); $nfTs = (string) time(); $nfBody = '{"op":"owed","emails":[]}';
ck('ngg fines: only NGG’s signature for this door opens it: right, stale, another door’s, or no secret',
   NggFineSettle::signatureOk($nfSec, $nfTs, $nfBody, 'sha256=' . hash_hmac('sha256', 'fines.' . $nfTs . '.' . $nfBody, $nfSec))
   && !NggFineSettle::signatureOk($nfSec, (string) (time() - 900), $nfBody, 'sha256=' . hash_hmac('sha256', 'fines.' . (time() - 900) . '.' . $nfBody, $nfSec))
   && !NggFineSettle::signatureOk($nfSec, $nfTs, $nfBody, 'sha256=' . hash_hmac('sha256', 'cards.' . $nfTs . '.' . $nfBody, $nfSec))
   && !NggFineSettle::signatureOk('', $nfTs, $nfBody, 'sha256=' . hash_hmac('sha256', 'fines.' . $nfTs . '.' . $nfBody, '')));
$nfDoor = (string) file_get_contents(AV_ROOT . '/integrations/ngg-fines.php');
ck('ngg fines: the door does not exist without the secret, and refuses a bad signature', str_contains($nfDoor, "'not-configured'], 404") && str_contains($nfDoor, "'bad_signature'], 401"));

/* ── Where NGG pays them, set from this wallet ───────────────────────────── */
$nfKeep = getenv('NGG_WEBHOOK_SECRET');
putenv('NGG_WEBHOOK_SECRET=' . str_repeat('n', 40));
$nfSeen = null;
NggPay::$transport = static function (string $url, string $body, array $headers) use (&$nfSeen): array {
    $nfSeen = json_decode($body, true);
    if (($nfSeen['accountNumber'] ?? '') === '12345') return ['code' => 422, 'body' => '{"ok":false,"error":"bad_account_number","detail":"An account number is 10 digits."}'];
    return ['code' => 200, 'body' => '{"ok":true,"bank":{"set":true,"bankName":"GTBank","accountName":"Fined Volunteer","last4":"6789","accountNumber":"0123456789"}}'];
};
$nfU1 = ['id' => $nf, 'email' => 'Fined@nf.test'];
$nfBad = NggPay::bankSet($nfU1, ['bankName' => 'GTBank', 'accountNumber' => '12345', 'accountName' => 'Fined Volunteer']);
ck('ngg bank: NGG’s refusal reaches the member in its own words', ($nfBad['ok'] ?? true) === false && ($nfBad['error'] ?? '') === 'An account number is 10 digits.');
$nfGood = NggPay::bankSet($nfU1, ['bankName' => 'GTBank', 'accountNumber' => '0123-456-789', 'accountName' => 'Fined Volunteer']);
ck('ngg bank: sent to NGG for this member’s email, digits only', ($nfSeen['op'] ?? '') === 'bank_set' && ($nfSeen['email'] ?? '') === 'fined@nf.test' && ($nfSeen['accountNumber'] ?? '') === '0123456789');
ck('ngg bank: the answer keeps the last four digits, never the whole number', ($nfGood['bank']['last4'] ?? '') === '6789' && !str_contains((string) json_encode($nfGood), '0123456789'));
NggPay::$transport = null;
putenv('NGG_WEBHOOK_SECRET');
if ($nfKeep !== false) putenv('NGG_WEBHOOK_SECRET=' . $nfKeep);

$nfClean = NggPay::clean(['owed' => 0, 'payouts' => [['period' => 'October 2026', 'amount' => 500, 'gross' => 2500, 'fineNgg' => 1000, 'fineAv' => 1000, 'days' => 1]],
    'finesTaken' => 2000, 'finesDeducted' => true, 'bank' => ['set' => true, 'bankName' => 'GTBank', 'last4' => '<b>', 'accountName' => 'X', 'accountNumber' => '0123456789']]);
ck('ngg bank: the wallet shows each payment’s fines and the bank, bounded and without the number',
   $nfClean['payouts'][0]['fines'] === 2000 && $nfClean['payouts'][0]['gross'] === 2500 && $nfClean['finesTaken'] === 2000
   && $nfClean['bank']['last4'] === '' && !str_contains((string) json_encode($nfClean), '0123456789'));
$nfJs = (string) file_get_contents(AV_ROOT . '/portal/wallet.js');
$nfRoute = (string) file_get_contents(AV_ROOT . '/portal/ngg-pay.php');
ck('ngg bank: the form is CSRF-checked and rate-limited, and drawn without innerHTML',
   str_contains($nfRoute, "av_require_write((int) \$u['id'], 'ngg_bank'") && str_contains($nfJs, "'X-CSRF-Token'") && !str_contains($nfJs, 'innerHTML'));
ck('ngg bank: no inline styles on the wallet', !preg_match('~\bstyle\s*=~i', (string) file_get_contents(AV_ROOT . '/portal/views/wallet.php')) && !str_contains($nfJs, '.style.'));

foreach (['ngv_payments', 'ngv_charges'] as $nfT) NgvDb::pdo()->prepare("DELETE FROM {$nfT} WHERE member_id = ?")->execute([$nf]);
$nfPdo->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$nf]);
