<?php
/**
 * tests/cfisrequestsmarter.test.php — a member's finance requests made
 * smarter (17 Nov 2026): finance's questions shown and answered, a receipt
 * added after a decision, a receipt photo read to fill the form, a heading
 * suggested as they type, "you asked for something similar" before a second
 * claim, one plain status line, and "Ask again like this".
 *
 * CFIS is replaced by CfisRequests::$transport, as in
 * cfisrequestsfollow.test.php. CFIS's half is in cacentre-site/finance
 * tests/Feature/Requests/MemberRequestSmarterTest.php.
 */
declare(strict_types=1);

$smEnvBefore = ['CFIS_URL' => getenv('CFIS_URL'), 'CFIS_SECRET' => getenv('CFIS_SECRET'), 'AV_PRIVATE_DIR' => getenv('AV_PRIVATE_DIR')];
putenv('CFIS_URL=http://127.0.0.1:9');
putenv('CFIS_SECRET=a-test-signing-key-0123456789abcdef');
$smPrivate = sys_get_temp_dir() . '/av-sm-private-' . getmypid();
@mkdir($smPrivate, 0700, true);
putenv('AV_PRIVATE_DIR=' . $smPrivate);

$smCalls = [];
$smAnswers = [];
$smAnswer = static function (string $suffix, int $code, ?array $json) use (&$smAnswers): void { $smAnswers[$suffix][] = [$code, $json]; };
CfisRequests::$transport = static function (string $path, array $claims, array $extra) use (&$smCalls, &$smAnswers): array {
    $smCalls[] = ['path' => $path, 'claims' => $claims, 'extra' => $extra];
    foreach ($smAnswers as $suffix => $queue) {
        if (str_ends_with($path, $suffix) && $queue !== []) { return array_shift($smAnswers[$suffix]); }
    }
    return [0, null];
};
$smLast = static function (string $suffix) use (&$smCalls): ?array {
    foreach (array_reverse($smCalls) as $c) if (str_ends_with($c['path'], $suffix)) return $c;
    return null;
};
$smRow = static fn (string $ref) => Database::pdo()->query("SELECT * FROM cfis_member_requests WHERE ref = " . Database::pdo()->quote($ref))->fetch(PDO::FETCH_ASSOC);
$smS = '/api/requests/' . rawurlencode(CfisCheckout::source());
$smV1 = '/api/v1/requests/' . rawurlencode(CfisCheckout::source());
$smUser = 77;

/* A real PNG, as a phone's upload arrives: a temp file and no error. */
$smPngBytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$smFile = static function (string $name = 'receipt.png', string $bytes = '') use ($smPngBytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'av-sm');
    file_put_contents($tmp, $bytes !== '' ? $bytes : $smPngBytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
};

/* ── "You asked for something similar" ─────────────────────────────── */

$smAnswer($smS, 201, ['ok' => true, 'reference' => 'REQ-2026-00300', 'status' => 'submitted', 'similar' => null]);
$smFirst = CfisRequests::submit($smUser, ['title' => 'Bus fare to Ikeja', 'purpose' => 'Saturday session', 'category' => 'transport', 'amount' => 12000],
    'Ada Nwosu', 'ada@test.ng');
ck('a first request goes straight through', ($smFirst['ok'] ?? false) === true && ($smFirst['reference'] ?? '') === 'REQ-2026-00300');

$smCount = static fn (): int => (int) Database::pdo()->query("SELECT COUNT(*) FROM cfis_member_requests WHERE user_id = 77")->fetchColumn();
$smBefore = $smCount();
$smCallsBefore = count($smCalls);
$smSame = CfisRequests::submit($smUser, ['title' => 'Printing workbooks', 'purpose' => 'Saturday session', 'category' => 'materials', 'amount' => 12000],
    'Ada Nwosu', 'ada@test.ng');
ck('the same amount within 30 days asks first, naming the day and the reference, in a plain question',
   ($smSame['ok'] ?? true) === false && !empty($smSame['needs_confirm'])
   && $smSame['error'] === 'You asked for something similar on ' . gmdate('j M Y') . ' (ref REQ-2026-00300), still send?');
ck('and nothing is written or sent until the member says yes', $smCount() === $smBefore && count($smCalls) === $smCallsBefore);

$smTitle = CfisRequests::submit($smUser, ['title' => 'Bus fares to Ikeja!', 'purpose' => 'Another trip', 'category' => 'transport', 'amount' => 3000],
    'Ada Nwosu', 'ada@test.ng');
