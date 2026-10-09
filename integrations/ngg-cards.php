<?php
/**
 * integrations/ngg-cards.php — Afrovanguard member cards for NGG's ID Card Studio.
 *
 * NextGen Genius's server asks; this answers with the card exactly as this
 * site draws it (lib/CardBundle.php). Signed the way integrations/ngg.php is,
 * with the same shared NGG_WEBHOOK_SECRET, in the other direction:
 *   X-NGG-Timestamp: unix seconds (±300 s)
 *   X-NGG-Signature: sha256=hex(HMAC_SHA256(NGG_WEBHOOK_SECRET, "cards." + ts + "." + body))
 * The "cards." prefix keeps a signature made for one endpoint from being
 * replayed at the other. Body: {op: "list", q?} | {op: "style"} | {op: "faces", ids: [...]}.
 * With the secret unset the endpoint does not exist.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/CardBundle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
$out = static function (array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; };

$secret = av_ngg_secret();
if ($secret === '') $out(['ok' => false, 'error' => 'not-configured'], 404);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['ok' => false, 'error' => 'POST only.'], 405);

$raw = (string) file_get_contents('php://input', false, null, 0, 64 * 1024);
$ts  = (string) ($_SERVER['HTTP_X_NGG_TIMESTAMP'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_NGG_SIGNATURE'] ?? '');
if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300
    || !hash_equals('sha256=' . hash_hmac('sha256', 'cards.' . $ts . '.' . $raw, $secret), $sig)) {
    $out(['ok' => false, 'error' => 'bad_signature'], 401);
}
$in = json_decode($raw, true);
if (!is_array($in)) $out(['ok' => false, 'error' => 'bad_json'], 422);

switch ((string) ($in['op'] ?? '')) {
    case 'list':
        $out(['ok' => true, 'design' => CardBundle::designVersion(), 'cards' => CardBundle::list(mb_substr((string) ($in['q'] ?? ''), 0, 80))]);
    case 'style':
        $out(['ok' => true] + CardBundle::style());
    case 'faces':
        $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
        $out(['ok' => true, 'design' => CardBundle::designVersion(), 'faces' => CardBundle::faces($ids)]);
}
$out(['ok' => false, 'error' => 'unknown_op'], 400);
