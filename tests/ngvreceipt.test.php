<?php
/**
 * tests/ngvreceipt.test.php — receipts for recorded payments.
 *
 * The gap this closes: a coordinator takes ₦5,000 in cash, types it into the
 * console, and the payer walks away with nothing. A transfer at least leaves a
 * bank record on their side; cash leaves them with no evidence at all, and the
 * only copy of the fact lives in a database they cannot read.
 *
 * What the tests pin:
 *
 *   • A receipt is DERIVED from the payment row — number, code and void state
 *     all. There is no receipts table to drift out of step with the payment it
 *     describes, and the number can never point at the wrong payment.
 *   • Only money RECEIVED gets one. A waiver is the programme deciding not to
 *     ask; receipting it would tell somebody they had paid money they never
 *     handed over.
 *   • VOIDING is the case that matters. Somebody holding a receipt for a
 *     reversed payment believes they have paid, so the receipt says cancelled
 *     and the void emails them to say so.
 *   • The link verifies in constant time, and a wrong code reveals nothing —
 *     not even whether that payment exists.
 *   • The public page shows ONE payment. Not the balance, not the arrears, not
 *     the fines: anybody holding the link sees everything on it.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

$rcReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_payments', 'ngv_charges', 'ngv_damages', 'ngv_participants'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};
$rcPerson = static function (int $id, string $name, string $email = 'x@example.test'): void {
    NgvDb::pdo()->prepare("INSERT INTO ngv_participants (member_id,name,email,cohort,status,start_date,created_at,updated_at)
                           VALUES (?,?,?,'2026 Alpha','active','2026-03-01','2026-03-01','2026-03-01')")
        ->execute([$id, $name, $email]);
};

/* ══ Issued on payment, derived from the row ═══════════════════════════════ */

$rcReset();
$rcPerson(401, 'Ada Obi', 'ada@example.test');
NgvLedger::accrueParticipant(NgvMember::participant(401), '2026-09-07');

$pay = NgvLedger::payment(401, 'membership', 10000, ['method' => 'cash', 'reference' => 'Front desk'], 1);
ck('ngv receipt: recording a payment issues one, and names it in the response',
   !empty($pay['ok']) && is_array($pay['receipt'] ?? null)
   && preg_match('~^NGV/\d{4}/\d{6}$~', (string) $pay['receipt']['no']) === 1);

$r = NgvLedger::receiptFor((int) $pay['id']);
ck('ngv receipt: the number is derived from the payment row, so it cannot point elsewhere',
   $r['no'] === 'NGV/' . substr((string) $r['paidOn'], 0, 4) . '/' . str_pad((string) $pay['id'], 6, '0', STR_PAD_LEFT));
ck('ngv receipt: it carries the facts of that one payment and nothing computed',
   (int) $r['amount'] === 10000 && $r['line'] === 'membership'
   && $r['lineLabel'] === 'Membership fee' && $r['method'] === 'cash'
   && $r['reference'] === 'Front desk' && $r['name'] === 'Ada Obi' && !$r['void']);
ck('ngv receipt: the send is stamped, however the mailer fared', $r['issuedAt'] !== '');

/* The whole reason there is no receipts table: a second copy of the amount is a
   second place for the amount to be wrong. Change the payment, and the receipt
   changes with it rather than disagreeing. */
NgvDb::pdo()->prepare('UPDATE ngv_payments SET amount = 12000 WHERE id = ?')->execute([(int) $pay['id']]);
ck('ngv receipt: it is a view of the payment, not a copy that can drift',
   (int) NgvLedger::receiptFor((int) $pay['id'])['amount'] === 12000);
NgvDb::pdo()->prepare('UPDATE ngv_payments SET amount = 10000 WHERE id = ?')->execute([(int) $pay['id']]);

/* ══ Verification ══════════════════════════════════════════════════════════ */

$code = $r['code'];
ck('ngv receipt: the right code verifies',
   NgvLedger::receiptForVerify((int) $pay['id'], $code) !== null);
ck('ngv receipt: …case-insensitively, because people retype it off paper',
   NgvLedger::receiptForVerify((int) $pay['id'], strtolower($code)) !== null
   && NgvLedger::receiptForVerify((int) $pay['id'], ' ' . $code . ' ') !== null);
