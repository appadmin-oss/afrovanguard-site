<?php
/**
 * tests/ngvpay.test.php — an online NGV payment is credited once, to the
 * member it was for.
 *
 *   • pay.php credited ANY reference Paystack called paid to whoever was signed
 *     in: a donation counted again as fees, another participant's payment taken.
 *   • payOnline() was check-then-insert, so the webhook and the payer's
 *     redirect arriving together both posted it.
 *   • The webhook answered 200 when it failed, so Paystack never retried.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$pyReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_online_refs'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};
$pyReset();
NgvMember::ensureParticipant(801, ['name' => 'Ada', 'email' => 'ada@example.test']);
$credits = static fn(string $ref): int => (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_payments WHERE reference = " . NgvDb::pdo()->quote($ref))->fetchColumn();

/* The other request got there first: it holds the claim, so this one posts nothing. */
NgvDb::pdo()->prepare('INSERT INTO ngv_online_refs (reference, member_id) VALUES (?, ?)')->execute(['RACE-1', 801]);
$r = NgvLedger::payOnline(801, 'RACE-1', 13000, ['receipt' => false]);
ck('ngv pay: a reference another request is already posting is answered as a duplicate', !empty($r['duplicate']) && $credits('RACE-1') === 0);

$a = NgvLedger::payOnline(801, 'ONCE-1', 13000, ['receipt' => false]);
$b = NgvLedger::payOnline(801, 'ONCE-1', 13000, ['receipt' => false]);
ck('ngv pay: one payment is credited once, however many times it arrives', !empty($a['ok']) && empty($a['duplicate']) && !empty($b['duplicate']) && $credits('ONCE-1') >= 1);
$once = $credits('ONCE-1');
NgvLedger::payOnline(801, 'ONCE-1', 13000, ['receipt' => false]);
ck('ngv pay: …and a third arrival adds no row', $credits('ONCE-1') === $once);

$f = NgvLedger::payOnline(999999, 'NOBODY-1', 5000, ['receipt' => false]);
$held = (int) NgvDb::pdo()->query("SELECT COUNT(*) FROM ngv_online_refs WHERE reference = 'NOBODY-1'")->fetchColumn();
ck('ngv pay: a payment that could not be posted does not keep its claim, so it can be retried', empty($f['ok']) && $held === 0);

$pay = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/pay.php');
ck('ngv pay: the return page credits only this member\'s NGV payment',
   str_contains($pay, "(int) (\$v['metadata']['ngv_member'] ?? 0) === (int) \$u['id']") && str_contains($pay, "\$state = 'notyours';"));
$pv = (string) file_get_contents(dirname(__DIR__) . '/lib/Payments.php');
ck('ngv pay: verification returns what the payment was for', str_contains($pv, "'metadata' => is_array(\$meta) ? \$meta : [],"));
$wh = (string) file_get_contents(dirname(__DIR__) . '/process-donation.php');
ck('ngv pay: the webhook says 500 when it did not record, so Paystack retries', str_contains($wh, 'http_response_code($ok ? 200 : 500);'));
