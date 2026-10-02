<?php
/**
 * tests/offlinepayments.test.php — offline payments are credited only once
 * their receipt is verified.
 *
 *   • No evidence, no payment. A receipt can be used once — by file, and by
 *     the reference printed on it.
 *   • The reader (Gemini vision in production, a stub here) only READS; the
 *     checks are code: amount, payee, date, reference, reuse, editing, legibility.
 *   • A pass credits through the path that owns the money — dues through
 *     Membership::grant, NGV through the ledger — once.
 *   • A fail is held with every reason; staff can reject; only a Super Admin
 *     can approve against the check, with a reason, and never a reused receipt.
 *   • Money cannot be credited to dues any other way.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$opPdo = Database::pdo();
/* The evidence store, in a throwaway directory — never the project's db/private. */
$opPrev = getenv('AV_PRIVATE_DIR');
$opDir = sys_get_temp_dir() . '/av-test-private-' . getmypid();
@mkdir($opDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $opDir);
OfflinePayments::ensure(); Membership::ensure();
$opPdo->exec('DELETE FROM offline_payments');
$opPdo->exec("DELETE FROM memberships WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@op.test')");
$opPdo->exec("DELETE FROM payments WHERE user_id IN (SELECT id FROM lms_users WHERE email LIKE '%@op.test')");
$opPdo->exec("DELETE FROM lms_users WHERE email LIKE '%@op.test'");
$opPdo->prepare("INSERT INTO lms_users (name, email, password_hash, role) VALUES ('Olu Payer', 'olu@op.test', 'x', 'member'), ('Nne Vanguard', 'nne@op.test', 'x', 'member')")->execute();
$olu = (int) $opPdo->query("SELECT id FROM lms_users WHERE email = 'olu@op.test'")->fetchColumn();
$nne = (int) $opPdo->query("SELECT id FROM lms_users WHERE email = 'nne@op.test'")->fetchColumn();

/* The reader stub: what the receipt "says" is set per test. */
$says = [];
$calls = 0;
OfflinePayments::$reader = function (string $bytes, string $mime) use (&$says, &$calls): array { $calls++; return ['ok' => true, 'data' => $says]; };
$good = fn(int $amount, string $ref = 'TRF8812', ?string $day = null) => ['is_payment_evidence' => true, 'amount' => $amount, 'currency' => 'NGN',
    'date' => $day ?? gmdate('Y-m-d'), 'reference' => $ref, 'payee_name' => 'AFROVANGUARD FOUNDATION', 'payee_account' => '0123456789',
    'payer_name' => 'OLU PAYER', 'bank' => 'GTBank', 'status' => 'successful', 'tampering_signs' => [], 'confidence' => 'high'];
$img = fn(string $seed) => "\xFF\xD8\xFF\xE0" . str_repeat($seed, 64);   // a JPEG by its magic bytes
$dues = fn(int $amount = 12000, int $months = 12) => ['purpose' => 'dues', 'months' => $months, 'amount_ngn' => $amount, 'method' => 'transfer', 'paid_on' => gmdate('Y-m-d')];

/* ── No evidence, no payment ─────────────────────────────────────────── */
ck('Offline: a payment without a receipt is refused', (OfflinePayments::submit($olu, $dues(), '', '', 'olu')['code'] ?? '') === 'evidence_required');
ck('Offline: dues money cannot be credited without a verified offline payment',
    (Membership::grant($olu, ['months' => 12, 'amount_ngn' => 12000, 'method' => 'cash', 'actor' => 'x'])['code'] ?? '') === 'evidence_required');
ck('Offline: less than the dues cost is refused before anything is read', (OfflinePayments::submit($olu, $dues(5000), $img('a'), 'image/jpeg', 'olu')['code'] ?? '') === 'short' && $calls === 0);
ck('Offline: only a photo or a PDF', !OfflinePayments::submit($olu, $dues(), 'plain text', 'text/plain', 'olu')['ok']);

/* ── A good receipt is credited, once ────────────────────────────────── */
$says = $good(12000);
$r = OfflinePayments::submit($olu, $dues(), $img('a'), 'image/jpeg', 'olu');
ck('Offline: a receipt that passes every check is credited', $r['ok'] && $r['status'] === 'verified' && Membership::summary($olu)['state'] === 'current');
ck('Offline: …through dues, with the money recorded once', Membership::summary($olu)['total_paid_ngn'] === 12000
    && (int) $opPdo->query("SELECT COUNT(*) FROM payments WHERE user_id = $olu AND status = 'paid'")->fetchColumn() === 1);
