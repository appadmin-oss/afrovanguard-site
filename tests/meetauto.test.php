<?php
/**
 * tests/meetauto.test.php — meetings that run themselves, against a fake
 * Google (tests/fixtures/meet-stub.php):
 *
 *   • scheduling makes the Calendar event and its Meet, then — as the
 *     organiser — switches on Google's recording, transcript, notes and
 *     attendance report; an edition without recording falls back to
 *     transcript and notes;
 *   • a Meet link Calendar had not made yet is read again by the cron;
 *   • after the call, finishing waits while Google writes the transcript,
 *     then takes it speaker by speaker, with Gemini's notes, and reads every
 *     join and leave (rejoining is two sessions);
 *   • Google's live events arrive through Pub/Sub: the subscription is made
 *     and renewed by the cron, the queue drained, and a "transcript ready"
 *     event finishes the meeting at once;
 *   • a Pub/Sub push is accepted only with Google's signed token for our
 *     audience;
 *   • calendar files come from spatie/icalendar-generator.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$mp = Database::pdo();
Meetings::ensure(); MeetingMinutes::ensure(); MeetingLive::ensure(); Volunteers::ensure();
$mEnv = [];
foreach (['AV_GDRIVE_SERVICE_ACCOUNT', 'AV_WS_SUBJECT', 'AV_WS_CALENDAR_ID', 'AV_WS_TOKEN_URL', 'AV_WS_BASE_URL', 'AV_MEET_BASE_URL', 'AV_REPORTS_BASE_URL', 'AV_PUBSUB_BASE_URL', 'AV_EVENTS_BASE_URL',
          'AV_MEET_ORGANISER', 'AV_MEET_ARTIFACTS', 'AV_MEET_PUBSUB_TOPIC', 'AV_MEET_PUBSUB_SUB', 'AV_MEET_PUSH_AUDIENCE', 'MEET_STUB_STATE'] as $k) $mEnv[$k] = getenv($k);

/* ── Settings ── */
$mDefs = array_column(AvSettings::describe()['groups'], 'fields', 'group')['Google Meet'] ?? [];
ck('meet: the organiser, what Google captures and the Pub/Sub queue are set in the Studio',
    !array_diff(['AV_MEET_ORGANISER', 'AV_MEET_ARTIFACTS', 'AV_MEET_PUBSUB_TOPIC', 'AV_MEET_PUBSUB_SUB', 'AV_MEET_PUSH_AUDIENCE'], array_column($mDefs, 'key')));

/* ── Calendar files (spatie/icalendar-generator) ── */
$mp->exec("DELETE FROM meetings WHERE title LIKE 'MA %'");
$mp->exec('DELETE FROM lms_users WHERE id BETWEEN 9800 AND 9899');
$mp->prepare('INSERT INTO lms_users (id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)')->execute([9801, 'Kemi Chair', 'kemi.chair@afrovanguard.org.ng', 'x', 'coordinator', 'active']);
$mp->prepare("INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, status, created_at, meet_url) VALUES (9801, 'MA Weekly; planning, and more', 'Budget\nVenues', ?, 60, 'weekly', 'workspace', 'scheduled', ?, 'https://meet.google.com/abc-defg-hij')")
   ->execute([gmdate('Y-m-d H:i:s', time() + 86400), gmdate('Y-m-d H:i:s')]);
$icsId = (int) $mp->lastInsertId();
$mp->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?, ?)')->execute([$icsId, 'kemi.chair@afrovanguard.org.ng']);
$ics = MeetingMinutes::icsFor(MeetingMinutes::meeting($icsId), 'REQUEST');
ck('ics: made by spatie/icalendar-generator', class_exists(\Spatie\IcalendarGenerator\Components\Calendar::class) && str_contains($ics, 'PRODID:-//Afrovanguard//Volunteer dashboard//EN'));
ck('ics: an invitation, repeating weekly, with the invitee asked to answer', str_contains($ics, 'METHOD:REQUEST') && str_contains($ics, 'RRULE:FREQ=WEEKLY')
    && preg_match('/ATTENDEE;[^\r\n]*RSVP=TRUE/', str_replace("\r\n ", '', $ics)) === 1);
