<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Best-effort IP → currency hint. Uses the Cloudflare country header if
 * present (cheap, no external call); falls back to NGN.
 */
$cc = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
$cc = is_string($cc) ? strtoupper($cc) : null;

$currency = match (true) {
    $cc === 'NG' => 'NGN',
    $cc === 'GB' => 'GBP',
    in_array($cc, ['DE','FR','IT','ES','NL','BE','PT','IE','AT','GR','FI','LU','MT','SK','SI','EE','LV','LT','CY','HR'], true) => 'EUR',
    $cc === 'US' || $cc === 'CA' => 'USD',
    default => 'NGN',
};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode(['ok' => true, 'currency' => $currency, 'country' => $cc]);
