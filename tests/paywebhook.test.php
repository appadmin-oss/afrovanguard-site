<?php
/**
 * tests/paywebhook.test.php — one front door for the gateway's webhook.
 *
 * A gateway posts to ONE address, and this site had two money handlers that
 * each understood only their own kind. Whichever address was in the Paystack
 * dashboard, the other kinds were mis-recorded or lost — and which one it was
 * is not visible from the code.
 *
 *   • The kind decides the owner, in a fixed order: a fee, then a charge we
 *     started, then the dues plan, then a donation as the fallback.
 *   • A dues RENEWAL writes a payments row keyed on the gateway's reference,
 *     is checked against today's price, and grants one month — once, however
 *     many times the webhook is redelivered.
 *   • A donation's reference is claimed in a table that never forgets, so a
 *     redelivery for a reference older than the file's last 500 is not
 *     counted again.
 *   • Both addresses do the same thing with the same event (driven over HTTP,
 *     signed as Paystack signs).
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pwPdo = Database::pdo();
Membership::ensure(); Donations::ensure();
$pwPlan = 'PLN_test_dues_' . bin2hex(random_bytes(3));
$pwPrevPlan = getenv('AV_DUES_PLAN_CODE');
putenv('AV_DUES_PLAN_CODE=' . $pwPlan);

$pwPdo->exec("DELETE FROM payments WHERE reference LIKE 'PW-%'");
$pwPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@paywh.test')");
$pwPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@paywh.test'");
$pwPdo->exec("DELETE FROM donation_refs WHERE reference LIKE 'PW-%'");
$pwPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role) VALUES ('Dues Payer', 'dues@paywh.test', 'x', 'member')")->execute();
$pwUser = (int) $pwPdo->query("SELECT id FROM lms_users WHERE email = 'dues@paywh.test'")->fetchColumn();

$pwCharge = static fn(array $data): array => ['event' => 'charge.success', 'data' => $data];
$pwKind = static fn(array $data, array $facts = []): string =>
    PaymentWebhook::classify($pwCharge($data), $facts + ['has_payment_row' => false, 'appeal_plan' => ''])['kind'];

/* ── The kind decides the owner, in a fixed order ─────────────────────── */
ck('webhook: a subscription event belongs to appeals',
    PaymentWebhook::classify(['event' => 'subscription.create', 'data' => []], [])['kind'] === PaymentWebhook::SUBSCRIPTION);
ck('webhook: an event this site does not act on is ignored, not guessed at',
    PaymentWebhook::classify(['event' => 'charge.failed', 'data' => ['reference' => 'PW-X']], [])['kind'] === PaymentWebhook::IGNORED);
ck('webhook: a charge with no reference is ignored — there is nothing to be idempotent on',
    $pwKind(['reference' => '']) === PaymentWebhook::IGNORED);
ck('webhook: a participant\'s fee is a fee', $pwKind(['reference' => 'PW-1', 'metadata' => ['ngv_member' => 7]]) === PaymentWebhook::NGV);
ck('webhook: …even when it also carries a plan', $pwKind(['reference' => 'PW-2', 'metadata' => ['ngv_member' => 7], 'plan' => ['plan_code' => $pwPlan]]) === PaymentWebhook::NGV);
ck('webhook: a charge we started is ours — the payments row names it',
    $pwKind(['reference' => 'PW-3'], ['has_payment_row' => true]) === PaymentWebhook::LMS);
ck('webhook: …including the FIRST charge of a dues subscription, which has a row and a plan',
    $pwKind(['reference' => 'PW-4', 'plan' => ['plan_code' => $pwPlan]], ['has_payment_row' => true]) === PaymentWebhook::LMS);
ck('webhook: a later charge on the dues plan, with no row, is a renewal',
    $pwKind(['reference' => 'PW-5', 'plan' => ['plan_code' => $pwPlan]]) === PaymentWebhook::DUES_RENEWAL);
ck('webhook: …and the plan is read where Paystack puts it on either kind of event',
    $pwKind(['reference' => 'PW-6', 'plan' => $pwPlan]) === PaymentWebhook::DUES_RENEWAL
    && $pwKind(['reference' => 'PW-7', 'plan_object' => ['plan_code' => $pwPlan]]) === PaymentWebhook::DUES_RENEWAL);
ck('webhook: a recurring gift on an appeal is a donation, never a month of dues',
    $pwKind(['reference' => 'PW-8', 'plan' => ['plan_code' => 'PLN_appeal']], ['appeal_plan' => 'clean-water']) === PaymentWebhook::DONATION);
ck('webhook: money with no owner we can name is recorded as given, not granted as bought',
    $pwKind(['reference' => 'PW-9']) === PaymentWebhook::DONATION);
/* The old academy handler treated ANY plan as a dues renewal, so a recurring
   donation would have bought somebody a month of membership. */