ck('a very similar title with a different amount asks too', !empty($smTitle['needs_confirm']) && ($smTitle['similar']['reference'] ?? '') === 'REQ-2026-00300');

$smOther = CfisRequests::submit($smUser + 1, ['title' => 'Bus fare to Ikeja', 'purpose' => 'x', 'category' => 'transport', 'amount' => 12000], 'Bola', 'bola@test.ng');
ck('another member\'s request is never "yours"', empty($smOther['needs_confirm']));

$smAnswer($smS, 201, ['ok' => true, 'reference' => 'REQ-2026-00301', 'status' => 'submitted',
    'similar' => ['reference' => 'REQ-2026-00300', 'raised_on' => gmdate('Y-m-d'),
        'message' => 'This looks like a request you sent on ' . gmdate('j M Y') . ' (REQ-2026-00300): the same amount under the same heading. It has been sent. Finance will check it is not the same claim before deciding.']]);
$smYes = CfisRequests::submit($smUser, ['title' => 'Printing workbooks', 'purpose' => 'Saturday session', 'category' => 'materials', 'amount' => 12000,
    'confirm_similar' => '1'], 'Ada Nwosu', 'ada@test.ng');
ck('said yes, it goes', ($smYes['ok'] ?? false) === true && ($smYes['reference'] ?? '') === 'REQ-2026-00301');
ck('and finance\'s own duplicate note is passed on in finance\'s words for the member',
   str_starts_with((string) ($smYes['similar'] ?? ''), 'This looks like a request you sent on') && str_contains((string) $smYes['similar'], 'It has been sent.'));

/* Withdrawn, refused, or older than 30 days: not "similar". */
$smPdo = Database::pdo();
$smPdo->exec("UPDATE cfis_member_requests SET status = 'rejected' WHERE user_id = 77 AND cfis_reference = 'REQ-2026-00300'");
$smPdo->exec("UPDATE cfis_member_requests SET status = 'cancelled' WHERE user_id = 77 AND cfis_reference = 'REQ-2026-00301'");
ck('a refused or withdrawn request is not counted', CfisRequests::similarTo($smUser, 1200000, 'Bus fare to Ikeja') === null);
$smPdo->exec("UPDATE cfis_member_requests SET status = 'submitted', created_at = '" . gmdate('c', time() - 31 * 86400) . "' WHERE user_id = 77");
ck('nor one from more than 30 days ago', CfisRequests::similarTo($smUser, 1200000, 'Bus fare to Ikeja') === null);
$smPdo->exec("UPDATE cfis_member_requests SET created_at = '" . gmdate('c') . "' WHERE user_id = 77");
ck('a different title and amount is not similar', CfisRequests::similarTo($smUser, 99900, 'Generator repair') === null);

/* ── Where it stands, finance's questions, and what may be added ──── */

$smRowA = $smRow((string) $smFirst['ref']);
$smRowB = $smRow((string) $smYes['ref']);
$smAnswer($smS . '/status', 200, ['ok' => true, 'reads_receipts' => true,
    'requests' => [
        (string) $smRowA['ref'] => ['reference' => 'REQ-2026-00300', 'status' => 'submitted', 'paid' => false,
            'stage' => 'Waiting for approval', 'can_withdraw' => true, 'on_hold' => true,
            'plain' => 'On hold: finance asked you something before deciding. The finance team decides once you answer. Answer finance\'s question below. It has waited 2 days.',
            'asks' => [['id' => 17, 'question' => 'Please attach a quote <b>now</b>.', 'asked_on' => '2026-11-12', 'due_on' => '2026-11-14'],
                       ['id' => 0, 'question' => 'no id'], 'not an ask'],
            'needs' => 'Answer finance\'s question below.', 'can_add_receipt' => true, 'can_add_evidence' => true],
        (string) $smRowB['ref'] => ['reference' => 'REQ-2026-00301', 'status' => 'approved', 'paid' => false,
            'stage' => 'Approved, awaiting payment', 'plain' => 'Approved, awaiting payment. The finance team pays it next. Nothing is needed from you. It has waited 1 day.',
            'can_add_receipt' => true, 'can_add_evidence' => false],
    ],
    'categories' => ['transport', 'materials'],
    'category_list' => [['key' => 'transport', 'label' => 'Transport and travel', 'hint' => 'Fares'], ['key' => 'materials', 'label' => 'Learning materials', 'hint' => 'Printing']]]);
