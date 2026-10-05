<?php
/**
 * tests/cfischeckout.test.php — taking money when this site has no merchant account.
 *
 * WHAT IS BEING PROTECTED. Nine places here gate a Pay button on
 * `Payments::configured('paystack')` — "do WE hold keys" — and when it is
 * false they tell the payer to telephone. A site with no merchant account
 * could therefore take nothing online, however willing the payer, and
 * whatever CACENTRE could have collected for it. The money then arrives as
 * cash and its record is whatever note the office wrote.
 *
 * WHAT IS NOT CLAIMED. Nothing here proves CACENTRE collects anything: that
 * is the other side of a network call and belongs to the CFIS suite, which
 * tests it against a faked provider. These assert the decisions THIS site
 * makes, every one of which is wrong in a way nobody would see:
 *
 *   · which route a payment takes, and that our own keys always win;
 *   · that the route is RECORDED, because verifying a CACENTRE charge
 *     against Paystack finds nothing and reads as "you did not pay";
 *   · that an auto-renewing plan is never offered on somebody else's
 *     merchant account;
 *   · that the signed envelope is one CFIS would actually open.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* The constants are read through defined()/getenv(), and a test cannot
   undefine a constant — so the switch is the environment, set per case. */
$cfReset = static function (): void {
    foreach (['CFIS_URL', 'CFIS_SOURCE', 'CFIS_SECRET'] as $k) {
        putenv($k);
        unset($_ENV[$k]);
    }
};

$cfOn = static function (): void {
    putenv('CFIS_URL=https://finance.example.test');
    putenv('CFIS_SOURCE=avg');
    putenv('CFIS_SECRET=' . str_repeat('k', 64));
};

/* ══ 1. configured means configured ═══════════════════════════════════════ */

$cfReset();
ck('cfis checkout: with nothing set, CACENTRE cannot collect for us',
   !CfisCheckout::configured());

putenv('CFIS_URL=https://finance.example.test');
putenv('CFIS_SOURCE=avg');
putenv('CFIS_SECRET=tooshort');
ck('cfis checkout: a short key counts as unset — a signing key that cannot sign '
   . 'is worse than none, because it fails at the payer rather than at the config',
   !CfisCheckout::configured());

$cfOn();
ck('cfis checkout: all three present and it is available', CfisCheckout::configured());

/* ══ 2. the route, and which one wins ═════════════════════════════════════ */

/* PAYSTACK_SECRET_KEY/PUBLIC_KEY are real constants in this process when the
   deployment defines them. The suite runs without them, which is exactly the
   case this feature exists for. */
$cfHaveOwn = Payments::configured('paystack');

$cfReset();
ck('payments: with neither route, nothing can be collected',
   $cfHaveOwn ? true : !Payments::canCollect());
ck('payments: and the route is empty rather than a guess',
   $cfHaveOwn ? true : Payments::route() === '');

$cfOn();
ck('payments: CACENTRE alone is enough to collect — this is the whole point',
   Payments::canCollect());
ck('payments: and the route says so',
   $cfHaveOwn ? Payments::route() === 'paystack' : Payments::route() === 'cfis');

/* The preference, asked of the pure function rather than of the source.
   The first version of this test grepped for the paystack line and PASSED
   with the two branches swapped — the line was still there, one place
   lower. Our own account puts the money in our bank the same day with
   nobody holding it, so the hosted route answers having no account; it is
   never a choice between the two. */
ck('payments: our own keys win whenever we have them',
   Payments::routeFor(true, true) === 'paystack');
ck('payments: CACENTRE is used only when we have none',
   Payments::routeFor(false, true) === 'cfis');
ck('payments: and neither means neither, not a guess',
   Payments::routeFor(false, false) === '');

/* ══ 3. the envelope CFIS has to be able to open ══════════════════════════ */

$cfOn();
$cfToken = CfisCheckout::mint(['reference' => 'AVG-1', 'amount_minor' => 150000]);
$cfParts = explode('.', $cfToken);

ck('cfis checkout: the token is the v1 envelope CFIS takes',
   count($cfParts) === 3 && $cfParts[0] === 'v1');

