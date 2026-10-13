<?php
/**
 * tests/payrace.test.php — one payment, one grant.
 *
 * finalizePayment read the row's status, then ran an UNCONDITIONAL
 * UPDATE … SET status='paid' and granted. The Paystack webhook
 * (academy/pay.php), the browser's return and the cron sweep can all arrive
 * for one reference together; each read 'pending', each updated, each granted.
 * A membership renewal extends from the later of now or the paid-through date,
 * so one payment bought two (or three) years.
 */
declare(strict_types=1);

$prPdo = Database::pdo();
$prPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@payrace.test'");
$prPdo->prepare('INSERT INTO lms_users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)')
      ->execute(['Race Payer', 'payer@payrace.test', 'x', 'member', 'active']);
$prUid = (int) $prPdo->lastInsertId();
$prLms = new LmsRepository();
$prCount = static fn(): int => (int) $prPdo->query("SELECT COUNT(*) FROM memberships WHERE user_id = $prUid")->fetchColumn();

/* Sequential: the second call is a no-op that still reports paid. */
$prLms->createPayment($prUid, 'membership', null, 1200000, 'RACE-SEQ-1', 'paystack', 12);
ck('payrace: the first finalise grants', $prLms->finalizePayment('RACE-SEQ-1', 1200000) === true && $prCount() === 1);
ck('payrace: a second finalise of the same reference reports paid', $prLms->finalizePayment('RACE-SEQ-1', 1200000) === true);
ck('payrace: …and grants nothing more', $prCount() === 1);

/* The claim is in the UPDATE itself — the property the race depends on. */
$prSrc = (string) file_get_contents(AV_ROOT . '/lib/LmsRepository.php');
ck('payrace: the paid-marking UPDATE is conditional on not already paid',
    str_contains($prSrc, "UPDATE payments SET status='paid', paid_at=? WHERE reference=? AND status <> 'paid'"));
ck('payrace: only the caller whose UPDATE changed the row grants', str_contains($prSrc, 'if ($st->rowCount() !== 1) {'));

/* Concurrent: separate processes on the same database, released together.
   Several rounds, because a race that is lost once in a while is still lost. */
$prPhp = PHP_BINARY;
$prFix = AV_ROOT . '/tests/fixtures/finalize-race.php';
$prRounds = 4; $prContenders = 6; $prWorst = 0; $prAllPaid = true;
for ($round = 1; $round <= $prRounds; $round++) {
    $ref = 'RACE-PAR-' . $round . '-' . bin2hex(random_bytes(3));
    $prLms->createPayment($prUid, 'membership', null, 1200000, $ref, 'paystack', 12);
    $before = $prCount();
    $at = microtime(true) + 1.2;   // long enough for every child to bootstrap first
    $procs = [];
    for ($i = 0; $i < $prContenders; $i++) {
        $pipes = [];
        $p = proc_open([$prPhp, $prFix, $ref, '1200000', sprintf('%.6F', $at)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    foreach ($procs as [$p, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        if (trim($out) !== 'ok') $prAllPaid = false;
    }
    $prWorst = max($prWorst, $prCount() - $before);
}
ck("payrace: $prContenders concurrent finalises of one reference grant exactly once (worst of $prRounds rounds: $prWorst)", $prWorst === 1);
ck('payrace: …and every contender is told the payment is paid', $prAllPaid);
$prPdo->exec("DELETE FROM memberships WHERE user_id = $prUid");
$prPdo->exec("DELETE FROM payments WHERE user_id = $prUid");
