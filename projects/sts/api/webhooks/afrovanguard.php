<?php
/**
 * STS · webhooks/afrovanguard — receives sponsor + donation confirmations
 * from the Afrovanguard payment processor after the user completes payment
 * on the parent site.
 *
 * Auth: shared-secret HMAC. Afrovanguard signs the raw request body with
 * AFROVANGUARD_WEBHOOK_SECRET (SHA-256) and sends the hex digest in
 * X-AV-Signature. We verify with hash_equals(), then update the matching
 * sponsor_inquiries row to status='converted'.
 *
 * Idempotent: replaying the same payload is safe — UPDATE sets the same row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$secret = env('AFROVANGUARD_WEBHOOK_SECRET');
if (!$secret) {
    log_line('webhook', 'rejected: no secret configured');
    json_error('Webhook not configured', 503);
}

$raw = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_AV_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $raw, $secret);
if (!$sig || !hash_equals($expected, $sig)) {
    log_line('webhook', 'invalid signature', ['ip' => client_ip()]);
    json_error('Invalid signature', 401);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) json_error('Invalid payload', 400);

$inquiryId = (int)($payload['inquiry_id'] ?? 0);
$amount    = (float)($payload['amount_ngn'] ?? 0);
$txRef     = (string)($payload['transaction_ref'] ?? '');
$status    = (string)($payload['status'] ?? 'unknown');

$validStatuses = ['converted','dropped','contacted','new'];
$mapped = match ($status) {
    'paid', 'completed', 'successful' => 'converted',
    'cancelled', 'failed', 'expired'  => 'dropped',
    default                            => 'contacted',
};

try {
    $pdo = db();
    if ($inquiryId > 0) {
        $stmt = $pdo->prepare("UPDATE sponsor_inquiries SET status = :st WHERE id = :id");
        $stmt->execute([':st' => $mapped, ':id' => $inquiryId]);
    }
    // Audit log
    log_line('webhook', 'afrovanguard event', [
        'inquiry' => $inquiryId, 'amount' => $amount, 'ref' => $txRef, 'status' => $mapped,
    ]);

    if ($mapped === 'converted' && $inquiryId > 0) {
        $r = $pdo->prepare("SELECT email, full_name, selected_program FROM sponsor_inquiries WHERE id = :id");
        $r->execute([':id' => $inquiryId]);
        $row = $r->fetch();
        if ($row && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            send_email($row['email'], 'Sponsorship confirmed — thank you',
                "<p>Hi " . htmlspecialchars(explode(' ', (string)$row['full_name'])[0]) . ",</p>"
                . "<p>Your sponsorship of <strong>₦" . number_format($amount) . "</strong> has been received via Afrovanguard. Reference: <code>" . htmlspecialchars($txRef) . "</code>.</p>"
                . "<p>We'll send your first monthly report at the end of the current school term, with photos and metrics from the sessions you fund.</p>"
                . "<p>— The STS team</p>");
        }
        send_email(inbox_for('sponsor'),
            "Sponsorship converted · ₦" . number_format($amount) . " · #$inquiryId",
            "<p>Sponsor inquiry #$inquiryId completed payment.</p>"
            . "<ul><li>Amount: ₦" . number_format($amount) . "</li><li>Ref: $txRef</li><li>Status: $mapped</li></ul>");
    }

    json_response(['ok' => true, 'mapped' => $mapped]);
} catch (Throwable $e) {
    log_line('webhook', 'db failed', ['err' => $e->getMessage()]);
    json_error('Server error', 500);
}