$cfB64 = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'), true);
$cfBody = json_decode($cfB64($cfParts[1]), true);

ck('cfis checkout: the claims travel inside the signature, not beside it',
   is_array($cfBody) && ($cfBody['amount_minor'] ?? null) === 150000
   && ($cfBody['reference'] ?? null) === 'AVG-1');

ck('cfis checkout: it carries a nonce and an expiry, so a captured token is '
   . 'not a reusable one',
   !empty($cfBody['nonce']) && (int) ($cfBody['exp'] ?? 0) > time());

ck('cfis checkout: the signature is over the payload with our shared key',
   hash_equals(
       rtrim(strtr(base64_encode(hash_hmac('sha256', $cfParts[1], str_repeat('k', 64), true)), '+/', '-_'), '='),
       $cfParts[2]
   ));

ck('cfis checkout: and a key that is one byte different does not verify',
   !hash_equals(
       rtrim(strtr(base64_encode(hash_hmac('sha256', $cfParts[1], str_repeat('k', 63) . 'j', true)), '+/', '-_'), '='),
       $cfParts[2]
   ));

/* ══ 4. the route is recorded, not assumed ════════════════════════════════ */

/* The defect this prevents is silent and total: a CACENTRE charge verified
   against Paystack finds nothing, so finalizePayment is never called and
   somebody who has just paid is shown a failure. */
$cfDues = (string) file_get_contents(AV_ROOT . '/portal/dues.php');

ck('dues: the payment row stores the route it was taken on',
   str_contains($cfDues, '$route = Payments::route();')
   && str_contains($cfDues, "createPayment((int) \$u['id'], 'membership', null, \$amountNgn * 100, \$reference, \$route, \$months)"));

ck('dues: the Pay button asks whether money can be taken at all, not whether '
   . 'we personally hold keys',
   str_contains($cfDues, 'Payments::canCollect()')
   && !str_contains($cfDues, "if (!Payments::configured('paystack')) json_out"));

$cfPay = (string) file_get_contents(AV_ROOT . '/academy/pay.php');
ck('return: the browser return verifies by the stored provider',
   str_contains($cfPay, "Payments::verifyBy((string) (\$payment['provider'] ?? 'paystack'), \$reference)"));

/* ══ 5. a subscription is never opened on somebody else's account ═════════ */

ck('dues: an auto-renewing plan is only offered on our own Paystack — stopping '
   . 'one taken through another merchant account would be a support request '
   . 'rather than a button',
   str_contains($cfDues, "\$planCode = (\$route === 'paystack' && \$period === 'month'"));

/* ══ 6. paid is not banked, and the client says so ════════════════════════ */

$cfSrc = (string) file_get_contents(AV_ROOT . '/lib/CfisCheckout.php');
ck('cfis checkout: the client states that paid is the card going through and '
   . 'not money in our bank',
   str_contains($cfSrc, 'NOT money in our bank'));

/* ══ 7. nothing is attempted when it cannot work ══════════════════════════ */

$cfReset();
$cfOut = CfisCheckout::open('AVG-X', 'dues', 150000, 'a@example.test');
ck('cfis checkout: with nothing configured it refuses locally rather than '
   . 'making a request that cannot succeed',
   empty($cfOut['ok']) && str_contains((string) $cfOut['error'], 'not configured'));

$cfStatus = CfisCheckout::status('AVG-X');
ck('cfis checkout: and the same for a status check', empty($cfStatus['ok']));

$cfReset();

/* ══ 8. the shape of an answer is the same on both routes ═════════════════ */

/* The reason this matters is not tidiness. A return leg asks "paid by THIS
   person, for THIS thing" by reading metadata off the verdict. If one route
   returns that key and the other does not, the ownership check silently
   stops existing on one of them — and the way anybody finds out is a
   participant's payment credited to whoever saw its reference first. */

$cfReset();
$cfVerdict = Payments::verifyBy('cfis', 'AVG-NOPE');

ck('verify: an answer always carries metadata, even when there is no answer — '
   . 'a caller reading $v[\'metadata\'][\'x\'] must not depend on the route',
   array_key_exists('metadata', $cfVerdict) && is_array($cfVerdict['metadata']));