ck('ngv receipt: a tampered code reveals nothing',
   NgvLedger::receiptForVerify((int) $pay['id'], 'NGVR-0000000000') === null
   && NgvLedger::receiptForVerify((int) $pay['id'], '') === null);
ck('ngv receipt: a code from one payment does not open another', (function () {
    $other = NgvLedger::payment(401, 'commitment', 1000, ['period' => '2026-08'], 1);
    $mine = NgvLedger::receiptFor((int) $other['id'])['code'];
    return NgvLedger::receiptForVerify((int) $other['id'], $mine) !== null
        && NgvLedger::receiptForVerify((int) $other['id'] + 1000, $mine) === null;
})());
ck('ngv receipt: an id that does not exist verifies to nothing',
   NgvLedger::receiptForVerify(987654, NgvLedger::receiptCode(987654)) === null);
/* Constant-time comparison, not `==`. A receipt code is a bearer credential for
   one person's financial record. */
ck('ngv receipt: the code is compared in constant time',
   strpos((string) @file_get_contents(AV_ROOT . '/lib/NgvLedger.php'), 'hash_equals(self::receiptCode') !== false);

/* ══ Only money received ═══════════════════════════════════════════════════ */

$wv = NgvLedger::waive(401, 'commitment', 1000, 'Difficult month', 1);
ck('ngv receipt: a waiver gets none — it is not money anybody handed over',
   !empty($wv['ok']) && NgvLedger::receiptFor((int) $wv['id']) === null);
$wo = NgvLedger::writeOff(401, 'commitment', 1000, 'Withdrew', 1);
ck('ngv receipt: nor does a write-off',
   !empty($wo['ok']) && NgvLedger::receiptFor((int) $wo['id']) === null);
ck('ngv receipt: and neither turns up in the list of somebody\'s receipts', (function () {
    foreach (NgvLedger::receiptsFor(401) as $rc) { if ($rc['line'] === '' ) return false; }
    return count(NgvLedger::receiptsFor(401)) === 2;      // the two real payments
})());
ck('ngv receipt: asking for one on a waiver says why it cannot have one',
   empty(NgvLedger::sendReceipt((int) $wv['id'])['ok']));

/* ══ The backlog case ══════════════════════════════════════════════════════ */

$rcReset();
$rcPerson(402, 'Bode Ade', 'bode@example.test');
$back = NgvLedger::payment(402, 'membership', 10000, ['receipt' => false], 1);
ck('ngv receipt: the email can be suppressed for a backlog of historic payments',
   !empty($back['ok']) && ($back['receipt'] ?? null) === null);
$still = NgvLedger::receiptFor((int) $back['id']);
ck('ngv receipt: …and the receipt still EXISTS, just unsent',
   $still !== null && $still['issuedAt'] === '' && (int) $still['amount'] === 10000);
ck('ngv receipt: it can be sent later, and the stamp follows',
   !empty(NgvLedger::sendReceipt((int) $back['id'])['ok'])
   && NgvLedger::receiptFor((int) $back['id'])['issuedAt'] !== '');

/* ══ No email address ══════════════════════════════════════════════════════ */

$rcReset();
$rcPerson(403, 'Chidi Eze', '');
$noMail = NgvLedger::payment(403, 'membership', 5000, [], 1);
ck('ngv receipt: a payment records fine when there is no address to send to',
   !empty($noMail['ok']) && (int) NgvLedger::balance(403)['received'] === 5000);
$attempt = NgvLedger::sendReceipt((int) $noMail['id']);
ck('ngv receipt: the send says why it could not, and points at printing instead',
   empty($attempt['ok']) && strpos((string) $attempt['error'], 'printed') !== false);
ck('ngv receipt: …but the receipt itself is still addressable and printable',
   NgvLedger::receiptFor((int) $noMail['id']) !== null);

/* ══ Voiding — the case that matters ═══════════════════════════════════════ */