$smMine = CfisRequests::forUser($smUser);
$smA = array_values(array_filter($smMine['requests'], fn ($x) => $x['reference'] === 'REQ-2026-00300'))[0] ?? [];
$smB = array_values(array_filter($smMine['requests'], fn ($x) => $x['reference'] === 'REQ-2026-00301'))[0] ?? [];
ck('the plain line from finance is passed to the page', str_starts_with((string) ($smA['plain'] ?? ''), 'On hold: finance asked you something'));
ck('finance\'s questions come through, and only well-formed ones',
   ($smA['on_hold'] ?? false) === true && count($smA['asks'] ?? []) === 1 && $smA['asks'][0]['id'] === 17
   && $smA['asks'][0]['question'] === 'Please attach a quote <b>now</b>.' && $smA['asks'][0]['due_on'] === '2026-11-14');
ck('a decided request offers a receipt and nothing else', ($smB['can_add_receipt'] ?? false) === true && ($smB['can_add_evidence'] ?? true) === false);
ck('"Ask again like this" carries what it said, never its receipts or dates',
   ($smB['again'] ?? null) === ['title' => 'Printing workbooks', 'purpose' => 'Saturday session', 'category' => 'materials',
       'claim_kind' => 'advance', 'amount' => '12000.00', 'lines' => []]
   && !array_key_exists('needed_by', $smB['again']) && !array_key_exists('evidence', $smB['again']));
ck('the member\'s own earlier words and headings come with the page, for the suggestion',
   in_array(['words' => 'Bus fare to Ikeja Saturday session', 'category' => 'transport'], $smMine['past'], true));
ck('and the photo reader is offered only when finance says it is on', $smMine['reads_receipts'] === true);
$smOff = CfisRequests::forUser($smUser);                     // nothing scripted: finance not answering
ck('with finance not answering there is no photo reader, no plain line, nothing to add',
   $smOff['reads_receipts'] === false && ($smOff['requests'][0]['plain'] ?? 'x') === '' && ($smOff['requests'][0]['can_add_receipt'] ?? true) === false);

/* ── Answering finance ─────────────────────────────────────────────── */

$smAnswer($smV1 . '/answer', 200, ['ok' => true, 'request' => ['on_hold' => false]]);
$smAns = CfisRequests::answerAsk($smUser, (int) $smRowA['id'], 'ada@test.ng', 17, '  Quote attached.  ', [$smFile('quote.png')]);
$smAc = $smLast('/answer');
ck('an answer goes to finance by this site\'s reference, the member, the question and the words',
   ($smAns['ok'] ?? false) === true && $smAns['message'] === 'Sent. Finance has your answer.'
   && $smAc['claims']['ref'] === $smRowA['ref'] && $smAc['claims']['email'] === 'ada@test.ng' && $smAc['claims']['ask'] === 17
   && $smAc['claims']['answer'] === 'Quote attached.' && $smAc['claims']['typ'] === 'cfis.requests.answer');
ck('with its file beside the token, named in the signed claims by its hash',
   ($smAc['claims']['evidence'] ?? null) === [['name' => 'quote.png', 'sha256' => hash('sha256', $smPngBytes)]]
   && ($smAc['extra']['files'][0]['data'] ?? '') === base64_encode($smPngBytes));
$smCallsBefore = count($smCalls);
ck('an empty answer is refused here, before finance is asked',
   (CfisRequests::answerAsk($smUser, (int) $smRowA['id'], 'ada@test.ng', 17, '   ')['ok'] ?? true) === false && count($smCalls) === $smCallsBefore);
ck('a file that is not a photo or PDF is refused here',
   (CfisRequests::answerAsk($smUser, (int) $smRowA['id'], 'ada@test.ng', 17, 'x', [$smFile('notes.txt', "plain text\n")])['ok'] ?? true) === false
   && count($smCalls) === $smCallsBefore);
ck('somebody else\'s request cannot be answered from this account',
   (CfisRequests::answerAsk($smUser + 1, (int) $smRowA['id'], 'bola@test.ng', 17, 'Mine')['ok'] ?? true) === false);
$smAnswer($smV1 . '/answer', 409, ['ok' => false, 'code' => 'conflict', 'error' => 'This ask is already closed.']);
ck('finance\'s own sentence comes back when the question is already closed',
   (CfisRequests::answerAsk($smUser, (int) $smRowA['id'], 'ada@test.ng', 17, 'Again')['error'] ?? '') === 'This ask is already closed.');
