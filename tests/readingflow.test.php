<?php
/**
 * tests/readingflow.test.php — recording a book, the whole way, over HTTP.
 *
 * The library is held to its promises by tests/ngvreading.test.php. This
 * drives the ROUTES a vanguard actually touches, signed in as one, because
 * the flow changed shape: a book can no longer be sent in one request. It is
 * drafted, read in sittings logged a day at a time, and then sent — and if
 * the dashboard's endpoint, the claim sheet's contract and the diary's
 * reading section do not agree on that, the member meets it and nobody else.
 *
 *   • the dashboard answers `get`, `save`, `session`, `session_drop`, `submit`
 *   • a book with no reading logged against it is refused, in words
 *   • the diary's Reading section reads the same sittings back
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$rfPort = 0;
if ($rfProbe = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) {
    $n = (string) stream_socket_get_name($rfProbe, false); $rfPort = (int) substr($n, strrpos($n, ':') + 1); fclose($rfProbe);
}
$rfEnv = getenv(); $rfEnv['APP_ENV'] = 'dev';
$rfLog = tempnam(sys_get_temp_dir(), 'avrf');
$rfProc = $rfPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $rfPort, AV_ROOT . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $rfLog, 'a'], 2 => ['file', $rfLog, 'a']], $rfPipes, AV_ROOT, $rfEnv) : null;
$rfUp = false;
if (is_resource($rfProc)) {
    for ($i = 0; $i < 120 && !$rfUp; $i++) { $c = @fsockopen('127.0.0.1', $rfPort, $e1, $e2, 0.2); if ($c) { fclose($c); $rfUp = true; } else usleep(50000); }
}

/* A signed-in vanguard with a book on the list. */
NgvReading::ensure();
$rfDb = Database::pdo();
NgvDb::pdo()->exec('DELETE FROM ngv_reading_sessions');
$rfDb->exec("DELETE FROM lms_users WHERE email LIKE '%@rflow.test'");
$rfDb->prepare("INSERT INTO lms_users (name, email, password_hash, role, status, email_verified) VALUES (?,?,?,?,?,1)")
     ->execute(['Rita Flow', 'rita@rflow.test', 'x', 'member', 'active']);
$rfUid = (int) $rfDb->lastInsertId();
$rfTok = bin2hex(random_bytes(20));
$rfDb->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
     ->execute([hash('sha256', $rfTok), $rfUid, '', '', gmdate('Y-m-d H:i:s', time() + 3600)]);
NgvMember::ensureParticipant($rfUid, ['name' => 'Rita Flow', 'email' => 'rita@rflow.test']);
NgvDb::pdo()->prepare("UPDATE ngv_participants SET start_date = '2026-01-01', status = 'active' WHERE member_id = ?")->execute([$rfUid]);
$rfBook = (int) (NgvReading::saveBook(['title' => 'The Flow Book', 'author' => 'A Writer', 'chapters' => 2,
                                       'value_keys' => ['diligence']], 1)['book']['id'] ?? 0);   // a list tag, for curation

$rfCall = static function (string $path, ?array $body, string $csrf = '') use ($rfPort, $rfTok): array {
    $h = "Cookie: av_lms=" . $rfTok . "\r\nAccept: application/json\r\n";
    $opt = ['ignore_errors' => true, 'timeout' => 20, 'header' => $h];
    if ($body !== null) {
        $opt['method'] = 'POST';
        $opt['header'] = $h . "Content-Type: application/json\r\nX-CSRF-Token: " . $csrf . "\r\n";
        $opt['content'] = json_encode($body);
    }
    $raw = (string) @file_get_contents('http://127.0.0.1:' . $rfPort . $path, false, stream_context_create(['http' => $opt]));
    $code = 0;
    foreach ($http_response_header ?? [] as $hd) if (preg_match('~^HTTP/\S+ (\d{3})~', $hd, $m)) $code = (int) $m[1];
    return [$code, json_decode($raw, true) ?: [], $raw];
};

