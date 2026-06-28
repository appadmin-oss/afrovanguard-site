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
    $event = json_decode($raw, true) ?: [];
    if (($event['event'] ?? '') === 'charge.success') {
        $reference = (string) ($event['data']['reference'] ?? '');
        if ($reference !== '') {
            // Re-verify with Paystack before granting (defence in depth). The
            // verified amount is passed so finalizePayment can reject underpayment.
            $v = Payments::paystackVerify($reference);
            if (!empty($v['paid'])) { $lms->finalizePayment($reference, (int) ($v['amount'] ?? 0)); }
        }
    }
    http_response_code(200);
    echo 'ok';
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
        $v = Payments::paystackVerify($reference);
        if (!empty($v['paid']) && $lms->finalizePayment($reference, (int) ($v['amount'] ?? 0))) {
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
