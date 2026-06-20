<?php
/**
 * get-config.php — Safe public configuration endpoint
 *
 * Returns ONLY the Paystack public key to the browser.
 * The secret key (PAYSTACK_SECRET_KEY) is NEVER sent — it stays
 * in config.php and is used only server-side by process-donation.php.
 *
 * Called by donate.html on page load via: fetch('get-config.php')
 */

/* ─── Security headers ──────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: public, max-age=3600');   // safe — public key only

$allowedOrigins = ['https://afrovanguard.org.ng', 'https://www.afrovanguard.org.ng'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}

/* ─── Load config ───────────────────────────────────────────── */
$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration unavailable']);
    exit;
}
require_once $cfg;

/* ─── Return ONLY the public key ────────────────────────────── */
// PAYSTACK_PUBLIC_KEY is safe to send to the browser.
// PAYSTACK_SECRET_KEY is defined in config.php but is NEVER returned here.
echo json_encode(['publicKey' => PAYSTACK_PUBLIC_KEY]);