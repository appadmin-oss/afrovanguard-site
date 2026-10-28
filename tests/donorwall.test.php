<?php
/**
 * tests/donorwall.test.php — the donor wall is opt-in, and lists donations.
 *
 * It was opt-out: a box saying "keep my name off the donor wall", so a donor
 * who read nothing was published. Worse, a GATEWAY charge with no donate form
 * behind it — dues, a renewal, a course fee — carried no form fields at all,
 * fell through to the payer's own name and amount, and went up on a public
 * page as a donation they never made.
 *
 *   • Only an entry carrying an explicit `listed` is shown. Absence is not
 *     consent, including for entries already in the file.
 *   • The consent travels as its own field (`wall`), set only by the donate
 *     form, so a charge from anywhere else cannot accidentally carry it.
 *   • The campaign totals and donor counts are unaffected: figures, not names.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* A throwaway donations.json — never the deployment's own. */
$dwPrev = getenv('AV_PRIVATE_DIR');
$dwDir = sys_get_temp_dir() . '/av-test-wall-' . getmypid();
@mkdir($dwDir, 0700, true);
putenv('AV_PRIVATE_DIR=' . $dwDir);
Donations::ensure();
Database::pdo()->exec("DELETE FROM donation_refs WHERE reference LIKE 'DW-%'");

$dwTx = static fn(string $ref, array $fields, array $extra = []): array => [
    'reference' => $ref, 'amount' => 500000, 'currency' => 'NGN',
    'customer' => ['email' => 'ada@wall.test', 'first_name' => 'Ada', 'last_name' => 'Giver'],
    'metadata' => ['custom_fields' => array_map(
        static fn($k, $v) => ['display_name' => $k, 'variable_name' => $k, 'value' => $v],
        array_keys($fields), array_values($fields))],
] + $extra;
$dwListed = static function (string $ref): ?bool {
    foreach (Donations::read()['donations'] ?? [] as $d) if (($d['reference'] ?? '') === $ref) return !empty($d['listed']);
    return null;
};

$r = Donations::recordCharge($dwTx('DW-YES', ['campaign' => 'general', 'wall' => 'Yes', 'first_name' => 'Ada', 'last_name' => 'Giver']));
ck('wall: a donor who asked to be listed is listed', !empty($r['new']) && $dwListed('DW-YES') === true);
$r = Donations::recordCharge($dwTx('DW-NO', ['campaign' => 'general', 'wall' => 'No', 'first_name' => 'Ada', 'last_name' => 'Giver']));
ck('wall: a donor who did not ask is not', !empty($r['new']) && $dwListed('DW-NO') === false);
/* The old form's field. It said "keep my name OFF", so its absence used to
   mean "publish me" — it is never a reason to list anybody now. */
$r = Donations::recordCharge($dwTx('DW-OLD', ['campaign' => 'general', 'anonymous' => 'No', 'first_name' => 'Ada', 'last_name' => 'Giver']));
ck('wall: the old opt-out field alone does not list anybody', !empty($r['new']) && $dwListed('DW-OLD') === false);
/* The one that put dues payers on the wall: a charge with no form behind it. */
$r = Donations::recordCharge(['reference' => 'DW-DUES', 'amount' => 1200000, 'currency' => 'NGN',
    'customer' => ['email' => 'member@wall.test', 'first_name' => 'Kemi', 'last_name' => 'Member']]);
ck('wall: a gateway charge with no donate form behind it is never listed', !empty($r['new']) && $dwListed('DW-DUES') === false);

/* The money still counts — the wall is about names, not figures. */
$dwData = Donations::read();
ck('wall: …and the campaign total still carries every gift',
    (float) ($dwData['campaigns']['general']['raised'] ?? 0) >= 5000 * 3);

/* ── The endpoint, over HTTP ──────────────────────────────────────────── */
$dwPort = 0;
if ($dwProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($dwProbe, false); $dwPort = (int) substr($n, strrpos($n, ':') + 1); fclose($dwProbe);
}
$dwEnv = getenv(); $dwEnv['AV_PRIVATE_DIR'] = $dwDir; $dwEnv['APP_ENV'] = 'dev';
$dwLog = tempnam(sys_get_temp_dir(), 'avdw');
$dwProc = $dwPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $dwPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $dwLog, 'a'], 2 => ['file', $dwLog, 'a']], $dwPipes, AV_ROOT, $dwEnv) : null;
$dwUp = false;
if (is_resource($dwProc)) {
    for ($i = 0; $i < 100 && !$dwUp; $i++) { $c = @fsockopen('127.0.0.1', $dwPort, $e1, $e2, 0.2); if ($c) { fclose($c); $dwUp = true; } else usleep(50000); }
}
if (!$dwUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    $body = json_decode((string) @file_get_contents('http://127.0.0.1:' . $dwPort . '/process-donation.php?action=get_donors&limit=50'), true) ?: [];
    $names = array_map(static fn($d) => (string) ($d['name'] ?? ''), (array) ($body['donors'] ?? []));
    ck('wall: the public wall shows the donor who asked for it', in_array('Ada Giver', $names, true));
    ck('wall: …and nobody else, so the dues payer is not on it',
        count($names) === 1 && !in_array('Kemi Member', $names, true) && !in_array('Anonymous', $names, true));
    ck('wall: …and the count it reports is of what it shows', (int) ($body['total'] ?? -1) === 1);
    /* The thermometer is a figure, not a name. A count that fell because
       donors stopped consenting to be named would be a false statement about
       how much support there is. */
    ck('wall: the gift count is every gift, not only the listed ones', (int) ($body['gifts'] ?? 0) === 4);
}
if (is_resource($dwProc)) { proc_terminate($dwProc); proc_close($dwProc); }
@unlink($dwLog);

/* ── The form asks, rather than assuming ──────────────────────────────── */
$dwForm = (string) file_get_contents(AV_ROOT . '/donate.html');
ck('wall: the donate form asks to be listed rather than offering a way out',
    str_contains($dwForm, 'Show my name on the donor wall') && !str_contains($dwForm, 'Keep my name off the donor wall'));
$dwJs = (string) file_get_contents(AV_ROOT . '/assets/site/avdn.js');
ck('wall: …and the page sends that answer, not its negation',
    str_contains($dwJs, 'wall: s2.elements.wall.checked') && !str_contains($dwJs, 'anonymous: d.anon'));

foreach (glob($dwDir . '/*') ?: [] as $dwF) @unlink($dwF);
@rmdir($dwDir);
Database::pdo()->exec("DELETE FROM donation_refs WHERE reference LIKE 'DW-%'");
if ($dwPrev === false) putenv('AV_PRIVATE_DIR'); else putenv('AV_PRIVATE_DIR=' . $dwPrev);
