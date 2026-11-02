<?php
/**
 * integrations/ngg-fines.php — NGG taking Afrovanguard's fines off a
 * volunteer's NGG pay (lib/NggFineSettle.php).
 *
 * Signed the way every NGG door is, with NGG_WEBHOOK_SECRET, under its own
 * purpose so no other door's signature fits:
 *   X-NGG-Timestamp: unix seconds (±300 s)
 *   X-NGG-Signature: sha256=hex(HMAC_SHA256(NGG_WEBHOOK_SECRET, "fines." + ts + "." + body))
 * Body: {op: "owed", emails} | {op: "settle", items} | {op: "unsettle", reference, reason}.
 * With the secret unset the endpoint does not exist.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/NggFineSettle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
$out = static function (array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };

$secret = av_ngg_secret();
if ($secret === '') $out(['ok' => false, 'error' => 'not-configured'], 404);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['ok' => false, 'error' => 'POST only.'], 405);

$raw = (string) file_get_contents('php://input', false, null, 0, 256 * 1024);
if (!NggFineSettle::signatureOk($secret, (string) ($_SERVER['HTTP_X_NGG_TIMESTAMP'] ?? ''), $raw, (string) ($_SERVER['HTTP_X_NGG_SIGNATURE'] ?? ''))) {
    $out(['ok' => false, 'error' => 'bad_signature'], 401);
}
$in = json_decode($raw, true);
if (!is_array($in)) $out(['ok' => false, 'error' => 'bad_json'], 422);

try {
    switch ((string) ($in['op'] ?? '')) {
        case 'owed':     $out(NggFineSettle::owed(is_array($in['emails'] ?? null) ? $in['emails'] : []));
        case 'settle':   $out(NggFineSettle::settle(is_array($in['items'] ?? null) ? $in['items'] : []));
        case 'unsettle': $out(NggFineSettle::unsettle((string) ($in['reference'] ?? ''), (string) ($in['reason'] ?? '')));
    }
} catch (Throwable $e) {
    error_log('[ngg-fines] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'server'], 500);
}
$out(['ok' => false, 'error' => 'unknown_op'], 400);
