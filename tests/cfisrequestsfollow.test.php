<?php
/**
 * tests/cfisrequestsfollow.test.php — what a member can do with a finance
 * request after asking (CFIS ADR 58): ask for several things at once, say
 * when it is needed, see the bank's name for the account they typed, see
 * where each request stands and why one was refused, withdraw one nobody
 * has decided, and see what they asked for from another device or account.
 *
 * CFIS is replaced by CfisRequests::$transport, which records each call and
 * answers as CFIS would — including as a CFIS from BEFORE these endpoints
 * existed (404, no `code`), because the portal goes live on its own
 * schedule and must not break against an older finance. CFIS's half is in
 * cacentre-site/finance tests/Feature/Requests/MemberRequestFeaturesTest.php.
 */
declare(strict_types=1);

$fuEnvBefore = ['CFIS_URL' => getenv('CFIS_URL'), 'CFIS_SECRET' => getenv('CFIS_SECRET'), 'AV_PRIVATE_DIR' => getenv('AV_PRIVATE_DIR')];
putenv('CFIS_URL=http://127.0.0.1:9');
putenv('CFIS_SECRET=a-test-signing-key-0123456789abcdef');
$fuPrivate = sys_get_temp_dir() . '/av-fu-private-' . getmypid();
@mkdir($fuPrivate, 0700, true);
putenv('AV_PRIVATE_DIR=' . $fuPrivate);
@unlink($fuPrivate . '/cfis-banks.json');

/* The fake CFIS: a queue of answers per path suffix, and a log of calls. */
$fuCalls = [];
$fuAnswers = [];
$fuAnswer = static function (string $suffix, int $code, ?array $json) use (&$fuAnswers): void { $fuAnswers[$suffix][] = [$code, $json]; };
CfisRequests::$transport = static function (string $path, array $claims, array $extra) use (&$fuCalls, &$fuAnswers): array {
    $fuCalls[] = ['path' => $path, 'claims' => $claims, 'extra' => $extra];
    foreach ($fuAnswers as $suffix => $queue) {
        if (str_ends_with($path, $suffix) && $queue !== []) { return array_shift($fuAnswers[$suffix]); }
    }
    return [0, null];                                      // nothing scripted: finance unreachable
};
$fuLast = static function (string $suffix) use (&$fuCalls): ?array {
    foreach (array_reverse($fuCalls) as $c) if (str_ends_with($c['path'], $suffix)) return $c;
    return null;
};
$fuRow = static fn (string $ref) => Database::pdo()->query("SELECT * FROM cfis_member_requests WHERE ref = " . Database::pdo()->quote($ref))->fetch(PDO::FETCH_ASSOC);
$fuBase = ['title' => 'Saturday session', 'purpose' => 'Getting four facilitators there and the workbooks printed', 'claim_kind' => 'advance'];
$fuUser = 41;
/* The paths as this site builds them: the source is whatever this install is set to. */
$fuS = '/api/requests/' . rawurlencode(CfisCheckout::source());
$fuV1 = '/api/v1/requests/' . rawurlencode(CfisCheckout::source());

/* ── Every call is signed afresh ───────────────────────────────────── */

$fuNonce = static fn (string $t) => json_decode((string) base64_decode(strtr(explode('.', $t)[1], '-_', '+/')), true)['nonce'] ?? '';
ck('every call to finance carries a fresh envelope, so a retry is never refused as a replay of the last one',
   $fuNonce(CfisCheckout::mint(['ref' => 'X'])) !== $fuNonce(CfisCheckout::mint(['ref' => 'X'])));
$fuSrc = (string) file_get_contents(AV_ROOT . '/lib/CfisRequests.php');
ck('and the one transport mints inside itself, never from a token kept by its caller',
   preg_match('/function post\(string \$path, array \$claims.*?CfisCheckout::mint\(\$claims\)/s', $fuSrc) === 1);

/* ── Several lines, and needed by ──────────────────────────────────── */