ck('ics: the Meet as the place and the link, the title escaped, lines folded', str_contains(str_replace("\r\n ", '', $ics), 'meet.google.com/abc-defg-hij')
    && str_contains(str_replace("\r\n ", '', $ics), 'MA Weekly\; planning\, and more') && max(array_map('strlen', explode("\r\n", $ics))) <= 75);
$mp->prepare('DELETE FROM meetings WHERE id = ?')->execute([$icsId]);

/* ── Against a fake Google ── */
$mPort = 0;
if ($s = @stream_socket_server('tcp://127.0.0.1:0', $e1, $e2)) { $n = (string) stream_socket_get_name($s, false); $mPort = (int) substr($n, strrpos($n, ':') + 1); fclose($s); }
$mState = tempnam(sys_get_temp_dir(), 'avms');
$setState = static function (array $st) use ($mState): void { file_put_contents($mState, json_encode($st)); };
$readLog = static function () use ($mState): array { return array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($mState . '.log', FILE_IGNORE_NEW_LINES) ?: []))); };
$clearLog = static function () use ($mState): void { @file_put_contents($mState . '.log', ''); };
$setState(['transcript' => 'none']);
$mStub = $mPort > 0 ? @proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $mPort, AV_ROOT . '/tests/fixtures/meet-stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $mPipes, null, getenv() + ['MEET_STUB_STATE' => $mState]) : null;
$mUp = false;
for ($i = 0; $i < 100 && $mPort > 0; $i++) { $c = @fsockopen('127.0.0.1', $mPort, $a, $b, 0.2); if ($c) { fclose($c); $mUp = true; break; } usleep(50000); }

