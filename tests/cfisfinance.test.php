<?php
/**
 * tests/cfisfinance.test.php — what the finance integration was broadened to
 * carry (CFIS ADR 56): money already spent, receipts, the words for a kind
 * of spending, utilities, and a finance mailbox that can actually be set.
 *
 * Nothing here talks to a real CFIS; CFIS's side is in cacentre-site/finance
 * tests/Feature/Requests/RequestCategoriesTest.php. Finance is pointed at a
 * port nothing answers on, which is the state that keeps a request here.
 */
declare(strict_types=1);

/* By environment, and cleared at the end: a constant would outlive this
   file and tell the next one finance is connected. */
putenv('CFIS_URL=http://127.0.0.1:9');
putenv('CFIS_SECRET=a-test-signing-key-0123456789abcdef');
/* Mail is off in the suite; lifted only while the fake roads below are in
   place, as appsscriptmail.test.php does. */
putenv('AV_MAIL_DISABLED=0');

$mail = [];
Mailer::fakeRoads(['smtp' => static function (array $m) use (&$mail): bool { $mail[] = $m; return true; },
                   'resend' => static function (array $m) use (&$mail): bool { $mail[] = $m; return true; },
                   'host' => static function (array $m) use (&$mail): bool { $mail[] = $m; return true; }]);