putenv('AV_DUES_PLAN_CODE');
ck('webhook: with no dues plan configured, a plan charge is a donation and nobody is granted dues',
    $pwKind(['reference' => 'PW-10', 'plan' => ['plan_code' => 'PLN_anything']]) === PaymentWebhook::DONATION);
putenv('AV_DUES_PLAN_CODE=' . $pwPlan);

/* ── A dues renewal: recorded, checked, granted once ──────────────────── */
$pwMonth = Membership::price(1);
$pwRenewal = static fn(string $ref, int $ngn, string $email = 'dues@paywh.test', string $cur = 'NGN'): array =>
    PaymentWebhook::handle(['event' => 'charge.success', 'data' => [
        'reference' => $ref, 'amount' => $ngn * 100, 'currency' => $cur,
        'customer' => ['email' => $email], 'plan' => ['plan_code' => $pwPlan]]]);
$pwRow = static function (string $ref) use ($pwPdo): array {
    $st = $pwPdo->prepare('SELECT * FROM payments WHERE reference = ?'); $st->execute([$ref]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$pwMonths = static fn(): int => (int) Database::pdo()->query('SELECT COUNT(*) FROM memberships WHERE user_id = ' . (int) $GLOBALS['pwUser'])->fetchColumn();
$GLOBALS['pwUser'] = $pwUser;

$was = $pwMonths();
$r = $pwRenewal('PW-REN-1', $pwMonth);
ck('webhook: a dues renewal is granted', !empty($r['granted']) && $r['kind'] === PaymentWebhook::DUES_RENEWAL && $pwMonths() === $was + 1);
ck('webhook: …and leaves a payments row, so renewal money is in the dues totals',
    ($pwRow('PW-REN-1')['status'] ?? '') === 'paid' && (int) ($pwRow('PW-REN-1')['amount_kobo'] ?? 0) === $pwMonth * 100
    && (int) ($pwRow('PW-REN-1')['user_id'] ?? 0) === $pwUser && (int) ($pwRow('PW-REN-1')['months'] ?? 0) === 1);
/* The redelivery now finds the row this one wrote, so it routes as a charge
   we started — and finalizePayment sees it already paid. Either way the
   promise is the same: one reference, one month, one row. */
$again = $pwRenewal('PW-REN-1', $pwMonth);
ck('webhook: …and a redelivery of the same reference adds no second month',
    $pwMonths() === $was + 1
    && (int) $pwPdo->query("SELECT COUNT(*) FROM payments WHERE reference = 'PW-REN-1'")->fetchColumn() === 1);

$case = $pwRenewal('PW-REN-CASE', $pwMonth, 'DUES@PayWH.Test');
ck('webhook: a renewal whose address the gateway echoed in another case still lands',
    !empty($case['granted']) && (int) ($pwRow('PW-REN-CASE')['user_id'] ?? 0) === $pwUser);

$pwBeforeShort = $pwMonths();
$short = $pwRenewal('PW-REN-SHORT', max(1, intdiv($pwMonth, 2)));
ck('webhook: a renewal at an old, lower price buys no month', empty($short['granted']) && ($short['error'] ?? '') === 'short'
    && $pwMonths() === $pwBeforeShort);
ck('webhook: …and is left recorded as pending, for somebody to act on', ($pwRow('PW-REN-SHORT')['status'] ?? '') === 'pending');
$pwShortAudited = false;
try {
    $pwShortAudited = (bool) $pwPdo->query("SELECT 1 FROM lms_audit WHERE action = 'dues_renewal_short' AND target = 'dues@paywh.test'")->fetchColumn();
} catch (Throwable $e) { /* reported as a failure below, which is what it is */ }
ck('webhook: …and said out loud in the trail rather than only the error log', $pwShortAudited);

$usd = $pwRenewal('PW-REN-USD', $pwMonth, 'dues@paywh.test', 'USD');
ck('webhook: a renewal in another currency is not read as naira', empty($usd['ok']) && ($usd['error'] ?? '') === 'currency'
    && (int) $usd['status'] === 200 && $pwRow('PW-REN-USD') === []);
$nobody = $pwRenewal('PW-REN-NOBODY', $pwMonth, 'ghost@paywh.test');
ck('webhook: a renewal for an address with no account grants nobody a month', empty($nobody['ok'])
    && ($nobody['error'] ?? '') === 'no account' && $pwRow('PW-REN-NOBODY') === []);

/* ── A donation's reference is claimed in a table that never forgets ──── */
ck('webhook: a donation reference is claimed once', Donations::claim('PW-DON-1') && !Donations::claim('PW-DON-1'));
ck('webhook: …and the claim survives the display file forgetting it', Donations::known('PW-DON-1'));
Donations::release('PW-DON-1');
ck('webhook: …and a claim can be given back when nothing was stored', !Donations::known('PW-DON-1'));

/* ── Both addresses, one behaviour ────────────────────────────────────
   The point of the whole change: a gateway posts to ONE of these, and which
   one is a setting in somebody's dashboard. Driven over HTTP and signed as
   Paystack signs, because what is being tested is the two routes, not a
   function they both happen to call. */
$pwKey = 'sk_test_paywh_' . bin2hex(random_bytes(8));
$pwPort = 0;
if ($pwProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($pwProbe, false); $pwPort = (int) substr($n, strrpos($n, ':') + 1); fclose($pwProbe);
}
$pwEnv = getenv(); $pwEnv['AV_PAYSTACK_SK'] = $pwKey; $pwEnv['APP_ENV'] = 'dev'; $pwEnv['AV_DUES_PLAN_CODE'] = $pwPlan;
$pwLog = tempnam(sys_get_temp_dir(), 'avpw');
$pwProc = $pwPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $pwPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $pwLog, 'a'], 2 => ['file', $pwLog, 'a']], $pwPipes, AV_ROOT, $pwEnv) : null;
$pwUp = false;
if (is_resource($pwProc)) {
    for ($i = 0; $i < 100 && !$pwUp; $i++) { $c = @fsockopen('127.0.0.1', $pwPort, $e1, $e2, 0.2); if ($c) { fclose($c); $pwUp = true; } else usleep(50000); }
}
$pwHttp = static function (string $path, array $event) use ($pwPort, $pwKey): array {
    $raw = json_encode($event);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/json\r\nX-Paystack-Signature: " . hash_hmac('sha512', $raw, $pwKey) . "\r\n",
        'content' => $raw]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $pwPort . $path, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $m)) $code = (int) $m[1];
    return [$code, json_decode($body, true) ?: []];
};

