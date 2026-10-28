<?php
/**
 * tests/ngvwebhook.test.php — the Paystack webhook posts NGV fees in naira only.
 *
 * process-donation.php credited amount/100 to an NGV participant's ledger for
 * any charge.success carrying ngv_member, whatever the currency — so a USD
 * charge of $130 (13000 cents) became ₦130 paid, while academy/ngv/pay.php,
 * the payer's own return, refused anything but NGN. Driven over HTTP here
 * against the dev server, signed as Paystack signs, because the webhook is a
 * script and its behaviour is the response and the ledger, not its source.
 */
declare(strict_types=1);

$whReset = static function (): void {
    $pdo = NgvDb::pdo();
    foreach (['ngv_charges', 'ngv_payments', 'ngv_participants', 'ngv_online_refs'] as $t) {
        try { $pdo->exec('DELETE FROM ' . $t); } catch (Throwable $e) {}
    }
    $c = new ReflectionProperty('NgvLedger', 'cache'); $c->setAccessible(true); $c->setValue(null, null);
    NgvLedger::saveSettings(['enabled' => true, 'accrueFrom' => '2026-01-01'], 'test');
};
$whReset();
NgvMember::ensureParticipant(802, ['name' => 'Bisi', 'email' => 'bisi@example.test']);
$whCredits = static fn(string $ref): int => (int) NgvDb::pdo()->query('SELECT COUNT(*) FROM ngv_payments WHERE reference = ' . NgvDb::pdo()->quote($ref))->fetchColumn();

$whKey = 'sk_test_webhook_' . bin2hex(random_bytes(8));
$whPort = 0;
if ($whProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($whProbe, false); $whPort = (int) substr($n, strrpos($n, ':') + 1); fclose($whProbe);
}
$whEnv = getenv(); $whEnv['AV_PAYSTACK_SK'] = $whKey; $whEnv['APP_ENV'] = 'dev';
$whLog = tempnam(sys_get_temp_dir(), 'avwh');
$whProc = $whPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $whPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $whLog, 'a'], 2 => ['file', $whLog, 'a']], $whPipes, AV_ROOT, $whEnv) : null;
$whUp = false;
if (is_resource($whProc)) {
    for ($i = 0; $i < 100 && !$whUp; $i++) { $c = @fsockopen('127.0.0.1', $whPort, $e1, $e2, 0.2); if ($c) { fclose($c); $whUp = true; } else usleep(50000); }
}
$whPost = static function (array $event) use ($whPort, $whKey): array {
    $raw = json_encode($event);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/json\r\nX-Paystack-Signature: " . hash_hmac('sha512', $raw, $whKey) . "\r\n",
        'content' => $raw]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $whPort . '/process-donation.php', false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $m)) $code = (int) $m[1];
    return [$code, json_decode($body, true) ?: []];
};

if (!$whUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    $ev = static fn(string $ref, string $cur) => ['event' => 'charge.success', 'data' => [
        'reference' => $ref, 'amount' => 13000, 'currency' => $cur, 'status' => 'success',
        'customer' => ['email' => 'bisi@example.test'], 'metadata' => ['ngv_member' => 802]]];

    [$code, $body] = $whPost($ev('WH-USD-1', 'USD'));
    ck('ngv webhook: a USD charge is answered 200, so Paystack does not retry for days', $code === 200);
    ck('ngv webhook: …and says it was not recorded, and why', ($body['recorded'] ?? null) === false && ($body['reason'] ?? '') === 'currency');
    ck('ngv webhook: …and posts nothing to the participant\'s ledger', $whCredits('WH-USD-1') === 0);

    [$code, $body] = $whPost($ev('WH-NONE-1', ''));
    ck('ngv webhook: a charge with no currency is not assumed to be naira', $code === 200 && $whCredits('WH-NONE-1') === 0);

    [$code, $body] = $whPost($ev('WH-NGN-1', 'NGN'));
    ck('ngv webhook: a naira charge is still posted', $code === 200 && ($body['received'] ?? false) === true && $whCredits('WH-NGN-1') === 1);

    /* An unsigned or wrongly signed call is still nothing. */
    $raw = json_encode($ev('WH-FORGED-1', 'NGN'));
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/json\r\nX-Paystack-Signature: " . hash_hmac('sha512', $raw, 'wrong') . "\r\n", 'content' => $raw]]);
    @file_get_contents('http://127.0.0.1:' . $whPort . '/process-donation.php', false, $ctx);
    ck('ngv webhook: a forged signature posts nothing', $whCredits('WH-FORGED-1') === 0);
}
if (is_resource($whProc)) { proc_terminate($whProc); proc_close($whProc); }
@unlink($whLog);
$whReset();