$count = static fn (): int => (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests")->fetchColumn();
$last = static fn () => Database::pdo()->query("SELECT * FROM cfis_member_requests ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$png = static function (): string {
    $p = tempnam(sys_get_temp_dir(), 'rcpt');
    $im = imagecreatetruecolor(4, 4); imagepng($im, $p);
    return $p;
};
$base = ['title' => 'Prepaid electricity', 'purpose' => 'Units for the Saturday session', 'category' => 'utility', 'amount' => 15000];

/* ── The finance mailbox ───────────────────────────────────────────── */

ck('finance mail goes to finance@afrovanguard.org.ng unless Studio says otherwise',
   av_finance_email() === 'finance@afrovanguard.org.ng');
ck('and the address is a setting the Studio offers — it was read but could not be set',
   AvSettings::defined('AV_FINANCE_EMAIL') && !AvSettings::isSecret('AV_FINANCE_EMAIL'));
putenv('AV_FINANCE_EMAIL=accounts@example.org');
ck('a configured address wins', av_finance_email() === 'accounts@example.org');
putenv('AV_FINANCE_EMAIL=not an address');
ck('and one that is not an address falls back rather than mailing nowhere', av_finance_email() === 'finance@afrovanguard.org.ng');
putenv('AV_FINANCE_EMAIL');

/* ── Utilities, and the words for every kind ───────────────────────── */

$view = CfisRequests::forUser(42);
$util = array_values(array_filter($view['category_list'], fn ($c) => $c['key'] === 'utility'))[0] ?? null;
ck('utilities is offered, even while finance cannot be reached', in_array('utility', $view['categories'], true) && $util !== null);
ck('with a name and what it covers, not a heading key', $util && $util['label'] === 'Utilities' && str_contains($util['hint'], 'generator fuel'));
ck('a property refund is never on a member\'s form', !in_array('hire_refund', $view['categories'], true) && !in_array('deposit_refund', $view['categories'], true));
ck('the form is told the receipt rule', (string) $view['receipt_over'] === '5000' && $view['max_files'] === 3);

/* ── Money already spent ───────────────────────────────────────────── */

$before = $count();
$r = CfisRequests::submit(42, $base + ['claim_kind' => 'reimbursement'], 'Ada', 'ada@test.ng');
ck('money already spent over ₦5,000 with no receipt and no reason is refused here', ($r['ok'] ?? true) === false
   && str_contains((string) $r['error'], 'receipt'));
ck('and nothing was written', $count() === $before);

$r = CfisRequests::submit(42, ['amount' => 4000] + $base + ['claim_kind' => 'reimbursement'], 'Ada', 'ada@test.ng');
ck('under the threshold it needs neither', ($r['ok'] ?? false) === true);

$mail = [];
$r = CfisRequests::submit(42, $base + ['claim_kind' => 'reimbursement', 'no_receipt_reason' => 'Bought by USSD, no paper receipt'], 'Ada', 'ada@test.ng');
$row = $last();
ck('a reason is enough, and both are kept with the request', ($r['ok'] ?? false) === true
   && $row['claim_kind'] === 'reimbursement' && $row['no_receipt'] === 'Bought by USSD, no paper receipt');
$toFinance = array_values(array_filter($mail, fn ($m) => ($m['to'] ?? '') === 'finance@afrovanguard.org.ng'));
ck('finance is told, at the finance mailbox', count($toFinance) === 1
   && str_contains((string) ($toFinance[0]['html'] ?? $toFinance[0]['body'] ?? json_encode($toFinance[0])), 'Utilities'));

/* What CFIS would be sent: the kind of claim and the reason ride in the
   signed claims. */
$claimsOf = new ReflectionMethod('CfisRequests', 'claimsOf');
$claims = $claimsOf->invoke(null, $row, 'Ada', 'ada@test.ng');
ck('the claims carry money-already-spent and the reason', $claims['claim_kind'] === 'reimbursement'
   && $claims['no_receipt_reason'] === 'Bought by USSD, no paper receipt' && $claims['category'] === 'utility');

/* ── Receipts ──────────────────────────────────────────────────────── */

$file = $png();
$r = CfisRequests::submit(42, $base + ['claim_kind' => 'reimbursement'], 'Ada', 'ada@test.ng',
    [['name' => 'units.png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]);
$row = $last();
$ev = json_decode((string) $row['evidence'], true);
ck('a receipt is enough, and it is kept until finance has it', ($r['ok'] ?? false) === true && count($ev) === 1
   && is_file($ev[0]['path']) && $ev[0]['sha256'] === hash_file('sha256', $file));
ck('in the private store, never the web root', str_contains($ev[0]['path'], '/db/private/') || (getenv('AV_PRIVATE_DIR') && str_starts_with($ev[0]['path'], (string) getenv('AV_PRIVATE_DIR'))));
$claims = $claimsOf->invoke(null, $row, 'Ada', 'ada@test.ng');
ck('and the signed claims name it by its hash, not its path', ($claims['evidence'][0]['sha256'] ?? '') === $ev[0]['sha256']
   && !isset($claims['evidence'][0]['path']));

$txt = tempnam(sys_get_temp_dir(), 'rcpt'); file_put_contents($txt, "<?php echo 'hi';");
$before = $count();
$r = CfisRequests::submit(42, $base + ['claim_kind' => 'reimbursement', 'no_receipt_reason' => 'x'], 'Ada', 'ada@test.ng',
    [['name' => 'receipt.jpg', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK, 'size' => filesize($txt)]]);
ck('a file that is not a photo or a PDF is refused, whatever it is called', ($r['ok'] ?? true) === false && $count() === $before);

$four = array_map(fn () => ['name' => 'r.png', 'tmp_name' => $png(), 'error' => UPLOAD_ERR_OK, 'size' => 100], range(1, 4));
$r = CfisRequests::submit(42, $base + ['claim_kind' => 'reimbursement'], 'Ada', 'ada@test.ng', $four);
ck('more than three receipts is refused', ($r['ok'] ?? true) === false && str_contains((string) $r['error'], 'Up to 3'));

/* A receipt that changed on disk is not sent as if it were the one hashed. */
file_put_contents($ev[0]['path'], 'tampered');
$send = CfisRequests::send((int) $row['id'], (string) $row['ref'], $claims, $ev);
ck('a kept receipt whose bytes changed is never sent', ($send['ok'] ?? true) === false && str_contains((string) $send['error'], 'can no longer be read'));

Mailer::fakeRoads(null);
putenv('AV_MAIL_DISABLED=1');
putenv('CFIS_URL');
putenv('CFIS_SECRET');
