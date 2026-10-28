<?php
/**
 * integrations/cacentre-mail.php — the door CACENTRE offers a bulk send at.
 *
 * ── THE SAME CONNECTION, NOT A NEW ONE ──────────────────────────────────────
 * integrations/cacentre-tasks.php beside this says why, and it applies here
 * word for word: CACENTRE is not a third party, it is the other half of the
 * same organisation, and it already shares a key with this site. A Studio
 * bearer token would be a second secret to rotate and a second place to
 * forget. So this door speaks the SSO language — a v1 token signed with
 * AV_SSO_SECRET — and the verification below is the tasks door's, line for
 * line, deliberately.
 *
 * ── WHAT IT IS FOR ──────────────────────────────────────────────────────────
 * Two hosting accounts are metered separately, so before this one site's
 * outbound quota was the whole organisation's ceiling while the other's went
 * unused. CACENTRE now offers its list here first; this site takes what it
 * can afford and says which addresses it did NOT take, and CACENTRE queues
 * those itself. Everything this site sends goes out under this site's name,
 * which is the name the recipient signed up under.
 *
 *   GET  ?t=<token>                     → what this site can take today
 *   POST ?t=<token>  {ref,subject,html,people:[{email,name}]}
 *                                       → {accepted, declined:[…], headroom}
 *
 * ── IT TAKES; IT DOES NOT SEND ──────────────────────────────────────────────
 * Accepting writes rows. tasks/cron.php sends them a few at a time. Sending
 * five hundred inside the request CACENTRE is waiting on would time out, and
 * a timeout over a half-sent batch is the one state nobody can reason about
 * afterwards.
 *
 * ── AND IT SAYS NOTHING TO ANYBODY ELSE ─────────────────────────────────────
 * An unsigned, expired or wrongly-signed request gets the same short refusal.
 * This URL is reachable from the internet.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/CacSso.php';
require_once AV_ROOT . '/lib/CacMail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
/* No CORS header: server-to-server. A browser has no business here. */

/* No `: never` return type — 8.1 syntax, and a parse error cannot be logged. */
$refuse = static function (string $why, int $code = 403): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_SLASHES);
    exit;
};

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) $refuse('GET or POST only.', 405);
if (!CacSso::ready())                          $refuse('not-configured', 503);

/* Not a (string) cast: ?t[]=x is an array, and casting one yields "Array"
   plus a warning printed into the response. */
$raw   = $_GET['t'] ?? '';
$token = is_string($raw) ? $raw : '';
if ($token === '') $refuse('no-token', 400);

/* ── Verify, exactly as the tasks door verifies ───────────────────────── */
$parts = explode('.', $token);
if (count($parts) !== 3 || $parts[0] !== 'v1') $refuse('bad-token');

$unb64 = static function (string $s): string {
    $s = strtr($s, '-_', '+/');
    return (string) base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
};

$want = hash_hmac('sha256', $parts[1], CacSso::secret(), true);
$got  = $unb64($parts[2]);
/* Constant time: a compare that short-circuits on a MAC is an oracle for
   forging one. */
if (!hash_equals($want, $got)) $refuse('bad-signature');

$claims = json_decode($unb64($parts[1]), true);
if (!is_array($claims)) $refuse('bad-token');

$now = time();
if ((int) ($claims['exp'] ?? 0) < $now - 60) $refuse('expired');
if ((int) ($claims['iat'] ?? 0) > $now + 60) $refuse('not-yet');

/* The token says what it is for. Today the two doors hold different claims
   anyway — this one names no member — but a token minted for the tasks door
   must not be spendable here on the day somebody adds a `uid` to this one. */
if ((string) ($claims['role'] ?? '') !== 'cac-mail') $refuse('wrong-purpose');

/* ── What can this site take? ─────────────────────────────────────────── */
if ($method === 'GET') {
    echo json_encode([
        'ok' => true,
        'headroom' => CacMail::headroom(),
        'pending'  => CacMail::pending(),
        /* So the console can say "sent as Afrovanguard" before anybody
           presses the button, rather than after. */
        'sender'   => defined('FROM_NAME') ? (string) FROM_NAME : 'Afrovanguard',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── An offer ─────────────────────────────────────────────────────────── */
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $refuse('bad-body', 400);

$ref     = trim((string) ($body['ref'] ?? ''));
$subject = trim((string) ($body['subject'] ?? ''));
$html    = (string) ($body['html'] ?? '');
$people  = $body['people'] ?? null;

if ($ref === '')      $refuse('no-ref', 400);
if ($subject === '')  $refuse('no-subject', 400);
if (trim($html) === '') $refuse('no-body', 400);
if (!is_array($people) || $people === []) $refuse('no-people', 400);

/* A cap on the offer itself, so one request cannot be made to hold an
   arbitrary amount of another system's contact list in memory. CACENTRE
   chunks; this is the number it chunks to. */
if (count($people) > 500) $refuse('too-many', 413);

$res = CacMail::accept($ref, $subject, $html, $people);

/* ALL THREE LISTS ARE NAMED, never counted. CACENTRE queues exactly what
   this site declined, skips exactly what it already holds, and can see that
   the three lists account for every address it offered. Totals cannot say
   which list an address fell in, so a person dropped by a bug here would be
   dropped by both sides at once with every number still adding up. */
echo json_encode([
    'ok' => true,
    'accepted' => $res['accepted'],
    'declined' => $res['declined'],
    'duplicates' => $res['duplicates'],
    'headroom' => $res['headroom'],
], JSON_UNESCAPED_SLASHES);
