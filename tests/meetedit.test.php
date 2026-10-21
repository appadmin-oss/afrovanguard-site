<?php
/**
 * tests/meetedit.test.php — changing a meeting (Meetings::update), against a
 * fake Google (tests/fixtures/meet-stub.php):
 *
 *   • the organiser changes the time, length, cadence, agenda and guests; the
 *     Calendar event is patched with exactly what changed, and everyone is
 *     told when the time moved; somebody else cannot change it;
 *   • a meeting made while Google was not working is given its Meet link
 *     when it is next edited;
 *   • when Google refuses, the warning says why and what fixes it;
 *   • a team meeting can be changed by the team's lead.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$ep = Database::pdo();
Meetings::ensure(); MeetingMinutes::ensure(); Volunteers::ensure();
$eEnv = [];
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT', 'AV_WS_CALENDAR_ID', 'AV_WS_TOKEN_URL', 'AV_WS_BASE_URL', 'AV_MEET_BASE_URL', 'AV_MEET_ARTIFACTS', 'AV_MEET_ORGANISER'] as $k) $eEnv[$k] = getenv($k);
$ep->exec('DELETE FROM lms_users WHERE id BETWEEN 9760 AND 9769');
foreach ([9761 => ['Ada Organiser', 'ada.org@afrovanguard.org.ng'], 9762 => ['Bisi Other', 'bisi.other@afrovanguard.org.ng'], 9763 => ['Chidi Lead', 'chidi.lead@afrovanguard.org.ng']] as $id => [$n, $e]) {
    $ep->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([$id, $n, $e, 'x', 'coordinator', 'active']);
}

/* Without Google at all: an edit still works, and says why there is no link. */
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT'] as $k) putenv($k);
$r = Meetings::schedule(9761, ['title' => 'ME Planning', 'when' => '2027-03-02T10:00', 'duration' => 30, 'attendees' => 'bisi.other@afrovanguard.org.ng']);
$mid = (int) ($r['id'] ?? 0);
ck('edit: a meeting made with Google not set up says so, in words', $mid > 0 && str_contains((string) ($r['warning'] ?? ''), 'not set up on this site'));
ck('edit: somebody else cannot change it', !Meetings::update(9762, $mid, ['title' => 'Hijacked'])['ok']);
$r = Meetings::update(9761, $mid, ['title' => 'ME Planning (moved)', 'when' => '2027-03-03T11:30', 'duration' => 45, 'agenda' => 'Budget']);
$row = $ep->query('SELECT * FROM meetings WHERE id = ' . $mid)->fetch(PDO::FETCH_ASSOC);
ck('edit: the organiser changes the title, time, length and agenda', $r['ok'] && $row['title'] === 'ME Planning (moved)' && (int) $row['duration_min'] === 45 && $row['agenda'] === 'Budget'
   && $row['scheduled_at'] === (new DateTime('2027-03-03 11:30', new DateTimeZone(av_user_tz(9761))))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
ck('edit: what changed is listed', in_array('scheduled_at', $r['changed'], true) && in_array('title', $r['changed'], true));
ck('edit: nothing changed is no change', Meetings::update(9761, $mid, ['title' => 'ME Planning (moved)'])['changed'] === []);

$port = 0;
if ($s = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) { $n = (string) stream_socket_get_name($s, false); $port = (int) substr($n, strrpos($n, ':') + 1); fclose($s); }
$state = tempnam(sys_get_temp_dir(), 'avme');
file_put_contents($state, json_encode(['transcript' => 'none']));
$stub = $port > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, AV_ROOT . '/tests/fixtures/meet-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null, getenv() + ['MEET_STUB_STATE' => $state]) : null;
$up = false;
for ($i = 0; $i < 100 && $port > 0; $i++) { $c = @fsockopen('127.0.0.1', $port, $a, $b, 0.2); if ($c) { fclose($c); $up = true; break; } usleep(50000); }
$log = static fn() => array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($state . '.log', FILE_IGNORE_NEW_LINES) ?: [])));