$smAnswer($smV1 . '/answer', 404, ['message' => 'Not Found']);
ck('against a finance from before answers, the member is told how else to reply',
   str_contains((string) (CfisRequests::answerAsk($smUser, (int) $smRowA['id'], 'ada@test.ng', 17, 'Hello')['error'] ?? ''), 'Reply to finance'));

/* ── Adding a receipt after a decision ─────────────────────────────── */

$smAnswer($smV1 . '/evidence', 201, ['ok' => true, 'added' => 1]);
$smRc = CfisRequests::addReceipt($smUser, (int) $smRowB['id'], 'ada@test.ng', [$smFile('till.png')]);
$smEc = $smLast('/evidence');
ck('a receipt goes to finance marked as a receipt, by reference and member',
   ($smRc['ok'] ?? false) === true && $smEc['claims']['typ'] === 'cfis.requests.evidence'
   && $smEc['claims']['evidence'] === [['name' => 'till.png', 'sha256' => hash('sha256', $smPngBytes), 'kind' => 'receipt']]
   && $smEc['claims']['ref'] === $smRowB['ref']);
ck('and the count here includes it, without keeping the file', count((array) json_decode((string) $smRow((string) $smYes['ref'])['evidence'], true)) === 1
   && ((array) json_decode((string) $smRow((string) $smYes['ref'])['evidence'], true))[0]['path'] === '');
ck('choosing no file is said plainly', (CfisRequests::addReceipt($smUser, (int) $smRowB['id'], 'ada@test.ng', [])['error'] ?? '') === 'Choose a photo or PDF of the receipt.');
$smAnswer($smV1 . '/evidence', 409, ['ok' => false, 'code' => 'conflict', 'error' => 'Finance has already decided this request, so only a receipt can be added to it now.']);
ck('finance\'s refusal is passed on in its own words',
   str_contains((string) (CfisRequests::addReceipt($smUser, (int) $smRowB['id'], 'ada@test.ng', [$smFile()])['error'] ?? ''), 'only a receipt'));
/* Nothing scripted for this send: finance does not answer, so it is kept here unsent. */
$smKept = CfisRequests::submit($smUser, ['title' => 'Generator repair', 'purpose' => 'Fan belt', 'category' => 'repairs', 'amount' => 4500, 'confirm_similar' => 1], 'Ada', 'ada@test.ng');
$smKeptRow = $smRow((string) $smKept['ref']);
ck('a request finance does not hold yet cannot take a receipt from here',
   str_contains((string) (CfisRequests::addReceipt($smUser, (int) $smKeptRow['id'], 'ada@test.ng', [$smFile()])['error'] ?? ''), 'not reached finance'));

/* ── Reading a receipt photo ───────────────────────────────────────── */

$smAnswer($smV1 . '/read-receipt', 200, ['ok' => true, 'available' => true, 'read' => [
    'amount' => '12450.00', 'date' => '2026-11-08', 'vendor' => 'ABC Motors', 'category' => 'transport', 'description' => 'Bus hire']]);
$smRead = CfisRequests::readReceipt('ada@test.ng', [$smFile('photo.png')]);
ck('a photo is read into amount, date, vendor and category',
   $smRead === ['ok' => true, 'available' => true, 'read' => ['amount' => '12450.00', 'date' => '2026-11-08', 'vendor' => 'ABC Motors',
       'category' => 'transport', 'description' => 'Bus hire']]);
ck('sent as one file named by its hash, for this member, at its own door',
   ($smLast('/read-receipt')['claims'] ?? null) === ['email' => 'ada@test.ng', 'evidence' => [['name' => 'photo.png', 'sha256' => hash('sha256', $smPngBytes)]],
       'typ' => 'cfis.requests.read_receipt']);
$smAnswer($smV1 . '/read-receipt', 200, ['ok' => true, 'available' => true, 'read' => [
    'amount' => '12,450', 'date' => 'yesterday', 'vendor' => '', 'category' => '<script>', 'description' => '']]);
$smBad = CfisRequests::readReceipt('ada@test.ng', [$smFile()]);
ck('anything that is not a plain amount, a date or a category key is dropped, not passed on',
   $smBad['read'] === ['amount' => null, 'date' => null, 'vendor' => null, 'category' => null, 'description' => null]);
