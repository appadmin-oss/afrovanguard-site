<?php
/**
 * portal/ngg-pay.php — the member's own NGG volunteer pay, for their Wallet.
 *
 *   GET → { ok, wallet: {owed, earned, paid, daysEarned, rate, frequency, on, active, days[], payouts[], bank, finesTaken} | null }
 *   POST {action: 'bank', bankName, accountNumber, accountName} → { ok, bank } | { ok: false, error }
 *
 * Their own only: the member comes from the session, and NGG finds the
 * volunteer by their email (lib/NggPay.php). Nothing is stored here.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* Where NGG pays them. Their own only, CSRF-checked and rate-limited;
       NGG checks the details and emails the volunteer about every change. */
    av_require_write((int) $u['id'], 'ngg_bank', 5, 3600);
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in) || (string) ($in['action'] ?? '') !== 'bank') json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    try { $b = NggPay::bankSet($u, $in); }
    catch (Throwable $e) { error_log('[ngg-pay] bank: ' . $e->getMessage()); $b = ['ok' => false, 'error' => 'NGG did not answer just now. Try again in a minute.']; }
    json_out($b, 200);
}
try {
    $r = NggPay::forUser($u);
} catch (Throwable $e) {
    error_log('[ngg-pay] ' . $e->getMessage());
    $r = ['ok' => false, 'error' => 'unreachable'];
}
if (!empty($r['ok'])) json_out(['ok' => true, 'wallet' => $r['wallet']]);
/* Not set up here: there is nothing to show, which is not an error to the member. */
if (($r['error'] ?? '') === 'not-configured') json_out(['ok' => true, 'wallet' => null]);
/* 200 with ok:false, so a proxy in front does not swap the answer for its own error page. */
json_out(['ok' => false, 'error' => 'NGG, which pays volunteers, did not answer just now.']);
