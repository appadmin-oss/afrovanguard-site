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
ck('with finance not connected, a request is refused in a sentence rather than lost',
   (function () {
       $r = CfisRequests::submit(1, ['title' => 'Bus fare', 'purpose' => 'Travel',
                                     'category' => 'transport', 'amount' => 5000], 'Ada', 'ada@test.ng');
       return ($r['ok'] ?? true) === false && str_contains((string) $r['error'], 'not switched on');
   })());

ck('and nothing is written down for a request that was never sendable',
   (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === 0);

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
   (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === 0);

/* ── A real one, with finance unreachable ──────────────────────────────── */

$sent = CfisRequests::submit(7, [
    'title' => 'Bus fare to the Saturday session',
    'purpose' => 'Four facilitators travelling to the Ikeja centre',
    'category' => 'transport', 'amount' => 12000,
    'account_number' => '0123456789', 'bank_name' => 'GTBank', 'bank_code' => '058',
], 'Ada Nwosu', 'ada@test.ng');

ck('a send that cannot reach finance says so plainly',
   ($sent['ok'] ?? true) === false && str_contains(mb_strtolower((string) $sent['error']), 'could not be reached'));

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
$again = CfisRequests::retry(7, (int) $row['id'], 'Ada Nwosu', 'ada@test.ng');
$after = Database::pdo()->query("SELECT ref FROM cfis_member_requests WHERE id = " . (int) $row['id'])->fetchColumn();

ck('a retry keeps the SAME reference — a fresh one is how somebody gets paid twice',
   $before === (string) $after);
ck('and it did not create a second request',
   (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn() === 1);

/* ── What the member sees ──────────────────────────────────────────────── */

$mine = CfisRequests::forUser(7);

ck('their own request is listed back to them', count($mine['requests']) === 1);
ck('it says it has not reached finance, rather than looking submitted',
   $mine['requests'][0]['sent'] === false);
ck('the page knows finance is unreachable, so the form can say so',
   $mine['finance_reachable'] === false);
ck('and no category list is invented while finance cannot be asked for one '
 . '— a stale copy is exactly how the estate\'s lists drifted before',
   $mine['categories'] === []);

/* ── The way in ────────────────────────────────────────────────────────── */

ck('the portal has an endpoint for it',
   is_file(AV_ROOT . '/portal/money-requests.php'));

$card = (string) @file_get_contents(AV_ROOT . '/portal/views/today.php');
ck('and the card is on Today, which is the page a member lands on',
   str_contains($card, 'id="moneyRequests"'));
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
                'Ask finance for money on Today'));
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
