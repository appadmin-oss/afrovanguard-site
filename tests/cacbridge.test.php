<?php
/**
 * tests/cacbridge.test.php — a failing CACENTRE is told to the administrators,
 * in words, once; members see only that it is not available.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

/* ── Which answers are faults, and what an administrator reads ──────────── */
$f = CacBridge::fault(500, 0, '', '', 'inventory', 'https://cac.test/api/afrovanguard-inventory.php');
ck('bridge: a blank 500 is a fault', $f !== null && $f['key'] === 'http500');
ck('bridge: …and the words say CACENTRE is failing and where its reason is', $f && str_contains($f['why'], 'empty page') && str_contains($f['why'], 'errors.log'));
ck('bridge: a timeout is its own fault', CacBridge::fault(0, 28, 'Operation timed out', '', 'week')['key'] === 'timeout');
ck('bridge: no connection names the error', str_contains(CacBridge::fault(0, 7, 'Failed to connect', '', 'week')['why'], 'Failed to connect'));
ck('bridge: a door CACENTRE lacks says to upload its package', str_contains(CacBridge::fault(404, 0, '', 'Not found', 'goals', 'https://cac.test/api/afrovanguard-goals.php')['why'], 'upload the latest CACENTRE package'));
ck('bridge: a refused signature names the secret', str_contains(CacBridge::fault(403, 0, '', '{"ok":false,"error":"bad-signature"}', 'tasks')['why'], 'AV_SSO_SECRET'));
ck('bridge: stale tokens name the clocks', str_contains(CacBridge::fault(403, 0, '', '{"ok":false,"error":"expired"}', 'tasks')['why'], 'clocks'));
ck('bridge: a refusal about one member is not a fault', CacBridge::fault(403, 0, '', '{"ok":false,"error":"no-member"}', 'tasks') === null);
ck('bridge: an answer is not a fault', CacBridge::fault(200, 0, '', '{"ok":true}', 'tasks') === null);
ck('bridge: the members\' sentence names nothing technical', !preg_match('/HTTP|PHP|secret|cPanel|log/i', CacBridge::MEMBER));

/* ── Against a CACENTRE answering a blank 500, as the live one did ──────── */
$db = Database::pdo();
Notifications::ensure();
$clearMeta = static function (): void { foreach (['http500', 'bad-signature', 'timeout'] as $k) Database::metaSet('cac_bridge_alert_' . $k, '0'); Database::metaSet('cac_bridge_last', ''); };
$db->exec('DELETE FROM lms_users WHERE id BETWEEN 9790 AND 9794');
$db->exec("DELETE FROM user_notifications WHERE dedupe_key LIKE 'cacbridge:%'");
$clearMeta();
foreach ([9791 => ['Ada Admin', 'ada.admin@afrovanguard.org.ng', 'admin'], 9792 => ['Bo Member', 'bo.member@afrovanguard.org.ng', 'member']] as $id => [$n, $e, $r]) {
    $db->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, 'x', $r, 'active']);
}
$notes = static function (int $uid) use ($db): array {
    $st = $db->prepare("SELECT title, body FROM user_notifications WHERE user_id = ? AND dedupe_key LIKE 'cacbridge:%'");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

$oldUrl = getenv('CAC_SITE_URL'); $oldSecret = getenv('AV_SSO_SECRET');
$port = 0;
if ($s = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) { $nm = (string) stream_socket_get_name($s, false); $port = (int) substr($nm, strrpos($nm, ':') + 1); fclose($s); }
$mode = tempnam(sys_get_temp_dir(), 'avcb');
file_put_contents($mode, 'blank500');
$stub = $port > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, AV_ROOT . '/tests/fixtures/cac-down-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, array_merge(getenv(), ['CAC_STUB_MODE' => $mode])) : null;
$up = false;
for ($i = 0; $i < 100 && $port > 0; $i++) { $c = @fsockopen('127.0.0.1', $port, $a, $b, 0.2); if ($c) { fclose($c); $up = true; break; } usleep(50000); }

if (!is_resource($stub) || !$up) {
    echo "  (skipped: could not start the failing CACENTRE)\n";
} else {
    putenv('CAC_SITE_URL=http://127.0.0.1:' . $port); putenv('AV_SSO_SECRET=' . str_repeat('k', 48));

    $r = CacInventory::search(9792, ['q' => 'projector']);
    ck('bridge: the member\'s read fails soft', !$r['ok'] && $r['error'] === 'unreachable');
    $n = $notes(9791);
    ck('bridge: the administrator is told', count($n) === 1 && str_contains($n[0]['title'], 'inventory') && str_contains($n[0]['body'], 'HTTP 500'));
    ck('bridge: the member is not', $notes(9792) === []);
    ck('bridge: the fault is kept for the Studio', str_contains((string) Database::metaGet('cac_bridge_last'), 'http500'));

    CacInventory::search(9792, ['q' => 'chairs']);
    CacTasks::forMember(9792);
    ck('bridge: the same fault again is not told again', count($notes(9791)) === 1);

    file_put_contents($mode, 'badsig');
    CacInventory::search(9792, ['q' => 'tables']);
    $n = $notes(9791);
    ck('bridge: a different fault is told', count($n) === 2 && (bool) array_filter($n, static fn($x) => str_contains($x['body'], 'AV_SSO_SECRET')));

    file_put_contents($mode, 'nomember');
    CacInventory::search(9792, ['q' => 'mics']);
    ck('bridge: one member\'s refusal tells nobody', count($notes(9791)) === 2);
}

if (is_resource($stub)) { proc_terminate($stub); proc_close($stub); }
@unlink($mode);
putenv('CAC_SITE_URL=' . ($oldUrl === false ? '' : $oldUrl)); putenv('AV_SSO_SECRET=' . ($oldSecret === false ? '' : $oldSecret));
$db->exec('DELETE FROM lms_users WHERE id BETWEEN 9790 AND 9794');
$db->exec("DELETE FROM user_notifications WHERE dedupe_key LIKE 'cacbridge:%'");
$clearMeta();