ck('Offline: the evidence is kept privately, never in the web root', !str_starts_with(av_private_path($r['payment']['evidence_file']), AV_ROOT . '/assets')
    && is_file(av_private_path($r['payment']['evidence_file'])) && OfflinePayments::evidence((int) $r['payment']['id']) !== null);
ck('Offline: checking it again credits nothing more', !empty(OfflinePayments::verify((int) $r['payment']['id'])['unchanged'])
    && Membership::summary($olu)['total_paid_ngn'] === 12000);
ck('Offline: the same file cannot be sent twice', (OfflinePayments::submit($olu, $dues(), $img('a'), 'image/jpeg', 'olu')['code'] ?? '') === 'duplicate_evidence');
$says = $good(12000);   // a different photo of the same transfer
$re = OfflinePayments::submit($olu, $dues(), $img('b'), 'image/jpeg', 'olu');
ck('Offline: …nor a different photo of a receipt whose reference has already been credited', $re['status'] === 'held'
    && str_contains(implode(' ', $re['reasons']), 'already been credited'));

/* ── Each check, named when it fails ─────────────────────────────────── */
$held = function (array $reading, array $claim = []) use (&$says, $olu, $dues, $img): array {
    static $n = 0; $n++;
    $says = $reading;
    return OfflinePayments::submit($olu, $claim + $dues(), $img('x' . $n), 'image/jpeg', 'olu');
};
$cases = [
    'not a receipt'  => [['is_payment_evidence' => false] + $good(12000, 'R1'), 'does not look like evidence'],
    'wrong amount'   => [$good(1200, 'R2'), 'not the ₦12,000 claimed'],
    'wrong payee'    => [['payee_name' => 'BOLA STORES', 'payee_account' => '9990001112'] + $good(12000, 'R3'), 'going to Afrovanguard'],
    'too old'        => [$good(12000, 'R4', gmdate('Y-m-d', strtotime('-200 days'))), 'older than'],
    'future'         => [$good(12000, 'R5', gmdate('Y-m-d', strtotime('+10 days'))), 'in the future'],
    'edited'         => [['tampering_signs' => ['the amount digits are a different font']] + $good(12000, 'R6'), 'Possible editing'],
    'illegible'      => [['confidence' => 'low'] + $good(12000, 'R7'), 'too hard to read'],
    'failed transfer'=> [['status' => 'failed'] + $good(12000, 'R8'), 'does not look like evidence'],
];
foreach ($cases as $label => [$reading, $why]) {
    $x = $held($reading);
    ck("Offline: $label — held, not credited, and says why", $x['ok'] && $x['status'] === 'held' && str_contains(implode(' ', $x['reasons']), $why));
}
$x = $held($good(12000, 'R9', gmdate('Y-m-d', strtotime('-10 days'))), ['paid_on' => gmdate('Y-m-d')]);
ck('Offline: a date far from the day the payer gave is held', $x['status'] === 'held' && str_contains(implode(' ', $x['reasons']), 'as given'));
$x = $held($good(12000, 'XYZ123'), ['reference' => 'ABC999']);
ck('Offline: a reference that is not the one given is held', $x['status'] === 'held' && str_contains(implode(' ', $x['reasons']), 'not ABC999'));
ck('Offline: nothing that was held was credited', Membership::summary($olu)['total_paid_ngn'] === 12000);
putenv('AV_OFFLINE_ACCOUNTS=0123456789');
$x = $held(['payee_name' => ''] + $good(12000, 'R10'));
ck('Offline: the payee may be recognised by a configured account number instead of the name', $x['status'] === 'verified');
putenv('AV_OFFLINE_ACCOUNTS');

/* ── When the reader cannot read ─────────────────────────────────────── */
OfflinePayments::$reader = fn() => ['ok' => false, 'error' => 'Receipt reading is unavailable (Gemini is not configured).'];
$down = OfflinePayments::submit($olu, $dues(), $img('down'), 'image/jpeg', 'olu');
ck('Offline: with no reader, a payment is held — never credited unread', $down['status'] === 'held' && str_contains($down['reasons'][0], 'unavailable'));
$did = (int) $down['payment']['id'];
ck('Offline: only a Super Admin can approve it against the check', (OfflinePayments::override($did, 'Seen the bank statement myself', 'admin@x', 'admin')['code'] ?? '') === 'superadmin_only');
ck('Offline: …and only with a written reason', (OfflinePayments::override($did, 'ok', 'boss', 'superadmin')['code'] ?? '') === 'reason_required');
$before = Membership::summary($olu)['total_paid_ngn'];
$ov = OfflinePayments::override($did, 'Checked against the bank statement for the 2nd', 'boss', 'superadmin');
ck('Offline: …and then it is credited, and says it was approved against the check', $ov['status'] === 'verified'
    && Membership::summary($olu)['total_paid_ngn'] === $before + 12000 && str_starts_with((string) $ov['payment']['decision_note'], 'OVERRIDE:'));
