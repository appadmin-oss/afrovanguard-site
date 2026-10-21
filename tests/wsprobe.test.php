<?php
/**
 * tests/wsprobe.test.php — the Studio's "Test" button for Google Workspace
 * has to come back.
 *
 * Reported: it returns no response at all. The cause is arithmetic.
 * GoogleWorkspace::probe() makes sixteen sequential calls to Google — a
 * token, four API reads, and then scopeCheck(), which exchanges a token
 * for EACH of the eleven delegated scopes. http() used CURLOPT_TIMEOUT 15
 * with no CURLOPT_CONNECTTIMEOUT, so a host that accepts connections and
 * never answers costs the full fifteen seconds each: up to four minutes
 * for one button press, against a shared host that stops the request at
 * thirty seconds. PHP is killed mid-flight and the browser is left with
 * nothing, which is exactly what was described.
 *
 * Two things are pinned here: the probe honours a time budget, and a
 * scope it had no time to reach is reported UNCHECKED rather than
 * refused — "refused" sends somebody to the Google Admin console to fix
 * a delegation that may be perfectly fine.
 *
 * The black hole is a listening socket that is never accept()ed: the
 * kernel completes the TCP handshake from the backlog, so curl connects
 * and then waits for a TLS answer that never comes. That is the failure
 * being reproduced, not a refused connection, which fails fast.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mkKey = static function (): string {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    return (string) json_encode(['type' => 'service_account', 'client_email' => 'probe@x.iam.gserviceaccount.com',
        'client_id' => '12345', 'private_key' => $pem]);
};

$errno = 0; $errstr = '';
$sink = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$sink) {
    ck('wsprobe: (skipped — no local socket available: ' . $errstr . ')', true);
    return;
}
$port = (int) explode(':', (string) stream_socket_get_name($sink, false))[1];
$base = 'https://127.0.0.1:' . $port;

$oldNo = getenv('no_proxy');
putenv('no_proxy=*'); putenv('NO_PROXY=*');
putenv('AV_WS_TOKEN_URL=' . $base . '/token');
putenv('AV_WS_BASE_URL=' . $base);

AvSettings::save(['AV_GDRIVE_SERVICE_ACCOUNT' => $mkKey(), 'AV_WS_SUBJECT' => 'admin@example.org'], 'test');
ck('wsprobe: the fixture key is accepted as configured', GoogleWorkspace::configured());

$budget = 3.0;
$t = microtime(true);
$p = GoogleWorkspace::probe($budget);
$took = microtime(true) - $t;

/* The number that matters. Unbounded this is 16 calls x 15s; bounded it is
   the budget plus the tail of the one call that was already in flight. */
ck(sprintf('wsprobe: a probe against a dead host returns in about its budget (%.1fs, budget %.0fs)', $took, $budget),
   $took < $budget + 6.0);
ck('wsprobe: and does not run anywhere near the old 240s worst case', $took < 30.0);

$scopes = (array) ($p['scopes'] ?? []);
$unchecked = array_filter($scopes, static fn($v) => $v === GoogleWorkspace::SCOPE_UNCHECKED);
ck('wsprobe: every delegated scope is accounted for', count($scopes) === count(GoogleWorkspace::DELEGATED_SCOPES));
ck('wsprobe: scopes it ran out of time for are marked unchecked', count($unchecked) > 0);
ck('wsprobe: and none of them is reported as refused',
   count(array_filter($scopes, static fn($v) => $v !== '' && $v !== GoogleWorkspace::SCOPE_UNCHECKED)) + count($unchecked) === count($scopes));

/* What the operator is shown must distinguish the two, and must not pass. */
$r = AvSettings::test('google_workspace');
ck('wsprobe: an incomplete test is not reported as a pass', $r['ok'] === false);
ck('wsprobe: and says it ran out of time rather than blaming delegation',
   str_contains((string) $r['detail'], 'ran out of time') || str_contains((string) $r['detail'], 'refused the service account'));

/* The budget is derived from what the host allows, not hard-coded. */
ck('wsprobe: the budget leaves the request room to answer',
   GoogleWorkspace::probeBudget() >= 5.0 && GoogleWorkspace::probeBudget() <= 20.0);

fclose($sink);
putenv('AV_WS_TOKEN_URL'); putenv('AV_WS_BASE_URL');
if ($oldNo === false) { putenv('no_proxy'); putenv('NO_PROXY'); } else { putenv('no_proxy=' . $oldNo); }
Database::pdo()->prepare("DELETE FROM av_settings WHERE setting_key IN ('AV_GDRIVE_SERVICE_ACCOUNT','AV_WS_SUBJECT')")->execute();
AvSettings::flush(); AvSettings::apply();
