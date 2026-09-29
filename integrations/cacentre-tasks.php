<?php
/**
 * integrations/cacentre-tasks.php — the tasks CACENTRE shows for one member.
 *
 * ── WHY THIS IS ITS OWN ENDPOINT ────────────────────────────────────────────
 * integrations/api.php is the door for third-party apps: Bearer tokens issued
 * in Studio, scoped per token, revocable one at a time. CACENTRE is not a
 * third party — it is the other half of the same organisation, and it already
 * shares a key with this site for single sign-on. Issuing it a Studio token
 * would mean a second secret to rotate and a second place to forget.
 *
 * So this one door speaks the SSO language: a v1 token signed with the shared
 * secret, naming the member being asked about, valid for a minute. That makes
 * the trust here exactly the trust that already exists, and no more.
 *
 * ── IT IS READ-ONLY, AND IT IS ONE PERSON ───────────────────────────────────
 * No writes, and the member is taken from inside the signed token rather than
 * from the query string. A token cannot be re-pointed at somebody else's task
 * list, which is the whole reason the id is not simply a parameter.
 *
 * ── AND IT SAYS NOTHING TO ANYBODY ELSE ─────────────────────────────────────
 * An unsigned, expired or wrongly-signed request gets the same short refusal
 * with no detail about whether the member exists. This URL is reachable from
 * the internet.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/CacSso.php';
require_once AV_ROOT . '/lib/Collab.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
/* No CORS header: this is server-to-server. A browser has no business here,
   and saying so by omission is stronger than saying it in a header. */

/**
 * One shape of refusal, whatever went wrong.
 *
 * No `: never` return type: it is a parse error below PHP 8.1, and a parse
 * error cannot be logged — the file simply does not run.
 */
$refuse = static function (string $why, int $code = 403): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $refuse('GET only.', 405);
if (!CacSso::ready())                                $refuse('not-configured', 503);

/* Not a (string) cast: ?t[]=x is an array, and casting one yields "Array"
   plus a warning printed into the response. */
$raw   = $_GET['t'] ?? '';
$token = is_string($raw) ? $raw : '';
if ($token === '') $refuse('no-token', 400);

/* ── Verify, the same way the other direction is verified ─────────────── */
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
/* A token from the future is a clock problem or a forgery attempt; neither is
   a reason to answer. Sixty seconds of skew, as the bridge allows. */
if ((int) ($claims['iat'] ?? 0) > $now + 60) $refuse('not-yet');

$uid = (int) ($claims['uid'] ?? 0);
if ($uid <= 0) $refuse('no-member');

/* ── The answer ───────────────────────────────────────────────────────── */
try {
    /* myTasks() creates the table if it is not there yet, so there is no
       separate ensure() to call — and there is no public one to call either. */
    $rows = Collab::myTasks($uid, 50);
} catch (Throwable $e) {
    error_log('[cacentre-tasks] ' . $e->getMessage());
    $refuse('unavailable', 503);
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'       => (int) ($r['id'] ?? 0),
        'title'    => (string) ($r['title'] ?? ''),
        'done'     => !empty($r['done']),
        'due'      => (string) ($r['due'] ?? ''),
        'priority' => (string) ($r['priority'] ?? 'normal'),
    ];
}

echo json_encode(['ok' => true, 'tasks' => $out], JSON_UNESCAPED_SLASHES);