foreach ([
    'reading switched off'      => [200, ['ok' => true, 'available' => false, 'read' => null]],
    'a photo it could not read' => [200, ['ok' => true, 'available' => true, 'read' => null]],
    'finance not answering'     => [0, null],
    'too many reads'            => [429, ['ok' => false, 'code' => 'throttled', 'error' => 'That is enough receipts read for now.']],
    'a finance from before it'  => [404, ['message' => 'Not Found']],
] as $smLabel => [$smCode, $smJson]) {
    $smAnswer($smV1 . '/read-receipt', $smCode, $smJson);
    $smX = CfisRequests::readReceipt('ada@test.ng', [$smFile()]);
    ck('with ' . $smLabel . ' nothing is read and nothing is refused, so the form is left as it was',
       $smX['ok'] === true && $smX['read'] === null);
}
$smCallsBefore = count($smCalls);
ck('and for an account with no usable email, nothing is even asked',
   CfisRequests::readReceipt('not-an-email', [$smFile()]) === ['ok' => true, 'available' => false, 'read' => null] && count($smCalls) === $smCallsBefore);

/* ── The page ──────────────────────────────────────────────────────── */

$smView = (string) file_get_contents(AV_ROOT . '/portal/views/requests.php');
$smJs = (string) file_get_contents(AV_ROOT . '/portal/money-requests.js');
$smEp = (string) file_get_contents(AV_ROOT . '/portal/money-requests.php');
ck('the photo reader has no name, so it is never sent with the form, and is hidden until finance offers it',
   preg_match('/<input type="file" data-mr-photo-in accept=/', $smView) === 1 && str_contains($smView, 'data-mr-photo hidden')
   && str_contains($smJs, 'photo.hidden = !data.reads_receipts'));
ck('the form\'s own fields are unchanged, so it still posts its one line as before',
   str_contains($smView, '<input name="title" maxlength="160" required') && str_contains($smView, 'name="receipts[]" multiple')
   && str_contains($smView, '<select name="category" required>') && str_contains($smView, '<input name="amount" type="number"'));
ck('finance\'s question is shown as text, never as markup, prominently with its answer box',
   str_contains($smJs, "q.textContent = 'Finance asked: ' + a.question") && str_contains($smJs, 'Send answer')
   && !preg_match('/innerHTML\s*=[^;]*a\.question/', $smJs));
ck('a reading that comes back empty says nothing',
   str_contains($smJs, "photoNote.hidden = !got") && !preg_match('/read_receipt[^;]*say\(/', $smJs));
ck('"Add receipt" is offered on decided requests only',
   str_contains($smJs, 'if (r.can_add_receipt && !r.can_add_evidence) li.appendChild(receiptBox(r))'));
ck('the similar-request question is the browser\'s own dialog, and saying no sends nothing',
   str_contains($smJs, 'res.needs_confirm') && str_contains($smJs, "fd.append('confirm_similar', '1')") && str_contains($smJs, 'Not sent. Your form is as you left it.'));
ck('"Ask again like this" leaves receipts and dates empty',
   str_contains($smJs, "['needed_by', 'no_receipt_reason']") && str_contains($smJs, "files.value = ''"));
ck('the heading is suggested with a button, never changed behind the member\'s back',
   str_contains($smJs, "'Use this'") && str_contains($smView, 'data-mr-suggest role="status" aria-live="polite" hidden'));
ck('answering, adding a receipt and reading a photo each check the token and have their own allowance, apart from asking for money',
   preg_match("/'answer'\) \{\s*av_require_write\(\\\$uid, 'cfis_answer'/", $smEp) === 1
   && preg_match("/'receipt'\) \{\s*av_require_write\(\\\$uid, 'cfis_receipt'/", $smEp) === 1
   && preg_match("/'read_receipt'\) \{\s*av_require_write\(\\\$uid, 'cfis_read_receipt', 10,/", $smEp) === 1
   && strpos($smEp, "'read_receipt'") < strpos($smEp, "av_require_write(\$uid, 'cfis_request'"));

/* ── Leave nothing behind for the next file ────────────────────────── */

CfisRequests::$transport = null;
foreach ($smEnvBefore as $smK => $smV) putenv($smV === false ? $smK : $smK . '=' . $smV);
@array_map('unlink', glob($smPrivate . '/cfis-receipts/*') ?: []);
@rmdir($smPrivate . '/cfis-receipts'); @unlink($smPrivate . '/cfis-banks.json'); @rmdir($smPrivate);
