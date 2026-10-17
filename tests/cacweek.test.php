<?php
/**
 * tests/cacweek.test.php — this week at CACENTRE, and portal tasks that count
 * toward it (lib/CacWeek.php), against a fake CACENTRE that checks the token.
 *
 *   • The week is read with the member's id and email inside the signature.
 *   • Only what the portal draws is kept.
 *   • A task counts toward a commitment; ticking it ticks it there; deleting
 *     it stops it counting; someone else's task cannot be attached.
 *   • CACENTRE down: nothing breaks here, and the tick is retried later.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$wp = Database::pdo();
CacWeek::ensure();
$wp->exec('DELETE FROM lms_users WHERE id BETWEEN 9900 AND 9909');
$wp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9900 AND 9909');
foreach ([9901 => ['Ada Week', 'Ada.Week@mail.test'], 9902 => ['Bayo Other', 'bayo@mail.test']] as $id => [$n, $e]) {
    $wp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, 'x', 'member', 'active']);
}
$ada = ['id' => 9901, 'name' => 'Ada Week', 'email' => 'Ada.Week@mail.test'];
$bayo = ['id' => 9902, 'name' => 'Bayo Other', 'email' => 'bayo@mail.test'];

$oldUrl = getenv('CAC_SITE_URL'); $oldSecret = getenv('AV_SSO_SECRET');
putenv('CAC_SITE_URL='); putenv('AV_SSO_SECRET=');
$w = CacWeek::forMember($ada);
ck('week: not set up here → an answer that says so, no request', !$w['ok'] && $w['error'] === 'not-configured');

$port = 0;
if ($s = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) { $nm = (string) stream_socket_get_name($s, false); $port = (int) substr($nm, strrpos($nm, ':') + 1); fclose($s); }
$state = tempnam(sys_get_temp_dir(), 'avcw');
file_put_contents($state, '{}');
$secret = str_repeat('s', 48);
$stub = $port > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, AV_ROOT . '/tests/fixtures/cac-week-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, array_merge(getenv(), ['CAC_STUB_STATE' => $state, 'AV_SSO_SECRET' => $secret])) : null;
$up = false;
for ($i = 0; $i < 100 && $port > 0; $i++) { $c = @fsockopen('127.0.0.1', $port, $a, $b, 0.2); if ($c) { fclose($c); $up = true; break; } usleep(50000); }

if (!is_resource($stub) || !$up) {
    echo "  (skipped: could not start the fake CACENTRE)\n";
} else {
    putenv('CAC_SITE_URL=http://127.0.0.1:' . $port); putenv('AV_SSO_SECRET=' . $secret);
    $log = static fn() => array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($state . '.log', FILE_IGNORE_NEW_LINES) ?: [])));

    $w = CacWeek::forMember($ada);
    ck('week: read, signed with the shared secret', $w['ok'] && $w['linked'] && $w['week']['start'] === '2026-10-12');
    $last = $log()[0] ?? [];
    ck('week: the member’s id and email are inside the signature', ($last['uid'] ?? 0) === 9901 && ($last['sub'] ?? '') === 'ada.week@mail.test');
    $c = $w['commitments'][0] ?? [];
    ck('week: the commitment and the goal it feeds', ($c['id'] ?? 0) === 7 && ($c['goal']['title'] ?? '') === 'Twelve sessions' && (float) $c['goal']['progress'] === 4.0);
    ck('week: only what the portal draws is kept', !isset($c['goal']['secret']));
$tok = json_decode((string) base64_decode(strtr(explode('.', CacSso::mint(['id' => 1], 'week'))[1], '-_', '+/')), true);
ck('door: a week token names its door', ($tok['aud'] ?? '') === 'week');
$link = CacSso::linkFor(['id' => 1, 'email' => 'a@b.test']);
parse_str((string) parse_url($link, PHP_URL_QUERY), $lq);
$ltok = json_decode((string) base64_decode(strtr(explode('.', (string) $lq['t'])[1], '-_', '+/')), true);
ck('door: a sign-in link says it is one, so CACENTRE will not spend it at a server door', ($ltok['aud'] ?? '') === 'sso');
ck('door: the tasks door here takes only CACENTRE\'s tasks token', str_contains((string) file_get_contents(AV_ROOT . '/integrations/cacentre-tasks.php'), "!== 'cac-tasks') \$refuse('wrong-purpose')"));

    $t1 = Collab::addTask(9901, 'Plan session one');
    $t2 = Collab::addTask(9901, 'Book the hall');
    $r = CacWeek::attach($ada, $t1, 7);
    ck('count: a task counts toward the commitment', $r['ok'] && CacWeek::attachedFor(9901) === [$t1 => 7]);
    ck('count: and CACENTRE was told its title and state', ($log()[1]['body']['task']['title'] ?? '') === 'Plan session one' && ($log()[1]['body']['action'] ?? '') === 'link');
    Collab::toggleTask(9901, $t1);
    $w = CacWeek::forMember($ada);
    ck('count: ticking it here counts there', (float) ($w['commitments'][0]['delivered'] ?? 0) === 1.0);
    ck('count: someone else cannot attach your task', !CacWeek::attach($bayo, $t2, 7)['ok']);
    ck('count: nor to a commitment that is not theirs', CacWeek::attach($ada, $t2, 99)['error'] === 'not-yours');
    CacWeek::attach($ada, $t2, 7);
    ck('count: moving off a commitment (0) stops it counting', CacWeek::attach($ada, $t2, 0)['ok'] && !isset(CacWeek::attachedFor(9901)[$t2]));
    Collab::deleteTask(9901, $t1);
    $w = CacWeek::forMember($ada);
    ck('count: deleting the task stops it counting there', ($w['commitments'][0]['av_tasks'] ?? [1]) === []);

    /* CACENTRE down */
    $t3 = Collab::addTask(9901, 'Write the report');
    CacWeek::attach($ada, $t3, 7);
    file_put_contents($state, json_encode(['down' => true] + (json_decode((string) file_get_contents($state), true) ?: [])));
    ck('down: ticking still works here', Collab::toggleTask(9901, $t3) === true);
    ck('down: and is marked to send later', (int) $wp->query('SELECT cac_sync_pending FROM collab_tasks WHERE id = ' . $t3)->fetchColumn() === 1);
    $w = CacWeek::forMember(['id' => 9901, 'name' => 'Ada Week', 'email' => 'ada.week@mail.test'], '2026-10-13');
    ck('down: the week says CACENTRE did not answer', !$w['ok'] && $w['error'] !== '');
    $st = json_decode((string) file_get_contents($state), true); unset($st['down']); file_put_contents($state, json_encode($st));
    ck('down: the cron sends it when CACENTRE is back', CacWeek::retryPending() === 1 && (int) $wp->query('SELECT cac_sync_pending FROM collab_tasks WHERE id = ' . $t3)->fetchColumn() === 0);
    ck('down: and it counts', (json_decode((string) file_get_contents($state), true)['tasks'][$t3] ?? false) === true);
}
if (is_resource($stub)) { proc_terminate($stub); proc_close($stub); }
@unlink($state); @unlink($state . '.log');
putenv('CAC_SITE_URL=' . ($oldUrl === false ? '' : $oldUrl)); putenv('AV_SSO_SECRET=' . ($oldSecret === false ? '' : $oldSecret));
$wp->exec('DELETE FROM collab_tasks WHERE creator_id BETWEEN 9900 AND 9909');
$wp->exec('DELETE FROM lms_users WHERE id BETWEEN 9900 AND 9909');