ck('verify: and a currency, so the check that the money was NGN reads the '
   . 'same whichever route took it',
   array_key_exists('currency', $cfVerdict) && is_string($cfVerdict['currency']));

ck('verify: an unreachable or unconfigured route is not paid',
   empty($cfVerdict['paid']));

/* ══ 9. a return leg with only a reference ════════════════════════════════ */

$cfReset();
$cfAny = Payments::verifyAny('AVG-NOPE');

ck('verifyAny: with no route at all it refuses rather than guessing',
   $cfAny['paid'] === false && $cfAny['provider'] === '' && !empty($cfAny['error']));

ck('verifyAny: and still answers in the full shape, so the caller does not '
   . 'branch on whether anything was configured',
   array_key_exists('metadata', $cfAny) && array_key_exists('amount_minor', $cfAny));

/* ══ 10. a kind nobody here can grant is never marked paid ════════════════ */

/* THE DEFECT THIS CATCHES. finalizePayment marks a row paid and then grants
   by kind. A kind with no branch — an NGV fee — was marked paid and granted
   nothing: the row is consumed, so the handler that really owns that kind
   never posts it, and the money disappears between two pieces of code that
   each believe the other had it. */

$cfPdo = Database::pdo();
$cfPdo->exec("DELETE FROM payments WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@cfis.test')");
$cfPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@cfis.test'");
$cfPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)')
    ->execute(['Cfis Payer', 'payer@cfis.test', 'x', 'member', 'active']);
$cfUid = (int) $cfPdo->lastInsertId();

$cfLms = new LmsRepository();
$cfLms->createPayment($cfUid, 'ngv', null, 1300000, 'CFIS-NGV-1', 'cfis', 1);

ck('finalize: a kind this cannot grant is declined',
   $cfLms->finalizePayment('CFIS-NGV-1', 1300000) === false);

ck('finalize: …and the row is left unpaid, so whatever owns that kind can '
   . 'still post it',
   (string) $cfPdo->query("SELECT status FROM payments WHERE reference = 'CFIS-NGV-1'")->fetchColumn() !== 'paid');

$cfLms->markPaymentPaid('CFIS-NGV-1');
ck('finalize: the owner of a kind can settle the row itself once it has posted',
   (string) $cfPdo->query("SELECT status FROM payments WHERE reference = 'CFIS-NGV-1'")->fetchColumn() === 'paid');

/* ══ 11. the sweep under a checkout that pushes nothing ═══════════════════ */

/* Our own Paystack retries a webhook until it is acknowledged. CACENTRE's
   checkout is a pull API: a payer who pays and closes the tab leaves the
   money taken and the account still asking for it, for ever, unless
   somebody asks. */

$cfReset();
ck('sweep: with CACENTRE not configured there is nothing to ask and it says so '
   . 'rather than failing',
   $cfLms->sweepHostedPayments(5) === ['asked' => 0, 'settled' => 0, 'pending' => 0]);

$cfCron = (string) file_get_contents(AV_ROOT . '/tasks/cron.php');
ck('sweep: and it is actually on the cron tick — a net nobody runs is not a net',
   str_contains($cfCron, 'sweepHostedPayments'));

$cfNgv = (string) file_get_contents(AV_ROOT . '/academy/ngv/pay.php');
ck('ngv: a fee payment records a pending row, or the sweep has nothing to find',
   str_contains($cfNgv, "createPayment(\$uid, 'ngv', null, \$amount * 100, \$ref, \$route, 1)"));

/* ══ 12. every gate that can be hosted, is ════════════════════════════════ */

$cfGates = [
    'academy/api.php' => 'enrolling on a paid course or taking out membership',
    'academy/learn.php' => 'the locked-lesson pay buttons',
    'academy/ngv/pay.php' => 'a participant paying their own fees',
    'academy/ngv/_dashboard-body.php' => 'the fees panel on the NGV dashboard',
    'lib/LmsRepository.php' => 'the payable flag the dues panel reads',
];
foreach ($cfGates as $cfFile => $cfWhat) {
    $cfText = (string) file_get_contents(AV_ROOT . '/' . $cfFile);
    ck('gate: ' . $cfWhat . ' asks whether money can be taken at all',
       str_contains($cfText, 'Payments::canCollect()')
       && !str_contains($cfText, "Payments::configured('paystack')"));
}

