<?php
/**
 * academy/pay.php — Paystack return URL (GET) + webhook (POST).
 *
 *  GET   /academy/pay.php?reference=...   ← browser is sent back here after
 *        the hosted checkout. We verify server-side, grant access on success,
 *        then redirect the learner to the course (or membership) with a flag.
 *
 *  POST  /academy/pay.php                 ← Paystack webhook. Signed with
 *        x-paystack-signature (HMAC-SHA512). Verified independently of the
 *        browser so access is granted even if the user closes the tab.
 *
 * Access is only ever granted after a verified transaction (never on the
 * browser redirect alone). finalizePayment() is idempotent, so the webhook
 * and the redirect can both fire safely.
 *
 * THE WEBHOOK IS NOT THIS FILE'S ANY MORE. A gateway posts to one address,
 * and this site has two money handlers; whichever address is configured, the
 * other kinds used to be mis-recorded or lost. Both now hand the event to
 * lib/PaymentWebhook, which routes by kind — so a donation posted here is
 * recorded as a donation, and the learner's course is still enrolled.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$lms = new LmsRepository();

/* ── Webhook (server-to-server) ─────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    $sig = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
    if (!Payments::paystackWebhookValid($raw, $sig)) {
        http_response_code(401);
        exit;
    }
    $r = PaymentWebhook::handle(json_decode($raw, true) ?: []);
    http_response_code((int) ($r['status'] ?? 200));
    header('Content-Type: application/json');
    echo json_encode(['received' => true, 'recorded' => !empty($r['ok']),
                      'kind' => (string) ($r['kind'] ?? ''), 'reason' => (string) ($r['error'] ?? '')]);
    exit;
}

/* ── Browser return ─────────────────────────────────────────── */
$reference = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($_GET['reference'] ?? $_GET['trxref'] ?? $_GET['ref'] ?? ''));
$payment   = $reference ? $lms->paymentByRef($reference) : null;

$dest = academy_url(''); // academy home as a safe default
$status = 'failed';

if ($payment) {
    if ($payment['status'] === 'paid') {
        $status = 'paid'; // already finalised (e.g. webhook beat the redirect)
    } else {
        /* Verified BY THE ROUTE THE PAYMENT WAS TAKEN ON, read off the
           stored row. A charge CACENTRE collected does not exist in our
           Paystack account, so asking Paystack about it finds nothing and
           would tell somebody who has just paid that they have not. */
        $v = Payments::verifyBy((string) ($payment['provider'] ?? 'paystack'), $reference);
        if (!empty($v['paid']) && $lms->finalizePayment($reference, (int) ($v['amount_minor'] ?? 0))) {
            $status = 'paid';
        }
    }
    if ($payment['kind'] === 'membership') {
        $dest = academy_url('');
    } elseif ($payment['course_id']) {
        $row = Database::pdo()->prepare('SELECT slug FROM courses WHERE id = ?');
        $row->execute([(int) $payment['course_id']]);
        $cslug = (string) ($row->fetchColumn() ?: '');
        if ($cslug !== '') $dest = academy_url($cslug . '/');
    }
}

$sep = (strpos($dest, '?') !== false) ? '&' : '?';
header('Location: ' . $dest . $sep . 'pay=' . $status . ($reference ? '&ref=' . urlencode($reference) : ''), true, 303);
exit;