if (!is_resource($stub) || !$up) {
    echo "  (skipped: could not start the fake Google)\n";
} else {
    $base = 'http://127.0.0.1:' . $port;
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    putenv('AV_GDRIVE_SERVICE_ACCOUNT=' . json_encode(['type' => 'service_account', 'client_email' => 'svc@av.iam.gserviceaccount.com', 'client_id' => '1', 'private_key' => $pem]));
    foreach (['AV_WS_TOKEN_URL' => $base . '/token', 'AV_WS_BASE_URL' => $base, 'AV_MEET_BASE_URL' => $base, 'AV_WS_SUBJECT' => 'admin@afrovanguard.org.ng', 'AV_WS_CALENDAR_ID' => '', 'AV_MEET_ARTIFACTS' => 'off'] as $k => $v) putenv($k . '=' . $v);

    $r = Meetings::update(9761, $mid, ['agenda' => 'Budget and venues']);
    $row = $ep->query('SELECT * FROM meetings WHERE id = ' . $mid)->fetch(PDO::FETCH_ASSOC);
    ck('edit: a meeting Google never gave a link is given one at its next edit', $r['ok'] && $row['meet_url'] === 'https://meet.google.com/abc-defg-hij' && $row['google_event_id'] === 'ev-1');

    @file_put_contents($state . '.log', '');
    $r = Meetings::update(9761, $mid, ['when' => '2027-03-04T09:00', 'frequency' => 'weekly', 'attendees' => 'bisi.other@afrovanguard.org.ng, new.guest@example.org']);
    $patch = array_values(array_filter($log(), fn($l) => $l['method'] === 'PATCH' && str_contains($l['path'], '/events/ev-1')))[0] ?? null;
    ck('edit: the Calendar event is patched with what changed — time, cadence, guests', $r['ok'] && $patch
       && isset($patch['body']['start']['dateTime'], $patch['body']['end']['dateTime']) && ($patch['body']['recurrence'] ?? []) === ['RRULE:FREQ=WEEKLY;BYDAY=TH']
       && in_array(['email' => 'new.guest@example.org'], $patch['body']['attendees'] ?? [], true) && !isset($patch['body']['summary']));
    ck('edit: and Google tells its invitees', $patch && str_contains((string) $patch['query'], 'sendUpdates=all'));
    $rr = Meetings::schedule(9761, ['title' => 'ME Rule', 'when' => '2027-03-01T09:00', 'repeat' => ['freq' => 'weekly', 'interval' => 2, 'days' => ['MO', 'WE'], 'end' => 'until', 'until' => '2027-06-30', 'skip' => ['2027-03-15']]]);
    $ev = array_values(array_filter($log(), fn($l) => $l['method'] === 'POST' && str_contains($l['path'], '/events') && ($l['body']['summary'] ?? '') === 'ME Rule'))[0] ?? null;
    $rec = (array) ($ev['body']['recurrence'] ?? []);
    ck('repeat: Google is given the whole rule — days, interval, end and the skipped date', $rr['ok'] && count($rec) === 2
       && str_starts_with($rec[0], 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20270630T') && str_starts_with($rec[1], 'EXDATE;TZID=') && str_contains($rec[1], ':20270315T'));
    ck('edit: the organiser stays invited whatever the list says', in_array('ada.org@afrovanguard.org.ng', array_column($ep->query('SELECT email FROM meeting_attendees WHERE meeting_id = ' . $mid)->fetchAll(PDO::FETCH_ASSOC), 'email'), true));
    $r = Meetings::update(9761, $mid, ['frequency' => 'once']);
    $patch = array_values(array_filter($log(), fn($l) => $l['method'] === 'PATCH'))[1] ?? null;
    ck('edit: back to one-off clears the repeat on Calendar too', $patch && ($patch['body']['recurrence'] ?? null) === []);

    /* Google refusing: the warning names the reason and the fix. */
    file_put_contents($state, json_encode(['transcript' => 'none', 'deny_scopes' => ['https://www.googleapis.com/auth/calendar']]));
    GoogleWorkspace::forgetTokens();
    $r = Meetings::update(9761, $mid, ['when' => '2027-03-05T09:00']);
    ck('edit: Google refusing is said in words, with the fix — the scope to grant', $r['ok'] && str_contains((string) ($r['warning'] ?? ''), 'unauthorized_client')
       && str_contains((string) $r['warning'], 'https://www.googleapis.com/auth/calendar') && str_contains((string) $r['warning'], 'Domain-wide delegation'));
    $r = Meetings::schedule(9761, ['title' => 'ME No link', 'when' => '2027-03-06T10:00']);
    ck('schedule: so is a meeting Google would not give a link', str_contains((string) ($r['warning'] ?? ''), 'unauthorized_client'));
    $ep->prepare('DELETE FROM meetings WHERE id = ?')->execute([(int) ($r['id'] ?? 0)]);
    file_put_contents($state, json_encode(['transcript' => 'none']));
    GoogleWorkspace::forgetTokens();

    /* Recording that Google will not switch on says why, on the meeting. */
    putenv('AV_MEET_ARTIFACTS=all'); putenv('AV_MEET_ORGANISER=admin@afrovanguard.org.ng');
    file_put_contents($state, json_encode(['transcript' => 'none', 'deny_scopes' => [GoogleWorkspace::SCOPE_MEET_SETTINGS]]));
    GoogleWorkspace::forgetTokens();
    $rc = Meetings::schedule(9761, ['title' => 'ME Recorded', 'when' => '2027-03-08T10:00']);
    $mm = Meetings::get(9761, (int) ($rc['id'] ?? 0)) ?? [];
    ck('recording: refused by Google, the meeting says why — the scope to grant', ($mm['capture'] ?? 'x') === '' && str_contains((string) ($mm['capture_note'] ?? ''), GoogleWorkspace::SCOPE_MEET_SETTINGS));
    file_put_contents($state, json_encode(['transcript' => 'none']));
    GoogleWorkspace::forgetTokens();
    Meetings::configureCapture((int) $rc['id'], 'abc-defg-hij');
    $mm = Meetings::get(9761, (int) $rc['id']) ?? [];
    ck('recording: granted later, it is switched on and the note cleared', ($mm['capture'] ?? '') === 'all' && ($mm['capture_note'] ?? 'x') === '');
    putenv('AV_MEET_ARTIFACTS=off');

    /* A team meeting: its lead may change it. */
    $tid = Volunteers::createTeam(['id' => 9761, 'role' => 'coordinator', 'email' => 'ada.org@afrovanguard.org.ng'], 'ME Team', '', 0)['id'] ?? 0;
    if ($tid) {
        Volunteers::setMember(['id' => 9761, 'role' => 'coordinator', 'email' => 'ada.org@afrovanguard.org.ng'], (int) $tid, 'chidi.lead@afrovanguard.org.ng', 'lead');
        $tm = MeetingMinutes::scheduleForTeam(['id' => 9761, 'role' => 'coordinator', 'email' => 'ada.org@afrovanguard.org.ng'], (int) $tid, ['title' => 'ME Team sync', 'when' => '2027-03-07T16:00']);
        $r = MeetingMinutes::updateForTeam(['id' => 9763, 'role' => 'member', 'email' => 'chidi.lead@afrovanguard.org.ng'], (int) ($tm['id'] ?? 0), ['duration' => 90]);
        ck('team: the team’s lead can change its meeting', $r['ok'] && (int) $ep->query('SELECT duration_min FROM meetings WHERE id = ' . (int) $tm['id'])->fetchColumn() === 90);
        ck('team: somebody off the team cannot', !MeetingMinutes::updateForTeam(['id' => 9762, 'role' => 'member', 'email' => 'bisi.other@afrovanguard.org.ng'], (int) $tm['id'], ['duration' => 15])['ok']);
        $ep->prepare('DELETE FROM meetings WHERE id = ?')->execute([(int) $tm['id']]);
        try { $ep->prepare('DELETE FROM vol_team_members WHERE team_id = ?')->execute([(int) $tid]); $ep->prepare('DELETE FROM vol_teams WHERE id = ?')->execute([(int) $tid]); } catch (Throwable $e) {}
    } else {
        ck('team: a team to test with', false);
    }
}
if (is_resource($stub)) { proc_terminate($stub); proc_close($stub); }
@unlink($state); @unlink($state . '.log');
$ep->exec("DELETE FROM meeting_attendees WHERE meeting_id IN (SELECT id FROM meetings WHERE title LIKE 'ME %')");
$ep->exec("DELETE FROM meetings WHERE title LIKE 'ME %'");
$ep->exec('DELETE FROM lms_users WHERE id BETWEEN 9760 AND 9769');
foreach ($eEnv as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }

/* ── What the organiser is SHOWN after a successful edit ──────────────────
   Reported as "meetings cannot be edited". They can: update() returns
   ok=true and the row changes. What failed was the telling.

   portal/meetings.js has say(text, tone), and tone decides the colour.
   The success branch passed `true` (green) and cleared after 3s; the
   warning branch passed nothing, so a save that carried a note — "there
   is still no Meet link", which this site emits on EVERY edit while
   Google Workspace is unreachable — was painted in the failure colour and
   left on screen. The edit had saved. Nothing said so.

   Pinned at source because the defect is in the rendering, not the route,
   and this repository has no browser in its suite. */
$js = (string) file_get_contents(AV_ROOT . '/portal/meetings.js');

ck('meet-ui: a warning after a save is never rendered with no tone',
   !preg_match('~say\(d\.warning\)~', $js));
ck('meet-ui: every warning branch says the work was done first',
   substr_count($js, "say('✓ ' + d.warning, 'warn')") === substr_count($js, 'd.warning) '));
ck('meet-ui: say() knows a third tone, so "done, with a caveat" is not red',
   str_contains($js, "tone === 'warn'") && str_contains($js, "tone === true || tone === 'ok'"));

/* The contract the rendering leans on — a save Google could not mirror is a
   success carrying a warning, never an error — is already pinned by the
   Google-refuses case earlier in this file, and that runs before teardown
   with a live meeting. Re-asserting it down here only proved the rows had
   already been deleted. */