ck('Offline: the payer does not see the staff override note', !array_filter(OfflinePayments::forUser($olu), fn($p) => str_contains((string) $p['decision_note'], 'OVERRIDE')));

/* A reused receipt is not overridable. */
OfflinePayments::$reader = function () use (&$says): array { return ['ok' => true, 'data' => $says]; };
$says = $good(12000);   // TRF8812 again
$again = OfflinePayments::submit($olu, $dues(), $img('reuse'), 'image/jpeg', 'olu');
ck('Offline: a receipt that already credited money cannot be approved even by a Super Admin',
    (OfflinePayments::override((int) $again['payment']['id'], 'I am sure this one is different', 'boss', 'superadmin')['code'] ?? '') === 'reused');
ck('Offline: rejecting needs a reason the payer will see', (OfflinePayments::reject((int) $again['payment']['id'], '', 'x')['code'] ?? '') === 'reason_required'
    && OfflinePayments::reject((int) $again['payment']['id'], 'Same transfer as payment 1', 'x')['status'] === 'rejected');
ck('Offline: the payer sees why', (bool) array_filter(OfflinePayments::forUser($olu), fn($p) => $p['status'] === 'rejected' && $p['decision_note'] === 'Same transfer as payment 1'));

/* ── NGV ─────────────────────────────────────────────────────────────── */
NgvMember::ensureParticipant($nne, ['name' => 'Nne Vanguard', 'email' => 'nne@op.test']);
NgvFines::issue($nne, 'late', 1000, '', '', false, 1);
$says = $good(1000, 'NGVTRF1');
$ng = OfflinePayments::submit($nne, ['purpose' => 'ngv', 'amount_ngn' => 1000, 'method' => 'transfer'], $img('ngv'), 'image/jpeg', 'nne');
ck('Offline: an NGV payment that passes is credited to the NGV account', $ng['status'] === 'verified' && (int) NgvLedger::balance($nne)['payable'] === 0);
ck('Offline: …once — the ledger is idempotent on its reference too', NgvLedger::paymentsByReference('OFFLINE-' . $ng['payment']['id']) !== []);
$says = $good(500, 'NGVTRF2');
$st = OfflinePayments::submit($nne, ['purpose' => 'ngv', 'line' => 'fine', 'amount_ngn' => 500, 'method' => 'cash'], $img('ngv2'), 'image/jpeg', 'staff@x', 'staff');
ck('Offline: the office can name the line a payment pays, as the console did', $st['status'] === 'verified'
    && (bool) array_filter(NgvLedger::credits($nne), fn($c) => ($c['kind'] ?? '') === 'fine' && (int) $c['amount'] === 500));
ck('Offline: NGV payments are for participants', !OfflinePayments::submit($olu, ['purpose' => 'ngv', 'amount_ngn' => 100, 'method' => 'cash'], $img('q'), 'image/jpeg', 'olu')['ok']);

/* ── The pure check, on its own ──────────────────────────────────────── */
$c = OfflinePayments::check(['amount_ngn' => 5000, 'paid_on' => '', 'reference' => ''], ['is_payment_evidence' => true, 'amount' => '5,000']);
ck('Check: an amount it cannot parse as a number is not read as one', $c['passed'] === false && isset($c['failed']['amount']));
ck('Check: a foreign currency is not naira', isset(OfflinePayments::check(['amount_ngn' => 50, 'paid_on' => '', 'reference' => ''], ['currency' => 'USD', 'amount' => 50] + $good(50))['failed']['amount']));

OfflinePayments::$reader = null;
foreach (glob($opDir . '/offline-*') ?: [] as $f) @unlink($f);
@rmdir($opDir);
putenv($opPrev === false ? 'AV_PRIVATE_DIR' : 'AV_PRIVATE_DIR=' . $opPrev);
