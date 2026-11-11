<?php
/**
 * tests/reviewfixes.test.php — the review of the Meeting Recorder branch (2026-11-11).
 *
 *   1. The web-cron's key is never the admin token: AV_CRON_KEY, else a key
 *      derived from the site's secret. The admin token is refused, with a
 *      message that says why (so a cron job does not just go quiet).
 *   2. The study coach is limited per learner, not only per address.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$cronSrc = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
putenv('AV_CRON_KEY');
$k = av_cron_key();
ck('cron: with no AV_CRON_KEY the key is derived from the site secret', $k['source'] === 'derived' && strlen($k['key']) === 32);
ck('cron: the derived key is not the admin token', !defined('ADMIN_TOKEN') || (string) ADMIN_TOKEN === '' || !hash_equals((string) ADMIN_TOKEN, $k['key']));
ck('cron: it is stable', av_cron_key()['key'] === $k['key']);
putenv('AV_CRON_KEY=my-own-cron-key-123');
ck('cron: AV_CRON_KEY wins when set', av_cron_key() === ['key' => 'my-own-cron-key-123', 'source' => 'env']);
putenv('AV_CRON_KEY=short');
ck('cron: a too-short AV_CRON_KEY is ignored', av_cron_key()['source'] === 'derived');
putenv('AV_CRON_KEY');
ck('cron: the endpoint no longer falls back to ADMIN_TOKEN', !preg_match("~getenv\\('AV_CRON_KEY'\\)\\s*\\?:\\s*\\(defined\\('ADMIN_TOKEN'\\)~", $cronSrc) && str_contains($cronSrc, "av_cron_key()['key']"));
ck('cron: an old admin-token caller is told why it is refused', str_contains($cronSrc, 'The admin token is no longer accepted as the cron key'));
ck('cron: the header is read before the URL', strpos($cronSrc, "HTTP_X_AV_CRON_KEY") < strpos($cronSrc, "\$_GET['key']"));
ck('cron: the cPanel helper puts the key in a header, not the URL', str_contains((string) file_get_contents(AV_ROOT . '/tools/cpanel-cron-setup.mjs'), '-H "X-AV-Cron-Key: ${key}"'));

$who = 'u' . random_int(100000, 999999);
$okAll = true;
for ($i = 0; $i < 3; $i++) $okAll = $okAll && av_rate_ok_for('test_coach', $who, 3, 600);
ck('coach: a learner gets their allowance', $okAll);
ck('coach: and no more, wherever they ask from', !av_rate_ok_for('test_coach', $who, 3, 600));
ck('coach: another learner has their own', av_rate_ok_for('test_coach', $who . 'x', 3, 600));
ck('coach: the endpoint counts per learner', str_contains((string) file_get_contents(AV_ROOT . '/academy/api.php'), "av_rate_ok_for('lms_coach', 'u' . (int) \$u['id']"));
foreach (glob(AV_ROOT . '/db/cache/rl/who_test_coach_*') ?: [] as $f) @unlink($f);

/* ── 2026-11-11, third review: money ─────────────────────────────────── */
// A paid charge is recorded as a donation only if no other part of the site owns it.
reset_users();   // payments.user_id must name a real account
$pdoR = Database::pdo();
$refDues = 'DUES' . random_int(100000, 999999);
$pdoR->prepare("INSERT INTO payments (reference, user_id, provider, kind, amount_kobo) VALUES (?, 1, 'paystack', 'membership', 1200000)")->execute([$refDues]);
ck('donation: a dues payment\'s reference is not recorded as a gift', PaymentWebhook::donationOwner('paystack', ['reference' => $refDues, 'status' => 'success', 'metadata' => []], [], $refDues) !== PaymentWebhook::DONATION);
ck('donation: nor an NGV payment', PaymentWebhook::donationOwner('paystack', ['reference' => 'NGV-1', 'metadata' => ['ngv_member' => 7]], [], 'NGV-1') === PaymentWebhook::NGV);
ck('donation: a gift from the donate form is', PaymentWebhook::donationOwner('paystack', ['reference' => 'AV_1_ABCD', 'metadata' => ['campaign' => 'general']], [], 'AV_1_ABCD') === PaymentWebhook::DONATION);
ck('donation: a CACENTRE charge without our donation fields is not', PaymentWebhook::donationOwner('cfis', [], ['purpose' => 'dues'], 'X-1') === 'not-a-donation');
ck('donation: a CACENTRE charge for a payments-table purchase is not, even with a campaign', PaymentWebhook::donationOwner('cfis', [], ['campaign' => 'general'], $refDues) !== PaymentWebhook::DONATION);
ck('donation: a CACENTRE gift from the donate form is', PaymentWebhook::donationOwner('cfis', [], ['campaign' => 'general'], 'AV_2_EFGH') === PaymentWebhook::DONATION);
ck('donation: record_donation asks before recording', str_contains((string) file_get_contents(AV_ROOT . '/process-donation.php'), 'PaymentWebhook::donationOwner($via'));
$pdoR->prepare('DELETE FROM payments WHERE reference = ?')->execute([$refDues]);

// The appeals console: an editor writes appeals, an admin moves money.
$gm = (string) file_get_contents(AV_ROOT . '/give/manage.php');
ck('appeals: money and donor actions need an admin', (bool) preg_match("~\\\$giveMoneyActions = \\['fund_item', 'meet_need', 'allocate_ngv', 'stop_recurring', 'mail_update', 'save_tiers', 'delete_appeal'\\]~", $gm)
    && strpos($gm, 'in_array($act, $giveMoneyActions, true) && !$canMoney') < strpos($gm, 'switch ($act) {'));
ck('appeals: an admin is admin or superadmin, not any Studio role', str_contains($gm, "\$canMoney = in_array(\$role, ['admin', 'superadmin'], true);"));
ck('appeals: an editor is not sent donors\' names or emails', str_contains($gm, "if (!\$canMoney) { \$selRec['rows'] = []; \$selMailTo = []; }"));

// The limiter holds under a real burst: 12 processes at once against a limit of 4.
$burstWho = 'burst' . random_int(100000, 999999);
$procs = [];
for ($i = 0; $i < 12; $i++) {
    $procs[] = proc_open([PHP_BINARY, '-r', 'require ' . var_export(AV_ROOT . '/lib/bootstrap.php', true) . '; echo av_rate_ok_for("burst", ' . var_export($burstWho, true) . ', 4, 600) ? "Y" : "N";'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$yes = 0;
foreach ($procs as $i => $p) { $yes += substr_count((string) stream_get_contents($pipes[$i][1]), 'Y'); proc_close($p); }
ck('limiter: 12 parallel requests against a limit of 4 let exactly 4 through', $yes === 4);
foreach (glob(AV_ROOT . '/db/cache/rl/who_burst_*') ?: [] as $f) @unlink($f);
ck('login: the lockout counter adds one in the database, not in PHP', str_contains((string) file_get_contents(AV_ROOT . '/lib/LmsAuth.php'), "SET fails = fails + 1"));
ck('recorder: audio moved to Drive is deleted by retention', str_contains((string) file_get_contents(AV_ROOT . '/lib/Recorder.php'), 'Drive::delete($fid)'));
