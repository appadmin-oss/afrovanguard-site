<?php
/**
 * tests/cfisrequests.test.php — a member asking CACENTRE finance for money.
 *
 * Raising a request in CFIS was already open to anybody with a finance
 * console login, which no member here has — so the people the feature was
 * written for could not use it. These tests cover this site's half: what
 * it refuses before anything is sent, what it writes down before sending,
 * and how it behaves when finance cannot be reached.
 *
 * Nothing here talks to a real CFIS. The far side has its own suite
 * (cacentre-site/finance, tests/Feature/Requests), and the two meet over a
 * signed envelope that was exercised by hand against a running server.
 */
declare(strict_types=1);

/* Unconfigured is the state of a fresh install, and of any developer
   machine: no CFIS_URL, no key. */
/* ON BY DEFAULT: with finance not connected a member can still ask. The
   request is kept under its own reference and goes by itself once finance
   is connected (CfisRequests::sendPending, from the cron). */
$kept = CfisRequests::submit(1, ['title' => 'Bus fare', 'purpose' => 'Travel',
                                 'category' => 'transport', 'amount' => 5000], 'Ada', 'ada@test.ng');
ck('with finance not connected, a member can still ask — the request is kept, not refused',
   ($kept['ok'] ?? false) === true && !empty($kept['queued']) && str_contains((string) $kept['message'], 'as soon as finance is connected'));
$keptRow = Database::pdo()->query("SELECT * FROM cfis_member_requests ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck('and it is written down, unsent, under its own reference, saying why it waits',
   is_array($keptRow) && $keptRow['status'] === 'unsent' && $keptRow['cfis_reference'] === '' && $keptRow['ref'] === $kept['ref']
   && str_contains((string) $keptRow['last_error'], 'finance is connected'));
