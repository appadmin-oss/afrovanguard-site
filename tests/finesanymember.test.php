<?php
/**
 * tests/finesanymember.test.php — any Afrovanguard member can be fined, not
 * only NGV participants (lib/NgvFines.php).
 *
 *   • A member outside NGV is found by the desk and matched by an import.
 *   • Their fine is one ledger row, under their portal id, and they are told.
 *   • They see it in their own portal (views/fines.php), and the office
 *     records their payment against it (payOne), with a receipt to their email.
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

/* The office records their payment. */
$fineId = (int) $one['entryId'];
ck('any member: a payment of more than is owing on a fine is refused', !NgvFines::payOne($fineId, 5000, 'cash', '', 1)['ok']);
$paid = NgvFines::payOne($fineId, 1500, 'transfer', 'TRF-123', 1);
ck('any member: the office records their payment against the fine', !empty($paid['ok']));
ck('any member: …which settles the oldest fine first', NgvFines::forMember($kunle)['owing'] === 500);
$rc = NgvLedger::receiptFor((int) $paid['id']);
ck('any member: the receipt carries their name and email from the portal', ($rc['name'] ?? '') === 'Kunle Adeyemi' && ($rc['email'] ?? '') === 'kunle@anyfine.test');

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