$fuAnswer($fuS, 201, ['ok' => true, 'reference' => 'REQ-2026-00100', 'status' => 'submitted']);
$fuR = CfisRequests::submit($fuUser, $fuBase + [
    'category' => 'transport', 'amount' => 999999,          // the form's single line is overruled by the lines
    'needed_by' => gmdate('Y-m-d', time() + 5 * 86400),
    'lines' => [
        ['description' => 'Bus fares', 'category' => 'transport', 'amount' => '12000'],
        ['description' => 'Printing workbooks', 'category' => 'materials', 'amount' => '3500'],
        ['description' => '', 'category' => 'transport', 'amount' => ''],   // an empty last row is not an error
    ],
], 'Ada Nwosu', 'ada@test.ng');
$fuSent = $fuLast($fuS);
ck('several lines go to finance as lines, each with its own kind of spending',
   ($fuR['ok'] ?? false) === true && is_array($fuSent)
   && ($fuSent['claims']['lines'] ?? null) === [
       ['description' => 'Bus fares', 'category' => 'transport', 'amount' => '12000.00'],
       ['description' => 'Printing workbooks', 'category' => 'materials', 'amount' => '3500.00'],
   ]);
ck('with the total and the first heading beside them, so an older finance books the whole sum rather than refusing it',
   ($fuSent['claims']['amount'] ?? '') === '15500.00' && ($fuSent['claims']['category'] ?? '') === 'transport');
ck('and the day it is needed by',
   ($fuSent['claims']['needed_by'] ?? '') === gmdate('Y-m-d', time() + 5 * 86400));
$fuRowA = $fuRow((string) $fuR['ref']);
ck('the lines and the date are kept with the request, for a send that has to wait',
   (int) $fuRowA['amount_minor'] === 1550000 && count((array) json_decode((string) $fuRowA['lines'], true)) === 2
   && $fuRowA['needed_by'] === gmdate('Y-m-d', time() + 5 * 86400));

$fuAnswer($fuS, 201, ['ok' => true, 'reference' => 'REQ-2026-00101', 'status' => 'submitted']);
$fuOne = CfisRequests::submit($fuUser, $fuBase + ['lines' => [['description' => 'Data', 'category' => 'internet', 'amount' => '500']]], 'Ada Nwosu', 'ada@test.ng');
$fuSentOne = $fuLast($fuS);
ck('one line sent as a list is just one line — the shape every finance understands',
   ($fuOne['ok'] ?? false) === true && !isset($fuSentOne['claims']['lines'])
   && $fuSentOne['claims']['amount'] === '500.00' && $fuSentOne['claims']['category'] === 'internet');
$fuAnswer($fuS, 201, ['ok' => true, 'reference' => 'REQ-2026-00102', 'status' => 'submitted']);
$fuVenue = CfisRequests::submit($fuUser, $fuBase + ['category' => 'venue', 'amount' => 7000], 'Ada Nwosu', 'ada@test.ng');
$fuSentV = $fuLast($fuS);
ck('and without any lines (no script on the page) the single line still posts',
   ($fuVenue['ok'] ?? false) && $fuSentV['claims']['amount'] === '7000.00' && !isset($fuSentV['claims']['lines']) && !isset($fuSentV['claims']['needed_by']));

