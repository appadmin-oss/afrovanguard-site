<?php
/**
 * portal/money-requests.php — a member asks CACENTRE finance for money.
 *
 *   GET                 → this member's requests, where each stands, and the
 *                         kinds of spending finance will accept right now.
 *   POST ?action=create → title, purpose, category, amount, whether the
 *                         money is already spent (claim_kind), up to three
 *                         receipts (multipart `receipts[]`) or the reason
 *                         there are none, and where a reimbursement should
 *                         go. Returns the finance reference.
 *   POST ?action=retry  → send again one that never reached finance. The
 *                         reference does not change, so this cannot pay twice.
 *   POST ?action=withdraw → take back one nobody has decided yet: kept here
 *                         and unsent, it is withdrawn here; in finance, CFIS
 *                         withdraws it (and refuses once anything is decided).
 *   POST ?action=account_name → the name the bank holds for an account, to
 *                         show before sending. Never a reason not to send.
 *
 * The create form takes `lines[i][description|category|amount]` for several
 * things in one request (added by the script; without it the single line
 * posts as before) and `needed_by` (YYYY-MM-DD).
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
    /* With the email, so finance can list what this member asked for from
       another device or account ("Earlier requests"). */
    json_out(CfisRequests::forUser($uid, $email));
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST;

/* Looking a name up is not asking for money, so it has its own allowance
   rather than spending the eight an hour a member has to ask with. Finance
   limits it again per member. */
if ($action === 'account_name') {
    av_require_write($uid, 'cfis_account_name', 12, 600);
    json_out(CfisRequests::accountName($email, (string) ($body['account_number'] ?? ''), (string) ($body['bank_code'] ?? '')));
}
if ($action === 'withdraw') {
    av_require_write($uid, 'cfis_withdraw', 10, 3600);
    $r = CfisRequests::withdraw($uid, (int) ($body['id'] ?? 0), $email);
    json_out($r, ($r['ok'] ?? false) ? 200 : 422);
}

/* A request costs somebody's attention at the other end, so the limit is
   low: a handful an hour is plenty for a real claim. */
av_require_write($uid, 'cfis_request', 8, 3600);

if ($action === 'retry') {
    json_out(CfisRequests::retry($uid, (int) ($body['id'] ?? 0), $name, $email));
}

if ($email === '') {
    json_out(['ok' => false, 'error' => 'Your account has no email address, so finance would have nobody to reply to.'], 422);
}

/* Receipts come as multipart `receipts[]`; PHP's per-field arrays are
   turned into one entry per file. */
$files = [];
$up = $_FILES['receipts'] ?? null;
if (is_array($up) && is_array($up['name'] ?? null)) {
    foreach (array_keys($up['name']) as $i) {
        $files[] = ['name' => $up['name'][$i] ?? '', 'tmp_name' => $up['tmp_name'][$i] ?? '',
                    'error' => $up['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $up['size'][$i] ?? 0];
    }
}

$r = CfisRequests::submit($uid, $body, $name, $email, $files);

/* A refusal from finance is a sentence written for the member — that
   heading is not on offer, that amount is over what a form may ask for —
   so it is passed through with a 422 rather than flattened into "could not
   send", which tells them nothing they can act on. */
json_out($r, ($r['ok'] ?? false) ? 200 : 422);
