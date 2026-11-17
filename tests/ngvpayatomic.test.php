<?php
/**
 * tests/ngvpayatomic.test.php — an online payment posts in full or not at all.
 *
 * payOnline() split a payment across lines and posted each line on its own.
 * A line whose insert failed was skipped with `continue`; the others posted,
 * the method still answered ok, and the reference stayed claimed, so every
 * retry was a "duplicate" and the rest of the money was never recorded.
 *
 * A trigger makes the second line's insert fail, so the failure lands after
 * the first line has posted. That is the case the old code got wrong.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

if (NgvDb::driver() !== 'sqlite') { ck('ngv pay atomic: (sqlite only, skipped)', true); return; }

$paReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_online_refs'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    try { Database::metaSet('ngv_fees', ''); } catch (Throwable $e) {}
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};
$paReset();
NgvMember::ensureParticipant(802, ['name' => 'Bola', 'email' => 'bola@example.test']);
NgvLedger::postCharge(802, 'membership', 5000, '2026-01');
NgvLedger::postCharge(802, 'commitment', 8000, '2026-02');
$due = NgvLedger::balance(802)['due'];
ck('ngv pay atomic: the fixture owes on two lines', (int) ($due['membership'] ?? 0) > 0 && (int) ($due['commitment'] ?? 0) > 0);

$pdo = NgvDb::pdo();
$rows = static fn(string $ref): int => (int) $pdo->query('SELECT COUNT(*) FROM ngv_payments WHERE reference = ' . $pdo->quote($ref))->fetchColumn();
$claimed = static fn(string $ref): int => (int) $pdo->query('SELECT COUNT(*) FROM ngv_online_refs WHERE reference = ' . $pdo->quote($ref))->fetchColumn();

$pdo->exec("CREATE TEMP TRIGGER ngv_pay_fail BEFORE INSERT ON ngv_payments
            WHEN NEW.kind = 'commitment' BEGIN SELECT RAISE(ABORT, 'forced'); END");
$r = NgvLedger::payOnline(802, 'ATOM-1', 13000, ['receipt' => false]);
ck('ngv pay atomic: a line that fails fails the payment', empty($r['ok']));
ck('ngv pay atomic: …and nothing of it is left posted', $rows('ATOM-1') === 0);
ck('ngv pay atomic: …and the claim is released for a retry', $claimed('ATOM-1') === 0);
ck('ngv pay atomic: …and no transaction is left open', !$pdo->inTransaction());

$pdo->exec('DROP TRIGGER ngv_pay_fail');
$r2 = NgvLedger::payOnline(802, 'ATOM-1', 13000, ['receipt' => false]);
$sum = (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ngv_payments WHERE reference = 'ATOM-1' AND voided = 0")->fetchColumn();
ck('ngv pay atomic: the retry records the whole payment', !empty($r2['ok']) && empty($r2['duplicate']) && $sum === 13000);
ck('ngv pay atomic: …split across the lines it pays', $rows('ATOM-1') >= 2);

/* The return page: NGN only (blank only from CACENTRE), and throttled per member. */
$paSrc = (string) file_get_contents(dirname(__DIR__) . '/academy/ngv/pay.php');
ck('ngv pay: the return page no longer takes a blank currency from any route',
   !str_contains($paSrc, "['NGN', ''], true)") && str_contains($paSrc, "=== '' && (string) (\$v['provider'] ?? '') === 'cfis'"));
ck('ngv pay: the return page is rate-limited per member before it asks the provider',
   strpos($paSrc, "av_rate_ok_for('ngv_pay_return'") !== false
   && strpos($paSrc, "av_rate_ok_for('ngv_pay_return'") < strpos($paSrc, 'Payments::verifyAny($ref)'));
