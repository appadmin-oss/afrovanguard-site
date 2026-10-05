<?php
/**
 * academy/ngv/pay.php — a participant pays their own NGV fees online.
 *
 *   POST  (JSON)              start a payment → returns a Paystack URL
 *   GET   ?ref=…              Paystack sends the payer back here
 *
 * WHY THIS EXISTS. The dashboard could show somebody they owed ₦13,000 and then
 * offer them a bank account number to go and transfer it to. Every one of those
 * transfers had to be spotted by a human, matched to a person by hand, and typed
 * into the console before the participant's account said anything different.
 *
 * THE AMOUNT IS NEVER THE BROWSER'S. What the payer's form says is used only to
 * open a transaction; what gets RECORDED comes from `Payments::verifyAny()` —
 * the provider's own answer, fetched server-side. Anything else means a form
 * field decides how much somebody has paid.
 *
 * EITHER ROUTE. Where this site holds Paystack keys the charge is ours; where
 * it does not, CACENTRE's checkout takes it on our behalf. The ownership check
 * below reads the same on both, because a control that quietly exists on one
 * route only is worse than none.
 *
 * SUCCESS IS RECORDED TWICE AND POSTED ONCE. The payer coming back here is one
 * confirmation; the webhook to `process-donation.php` is another, and it is
 * retried until acknowledged. Both call `NgvLedger::payOnline()`, which is
 * idempotent on the provider reference — so closing the tab at the wrong moment
 * costs nobody their payment, and neither does it double it.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$u      = LmsAuth::user();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uid    = (int) ($u['id'] ?? 0);

/* ── Start a payment ─────────────────────────────────────────────────────── */
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$u) json_out(['ok' => false, 'error' => 'Please sign in first.'], 401);
    av_require_write($uid, 'ngv_pay', 12, 600);

    $p = NgvMember::participant($uid);
    if (!$p) json_out(['ok' => false, 'error' => 'You are not enrolled on NextGen Vanguard.'], 403);
    /* `canCollect()`, not `configured('paystack')`. A participant who owes
       ₦13,000 and is told "card payment is not switched on" goes back to a
       bank transfer somebody has to spot, match by hand and type in — which
       is the thing this page exists to replace. CACENTRE can collect for
       us; our own keys are still preferred when present. */
    if (!Payments::canCollect()) {
        json_out(['ok' => false, 'error' => 'Card payment is not switched on yet. Your team can still record a transfer.'], 503);
    }

    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];

    $balance = NgvLedger::balance($uid);
    $payable = (int) $balance['payable'];
    $amount  = (int) ($in['amount'] ?? 0);
    if ($amount <= 0) $amount = $payable;

    /* Floors and ceilings, both of them real. Paystack refuses a charge under
       ₦100, and a participant must not be able to send ten times what they owe
       through a form — an overpayment is a refund request somebody has to
       process by hand. Paying AHEAD on the training fee is legitimate, so the
       ceiling is generous rather than exact. */
    if ($amount < 100) json_out(['ok' => false, 'error' => 'The smallest payment is ₦100.'], 400);
    $ceiling = max($payable, 0) + 500000;
    if ($amount > $ceiling) {
        json_out(['ok' => false, 'error' => 'That is far more than your account is asking for. '
            . 'If you mean to pay ahead, do it in smaller amounts, or speak to your track lead.'], 400);
    }

    $email = trim((string) ($p['email'] ?? $u['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'We do not have a valid email on your record, so we cannot send a receipt. Tell your team.'], 400);
    }

    $ref = Payments::reference('ngv');
    /* A pending row, so the sweep can find this if the payer never comes
       back. On our own Paystack a webhook would; CACENTRE's checkout is a
       pull API and pushes nothing, so without a row a paid fee would sit
       taken-but-unrecorded until somebody noticed by hand. `kind` is `ngv`
       and finalizePayment refuses kinds it cannot grant, so nothing else
       can consume it. */
    $route = Payments::route();
    try { (new LmsRepository())->createPayment($uid, 'ngv', null, $amount * 100, $ref, $route, 1); }
    catch (Throwable $e) { error_log('[ngv] pay.php: could not record the pending payment: ' . $e->getMessage()); }

    $started = Payments::startCheckout(
        $email, $amount * 100, $ref,
        rtrim(SITE_URL, '/') . '/academy/ngv/pay.php?ref=' . rawurlencode($ref),
        /* A training fee, in CACENTRE's vocabulary. It is not a donation
           and must not land in the donations account. */
        'training_fee',
        'NextGen Vanguard fees',
        (string) ($p['name'] ?? ''),
        /* The webhook and the return leg both read these. `ngv_member` is
           what tells process-donation.php this is a fee payment and not a
           donation, and what the return leg checks ownership against — on
           EITHER route, because CACENTRE hands a property its own metadata
           back for exactly this. */
        ['ngv_member' => $uid, 'ngv' => '1', 'name' => (string) ($p['name'] ?? '')]
    );

    if (empty($started['ok'])) {
        json_out(['ok' => false, 'error' => (string) ($started['error'] ?? 'Could not start that payment. Please try again.')], 502);
    }
    json_out(['ok' => true, 'url' => $started['url'], 'reference' => $ref, 'amount' => $amount]);
}

/* ── The payer comes back ────────────────────────────────────────────────── */
$ref   = trim((string) ($_GET['ref'] ?? ''));
$state = 'unknown';
$paid  = 0;
$result = null;

if ($u && $ref !== '' && Payments::canCollect()) {
    /* Verified server-side. The querystring says only WHICH transaction to ask
       about; the provider says whether it was paid and for how much.

       `verifyAny` rather than a route recorded at init: this page's return
       leg carries nothing but `?ref=`, and persisting the route would mean
       a table that exists for one flow. It asks the preferred route first
       and the other only if that one does not say paid — both are reads, so
       a wrong guess returns "not paid" and never a false yes. It also
       survives the case that actually happens: keys arriving between the
       payment starting and the payer coming back. */
    $v = Payments::verifyAny($ref);
    /* Paid is not enough: it has to be THIS member's NGV fee. Any reference
       the provider called paid used to be credited to whoever loaded the
       page — a donation counted twice, as a gift and as fees, or another
       participant's payment taken by whoever saw its reference first.

       The currency clause survives the move: `verifyBy` reports it on both
       routes, so the check reads the same whichever one took the money. A
       control that silently passes on one route and not the other is worse
       than no control, because nobody looks at it again. */
    $mine = (int) ($v['metadata']['ngv_member'] ?? 0) === (int) $u['id']
         && (string) ($v['metadata']['ngv'] ?? '') === '1'
         && in_array((string) ($v['currency'] ?? ''), ['NGN', ''], true);
    if (!empty($v['paid']) && !$mine) {
        $state = 'notyours';
        error_log('[ngv] pay.php: reference ' . $ref . ' (' . (string) ($v['provider'] ?? '?')
            . ') is not an NGV payment by member ' . (int) $u['id']);
    } elseif (!empty($v['paid'])) {
        $paid   = (int) round(((int) $v['amount_minor']) / 100);
        $result = NgvLedger::payOnline($uid, $ref, $paid, ['method' => 'card', 'note' => 'Paid online by card']);
        $state  = !empty($result['ok']) ? (!empty($result['duplicate']) ? 'already' : 'done') : 'failed';
        /* Settle the pending row too, or the sweep keeps asking CACENTRE
           about a payment that is already in the ledger. Harmless —
           payOnline is idempotent on the reference — but it is a question
           asked for ever. */
        if (!empty($result['ok'])) {
            try { (new LmsRepository())->markPaymentPaid($ref); }
            catch (Throwable $e) { error_log('[ngv] pay.php: ' . $e->getMessage()); }
        }
    } else {
        $state = 'notpaid';
    }
}

$account = $u ? NgvLedger::balance($uid) : ['payable' => 0];
$owed    = (int) ($account['payable'] ?? 0);

require_once AV_ROOT . '/lib/partials.php';
render_head([
    'title'     => 'Payment · NextGen Vanguard',
    'desc'      => 'Your NextGen Vanguard payment.',
    'canonical' => rtrim(SITE_URL, '/') . '/academy/ngv/',
    'robots'    => 'noindex, nofollow',
    'css'       => ['/assets/site/editorial.css', '/academy/ngv.css'],
    'body_class' => 'ngv-pay',
]);
render_nav('academy');
?>
<main id="main-content" class="ed-wrap ed-section" style="max-width:720px">
  <?php if (!$u): ?>
    <span class="ed-kicker ed-kicker--muted">Payment</span>
    <h1 class="ed-h2">Please sign in</h1>
    <p class="ed-lede">We need to know whose account to credit. Sign in and open your payment again.</p>
    <p><a class="ed-link" href="<?= e(av_login_url('/academy/ngv/dashboard.php')) ?>">Sign in</a></p>

  <?php elseif ($state === 'done'): ?>
    <span class="ed-kicker">Payment received</span>
    <h1 class="ed-h2">Thank you — that's on your account</h1>
    <p class="ed-lede"><strong><?= e('₦' . number_format($paid)) ?></strong> has been recorded against your fees.
      <?php if (!empty($result['receipt']['no'])): ?>
        Your receipt is <strong><?= e((string) $result['receipt']['no']) ?></strong><?php
          if (!empty($result['receipt']['delivered'])): ?> and is on its way to your email<?php endif; ?>.
      <?php endif; ?></p>
    <?php if (!empty($result['posted'])): ?>
      <ul class="ed-lede" style="padding-left:1.2em">
        <?php foreach ($result['posted'] as $row): ?>
          <li><?= e(ucfirst((string) $row['line'])) ?> — <?= e('₦' . number_format((int) $row['amount'])) ?><?php
            if (!empty($row['ahead'])): ?> <em>(paid ahead)</em><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="ed-lede"><?= $owed > 0
        ? 'Still outstanding: <strong>₦' . number_format($owed) . '</strong>.'
        : 'Your account is now clear.' ?></p>
    <p><a class="ed-link" href="/academy/ngv/dashboard.php">Back to my dashboard</a></p>

  <?php elseif ($state === 'already'): ?>
    <span class="ed-kicker">Payment received</span>
    <h1 class="ed-h2">That one is already on your account</h1>
    <p class="ed-lede">We had recorded this payment before you got back here — nothing has been charged twice.
      <?= $owed > 0 ? 'Still outstanding: <strong>₦' . number_format($owed) . '</strong>.' : 'Your account is clear.' ?></p>
    <p><a class="ed-link" href="/academy/ngv/dashboard.php">Back to my dashboard</a></p>

  <?php elseif ($state === 'notpaid'): ?>
    <span class="ed-kicker ed-kicker--muted">Payment</span>
    <h1 class="ed-h2">That payment didn't go through</h1>
    <p class="ed-lede">Nothing has been taken and nothing has changed on your account. Card payments fail for
      ordinary reasons — a daily limit, a bank timeout — and trying again usually works.</p>
    <p><a class="ed-link" href="/academy/ngv/dashboard.php">Back to my dashboard</a></p>

  <?php elseif ($state === 'notyours'): ?>
    <span class="ed-kicker ed-kicker--muted">Payment</span>
    <h1 class="ed-h2">That payment is not one of your NGV fees</h1>
    <p class="ed-lede">Paystack knows reference <strong><?= e($ref) ?></strong>, but it was not made from your NextGen
      Vanguard account, so nothing has been added to it. If you paid your fees and see this, quote the reference
      to your track lead.</p>
    <p><a class="ed-link" href="/academy/ngv/dashboard.php">Back to my dashboard</a></p>

  <?php elseif ($state === 'failed'): ?>
    <span class="ed-kicker ed-kicker--muted">Payment</span>
    <h1 class="ed-h2">Your money arrived — we could not file it</h1>
    <p class="ed-lede">Paystack confirmed <strong><?= e('₦' . number_format($paid)) ?></strong>, but we could not write it to
      your account just now. <strong>You have not lost it.</strong> Quote reference
      <strong><?= e($ref) ?></strong> to your track lead and it will be put right today.</p>
    <p><a class="ed-link" href="/contact.html">Contact us</a></p>

  <?php else: ?>
    <span class="ed-kicker ed-kicker--muted">Payment</span>
    <h1 class="ed-h2">Nothing to confirm here</h1>
    <p class="ed-lede">This page confirms a payment after you have made one. Start from your dashboard.</p>
    <p><a class="ed-link" href="/academy/ngv/dashboard.php">Back to my dashboard</a></p>
  <?php endif; ?>
</main>
<?php render_footer();
