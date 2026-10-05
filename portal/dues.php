<?php
/**
 * portal/dues.php — member dues (the annual membership fee) status + checkout.
 *
 *   GET  ?action=status    → JSON dues status for the signed-in member.
 *   POST ?action=pay_init  → start a Paystack checkout for the annual dues and
 *                            return { authorization_url }. The transaction is
 *                            finalised by academy/pay.php (browser return +
 *                            webhook), which grants/extends membership
 *                            idempotently via LmsRepository::grantMembership().
 *
 * Note: unlike academy's pay_init, this deliberately does NOT short-circuit for
 * org (@afrovanguard.org.ng) accounts. Org members get member ACCESS for free,
 * but dues are a separate financial contribution they may still choose to pay.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$lms    = new LmsRepository();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'status');

if ($action === 'status') {
    json_out(['ok' => true, 'dues' => $lms->duesStatus((int) $u['id'])]);
}

if ($action === 'pay_init') {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    require_same_origin();
    av_csrf_require();
    /* `canCollect()`, not `configured('paystack')`. The narrow question —
       do WE hold keys — was gating the Pay button, so a site without its own
       merchant account told a willing payer to telephone. CACENTRE can
       collect on our behalf; our own keys are still preferred when present. */
    if (!Payments::canCollect()) json_out(['ok' => false, 'error' => 'Online payment is not available yet — please contact us to pay your dues.'], 503);
    if (!av_rate_ok('dues_pay_init', 12, 600)) json_out(['ok' => false, 'error' => 'Too many attempts — please try again shortly.'], 429);

    // Period: monthly (₦1,000 · 1 month) or annual (₦12,000 · 12 months).
    $period = (($body['period'] ?? 'year') === 'month') ? 'month' : 'year';
    $months = $period === 'month' ? 1 : 12;
    $amountNgn = $period === 'month'
        ? (defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000)
        : (defined('AV_DUES_ANNUAL_NGN')  ? (int) AV_DUES_ANNUAL_NGN  : 12000);
    if ($amountNgn <= 0) json_out(['ok' => false, 'error' => 'Dues are not payable online right now.'], 400);

    $reference = Payments::reference('membership');
    /* The provider is recorded FROM THE ROUTE, not assumed. Verifying a
       CACENTRE charge against Paystack finds nothing and would tell somebody
       who paid that they did not. */
    $route = Payments::route();
    $lms->createPayment((int) $u['id'], 'membership', null, $amountNgn * 100, $reference, $route, $months);
    // Reuse the academy return/webhook handler — it verifies server-side and
    // finalises membership idempotently (browser redirect AND Paystack webhook).
    $callback = rtrim(SITE_URL, '/') . '/academy/pay.php';
    $meta = ['user_id' => (int) $u['id'], 'kind' => 'membership', 'source' => 'portal_dues', 'purpose' => 'Afrovanguard membership dues (' . $period . ')'];

    // Monthly + a Paystack Plan configured → start an AUTO-RENEWING subscription.
    // Otherwise, a one-time charge for the chosen period.
    /* An auto-renewing subscription needs a Paystack Plan on OUR account, so
       it is only ever offered on the direct route. The hosted route takes
       the period as a one-off; renewing through somebody else's merchant
       account would make stopping it a support request rather than a
       button, which is not a thing to do to a payer. */
    $planCode = ($route === 'paystack' && $period === 'month'
        && defined('AV_DUES_PLAN_CODE') && AV_DUES_PLAN_CODE) ? (string) AV_DUES_PLAN_CODE : '';

    if ($planCode !== '') {
        $url = Payments::paystackInitPlan((string) $u['email'], $planCode, $reference, $callback, $meta);
        if (!$url) json_out(['ok' => false, 'error' => 'Could not start the payment. Please try again in a moment.'], 502);
        json_out(['ok' => true, 'authorization_url' => $url, 'reference' => $reference]);
    }

    $started = Payments::startCheckout(
        (string) $u['email'], $amountNgn * 100, $reference, $callback,
        'dues', 'Afrovanguard membership dues (' . $period . ')',
        (string) ($u['name'] ?? ''), $meta
    );
    if (empty($started['ok'])) {
        json_out(['ok' => false, 'error' => (string) ($started['error'] ?? 'Could not start the payment.')], 502);
    }
    json_out(['ok' => true, 'authorization_url' => $started['url'], 'reference' => $reference]);
}

json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