$keptCount = (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn();
ck('with finance not connected, the cron sends nothing and drops nothing', CfisRequests::sendPending() === ['sent' => 0, 'refused' => 0, 'waiting' => 0]
   && (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests WHERE status = 'unsent'")->fetchColumn() === $keptCount);

/* Point it at a port nothing answers on: configured, unreachable. That is
   the interesting state — a member pressing the button while the link to
   finance is down. */
if (!defined('CFIS_URL')) define('CFIS_URL', 'http://127.0.0.1:9');
if (!defined('CFIS_SOURCE')) define('CFIS_SOURCE', 'afrovanguard');
if (!defined('CFIS_SECRET')) define('CFIS_SECRET', 'a-test-signing-key-0123456789abcdef');

ck('with a key and an address, the site considers finance connected',
   CfisRequests::configured());

/* ── What is refused here, before anything leaves ──────────────────────── */

$bad = [
    'a request with no title'    => ['title' => '',          'purpose' => 'Travel', 'category' => 'transport', 'amount' => 5000],
    'a request with no reason'   => ['title' => 'Bus fare',  'purpose' => '',       'category' => 'transport', 'amount' => 5000],
    'a request for nothing'      => ['title' => 'Bus fare',  'purpose' => 'Travel', 'category' => 'transport', 'amount' => 0],
    'a request for a few naira'  => ['title' => 'Bus fare',  'purpose' => 'Travel', 'category' => 'transport', 'amount' => 20],
    'a request with no kind'     => ['title' => 'Bus fare',  'purpose' => 'Travel', 'category' => '',          'amount' => 5000],
];
foreach ($bad as $label => $in) {
    $r = CfisRequests::submit(1, $in, 'Ada', 'ada@test.ng');
    ck($label . ' is refused before anything is sent', ($r['ok'] ?? true) === false);
}

ck('and none of those reached the database either',
   (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === $keptCount);

/* ── A real one, with finance unreachable ──────────────────────────────── */

$sent = CfisRequests::submit(7, [
    'title' => 'Bus fare to the Saturday session',
    'purpose' => 'Four facilitators travelling to the Ikeja centre',
    'category' => 'transport', 'amount' => 12000,
    'account_number' => '0123456789', 'bank_name' => 'GTBank', 'bank_code' => '058',
], 'Ada Nwosu', 'ada@test.ng');

ck('a send that cannot reach finance says so plainly — and keeps the request to send by itself',
   ($sent['ok'] ?? false) === true && !empty($sent['queued']) && str_contains(mb_strtolower((string) $sent['message']), 'could not be reached'));

$row = Database::pdo()->query("SELECT * FROM cfis_member_requests ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

ck('but the request IS written down, so the member has not lost what they typed',
   is_array($row) && $row['title'] === 'Bus fare to the Saturday session');
ck('with our own reference on it, which is what stops a retry paying twice',
   is_array($row) && str_starts_with((string) $row['ref'], 'AVG-REQ-7-'));
ck('the amount is kept in minor units, never a float',
   is_array($row) && (int) $row['amount_minor'] === 1200000);
ck('it is marked as not yet sent, and the reason is kept',
   is_array($row) && $row['status'] === 'unsent' && $row['cfis_reference'] === ''
   && str_contains(mb_strtolower((string) $row['last_error']), 'reach'));

/* ── Sending it again ──────────────────────────────────────────────────── */

$before = (string) $row['ref'];
$countBefore = (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn();
$again = CfisRequests::retry(7, (int) $row['id'], 'Ada Nwosu', 'ada@test.ng');
$after = Database::pdo()->query("SELECT ref FROM cfis_member_requests WHERE id = " . (int) $row['id'])->fetchColumn();

ck('a retry keeps the SAME reference — a fresh one is how somebody gets paid twice',
   $before === (string) $after);
ck('and it did not create a second request',
   (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === $countBefore);
$pend = CfisRequests::sendPending();
ck('the cron tries the waiting ones, stops at the first sign finance is still down, and loses none',
   $pend['sent'] === 0 && $pend['waiting'] === 1 && (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === $countBefore);
ck('the bank details wait with the request, to go when it is sent',
   str_contains((string) Database::pdo()->query("SELECT bank FROM cfis_member_requests WHERE id = " . (int) $row['id'])->fetchColumn(), '0123456789'));

/* ── What the member sees ──────────────────────────────────────────────── */

$mine = CfisRequests::forUser(7);

ck('their own request is listed back to them', count($mine['requests']) === 1);
ck('it says it has not reached finance, rather than looking submitted',
   $mine['requests'][0]['sent'] === false);
ck('the page knows finance is unreachable, so the form can say so',
   $mine['finance_reachable'] === false);
ck('while finance cannot be asked, the form offers CFIS\'s own default headings — and finance still decides on arrival',
   $mine['categories'] === CfisRequests::DEFAULT_CATEGORIES && $mine['accepting'] === true);

/* ── The way in ────────────────────────────────────────────────────────── */

ck('the portal has an endpoint for it',
   is_file(AV_ROOT . '/portal/money-requests.php'));

$card = (string) @file_get_contents(AV_ROOT . '/portal/views/requests.php');
ck('and the card is on Finance requests, its own page in the member\'s menu — Today points to it',
   str_contains($card, 'id="moneyRequests"')
   && str_contains((string) @file_get_contents(AV_ROOT . '/portal/views/today.php'), 'data-goto="requests"'));
ck('exactly once across every view — all of them render into one document, '
 . 'so a second copy would be a duplicate id and the script would drive '
 . 'whichever came first',
   (function () {
       $n = 0;
       foreach (glob(AV_ROOT . '/portal/views/*.php') as $f) {
           $n += substr_count((string) @file_get_contents($f), 'id="moneyRequests"');
       }
       return $n === 1;
   })());
ck('and the membership view points at it rather than repeating it',
   str_contains((string) @file_get_contents(AV_ROOT . '/portal/views/membership.php'),
                'Ask finance for money in Finance requests'));
ck('whose script the portal loads',
   str_contains((string) @file_get_contents(AV_ROOT . '/portal/index.php'), 'money-requests.js'));
ck('the card is not inside the org-only dues block — a facilitator who paid '
 . 'a bus fare out of their own pocket is exactly who it is for',
   (function ($c) {
       $at = strpos($c, 'id="moneyRequests"');
       if ($at === false) return false;
       /* Count the $isOrg blocks opened and closed before this point: if
          the card sat inside one, an if would be open here. */
       $before = substr($c, 0, $at);
       return substr_count($before, '<?php if ($isOrg') === substr_count($before, '<?php endif; ?>')
           || !str_contains(substr($c, max(0, $at - 400), 400), '<?php if ($isOrg');
   })($card));

$js = (string) @file_get_contents(AV_ROOT . '/portal/money-requests.js');
ck('the form is drawn from what finance says it accepts, with no list kept here',
   str_contains($js, 'data.categories') && !preg_match('/\[\s*.transport.\s*,/', $js));

/* ── what Today can say without asking finance ─────────────────────── */

$sum = CfisRequests::summaryFor(7);
ck('the dashboard count knows about the one that never went',
   $sum['total'] === 1 && $sum['unsent'] === 1 && $sum['open'] === 1);
ck('and it is local only — no call to finance to draw the page somebody lands on',
   !str_contains((function () {
       $src = (string) @file_get_contents(AV_ROOT . '/lib/CfisRequests.php');
       $at = strpos($src, 'function summaryFor');
       return substr($src, $at, strpos($src, 'function post', $at) - $at);
   })(), 'self::post('));
ck('somebody else\'s requests are not theirs to see',
   count(CfisRequests::forUser(8)['requests']) === 0);