if (!$pwUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    $pwEvent = static fn(string $ref): array => ['event' => 'charge.success', 'data' => [
        'reference' => $ref, 'amount' => $pwMonth * 100, 'currency' => 'NGN',
        'customer' => ['email' => 'dues@paywh.test'], 'plan' => ['plan_code' => $pwPlan]]];
    $before = $pwMonths();
    [$c1, $b1] = $pwHttp('/process-donation.php', $pwEvent('PW-HTTP-DON'));
    ck('webhook: a dues renewal posted to the donation address is dues, not a donation',
        $c1 === 200 && ($b1['kind'] ?? '') === PaymentWebhook::DUES_RENEWAL && ($b1['recorded'] ?? false) === true);
    [$c2, $b2] = $pwHttp('/academy/pay.php', $pwEvent('PW-HTTP-ACAD'));
    ck('webhook: …and the same event at the academy address does the same thing',
        $c2 === 200 && ($b2['kind'] ?? '') === PaymentWebhook::DUES_RENEWAL && ($b2['recorded'] ?? false) === true);
    ck('webhook: …two references, two months, and a payments row for each', $pwMonths() === $before + 2
        && (int) $pwPdo->query("SELECT COUNT(*) FROM payments WHERE reference IN ('PW-HTTP-DON','PW-HTTP-ACAD') AND status = 'paid'")->fetchColumn() === 2);

    /* And the other way: a plain gift posted to the ACADEMY address, which
       used to grant a month of membership to anybody whose charge had a plan
       on it, and record nothing anywhere when it did not. */
    [$c3, $b3] = $pwHttp('/academy/pay.php', ['event' => 'charge.success', 'data' => [
        'reference' => 'PW-HTTP-GIFT', 'amount' => 500000, 'currency' => 'NGN',
        'customer' => ['email' => 'kind@paywh.test', 'first_name' => 'Ada', 'last_name' => 'Gift'],
        'plan' => ['plan_code' => 'PLN_somebody_elses']]]);
    ck('webhook: a recurring gift at the academy address is a donation, and buys nobody a membership',
        $c3 === 200 && ($b3['kind'] ?? '') === PaymentWebhook::DONATION && Donations::known('PW-HTTP-GIFT')
        && (int) $pwPdo->query("SELECT COUNT(*) FROM payments WHERE reference = 'PW-HTTP-GIFT'")->fetchColumn() === 0);
    ck('webhook: …and a redelivery of it is not a second donation', !Donations::claim('PW-HTTP-GIFT'));
}
if (is_resource($pwProc)) { proc_terminate($pwProc); proc_close($pwProc); }
@unlink($pwLog);

$pwPdo->exec("DELETE FROM payments WHERE reference LIKE 'PW-%'");
$pwPdo->exec("DELETE FROM memberships WHERE user_id = {$pwUser}");
$pwPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@paywh.test'");
$pwPdo->exec("DELETE FROM donation_refs WHERE reference LIKE 'PW-%'");
foreach (Donations::read()['donations'] ?? [] as $d) { /* the display file is a fixture here, left as found below */ }
if ($pwPrevPlan === false) putenv('AV_DUES_PLAN_CODE'); else putenv('AV_DUES_PLAN_CODE=' . $pwPrevPlan);
