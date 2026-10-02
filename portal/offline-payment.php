<?php
/**
 * portal/offline-payment.php — a member tells us they paid offline, with the receipt.
 *
 *   GET                   → their offline payments and where each stands
 *   POST (multipart)      → purpose (dues|ngv), months (dues), amount, method,
 *                           reference, paid_on, evidence (the file)
 *
 * Nothing is credited on the member's word: the receipt is read and checked
 * (lib/OfflinePayments.php) and the payment is credited only if it passes.
 * One that does not is held, with the reasons, and the member can send better
 * evidence. Card payment (portal/dues.php, academy/ngv/pay.php) is unchanged.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['ok' => true, 'payments' => OfflinePayments::forUser($uid), 'methods' => OfflinePayments::METHODS,
              'prices' => ['month' => OfflinePayments::duesPrice(1), 'year' => OfflinePayments::duesPrice(12)]]);
}

// Same-origin, CSRF and a per-member limit. Each submission costs a reading; a handful an hour is plenty for a real payer.
av_require_write($uid, 'offline_pay', 6, 3600);

$f = $_FILES['evidence'] ?? null;
$ok = $f && (int) ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name']);
$bytes = $ok ? (string) file_get_contents((string) $f['tmp_name']) : '';
$r = OfflinePayments::submit($uid, [
    'purpose' => (string) ($_POST['purpose'] ?? 'dues'), 'months' => (int) ($_POST['months'] ?? 12),
    'amount_ngn' => $_POST['amount'] ?? 0, 'method' => (string) ($_POST['method'] ?? ''),
    'reference' => (string) ($_POST['reference'] ?? ''), 'paid_on' => (string) ($_POST['paid_on'] ?? ''),
], $bytes, $ok ? Storage::mime((string) $f['tmp_name']) : '', (string) $u['email'], 'member');
json_out($r, empty($r['ok']) ? 422 : 200);
