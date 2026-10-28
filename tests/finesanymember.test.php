<?php
/**
 * tests/finesanymember.test.php — any Afrovanguard member can be fined, not
 * only NGV participants (lib/NgvFines.php).
 *
 *   • A member outside NGV is found by the desk and matched by an import.
 *   • Their fine is one ledger row, under their portal id, and they are told.
 *   • They see it in their own portal (views/fines.php), and the office
 *     records their payment against it (payOne) — with the receipt, which is
 *     read and checked before anything is credited — and a receipt goes to
 *     their email.
 *   • Nobody clears a fine of their own: not by paying it, not by waiving it,
 *     not by voiding it.
 *   • A NGV participant is fined exactly as before.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$fa = NgvDb::pdo();
$faMain = Database::pdo();
MemberRoster::ensure();
GateAttendance::ensure();
foreach (['ngv_charges', 'ngv_payments', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $fa->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$faMain->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@anyfine.test')");
$faMain->exec("DELETE FROM lms_users WHERE email LIKE '%@anyfine.test'");
$fa->exec("DELETE FROM ngv_participants WHERE email LIKE '%@anyfine.test'");

$kunle = (int) MemberRoster::create(['name' => 'Kunle Adeyemi', 'email' => 'kunle@anyfine.test', 'phone' => '08039990001'], 'tester')['id'];
$vanguard = (int) MemberRoster::create(['name' => 'Ronke Vanguard', 'email' => 'ronke@anyfine.test'], 'tester')['id'];
$fa->prepare("INSERT INTO ngv_participants (member_id, name, email, status, start_date, created_at, updated_at) VALUES (?,?,?,'active','2026-01-05','2026-01-05','2026-01-05')")->execute([$vanguard, 'Ronke Vanguard', 'ronke@anyfine.test']);
NgvFines::forget();

ck('any member: a member outside NGV is somebody a fine can be for', NgvFines::isMember($kunle) && !NgvMember::participant($kunle));
$found = NgvFines::people('Kunle Adeyemi');
ck('any member: the desk finds them by name, marked as a member, not NGV', ($found[0]['id'] ?? 0) === $kunle && $found[0]['ngv'] === false && str_contains($found[0]['detail'], 'member'));

$one = NgvFines::issue($kunle, 'late', 1500, 'Came in at 10', date('Y-m-d'), false, 1);
ck('any member: a fine is issued to them, one ledger row under their portal id', $one['ok'] && $one['status'] === 'fined'
    && (int) $fa->query("SELECT COUNT(*) FROM ngv_charges WHERE kind = 'fine' AND member_id = {$kunle}")->fetchColumn() === 1);
$mine = NgvFines::forMember($kunle);
ck('any member: they see it, owing, with its reason', count($mine['fines']) === 1 && $mine['owing'] === 1500 && $mine['fines'][0]['label'] === NgvLedger::FINE_REASONS['late']);
ck('any member: the desk list names them from the portal', (array_values(array_filter(NgvFines::all(), static fn($r) => (int) $r['member_id'] === $kunle))[0]['name'] ?? '') === 'Kunle Adeyemi');

/* An import, matched by phone. */
$rows = [array_merge(array_fill_keys(NgvFines::COLUMNS, ''), ['phone' => '0803 999 0001', 'amount' => '500', 'reason' => 'no uniform', 'date' => date('Y-m-d')])];
$dry = NgvFines::import($rows);
ck('any member: an import row is matched to them by phone', ($dry['rows'][0]['status'] ?? '') === 'ready' && (int) ($dry['rows'][0]['member']['id'] ?? 0) === $kunle);
$done = NgvFines::import($rows, ['apply' => true, 'expect_digest' => $dry['digest'], 'by' => 1]);
ck('any member: …and imported, charged once', $done['counts']['fined'] === 1 && NgvFines::forMember($kunle)['owing'] === 2000);
ck('any member: the same sheet again charges nobody twice', NgvFines::import($rows)['counts']['already'] === 1);

/* The office records their payment — with the receipt. The evidence store is
   a throwaway directory, and the reader is a stub: lib/OfflinePayments does
   the checking, and tests/offlinepayments.test.php holds it to each check. */
$faPrev = getenv('AV_PRIVATE_DIR');
$faDir = sys_get_temp_dir() . '/av-test-anyfine-' . getmypid();
@mkdir($faDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $faDir);
$faReader = OfflinePayments::$reader;
$faSlip = fn(int $amount, string $ref) => ['ok' => true, 'data' => ['is_payment_evidence' => true, 'amount' => $amount, 'currency' => 'NGN',
    'date' => gmdate('Y-m-d'), 'reference' => $ref, 'payee_name' => 'AFROVANGUARD', 'payee_account' => null, 'payer_name' => 'KUNLE ADEYEMI',
    'bank' => 'GTBank', 'status' => 'successful', 'tampering_signs' => [], 'confidence' => 'high']];
$faJpeg = fn(string $seed) => "\xFF\xD8\xFF\xE0" . str_repeat($seed, 64);

/* A real staff account, not a stand-in number: the self-check compares the
   actor's id with the fined member's, and in a freshly emptied table the
   first member created can be id 1. */
