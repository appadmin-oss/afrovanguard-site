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
