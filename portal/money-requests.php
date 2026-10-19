<?php
/**
 * portal/money-requests.php — a member asks CACENTRE finance for money.
 *
 *   GET                 → this member's requests, where each stands, and the
 *                         kinds of spending finance will accept right now.
 *   POST ?action=create → title, purpose, category, amount, and where a
 *                         reimbursement should go. Returns the finance
 *                         reference.
 *   POST ?action=retry  → send again one that never reached finance. The
 *                         reference does not change, so this cannot pay twice.
 *
 * Nothing here decides anything. The request enters the same approval ladder
 * in CFIS as one typed at a finance desk, and the same people have to agree
 * to it. This site is trusted to say who a member is and what they asked
 * for — see lib/CfisRequests.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);

$uid = (int) $u['id'];
$name = trim((string) ($u['name'] ?? '')) ?: 'A member';
$email = trim((string) ($u['email'] ?? ''));
$action = (string) ($_GET['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(CfisRequests::forUser($uid));
}

/* A request costs somebody's attention at the other end, so the limit is
   low: a handful an hour is plenty for a real claim. */
av_require_write($uid, 'cfis_request', 8, 3600);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

if ($action === 'retry') {
    json_out(CfisRequests::retry($uid, (int) ($body['id'] ?? 0), $name, $email));
}

if ($email === '') {
    json_out(['ok' => false, 'error' => 'Your account has no email address, so finance would have nobody to reply to.'], 422);
}

$r = CfisRequests::submit($uid, $body, $name, $email);

/* A refusal from finance is a sentence written for the member — that
   heading is not on offer, that amount is over what a form may ask for —
   so it is passed through with a 422 rather than flattened into "could not
   send", which tells them nothing they can act on. */
json_out($r, ($r['ok'] ?? false) ? 200 : 422);
