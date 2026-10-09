<?php
/**
 * integrations/ngg.php — NextGen Genius telling this site about its members.
 *
 * One event today: `private.ngv.account` — an NGG member was promoted to
 * NextGen Vanguard, so they get their NGV account here (lib/NgvIntake.php).
 *
 * Sent by NGG's integration bus as a generic signed webhook, and retried by it
 * until this answers 2xx:
 *   X-NGG-Timestamp: unix seconds (±300 s)
 *   X-NGG-Signature: sha256=hex(HMAC_SHA256(NGG_WEBHOOK_SECRET, ts + "." + body))
 *   X-NGG-Delivery:  the event id, the same on every retry
 * Body: {id, event, createdAt, data}. With NGG_WEBHOOK_SECRET unset the
 * endpoint does not exist.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = static function (array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_SLASHES); exit; };

$secret = av_ngg_secret();
if (trim($secret) === '') $out(['ok' => false, 'error' => 'not-configured'], 404);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $out(['ok' => false, 'error' => 'POST only.'], 405);

$raw = (string) file_get_contents('php://input', false, null, 0, 64 * 1024);
$ts = (string) ($_SERVER['HTTP_X_NGG_TIMESTAMP'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_NGG_SIGNATURE'] ?? '');
if (!ctype_digit($ts) || abs(time() - (int) $ts) > 300
    || !hash_equals('sha256=' . hash_hmac('sha256', $ts . '.' . $raw, trim($secret)), $sig)) {
    $out(['ok' => false, 'error' => 'bad_signature'], 401);
}
$in = json_decode($raw, true);
if (!is_array($in)) $out(['ok' => false, 'error' => 'bad_json'], 422);

$event = (string) ($in['event'] ?? '');
if ($event === 'private.ngv.account') {
    $r = NgvIntake::take(is_array($in['data'] ?? null) ? $in['data'] : [], (string) ($_SERVER['HTTP_X_NGG_DELIVERY'] ?? ($in['id'] ?? '')));
    /* A promotion that cannot be used as sent (no email) is still received:
       retrying would not give it one. Only a real failure asks for a retry. */
    $out($r, $r['ok'] || ($r['status'] ?? '') === 'bad_event' ? 200 : 500);
}
if ($event === 'private.ngv.revoked') {
    /* Demoted on NGG: no longer a vanguard here either. */
    $r = NgvIntake::revoke(is_array($in['data'] ?? null) ? $in['data'] : []);
    $out($r, $r['ok'] ? 200 : 500);
}
/* Not an event this site acts on: taken, so NGG does not retry it. */
$out(['ok' => true, 'status' => 'ignored']);
