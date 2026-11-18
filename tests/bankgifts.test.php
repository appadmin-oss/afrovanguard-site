<?php
/**
 * tests/bankgifts.test.php — a bank-transfer gift is recorded by one named
 * person and counted only when another verifies it.
 *
 * It was a form on the donor dashboard that took the master ADMIN_TOKEN and
 * wrote the gift straight in: no name, no second factor, nobody else looking.
 * The promises pinned here:
 *
 *   • A submitted gift is HELD: no total moves and no receipt goes out.
 *   • The person who recorded it cannot verify it; the emergency token cannot
 *     do either (it is a credential, not a person).
 *   • Verified, it is stored once, through the same store as every gift, with
 *     both names on it, and receipted once.
 *   • Rejected needs a reason; the same bank reference cannot be entered twice.
 *   • The old token endpoint refuses, and the dashboard no longer asks for it.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$bgPrev = getenv('AV_PRIVATE_DIR');
$bgDir = sys_get_temp_dir() . '/av-test-bankgifts-' . getmypid();
@mkdir($bgDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $bgDir);
Donations::ensure();
BankGifts::ensure();
Database::pdo()->exec("DELETE FROM bank_gifts");
Database::pdo()->exec("DELETE FROM donation_refs WHERE reference LIKE 'ZB_%'");

$bgTotal = static function (string $camp): float {
    $d = Donations::read();
    return (float) ($d['campaigns'][$camp]['raised'] ?? 0);
};
$bgCamp = array_key_first((array) (Donations::read()['campaigns'] ?? [])) ?: 'general';
$bgBefore = $bgTotal($bgCamp);
$bgGift = ['name' => 'Ada Obi', 'email' => 'ada@example.org', 'amount' => 25000, 'campaign' => $bgCamp,
           'bank_ref' => 'ZEN-0001', 'paid_on' => date('Y-m-d'), 'listed' => false];

$bgSub = BankGifts::submit($bgGift, 'first.admin@afrovanguard.org.ng');
ck('bank gift: recorded by a named admin, and held', !empty($bgSub['ok']) && ($bgSub['status'] ?? '') === 'pending' && ($bgSub['gift']['submitted_by'] ?? '') === 'first.admin@afrovanguard.org.ng');
ck('bank gift: …held means no campaign total moved', $bgTotal($bgCamp) === $bgBefore);
$bgId = (int) ($bgSub['gift']['id'] ?? 0);

$bgDup = BankGifts::submit($bgGift + [], 'other.admin@afrovanguard.org.ng');
ck('bank gift: the same bank reference cannot be entered twice', empty($bgDup['ok']) && ($bgDup['code'] ?? '') === 'duplicate');

ck('bank gift: the emergency token cannot record money (it is not a person)',
    (BankGifts::submit(['bank_ref' => 'ZEN-T'] + $bgGift, 'superadmin (token)')['code'] ?? '') === 'no_actor');
ck('bank gift: …nor verify it', (BankGifts::verify($bgId, 'superadmin (token)')['code'] ?? '') === 'no_actor');

$bgOwn = BankGifts::verify($bgId, 'FIRST.admin@afrovanguard.org.ng');
ck('bank gift: the person who recorded it cannot verify it (whatever the case of their address)', empty($bgOwn['ok']) && ($bgOwn['code'] ?? '') === 'own_submission');
ck('bank gift: …and it is still held', (BankGifts::get($bgId)['status'] ?? '') === 'pending' && $bgTotal($bgCamp) === $bgBefore);

$bgSent = [];
$bgOk = BankGifts::verify($bgId, 'second.admin@afrovanguard.org.ng', static function (string $to, array $d) use (&$bgSent) { $bgSent[] = [$to, $d]; return true; });
ck('bank gift: a second admin verifies it, and it is counted', !empty($bgOk['ok']) && $bgTotal($bgCamp) === $bgBefore + 25000.0);
ck('bank gift: …both names are kept', ($bgOk['gift']['decided_by'] ?? '') === 'second.admin@afrovanguard.org.ng' && ($bgOk['gift']['submitted_by'] ?? '') === 'first.admin@afrovanguard.org.ng');
ck('bank gift: …the donor is receipted once, as a bank transfer', count($bgSent) === 1 && $bgSent[0][0] === 'ada@example.org' && ($bgSent[0][1]['method'] ?? '') === 'Bank transfer');
$bgEntry = array_values(array_filter((array) (Donations::read()['donations'] ?? []), static fn($d) => ($d['reference'] ?? '') === ($bgOk['gift']['donation_ref'] ?? '-')))[0] ?? [];
ck('bank gift: …the donation record carries who recorded and who confirmed it', ($bgEntry['recorded_by'] ?? '') === 'first.admin@afrovanguard.org.ng' && ($bgEntry['confirmed_by'] ?? '') === 'second.admin@afrovanguard.org.ng');
ck('bank gift: …and the wall stays opt-in (not listed unless asked)', isset($bgEntry['listed']) && $bgEntry['listed'] === false && ($bgEntry['name'] ?? '') === 'Anonymous');

ck('bank gift: a decided gift cannot be verified again', (BankGifts::verify($bgId, 'third.admin@afrovanguard.org.ng')['code'] ?? '') === 'decided' && $bgTotal($bgCamp) === $bgBefore + 25000.0);

$bgB = BankGifts::submit(['bank_ref' => 'ZEN-0002'] + $bgGift, 'first.admin@afrovanguard.org.ng');
$bgBid = (int) ($bgB['gift']['id'] ?? 0);
ck('bank gift: rejecting needs a reason', (BankGifts::reject($bgBid, '  ', 'second.admin@afrovanguard.org.ng')['code'] ?? '') === 'reason_required');
$bgR = BankGifts::reject($bgBid, 'Not on the statement', 'second.admin@afrovanguard.org.ng');
ck('bank gift: …with one it is rejected, and nothing was ever counted', !empty($bgR['ok']) && $bgTotal($bgCamp) === $bgBefore + 25000.0);

$bgC = BankGifts::submit(['bank_ref' => 'ZEN-0003'] + $bgGift, 'first.admin@afrovanguard.org.ng');
$bgCid = (int) ($bgC['gift']['id'] ?? 0);
ck('bank gift: only the recorder may withdraw their own pending entry', (BankGifts::withdraw($bgCid, 'second.admin@afrovanguard.org.ng')['code'] ?? '') === 'not_yours'
    && !empty(BankGifts::withdraw($bgCid, 'first.admin@afrovanguard.org.ng')['ok']) && BankGifts::get($bgCid) === null);

ck('bank gift: the whole entry is checked before anything is written',
    (BankGifts::submit(['paid_on' => date('Y-m-d', strtotime('+3 days')), 'bank_ref' => 'ZEN-F'] + $bgGift, 'first.admin@afrovanguard.org.ng')['code'] ?? '') === 'paid_on'
    && (BankGifts::submit(['amount' => 50, 'bank_ref' => 'ZEN-S'] + $bgGift, 'first.admin@afrovanguard.org.ng')['code'] ?? '') === 'amount'
    && (BankGifts::submit(['bank_ref' => ''] + $bgGift, 'first.admin@afrovanguard.org.ng')['code'] ?? '') === 'bank_ref'
    && (int) Database::pdo()->query("SELECT COUNT(*) FROM bank_gifts WHERE bank_ref IN ('ZEN-F','ZEN-S','')")->fetchColumn() === 0);

ck('bank gift: the queue lists pending gifts and counts them', BankGifts::pendingCount() === 0 && count(BankGifts::list('all')) === 2);

/* The old road is shut, and the dashboard no longer asks anyone for the token. */
$bgPd = (string) file_get_contents(AV_ROOT . '/process-donation.php');
ck('bank gift: the token endpoint refuses (410) and points to the Studio',
    (bool) preg_match("/action==='record_bank_transfer'\\) \\{\\s*http_response_code\\(410\\)/", $bgPd) && !preg_match("/record_bank_transfer[\\s\\S]{0,300}av_admin_token_matches/", $bgPd));
$bgDash = (string) file_get_contents(AV_ROOT . '/donor-dashboard.html');
ck('bank gift: the donor dashboard no longer asks for or sends the admin token', !str_contains($bgDash, 'admin_token') && !str_contains($bgDash, 'record_bank_transfer'));
$bgApi = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('bank gift: the Studio actions are management-only (never an editor)', (bool) preg_match("/'gifts_list', 'gifts_submit', 'gifts_decide'/", $bgApi));

foreach (glob($bgDir . '/*') ?: [] as $bgF) @unlink($bgF);
@rmdir($bgDir);
Database::pdo()->exec("DELETE FROM bank_gifts");
Database::pdo()->exec("DELETE FROM donation_refs WHERE reference LIKE 'ZB_%'");
if ($bgPrev === false) putenv('AV_PRIVATE_DIR'); else putenv('AV_PRIVATE_DIR=' . $bgPrev);