$rcReset();
$rcPerson(404, 'Dami Ola', 'dami@example.test');
NgvLedger::accrueParticipant(NgvMember::participant(404), '2026-09-07');
$dup = NgvLedger::payment(404, 'membership', 10000, ['method' => 'transfer'], 1);
$before = NgvLedger::receiptFor((int) $dup['id']);
$void = NgvLedger::void('credit', (int) $dup['id'], 'Entered twice', 1);
ck('ngv receipt: voiding a receipted payment emails the cancellation',
   !empty($void['ok']) && !empty($void['receiptCancelled']));
$after = NgvLedger::receiptFor((int) $dup['id']);
ck('ngv receipt: the receipt stays addressable and says cancelled, with the reason',
   $after !== null && $after['void'] === true && $after['voidReason'] === 'Entered twice'
   && $after['no'] === $before['no']);
ck('ngv receipt: …and its link still verifies, because somebody is holding it',
   NgvLedger::receiptForVerify((int) $dup['id'], $after['code']) !== null);
ck('ngv receipt: an unsent receipt does not email a cancellation nobody was told about', (function () {
    $p = NgvLedger::payment(404, 'commitment', 1000, ['receipt' => false], 1);
    $v = NgvLedger::void('credit', (int) $p['id'], 'Wrong person', 1);
    return !empty($v['ok']) && empty($v['receiptCancelled']);
})());

/* ══ The public page ═══════════════════════════════════════════════════════
 * Rendered for real, in all three states. A receipt nobody can open is not a
 * receipt, and the failure state is the one most likely to rot unnoticed.
 */

$rcReset();
$rcPerson(405, 'Efe Uche', 'efe@example.test');
NgvLedger::accrueParticipant(NgvMember::participant(405), '2026-09-07');
$good = NgvLedger::payment(405, 'membership', 10000, ['method' => 'cash'], 1);
$goodRc = NgvLedger::receiptFor((int) $good['id']);
$bad = NgvLedger::payment(405, 'commitment', 2000, ['period' => '2026-08'], 1);
NgvLedger::void('credit', (int) $bad['id'], 'Recorded against the wrong person', 1);
$badRc = NgvLedger::receiptFor((int) $bad['id']);

$render = static function (int $id, string $code): string {
    $keep = $_GET;
    $_GET = ['id' => (string) $id, 'c' => $code];
    ob_start();
    try { require AV_ROOT . '/academy/ngv/receipt.php'; } catch (Throwable $e) { /* reported by the assertion */ }
    $html = (string) ob_get_clean();
    $_GET = $keep;
    return $html;
};

$okHtml = $render((int) $good['id'], $goodRc['code']);
ck('ngv receipt page: a valid link renders the receipt, verified',
   strpos($okHtml, 'Verified receipt') !== false
   && strpos($okHtml, $goodRc['no']) !== false
   && strpos($okHtml, '10,000') !== false
   && strpos($okHtml, 'Efe Uche') !== false);
ck('ngv receipt page: it is printable without a PDF library',
   strpos($okHtml, '@media print') !== false && strpos($okHtml, 'window.print()') !== false);
ck('ngv receipt page: it is not indexable and not cacheable by a proxy',
   strpos($okHtml, 'noindex') !== false);

/* The disclosure boundary. Anybody holding the link sees the whole page, so it
   carries ONE payment — never what is still owed. */
ck('ngv receipt page: it shows one payment and never the balance or arrears',
   strpos($okHtml, 'outstanding') === false
   && strpos($okHtml, 'Outstanding') === false
   && strpos($okHtml, 'arrears') === false
   && strpos($okHtml, '2,000') === false);          // the other payment on this account

$badHtml = $render((int) $bad['id'], $badRc['code']);
ck('ngv receipt page: a cancelled receipt still renders, loudly marked',
   strpos($badHtml, 'cancelled') !== false
   && strpos($badHtml, 'Recorded against the wrong person') !== false
   && strpos($badHtml, 'Verified receipt') === false);

$tamperHtml = $render((int) $good['id'], 'NGVR-DEADBEEF00');
ck('ngv receipt page: a tampered link verifies nothing and leaks nothing',
   strpos($tamperHtml, 'not verified') !== false
   && strpos($tamperHtml, 'Efe Uche') === false
   && strpos($tamperHtml, '10,000') === false
   && strpos($tamperHtml, $goodRc['no']) === false);