if (!is_resource($mStub) || !$mUp) {
    echo "  (skipped: could not start the fake Google)\n";
} else {
    $base = 'http://127.0.0.1:' . $mPort;
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    putenv('AV_GDRIVE_SERVICE_ACCOUNT=' . json_encode(['type' => 'service_account', 'client_email' => 'svc@av.iam.gserviceaccount.com', 'client_id' => '1', 'private_key' => $pem]));
    foreach (['AV_WS_TOKEN_URL' => $base . '/token', 'AV_WS_BASE_URL' => $base, 'AV_MEET_BASE_URL' => $base, 'AV_REPORTS_BASE_URL' => $base, 'AV_PUBSUB_BASE_URL' => $base, 'AV_EVENTS_BASE_URL' => $base,
              'AV_WS_SUBJECT' => 'admin@afrovanguard.org.ng', 'AV_WS_CALENDAR_ID' => '', 'AV_MEET_ORGANISER' => 'meetings@afrovanguard.org.ng', 'AV_MEET_ARTIFACTS' => 'all'] as $k => $v) putenv($k . '=' . $v);

    ck('meet: the organiser is the meetings mailbox, then the calendar, then the admin', GoogleWorkspace::organiser() === 'meetings@afrovanguard.org.ng');
    putenv('AV_MEET_ORGANISER='); putenv('AV_WS_CALENDAR_ID=c_abc123@group.calendar.google.com');
    ck('meet: a shared calendar id is not a mailbox Google can act as — the admin is the organiser', GoogleWorkspace::organiser() === 'admin@afrovanguard.org.ng');
    putenv('AV_WS_CALENDAR_ID=mentorship@afrovanguard.org.ng');
    ck('meet: a calendar that IS a mailbox still is', GoogleWorkspace::organiser() === 'mentorship@afrovanguard.org.ng');
    putenv('AV_MEET_ORGANISER=meetings@afrovanguard.org.ng'); putenv('AV_WS_CALENDAR_ID=');

    /* Schedule */
    $clearLog();
    $r = Meetings::schedule(9801, ['title' => 'MA Outreach', 'when' => date('Y-m-d\TH:i', time() + 2 * 86400), 'duration' => 60, 'frequency' => 'once', 'attendees' => ['ada@afrovanguard.org.ng']]);
    $mid = (int) ($r['id'] ?? 0);
    $row = $mp->query('SELECT * FROM meetings WHERE id = ' . $mid)->fetch(PDO::FETCH_ASSOC);
    ck('meet: scheduling makes the Calendar event and its Meet', $r['ok'] && $row['meet_url'] === 'https://meet.google.com/abc-defg-hij' && $row['meet_code'] === 'abc-defg-hij');
    $patch = array_values(array_filter($readLog(), fn($x) => $x['method'] === 'PATCH' && $x['path'] === '/v2/spaces/S1'));
    ck('meet: …then Google records, transcribes, takes notes and reports attendance on its own',
        $row['meet_capture'] === 'all' && $row['meet_space'] === 'spaces/S1' && $row['bot_state'] === 'native'
        && ($patch[0]['body']['config']['artifactConfig']['recordingConfig']['autoRecordingGeneration'] ?? '') === 'ON'
        && ($patch[0]['body']['config']['artifactConfig']['smartNotesConfig']['autoSmartNotesGeneration'] ?? '') === 'ON'
        && ($patch[0]['body']['config']['attendanceReportGenerationType'] ?? '') === 'GENERATE_REPORT');
    ck('meet: …switched on as the organiser, who owns the space', ($patch[0]['sub'] ?? '') === 'meetings@afrovanguard.org.ng');

    $setState(['transcript' => 'none', 'norec' => true]);
    $mp->prepare("UPDATE meetings SET meet_capture = '' WHERE id = ?")->execute([$mid]);
    Meetings::configureCapture($mid, 'abc-defg-hij');
    ck('meet: an edition without recording gets transcript and notes instead', (string) $mp->query('SELECT meet_capture FROM meetings WHERE id = ' . $mid)->fetchColumn() === 'transcript');

    /* A Meet link Calendar had not made yet */
    $mp->prepare("UPDATE meetings SET meet_url = '', meet_code = '', google_event_id = 'ev-1' WHERE id = ?")->execute([$mid]);
    $up = Meetings::meetUpkeep();
    ck('meet: a link Calendar made late is read again by the cron', $up['links'] >= 1 && (string) $mp->query('SELECT meet_url FROM meetings WHERE id = ' . $mid)->fetchColumn() === 'https://meet.google.com/abc-defg-hij');

    /* After the call */
    $start = time() - 3 * 3600;
    $mp->prepare("UPDATE meetings SET scheduled_at = ?, meet_capture = 'all' WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', $start), $mid]);
    $m = MeetingMinutes::meeting($mid);
    $occ = MeetingMinutes::occurrenceKey($start);
    $setState(['transcript' => 'pending', 'conf_start' => gmdate('c', $start), 'conf_end' => gmdate('c', $start + 3600)]);
    $clearLog();
    ck('finish: while Google is still writing the transcript, it waits', MeetingLive::finish($m, $occ) === MeetingLive::WAITING);
    ck('finish: …and reads Google as the organiser, not the creator', !in_array('kemi.chair@afrovanguard.org.ng', array_column($readLog(), 'sub'), true)
        && in_array('meetings@afrovanguard.org.ng', array_column($readLog(), 'sub'), true));
    $joins = (int) $mp->query("SELECT COUNT(*) FROM meeting_live_events WHERE meeting_id = $mid AND source = 'google' AND kind = 'join'")->fetchColumn();
    ck('finish: every time somebody was in counts — leaving and rejoining is two', $joins === 3);
    $setState(['transcript' => 'ready', 'conf_start' => gmdate('c', $start), 'conf_end' => gmdate('c', $start + 3600)]);
    ck('finish: once it is written, the transcript is taken', MeetingLive::finish($m, $occ) === 'transcript from Google');
    $tx = (string) $mp->query('SELECT raw_text FROM meeting_transcripts WHERE meeting_id = ' . $mid)->fetchColumn();
    ck('finish: …speaker by speaker, by the names Meet shows', str_contains($tx, 'Ada Obi: Welcome everyone') && str_contains($tx, 'Guest Bisi: I can lead the school visits.'));
    ck('finish: …with Gemini\'s notes after it', str_contains($tx, 'Notes by Gemini') && str_contains($tx, 'Ada books the hall'));
    ck('finish: a second run does nothing', MeetingLive::finish($m, $occ) === 'has transcript');

    /* The sweep asks again, not every minute */
    $mp->exec('DELETE FROM meeting_transcripts WHERE meeting_id = ' . $mid);
    $mp->prepare('DELETE FROM meeting_nudges WHERE meeting_id = ?')->execute([$mid]);
    $setState(['transcript' => 'pending', 'conf_start' => gmdate('c', $start), 'conf_end' => gmdate('c', $start + 3600)]);
    $t0 = $start + 3600 + 30 * 60;
    MeetingMinutes::sweep($t0); MeetingMinutes::sweep($t0 + 60);
    ck('sweep: waiting, it asks once in each window — not every tick', (int) $mp->query("SELECT COUNT(*) FROM meeting_nudges WHERE meeting_id = $mid AND kind LIKE 'f10:%'")->fetchColumn() === 1
        && !MeetingMinutes::nudged($mid, $occ, 'finished'));

    /* Google's live events */
    putenv('AV_MEET_PUBSUB_TOPIC=projects/p/topics/meet-events'); putenv('AV_MEET_PUBSUB_SUB=projects/p/subscriptions/meet-pull');
    Database::metaSet('meet_events_sub', '');
    $clearLog();
    $sub = MeetEvents::renewIfDue();
    $post = array_values(array_filter($readLog(), fn($x) => $x['path'] === '/v1/subscriptions'));
    ck('events: the cron subscribes to the organiser\'s Meet events, into the topic', ($sub['subscribed'] ?? '') === 'subscriptions/SUB1'
        && ($post[0]['body']['targetResource'] ?? '') === '//cloudidentity.googleapis.com/users/4242' && ($post[0]['body']['notificationEndpoint']['pubsubTopic'] ?? '') === 'projects/p/topics/meet-events'
        && in_array('google.workspace.meet.transcript.v2.fileGenerated', $post[0]['body']['eventTypes'] ?? [], true));
    ck('events: …and leaves it alone while it is young', MeetEvents::renewIfDue() === null);
    ck('events: …renewing it before Google\'s seven days run out', (MeetEvents::renewIfDue(time() + 5 * 86400)['renewed'] ?? '') === 'subscriptions/SUB1');
    /* A renewal Google refuses, then a create refused as ALREADY_EXISTS: the existing
       subscription is found and kept, instead of the site failing every tick. */
    $setState(['transcript' => 'none', 'sub_exists' => true]);
    Database::metaSet('meet_events_sub', (string) json_encode(['name' => 'subscriptions/SUB0', 'at' => time() - 6 * 86400]));
    ck('events: a subscription Google already holds is found and kept, not re-asked for ever', (MeetEvents::renewIfDue()['subscribed'] ?? '') === 'subscriptions/SUB0');
    $setState(['transcript' => 'none']);
    Database::metaSet('meet_events_sub', (string) json_encode(['name' => 'subscriptions/SUB1', 'at' => time()]));
    $setState(['transcript' => 'ready', 'conf_start' => gmdate('c', $start), 'conf_end' => gmdate('c', $start + 3600), 'pull' => [
        ['ackId' => 'a1', 'message' => ['attributes' => ['ce-type' => 'google.workspace.meet.transcript.v2.fileGenerated'], 'data' => base64_encode(json_encode(['transcript' => ['name' => 'conferenceRecords/C1/transcripts/T1']]))]],
        ['ackId' => 'a2', 'message' => ['attributes' => ['ce-type' => 'google.workspace.meet.participant.v2.joined'], 'data' => base64_encode(json_encode(['participantSession' => ['name' => 'conferenceRecords/ZZZ/participants/x/participantSessions/y']]))]],
    ]]);
    $clearLog();
    $d = MeetEvents::drain();
    ck('events: the queue is drained; "transcript ready" finishes the meeting at once', $d['received'] === 2 && $d['handled'] === 1
        && (string) $mp->query('SELECT source FROM meeting_transcripts WHERE meeting_id = ' . $mid)->fetchColumn() === 'google' && MeetingMinutes::nudged($mid, $occ, 'finished'));
    $ack = array_values(array_filter($readLog(), fn($x) => str_ends_with($x['path'], ':acknowledge')));
    ck('events: …every message is acknowledged, a stranger\'s too', ($ack[0]['body']['ackIds'] ?? []) === ['a1', 'a2']);

    /* Push, signed by Google */
    putenv('AV_MEET_PUSH_AUDIENCE=https://afrovanguard.test/webhooks/google-meet.php');
    $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pk, $pkPem);
    $det = openssl_pkey_get_details($pk);
    $b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64u($det['rsa']['n']), 'e' => $b64u($det['rsa']['e'])]]];
    Database::metaSet('google_oidc_jwks', (string) json_encode(['at' => time(), 'keys' => $jwks]));
    $tok = static fn(array $c): string => \Firebase\JWT\JWT::encode($c + ['iat' => time(), 'exp' => time() + 600], $pkPem, 'RS256', 'k1');
    $good = ['iss' => 'https://accounts.google.com', 'aud' => 'https://afrovanguard.test/webhooks/google-meet.php', 'email' => 'pubsub@p.iam.gserviceaccount.com', 'email_verified' => true];
    ck('push: Google\'s signed token for our audience is accepted', (MeetEvents::verifyPush('Bearer ' . $tok($good))['email'] ?? '') === 'pubsub@p.iam.gserviceaccount.com');
    ck('push: …another audience, another issuer, or no token, is not', MeetEvents::verifyPush('Bearer ' . $tok(['aud' => 'https://evil.test'] + $good)) === null
        && MeetEvents::verifyPush('Bearer ' . $tok(['iss' => 'https://evil.test'] + $good)) === null && MeetEvents::verifyPush('') === null);
    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($other, $otherPem);
    ck('push: …nor one signed with a key that is not Google\'s', MeetEvents::verifyPush('Bearer ' . \Firebase\JWT\JWT::encode($good + ['exp' => time() + 600], $otherPem, 'RS256', 'k1')) === null);

    $mp->prepare('DELETE FROM meeting_live_events WHERE meeting_id = ?')->execute([$mid]);
    $mp->prepare('DELETE FROM meeting_transcripts WHERE meeting_id = ?')->execute([$mid]);
    $mp->prepare('DELETE FROM meeting_nudges WHERE meeting_id = ?')->execute([$mid]);
    $mp->prepare('DELETE FROM meeting_attendees WHERE meeting_id = ?')->execute([$mid]);
    $mp->prepare('DELETE FROM meetings WHERE id = ?')->execute([$mid]);
}
if (is_resource($mStub)) { proc_terminate($mStub); proc_close($mStub); }
@unlink($mState); @unlink($mState . '.log');
Database::metaSet('meet_events_sub', ''); Database::metaSet('google_oidc_jwks', '');
$mp->exec('DELETE FROM lms_users WHERE id BETWEEN 9800 AND 9899');
foreach ($mEnv as $k => $v) { if ($v === false) putenv($k); else putenv($k . '=' . $v); }