if (!$rfUp) {
    echo "  (skipped: could not start a loopback HTTP server)\n";
} else {
    /* The CSRF token the dashboard hands its own page. */
    [$code, , $html] = $rfCall('/academy/ngv/dashboard.php', null);
    preg_match('/NGV_CSRF\s*=\s*"([^"]+)"/', $html, $m) || preg_match("/NGV_CSRF\s*=\s*'([^']+)'/", $html, $m);
    $rfCsrf = (string) ($m[1] ?? '');
    ck('flow: a vanguard can open their dashboard', $code === 200 && $rfCsrf !== '');

    $post = static fn(array $b) => $rfCall('/academy/ngv/dashboard.php', $b, $rfCsrf);

    [$c1, $j1] = $post(['book_action' => 'get', 'slot' => 1]);
    ck('flow: the claim sheet is told the books, the moods and what each field needs',
       $c1 === 200 && !empty($j1['ok']) && !empty($j1['moods'])
       && ($j1['minSit']['notes'] ?? 0) === NgvReading::MIN_SESSION_NOTES
       && !isset($j1['values']));   // a reader is never asked for one

    $rfClaim = ['slot' => 1, 'book_id' => $rfBook, 'started_on' => '2026-03-01', 'finished_on' => '2026-03-09',
                'chapter_notes' => [str_repeat('What the first chapter set out, at some length. ', 3),
                                    str_repeat('And what the second one did with it, at length. ', 3)],
                'reflection' => str_repeat('The book argues that habit beats intention, and the chapter on practice matched what I see at the centre. ', 4),
                'takeaway' => 'I now write the next action down before I close my notebook, every single day without fail.'];
    [$c2, $j2] = $post(['book_action' => 'submit', 'book' => $rfClaim]);
    ck('flow: it cannot be sent with no reading logged against it, and says so',
       $c2 === 400 && ($j2['code'] ?? '') === 'no_sessions' && str_contains((string) $j2['error'], 'sitting'));

    [$c3, $j3] = $post(['book_action' => 'save', 'book' => $rfClaim]);
    ck('flow: it saves as a draft', $c3 === 200 && !empty($j3['ok']));

    $rfSit = ['slot' => 1, 'day' => '2026-03-02', 'minutes' => 50, 'chapter_from' => 1, 'chapter_to' => 1,
              'place' => 'At the centre', 'mood' => 'absorbed',
              'notes' => 'The first chapter lays out the case that what you repeat is what you become, and carries it with the example of the morning routine and the man who kept it for a decade.',
              'stance' => 'I agree with most of it, though I think it underrates how much a bad week can be recovered from.',
              'application' => 'I am going to keep the same start time for study every day this week.'];
    [$c4, $j4] = $post(['book_action' => 'session', 'session' => $rfSit]);
    ck('flow: a sitting is logged against it', $c4 === 200 && !empty($j4['ok']) && count($j4['sessions']) === 1);
    [$c5, $j5] = $post(['book_action' => 'session', 'session' => ['slot' => 1, 'day' => '2026-03-05'] + $rfSit]);
    ck('flow: and another', $c5 === 200 && count($j5['sessions']) === 2);
    [$c6, $j6] = $post(['book_action' => 'session', 'session' => ['slot' => 1, 'day' => '2026-03-06', 'place' => ''] + $rfSit]);
    ck('flow: one with a field missing is refused, in the member\'s own words',
       $c6 === 400 && str_contains((string) $j6['error'], 'Where did you read'));

    [$c7, $j7] = $post(['book_action' => 'session_drop', 'id' => (int) $j5['sessions'][1]['id']]);
    ck('flow: a sitting can be taken back out while the book is still theirs',
       $c7 === 200 && count($j7['sessions']) === 1);

    [$c8, $j8] = $post(['book_action' => 'submit', 'book' => $rfClaim]);
    ck('flow: and now it sends', $c8 === 200 && !empty($j8['ok']) && ($j8['status'] ?? '') === 'submitted'
       && (int) ($j8['progress']['waiting'] ?? 0) === 1);

    [$c9, $j9] = $post(['book_action' => 'session', 'session' => ['slot' => 1, 'day' => '2026-03-07'] + $rfSit]);
    ck('flow: once sent, the reading under it stops moving too', $c9 === 400 && ($j9['code'] ?? '') === 'closed');

    /* The diary's Reading section reads the same sittings — it does not keep
       a copy, so there is nothing to drift. */
    [$c10, $j10] = $rfCall('/portal/notebooks.php?action=reading', null);
    ck('flow: the diary shows the reading back', $c10 === 200 && count($j10['entries'] ?? []) === 1
       && (string) $j10['entries'][0]['title'] === 'The Flow Book');
    ck('flow: …read-only, because the book owns it',
       ($j10['entries'][0]['mine'] ?? true) === false && !empty($j10['entries'][0]['reading']));
    ck('flow: …and the whole sitting is in it, not just the notes',
       str_contains((string) $j10['entries'][0]['body'], 'Where I stand')
       && str_contains((string) $j10['entries'][0]['body'], 'What I will do'));
    [$c11, $j11] = $rfCall('/portal/notebooks.php?action=list', null);
    ck('flow: and the diary counts it, so the section shows itself',
       $c11 === 200 && (int) ($j11['counts']['reading'] ?? 0) === 1);

    /* Nothing of somebody else's. */
    [$c12, $j12] = $post(['book_action' => 'session_drop', 'id' => 999999]);
    ck('flow: a sitting id that is not theirs does nothing', $c12 === 400 && empty($j12['ok']));
}

if (is_resource($rfProc)) { proc_terminate($rfProc); proc_close($rfProc); }
@unlink($rfLog);
NgvDb::pdo()->exec('DELETE FROM ngv_reading_sessions');
NgvDb::pdo()->prepare('DELETE FROM ngv_book_claims WHERE member_id = ?')->execute([$rfUid]);
NgvDb::pdo()->prepare('DELETE FROM ngv_participants WHERE member_id = ?')->execute([$rfUid]);
$rfDb->prepare('DELETE FROM lms_sessions WHERE user_id = ?')->execute([$rfUid]);
$rfDb->prepare('DELETE FROM lms_users WHERE id = ?')->execute([$rfUid]);