$emptyHtml = $render(0, '');
ck('ngv receipt page: so does a bare, argument-free hit',
   strpos($emptyHtml, 'not verified') !== false);

/* ── Guessing is throttled; reading is not ─────────────────────────────────
 * The code is 40 bits of HMAC, but this endpoint is unauthenticated and what
 * sits behind it is somebody's financial record. Only FAILURES consume a token:
 * throttling a valid code would lock somebody out of their own proof of payment
 * to slow an attacker down, which protects the wrong person. */
$rlDir = AV_ROOT . '/db/cache/rl';
$rlClear = static function () use ($rlDir): void {
    foreach ((array) @glob($rlDir . '/ngv_receipt_*.json') as $f) @unlink($f);
};
$rlClear();
$hits = 0;
for ($i = 0; $i < 25; $i++) {
    if (strpos($render((int) $good['id'], 'NGVR-000000000' . ($i % 10)), 'Too many attempts') !== false) { $hits++; }
}
ck('ngv receipt page: repeated guessing is throttled', $hits > 0);
ck('ngv receipt page: …but a valid code is never throttled, even right after',
   strpos($render((int) $good['id'], $goodRc['code']), 'Verified receipt') !== false);
$rlClear();

/* ══ The statement quotes them ═════════════════════════════════════════════ */

$body = strip_tags(str_replace('<br>', "\n", implode("\n", NgvLedger::statementRows(405)['rows'])));
ck('ngv receipt: the statement lists payments received with their receipt numbers',
   strpos($body, 'Payments received') !== false && strpos($body, $goodRc['no']) !== false);
ck('ngv receipt: …and does not list the cancelled one as received',
   strpos($body, $badRc['no']) === false);

/* ══ Bulk back-fill ════════════════════════════════════════════════════════
 *
 * Payments recorded before receipts existed already have numbers and links —
 * nothing needs generating. What was missing was a way to actually send them
 * without pressing a button once per payment.
 *
 * The property that matters most is NOT throughput. It is one email per PERSON:
 * somebody eighteen months into the programme has a membership payment and a
 * dozen commitments behind them, and thirteen emails inside a second reads as
 * something having gone wrong with their account, not as good record-keeping.
 */

