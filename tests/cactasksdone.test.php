<?php
/**
 * tests/cactasksdone.test.php — CACENTRE's "mark done" over the tasks door.
 *
 * CACENTRE sends `toggle` meaning "mark done", and this door has always
 * flipped done/not-done. A tick that is retried — a timeout on the other side,
 * a double press, a replayed request inside the token's minute — therefore
 * UN-did the work it was confirming, and the member's finished task came back
 * open. `done` is the action that means what CACENTRE means: done, however
 * many times it is said, answering in the shape the door already answers.
 * `toggle` stays exactly as it was for CACENTRE deploys that still send it.
 *
 * Exercised over HTTP through the real door, on a loopback server sharing the
 * suite's database, because the door's contract is its JSON, not a method.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$cdp = Database::pdo();
Collab::myTasks(0, 1);
$cdp->exec('DELETE FROM lms_users WHERE id IN (9971, 9972)');
$cdp->prepare("INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (9971, 'Dee Done', 'dee.done@mail.test', 'x', 'member', 'active'), (9972, 'Eve Else', 'eve.else@mail.test', 'x', 'member', 'active')")->execute();
$cdTask  = Collab::addTask(9971, 'Send the attendance sheet');
$cdOther = Collab::addTask(9972, 'Not yours to finish');

$cdSecret = 'cd-test-secret-0123456789abcdefghijklmnop';
$cdKeep = getenv('AV_SSO_SECRET');
putenv('AV_SSO_SECRET=' . $cdSecret);
$cdMint = static function (int $uid) use ($cdSecret): string {
    $b = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $p = $b((string) json_encode(['uid' => $uid, 'role' => 'cac-tasks', 'iat' => time(), 'exp' => time() + 60]));
    return 'v1.' . $p . '.' . $b(hash_hmac('sha256', $p, $cdSecret, true));
};

$cdPort = 0;
if ($probe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $nm = (string) stream_socket_get_name($probe, false); $cdPort = (int) substr($nm, strrpos($nm, ':') + 1); fclose($probe);
}
$cdLog = tempnam(sys_get_temp_dir(), 'avcd-log');
$cdProc = $cdPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $cdPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $cdLog, 'a'], 2 => ['file', $cdLog, 'a']], $cdPipes, AV_ROOT) : null;
$cdUp = false;
if (is_resource($cdProc)) {
    for ($i = 0; $i < 100 && !$cdUp; $i++) { $c = @fsockopen('127.0.0.1', $cdPort, $e1, $e2, 0.2); if ($c) { fclose($c); $cdUp = true; } else usleep(50000); }
}
$cdPost = static function (int $uid, array $body) use ($cdPort, $cdMint): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => 'Content-Type: application/json', 'content' => (string) json_encode($body)]]);
    $raw = (string) @file_get_contents('http://127.0.0.1:' . $cdPort . '/integrations/cacentre-tasks.php?t=' . rawurlencode($cdMint($uid)), false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $mm)) $code = (int) $mm[1];
    return [$code, json_decode($raw, true), $raw];
};
$cdDone = static fn(int $id): int => (int) $cdp->query('SELECT done FROM collab_tasks WHERE id = ' . $id)->fetchColumn();

if (!$cdUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    [$c, $j] = $cdPost(9971, ['action' => 'done', 'id' => $cdTask]);
    ck('done: CACENTRE\'s "mark done" finishes the task, in the door\'s shape', $c === 200 && $j === ['ok' => true, 'done' => true] && $cdDone($cdTask) === 1);
    [$c, $j] = $cdPost(9971, ['action' => 'done', 'id' => $cdTask]);
    ck('done: said twice (a retry, a replay) it is still done and still answers ok', $c === 200 && $j === ['ok' => true, 'done' => true] && $cdDone($cdTask) === 1);
    ck('done: and the repeat records nothing a second time',
       (int) $cdp->query("SELECT COUNT(*) FROM activity WHERE verb = 'completed a task' AND object = 'Send the attendance sheet'")->fetchColumn() === 1);
    [$c, $j] = $cdPost(9971, ['action' => 'done', 'id' => $cdOther]);
    ck('done: somebody else\'s task is refused as before, and left open', $c === 404 && ($j['error'] ?? '') === 'not-yours' && $cdDone($cdOther) === 0);

    /* Older CACENTRE deploys still send toggle: it flips, exactly as it did. */
    $cdFlip = Collab::addTask(9971, 'Ring the venue');
    [$c, $j] = $cdPost(9971, ['action' => 'toggle', 'id' => $cdFlip]);
    ck('toggle: older CACENTRE deploys still flip an open task to done', $c === 200 && $j === ['ok' => true, 'done' => true] && $cdDone($cdFlip) === 1);
    [$c, $j] = $cdPost(9971, ['action' => 'toggle', 'id' => $cdFlip]);
    ck('toggle: …and a done one back open, exactly as before', $c === 200 && $j === ['ok' => true, 'done' => false] && $cdDone($cdFlip) === 0);
}
if (is_resource($cdProc)) { proc_terminate($cdProc); proc_close($cdProc); }
@unlink($cdLog);
putenv($cdKeep === false ? 'AV_SSO_SECRET' : 'AV_SSO_SECRET=' . $cdKeep);