/* The one that must NOT move. A subscription needs a Paystack Plan on our
   own account; running one through CACENTRE would make cancelling a donor's
   standing gift a support request to another organisation. */
$cfAppeals = (string) file_get_contents(AV_ROOT . '/lib/Appeals.php');
ck('gate: recurring giving stays on our own keys, deliberately',
   str_contains($cfAppeals, "!Payments::configured('paystack')")
   && !str_contains($cfAppeals, 'Payments::canCollect()'));

ck('gate: …and says why, so the next person does not "fix" it',
   str_contains($cfAppeals, 'HERE IS CORRECT'));

/* A course fee is tuition and a membership is membership. A checkout opened
   with a stream nobody set up is refused outright, which is the right
   failure — money swept into a default account is money nobody finds until
   the year-end review. */
$cfApi = (string) file_get_contents(AV_ROOT . '/academy/api.php');
ck('streams: the academy names the kind of money it is taking',
   str_contains($cfApi, "\$stream = \$kind === 'membership' ? 'membership' : 'tuition';"));
ck('streams: and NGV fees are a training fee, not a donation',
   str_contains($cfNgv, "'training_fee'"));

/* ══ 13. the donate page without a merchant account ═══════════════════════ */

/* THE DEFECT THIS CATCHES FIRST, which is not about routing at all. The
   whole endpoint used to 503 at file scope when PAYSTACK_SECRET_KEY was
   missing — taking the donor wall, the thermometer and the in-kind form
   down with the card option, none of which touches Paystack. A site with
   no merchant account showed a broken page where it should have shown a
   working one and another way to give. */

$cfDon = (string) file_get_contents(AV_ROOT . '/process-donation.php');

ck('donate: the missing-key refusal is per action, not the whole endpoint',
   str_contains($cfDon, 'function av_ps_require(')
   && !preg_match('/^if \(!defined\(\x27PAYSTACK_SECRET_KEY\x27\)/m', $cfDon));

ck('donate: reading the donor wall and the totals needs no merchant account',
   !str_contains(
       substr($cfDon, (int) strpos($cfDon, "if (\$action === 'get_stats')"),
              (int) strpos($cfDon, "if (\$action === 'verify_payment')")
              - (int) strpos($cfDon, "if (\$action === 'get_stats')")),
       'av_ps_require'
   ));

ck('donate: a card gift is routed, and our own keys still win',
   str_contains($cfDon, "if (av_card_route() === 'cfis') {")
   && str_contains($cfDon, "if (av_ps_ready()) return 'paystack';"));

ck('donate: the hosted route takes NGN and says so rather than converting '
   . 'a donor\'s $50 into ₦50',
   str_contains($cfDon, "Card payment is only available in NGN at the moment"));

/* Pinned as a pattern, not as the exact indentation of one line. A test
   that breaks when somebody reflows an argument list is a test people
   learn to edit without reading. */
ck('donate: a donation is named as a donation, so it lands in the right account',
   preg_match('/CfisCheckout::open\(\s*\$ref,\s*\x27donation\x27\s*,/', $cfDon) === 1);

ck('donate: the receipt is verified against the route that took the money, '
   . 'not assumed to be Paystack',
   str_contains($cfDon, 'CfisCheckout::status($ref)')
   && str_contains($cfDon, 'if (av_ps_ready()) {'));

ck('donate: a signed webhook is refused outright when we hold no key to '
   . 'check it against, rather than compared to a hash of nothing',
   str_contains($cfDon, 'if ($sig && !av_ps_ready()) {'));

ck('donate: a virtual account is still ours alone — there is no hosted '
   . 'equivalent, and it says so',
   str_contains($cfDon, "av_ps_require('virtual-account bank transfer');"));

$cfGive = (string) file_get_contents(AV_ROOT . '/assets/site/give-pay.js');
ck('donate: with no access code the donor is sent to the hosted page rather '
   . 'than left on a button that does nothing',
   str_contains($cfGive, 'if (!d.access_code) {'));