$rcReset();
$rcPerson(420, 'Ada Obi', 'ada@example.test');
$rcPerson(421, 'Bode Ade', 'bode@example.test');
$rcPerson(422, 'No Address', '');
for ($i = 1; $i <= 13; $i++) {
    NgvLedger::payment(420, 'commitment', 1000,
        ['period' => '2026-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'receipt' => false], 1);
}
NgvLedger::payment(421, 'membership', 10000, ['receipt' => false], 1);
for ($i = 1; $i <= 4; $i++) NgvLedger::payment(422, 'commitment', 1000, ['receipt' => false], 1);
$dead = NgvLedger::payment(421, 'commitment', 1000, ['receipt' => false], 1);
NgvLedger::void('credit', (int) $dead['id'], 'Entered twice', 1);
NgvLedger::waive(420, 'commitment', 500, 'Short month', 1);

$pv = NgvLedger::backfillReceipts(25, true);
ck('ngv backfill: the preview counts what is waiting, and who it belongs to',
   (int) $pv['sendable'] === 2 && (int) $pv['sendablePayments'] === 14
   && count($pv['who']) === 2);
/* Work and a data problem are different things, and one number would hide both. */
ck('ngv backfill: somebody with no address is counted apart from the sendable queue',
   (int) $pv['noEmail'] === 1 && (int) $pv['noEmailPayments'] === 4);
ck('ngv backfill: a voided payment is not in the queue at all',
   (int) $pv['pending'] === 18);          // 13 + 1 + 4 — never the voided one
ck('ngv backfill: previewing sends nothing and stamps nothing', (function () {
    $n = (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE receipt_at <> ''")->fetchColumn();
    NgvLedger::backfillReceipts(25, true);
    return (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE receipt_at <> ''")->fetchColumn() === $n;
})());

$run = NgvLedger::backfillReceipts(25, false);
/* THE assertion. Fourteen payments, two messages. */
ck('ngv backfill: one message per person, not one per payment',
   ((int) $run['sent'] + (int) $run['failed']) === 2 && (int) $run['stamped'] === 14);
ck('ngv backfill: a second run has nothing left to do',
   (int) NgvLedger::backfillReceipts(25, false)['stamped'] === 0);
ck('ngv backfill: the voided payment was never stamped — nobody gets a cancelled receipt back-filled',
   (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE voided = 1 AND receipt_at <> ''")->fetchColumn() === 0);
/* Not stamped, so adding an address later brings them back rather than losing
   them to a "done" flag nobody will ever look at again. */
ck('ngv backfill: somebody with no address stays in the queue instead of being marked done',
   (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE member_id = 422 AND receipt_at = ''")->fetchColumn() === 4);
ck('ngv backfill: …and giving them one puts them back in the sendable queue', (function () {
    NgvDb::pdo()->prepare('UPDATE ngv_participants SET email = ? WHERE member_id = 422')->execute(['late@example.test']);
    $p = NgvLedger::backfillReceipts(25, true);
    return (int) $p['sendable'] === 1 && (int) $p['sendablePayments'] === 4 && (int) $p['noEmail'] === 0;
})());
ck('ngv backfill: a waiver is never in the queue — it is not money anybody handed over',
   strpos(json_encode(NgvLedger::backfillReceipts(25, true)), '"sendablePayments":4') !== false);

/* The queue is a column, not a cursor: interrupt it and it resumes exactly. */
$rcReset();
$rcPerson(430, 'Chidi Eze', 'chidi@example.test');
$rcPerson(431, 'Dami Ola', 'dami@example.test');
$rcPerson(432, 'Efe Uche', 'efe@example.test');
foreach ([430, 431, 432] as $mid) NgvLedger::payment($mid, 'membership', 10000, ['receipt' => false], 1);
$first = NgvLedger::backfillReceipts(1, false);
ck('ngv backfill: a bounded run does one person and says how many are left',
   ((int) $first['sent'] + (int) $first['failed']) === 1 && (int) $first['remaining'] === 2);
NgvLedger::backfillReceipts(1, false);
$last = NgvLedger::backfillReceipts(5, false);
ck('ngv backfill: pressing again resumes where it stopped, with nothing skipped',
   (int) $last['stamped'] === 1
   && (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE receipt_at = ''")->fetchColumn() === 0);
ck('ngv backfill: and a payment already receipted is never sent twice',
   (int) NgvLedger::backfillReceipts(25, true)['pending'] === 0);

/* ══ Photos on a damage record ═════════════════════════════════════════════ */

$rcReset();
$rcPerson(440, 'Grace Umeh', 'grace@example.test');
$dmg = NgvDamage::report(440, ['item' => 'Laptop screen', 'severity' => 'major',
    'description' => 'Knocked off the desk while packing up.'], 0, true);
$tmp = sys_get_temp_dir() . '/av-photo-' . getmypid();
@mkdir($tmp);
$png = $tmp . '/shot.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$evil = $tmp . '/evil.jpg';
file_put_contents($evil, "<?php echo 'pwned'; ?>");
$txt = $tmp . '/notes.txt';
file_put_contents($txt, 'this is not a photo at all');
$asUpload = static fn(string $path, string $name): array =>
    ['tmp_name' => $path, 'name' => $name, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];

$p1 = NgvDamage::addPhotos((int) $dmg['id'], $asUpload($png, 'shot.png'), 1);
ck('ngv damage photo: a real image attaches and is listed on the record',
   !empty($p1['ok']) && count(NgvDamage::get((int) $dmg['id'])['photos']) === 1);

/* The type comes from the FILE'S OWN BYTES, never from the name or the
   browser's claimed type — both are attacker-supplied, and a .php named .jpg
   landing in a web-served uploads directory is the whole reason this matters. */
$pEvil = NgvDamage::addPhotos((int) $dmg['id'], $asUpload($evil, 'evil.jpg'), 1);
ck('ngv damage photo: a PHP file named .jpg is refused on its sniffed type',
   empty($pEvil['ok']) && count(NgvDamage::get((int) $dmg['id'])['photos']) === 1);
ck('ngv damage photo: so is anything else that is not an image',
   empty(NgvDamage::addPhotos((int) $dmg['id'], $asUpload($txt, 'notes.txt'), 1)['ok']));
ck('ngv damage photo: an over-size file is refused with the limit named',
   (function () use ($dmg, $png) {
       $r = NgvDamage::addPhotos((int) $dmg['id'],
           ['tmp_name' => $png, 'name' => 's.png', 'error' => UPLOAD_ERR_OK, 'size' => 99999999], 1);
       return empty($r['ok']) && strpos((string) $r['error'], 'MB') !== false;
   })());

for ($i = 0; $i < 5; $i++) NgvDamage::addPhotos((int) $dmg['id'], $asUpload($png, 's.png'), 1);
ck('ngv damage photo: the cap holds across separate calls, not just within one',
   count(NgvDamage::get((int) $dmg['id'])['photos']) === NgvDamage::PHOTOS_MAX);
ck('ngv damage photo: a full record says so rather than silently dropping the file',
   strpos((string) NgvDamage::addPhotos((int) $dmg['id'], $asUpload($png, 's.png'), 1)['error'], 'already has') !== false);

/* photosOf() reads a raw row and a shaped one. Handling only the raw form made
   the cap look like a one-photo overwrite, because every shaped caller got []. */
ck('ngv damage photo: the reader handles a raw row, a shaped one, and rubbish',
   count(NgvDamage::photosOf(['photos' => '["/a.png","/b.png"]'])) === 2
   && count(NgvDamage::photosOf(['photos' => ['/a.png', '/b.png']])) === 2
   && NgvDamage::photosOf(['photos' => '']) === []
   && NgvDamage::photosOf(['photos' => 'not json']) === []
   && NgvDamage::photosOf([]) === []);
ck('ngv damage photo: attaching one is not a way to change any money',
   (int) NgvLedger::balance(440)['charged'] === 0);

$dashSrc2 = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
/* A signed-in member could otherwise attach a picture to somebody else's
   incident by editing one number in the form. */
ck('ngv damage photo: the member route attaches only onto their own record',
   strpos($dashSrc2, "(int) \$d['member_id'] !== \$uid") !== false);

foreach ([$png, $evil, $txt] as $f) @unlink($f);
@rmdir($tmp);

/* ══ What must never happen ════════════════════════════════════════════════ */

$dashSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/dashboard.php');
ck('ngv receipt: a member\'s dashboard reads receipts but cannot issue or cancel one',
   strpos($dashSrc, 'NgvLedger::sendReceipt') === false
   && strpos($dashSrc, 'NgvLedger::receiptsFor') !== false);

$pageSrc = (string) @file_get_contents(AV_ROOT . '/academy/ngv/receipt.php');
$pageCode = (string) preg_replace('~/\*.*?\*/~s', '', $pageSrc);      // strip the docblock
ck('ngv receipt: the public page only ever reads, through the verifying accessor',
   substr_count($pageCode, 'NgvLedger::') === 1
   && strpos($pageCode, 'NgvLedger::receiptForVerify') !== false);

/* It renders somebody's name, a reference and a staff note. Every one of those is
   attacker-influenced somewhere upstream — a participant names the damaged item,
   a coordinator types the bank reference — so every STRING the page prints goes
   through the escaper. Checked by walking the echo tags rather than by one
   regex over the file, because `<?= $e($r['no']) ?>` and `<?= $r['void'] ? …`
   look identical to a pattern that only knows about `$r[`. */
$mustEscape = ['no', 'code', 'name', 'cohort', 'lineLabel', 'period', 'method', 'reference', 'note', 'voidReason'];
$unescaped = [];
preg_match_all('/<\?=(.*?)\?>/s', $pageCode, $echoes);
foreach ($echoes[1] as $expr) {
    if (strpos($expr, '$e(') !== false) continue;             // escaped, fine
    foreach ($mustEscape as $f) {
        if (strpos($expr, "\$r['" . $f . "']") !== false) $unescaped[] = $f . ' → ' . trim($expr);
    }
}
ck('ngv receipt: every string it prints is escaped', $unescaped === []);
ck('ngv receipt: …and the money it prints is cast, not echoed raw',
   strpos($pageCode, "number_format((int) \$r['amount'])") !== false);

$rcReset();