$fuCount = static fn (): int => (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn();
$fuBefore = $fuCount();
foreach ([
    'a line with no kind of spending'         => ['lines' => [['description' => 'Fare', 'category' => '', 'amount' => '100'], ['description' => 'Data', 'category' => 'internet', 'amount' => '100']]],
    'a line with nothing to pay'              => ['lines' => [['description' => 'Fare', 'category' => 'transport', 'amount' => '0'], ['description' => 'Data', 'category' => 'internet', 'amount' => '100']]],
    'more lines than finance takes'           => ['lines' => array_fill(0, CfisRequests::MAX_LINES + 1, ['description' => 'Fare', 'category' => 'transport', 'amount' => '100'])],
    'a needed-by date already gone'           => ['category' => 'transport', 'amount' => 5000, 'needed_by' => '2020-01-01'],
    'a needed-by that is not a date'          => ['category' => 'transport', 'amount' => 5000, 'needed_by' => 'next week'],
] as $fuLabel => $fuIn) {
    ck($fuLabel . ' is refused here, before anything is written or sent',
       (CfisRequests::submit($fuUser, $fuBase + $fuIn, 'Ada Nwosu', 'ada@test.ng')['ok'] ?? true) === false);
}
ck('and none of those reached the database', $fuCount() === $fuBefore);

/* ── Withdraw ──────────────────────────────────────────────────────── */

/* Kept here, never sent: withdrawn here, and finance is asked nothing. */
$fuKept = CfisRequests::submit($fuUser, $fuBase + ['category' => 'transport', 'amount' => 5000], 'Ada Nwosu', 'ada@test.ng');
$fuKeptRow = $fuRow((string) $fuKept['ref']);
$fuCallsBefore = count($fuCalls);
$fuW = CfisRequests::withdraw($fuUser, (int) $fuKeptRow['id'], 'ada@test.ng');
ck('a request never sent is withdrawn here, without a word to finance',
   ($fuW['ok'] ?? false) === true && !empty($fuW['local']) && count($fuCalls) === $fuCallsBefore
   && $fuRow((string) $fuKept['ref'])['status'] === 'withdrawn');
ck('and it is never sent afterwards — not by the cron, not by "send it again"',
   (function () use ($fuKept, $fuUser, $fuKeptRow, &$fuCalls) {
       $from = count($fuCalls);
       CfisRequests::sendPending();
       $retry = CfisRequests::retry($fuUser, (int) $fuKeptRow['id'], 'Ada Nwosu', 'ada@test.ng');
       foreach (array_slice($fuCalls, $from) as $c) if (($c['claims']['ref'] ?? '') === $fuKept['ref']) return false;
       return ($retry['ok'] ?? true) === false;
   })());
ck('it is not somebody else\'s to withdraw',
   (CfisRequests::withdraw($fuUser + 1, (int) $fuRowA['id'], 'other@test.ng')['ok'] ?? true) === false);

/* In finance: CFIS is asked, by our own reference and the member's email. */
$fuAnswer($fuV1 . '/withdraw', 200, ['ok' => true, 'already' => false, 'request' => ['status' => 'cancelled', 'stage' => 'Withdrawn']]);
$fuW2 = CfisRequests::withdraw($fuUser, (int) $fuRowA['id'], 'ada@test.ng');
$fuWc = $fuLast($fuV1 . '/withdraw');
ck('one finance has is withdrawn there, by this site\'s own reference and the member who asked',
   ($fuW2['ok'] ?? false) === true && ($fuWc['claims'] ?? null) === ['ref' => $fuRowA['ref'], 'email' => 'ada@test.ng', 'typ' => 'cfis.requests.withdraw']
   && $fuRow((string) $fuRowA['ref'])['status'] === 'cancelled');

$fuRowB = $fuRow((string) $fuVenue['ref']);
$fuAnswer($fuV1 . '/withdraw', 409, ['ok' => false, 'code' => 'conflict', 'error' => 'Finance has already made a decision on this request, so it can no longer be withdrawn here.']);
$fuW3 = CfisRequests::withdraw($fuUser, (int) $fuRowB['id'], 'ada@test.ng');
ck('once finance has decided, its own sentence says so, and nothing changes here',
   ($fuW3['ok'] ?? true) === false && str_contains((string) $fuW3['error'], 'already made a decision')
   && $fuRow((string) $fuRowB['ref'])['status'] !== 'cancelled');

$fuAnswer($fuV1 . '/withdraw', 404, ['message' => 'The route api/v1/requests/avg/withdraw could not be found.']);
$fuW4 = CfisRequests::withdraw($fuUser, (int) $fuRowB['id'], 'ada@test.ng');
ck('against a finance from before withdrawals, the member is told to ask finance — not "not found"',
   ($fuW4['ok'] ?? true) === false && str_contains((string) $fuW4['error'], 'Ask finance to cancel'));

/* ── Where each stands, why, and the earlier ones ─────────────────── */

$fuAnswer($fuS . '/status', 200, ['ok' => true,
    'requests' => [
        (string) $fuRowB['ref'] => ['reference' => 'REQ-2026-00102', 'status' => 'rejected', 'paid' => false,
            'stage' => 'Not approved', 'reason' => 'No budget left this quarter.', 'can_withdraw' => false],
        (string) $fuOne['ref'] => ['reference' => 'REQ-2026-00101', 'status' => 'submitted', 'paid' => false,
            'stage' => 'Waiting for approval — step 1 of 2', 'reason' => null, 'can_withdraw' => true],
    ],
    'categories' => ['transport'], 'category_list' => [['key' => 'transport', 'label' => 'Transport and travel', 'hint' => '']],
    'max_lines' => 10, 'banks' => [['name' => 'Guaranty Trust Bank', 'code' => '058'], ['name' => 'Bad', 'code' => '<script>']]]);
$fuAnswer($fuV1 . '/history', 200, ['ok' => true, 'requests' => [
    ['own_reference' => (string) $fuRowB['ref'], 'reference' => 'REQ-2026-00102', 'title' => 'Already listed', 'status' => 'rejected'],
    ['own_reference' => 'AVG-REQ-OLD-PHONE', 'reference' => 'REQ-2026-00007', 'title' => 'Generator fuel', 'status' => 'paid',
     'stage' => 'Paid on 2 Oct 2026', 'paid' => true, 'amount' => '₦8,000.00', 'raised_on' => '2026-09-30'],
]]);
$fuMine = CfisRequests::forUser($fuUser, 'ada@test.ng');
$fuRej = array_values(array_filter($fuMine['requests'], fn ($x) => $x['status'] === 'rejected'))[0] ?? [];
ck('a refused request says why, in the approver\'s words for the refusal',
   ($fuRej['stage'] ?? '') === 'Not approved' && ($fuRej['reason'] ?? '') === 'No budget left this quarter.');
$fuOpen = array_values(array_filter($fuMine['requests'], fn ($x) => $x['status'] === 'submitted'))[0] ?? [];
ck('one waiting says which approval it waits for, and offers Withdraw because finance says it may',
   ($fuOpen['stage'] ?? '') === 'Waiting for approval — step 1 of 2' && ($fuOpen['can_withdraw'] ?? false) === true && ($fuOpen['reason'] ?? 'x') === '');
$fuLocalW = array_values(array_filter($fuMine['requests'], fn ($x) => $x['status'] === 'withdrawn'))[0] ?? [];
ck('one withdrawn before it was sent says so, offers nothing to press, and shows no error',
   ($fuLocalW['stage'] ?? '') === 'Withdrawn before it reached finance' && $fuLocalW['can_withdraw'] === false && $fuLocalW['error'] === '');
ck('earlier requests from finance\'s record are listed once — one already on the page is not repeated',
   count($fuMine['earlier']) === 1 && $fuMine['earlier'][0]['own_reference'] === 'AVG-REQ-OLD-PHONE'
   && $fuMine['earlier'][0]['stage'] === 'Paid on 2 Oct 2026' && $fuMine['history_available'] === true);
ck('the history is asked for by this member\'s email, and nothing else (bar the door it is for)',
   ($fuLast('/history')['claims'] ?? null) === ['email' => 'ada@test.ng', 'typ' => 'cfis.requests.history']);
ck('the bank list comes with the status when the copy here is stale, and only well-formed codes are kept',
   ($fuLast($fuS . '/status')['claims']['banks'] ?? false) === true
   && $fuMine['banks'] === [['name' => 'Guaranty Trust Bank', 'code' => '058']]);

$fuAnswer($fuS . '/status', 200, ['ok' => true, 'requests' => [], 'categories' => ['transport']]);
$fuAnswer($fuV1 . '/history', 404, ['message' => 'Not Found']);
$fuOld = CfisRequests::forUser($fuUser, 'ada@test.ng');
ck('against a finance from before history, the page still draws — with no earlier list',
   $fuOld['ok'] === true && $fuOld['earlier'] === [] && $fuOld['history_available'] === false && $fuOld['finance_reachable'] === true);
ck('and the bank list is not asked for again within the day',
   !isset($fuLast($fuS . '/status')['claims']['banks']) && $fuOld['banks'] === [['name' => 'Guaranty Trust Bank', 'code' => '058']]);
$fuStale = CfisRequests::forUser($fuUser + 9);
ck('a finance from before stage and reason sends neither, and the page falls back to the bare status',
   $fuStale['requests'] === [] && $fuStale['finance_reachable'] === false);

/* ── The bank's name for the account ───────────────────────────────── */

$fuAnswer($fuV1 . '/account-name', 200, ['ok' => true, 'name' => 'NWOSU ADA CHIOMA', 'checked' => true]);
$fuN = CfisRequests::accountName('ada@test.ng', '0123456789', '058');
ck('the name the bank holds is shown back before sending',
   $fuN === ['ok' => true, 'name' => 'NWOSU ADA CHIOMA', 'checked' => true]
   && ($fuLast('/account-name')['claims'] ?? null) === ['email' => 'ada@test.ng', 'account_number' => '0123456789', 'bank_code' => '058', 'typ' => 'cfis.requests.account_name']);
$fuCallsBefore = count($fuCalls);
ck('a number that is not ten digits is not looked up at all',
   CfisRequests::accountName('ada@test.ng', '12345', '058')['checked'] === false && count($fuCalls) === $fuCallsBefore);
foreach ([
    'finance not answering'          => [0, null],
    'a finance from before the check' => [404, ['message' => 'Not Found']],
    'too many checks'                => [429, ['ok' => false, 'code' => 'throttled', 'error' => 'That is enough account checks for now.']],
    'the bank\'s lookup is down'      => [200, ['ok' => true, 'name' => null, 'checked' => false]],
] as $fuLabel => [$fuCode, $fuJson]) {
    $fuAnswer($fuV1 . '/account-name', $fuCode, $fuJson);
    $fuX = CfisRequests::accountName('ada@test.ng', '0123456789', '058');
    ck('with ' . $fuLabel . ' the answer is "not checked" — never a refusal, so nobody is stopped from asking',
       $fuX['ok'] === true && $fuX['checked'] === false && $fuX['name'] === null);
}

/* ── The page ──────────────────────────────────────────────────────── */

$fuView = (string) file_get_contents(AV_ROOT . '/portal/views/requests.php');
$fuJs = (string) file_get_contents(AV_ROOT . '/portal/money-requests.js');
$fuEp = (string) file_get_contents(AV_ROOT . '/portal/money-requests.php');
ck('the form offers a needed-by date, in the markup itself so it works without script',
   str_contains($fuView, 'name="needed_by" type="date"'));
ck('"+ Add another line" is a real button, revealed by the script that makes it work',
   str_contains($fuView, '<button type="button" class="pbtn pbtn-ghost" data-mr-addline>+ Add another line</button>')
   && preg_match('/data-mr-addline-wrap hidden/', $fuView) === 1 && str_contains($fuJs, 'addWrap.hidden = false'));
ck('with a running total read out as it changes, and each line\'s remove button named for its line',
   str_contains($fuView, 'data-mr-total aria-live="polite"') && str_contains($fuJs, "'Remove line ' + (i + 1)"));
ck('the bank picker is labelled, and the bank\'s answer is announced',
   preg_match('/<label data-mr-bankpick-wrap hidden>Bank <select data-mr-bankpick>/', $fuView) === 1
   && str_contains($fuView, 'data-mr-acct role="status" aria-live="polite"'));
ck('the bank\'s answer never blocks sending: no path in the script disables the submit button on it',
   !preg_match('/res\.checked[^;]*disabled|res\.name[^;]*disabled/', $fuJs) && str_contains($fuJs, 'You can still send.'));
ck('Withdraw asks first, and is offered only where finance says it can work',
   str_contains($fuJs, 'window.confirm(') && str_contains($fuJs, 'if (r.can_withdraw)'));
ck('earlier requests have their own headed section',
   str_contains($fuView, '<h3 id="avpMrEarlierH">Earlier requests</h3>') && str_contains($fuView, 'aria-labelledby="avpMrEarlierH"'));
ck('looking a name up does not spend the allowance a member has to ask for money',
   strpos($fuEp, "'account_name'") < strpos($fuEp, "av_require_write(\$uid, 'cfis_request'")
   && strpos($fuEp, "'withdraw'") < strpos($fuEp, "av_require_write(\$uid, 'cfis_request'"));

/* ── Leave nothing behind for the next file ────────────────────────── */

CfisRequests::$transport = null;
foreach ($fuEnvBefore as $fuK => $fuV) putenv($fuV === false ? $fuK : $fuK . '=' . $fuV);
@unlink($fuPrivate . '/cfis-banks.json'); @rmdir($fuPrivate);