$faDesk = (int) MemberRoster::create(['name' => 'Desk Officer', 'email' => 'desk@anyfine.test'], 'tester')['id'];
$fineId = (int) $one['entryId'];
OfflinePayments::$reader = fn() => $faSlip(5000, 'TRF-TOOMUCH');
ck('any member: a payment of more than is owing on a fine is refused', !NgvFines::payOne($fineId, 5000, 'cash', '', $faDesk, ['bytes' => $faJpeg('a'), 'mime' => 'image/jpeg'])['ok']);
ck('any member: a fine payment with no receipt is refused, like every other payment',
    (NgvFines::payOne($fineId, 1500, 'transfer', 'TRF-123', $faDesk)['code'] ?? '') === 'evidence_required');
OfflinePayments::$reader = fn() => $faSlip(1500, 'TRF-123');
$paid = NgvFines::payOne($fineId, 1500, 'transfer', 'TRF-123', $faDesk, ['bytes' => $faJpeg('b'), 'mime' => 'image/jpeg'], 'desk@anyfine.test');
ck('any member: the office records their payment against the fine, once the receipt checks out',
    !empty($paid['ok']) && ($paid['status'] ?? '') === 'verified');
ck('any member: …which settles the oldest fine first', NgvFines::forMember($kunle)['owing'] === 500);
$faCredit = array_values(array_filter(NgvLedger::credits($kunle), static fn($c) => (int) $c['amount'] === 1500))[0] ?? [];
$rc = NgvLedger::receiptFor((int) ($faCredit['id'] ?? 0));
ck('any member: the receipt carries their name and email from the portal', ($rc['name'] ?? '') === 'Kunle Adeyemi' && ($rc['email'] ?? '') === 'kunle@anyfine.test');

/* Nobody settles their own account. */
$faMine = (int) NgvFines::issue($kunle, 'late', 400, 'For the self-check', date('Y-m-d'), false, 1)['entryId'];
OfflinePayments::$reader = fn() => $faSlip(400, 'TRF-SELF');
ck('any member: the fined member cannot record the payment themselves',
    (NgvFines::payOne($faMine, 400, 'cash', '', $kunle, ['bytes' => $faJpeg('c'), 'mime' => 'image/jpeg'])['code'] ?? '') === 'own_account');
ck('any member: …nor waive it', (NgvFines::waiveOne($faMine, 'I will let myself off', $kunle)['code'] ?? '') === 'own_account');
ck('any member: …nor void it', (NgvFines::voidOne($faMine, 'It never happened', $kunle)['code'] ?? '') === 'own_account');
ck('any member: …and none of that moved the money', NgvFines::forMember($kunle)['owing'] === 900);
ck('any member: another member of staff can', NgvFines::waiveOne($faMine, 'First offence, spoken to', $faDesk)['ok']
    && NgvFines::forMember($kunle)['owing'] === 500);

OfflinePayments::$reader = $faReader;
if ($faPrev === false) putenv('AV_PRIVATE_DIR'); else putenv('AV_PRIVATE_DIR=' . $faPrev);
foreach (glob($faDir . '/*') ?: [] as $faF) @unlink($faF);
@rmdir($faDir);

/* Somebody who is not a member at all is still refused. */
ck('any member: nobody outside the membership is fined', !NgvFines::issue(987654, 'late', 500, '', date('Y-m-d'), false, 1)['ok']);

/* A vanguard, as before. */
$v = NgvFines::issue($vanguard, 'late', 1000, '', date('Y-m-d'), false, 1);
ck('any member: an NGV participant is fined as before, on their NGV account', $v['ok'] && NgvFines::forMember($vanguard)['owing'] === 1000);

/* Where they see it. */
$idx = (string) file_get_contents(AV_ROOT . '/portal/index.php');
$view = (string) file_get_contents(AV_ROOT . '/portal/views/fines.php');
ck('any member: the portal gives a member outside NGV a Fines page once they have had one',
   (PortalNav::SECTIONS['fines']['view'] ?? '') === 'fines' && PortalNav::visible('fines', ['fines' => true]) && !PortalNav::visible('fines', ['org' => true])
   && str_contains($idx, "'fines' => (bool) \$myFines['fines']") && str_contains($view, 'id="view-fines"'));
ck('any member: the page carries no inline style', !str_contains($view, 'style='));
$desk = (string) file_get_contents(AV_ROOT . '/academy/ngv/fines.php');
ck('any member: the fines desk records a payment against a fine', str_contains($desk, "\$act === 'pay'") && str_contains($desk, 'data-pay='));

foreach (['ngv_charges', 'ngv_payments', 'ngv_fine_meta', 'ngv_fine_imports'] as $t) { try { $fa->exec('DELETE FROM ' . $t); } catch (Throwable $e) {} }
$fa->exec("DELETE FROM ngv_participants WHERE email LIKE '%@anyfine.test'");
$faMain->exec("DELETE FROM member_profiles WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@anyfine.test')");
$faMain->exec("DELETE FROM lms_users WHERE email LIKE '%@anyfine.test'");
NgvFines::forget();
