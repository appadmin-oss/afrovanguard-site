<?php
/**
 * tests/recorder.test.php — the Meeting Recorder extension's server side (lib/Recorder.php).
 *
 *   1. Pairing: a code works once, expires, and the token it buys is stored
 *      only as a hash. Revoked and suspended devices are refused.
 *   2. A recording lands on the right meeting: the one with its Meet code that
 *      the user takes part in; otherwise a plain record (never a new Meet room).
 *   3. Uploads are idempotent and checked: same chunk twice is fine, a bad
 *      checksum or an oversized chunk is refused, a segment with a missing
 *      chunk says which, and the assembled file is exactly the bytes sent.
 *   4. The cron writes the minutes from the parts in order, retries a failed
 *      part, never overwrites someone else's transcript, and purges old audio.
 *   5. One user's device cannot touch another user's recording.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

reset_users();
putenv('AV_REC_DIR=' . sys_get_temp_dir() . '/av-test-rec-' . getmypid());
putenv('AV_REC_CHUNK_MAX=' . (256 * 1024));
Recorder::ensure();
$db = Database::pdo();
foreach (['recorder_devices', 'recorder_pairings', 'recordings', 'recording_segments', 'meeting_transcripts', 'meeting_attendees', 'meetings'] as $t) $db->exec('DELETE FROM ' . $t);

$tmp = static function (string $bytes): string {
    $f = tempnam(sys_get_temp_dir(), 'avrc');
    file_put_contents($f, $bytes);
    return $f;
};
$send = static function (array $dev, int $rid, int $seg, int $idx, string $bytes) use ($tmp): array {
    return Recorder::chunk($dev, $rid, $seg, $idx, hash('sha256', $bytes), $tmp($bytes), strlen($bytes));
};

/* ── 1. pairing ─────────────────────────────────────────────────────── */
$code = Recorder::createPairing(1);
ck('a pairing code is 32 hex characters', (bool) preg_match('/^[a-f0-9]{32}$/', $code));
ck('the code itself is not stored', (int) $db->query("SELECT COUNT(*) FROM recorder_pairings WHERE code_hash = " . $db->quote($code))->fetchColumn() === 0);
$p = Recorder::pair($code, "Ada's laptop\n");
ck('pairing returns a token', !empty($p['ok']) && str_starts_with((string) $p['token'], 'av_rec_'));
ck('pairing names the user', ($p['user']['email'] ?? '') === 'a@x.co');
$token = (string) $p['token'];
ck('the token itself is not stored', (int) $db->query("SELECT COUNT(*) FROM recorder_devices WHERE token_hash = " . $db->quote($token))->fetchColumn() === 0);
ck('the device name is cleaned', (string) $db->query('SELECT name FROM recorder_devices')->fetchColumn() === "Ada's laptop");
ck('a code works only once', empty(Recorder::pair($code, 'again')['ok']));
ck('a made-up code is refused', empty(Recorder::pair(str_repeat('a', 32), 'x')['ok']));
$old = Recorder::createPairing(1);
$db->exec('UPDATE recorder_pairings SET expires_at = ' . (time() - 1));
ck('an expired code is refused', empty(Recorder::pair($old, 'x')['ok']));

$dev = Recorder::device('Bearer ' . $token);
ck('the token finds its device', $dev !== null && (int) $dev['user_id'] === 1);
ck('a wrong token finds nothing', Recorder::device('Bearer av_rec_' . str_repeat('0', 48)) === null);
ck('a token without "Bearer" is refused', Recorder::device($token) === null);

$p2 = Recorder::pair(Recorder::createPairing(2), 'Bode');
$devB = Recorder::device('Bearer ' . $p2['token']);
ck('a second user pairs separately', $devB !== null && (int) $devB['user_id'] === 2);

$p3 = Recorder::pair(Recorder::createPairing(1), 'Spare');
$spareId = (int) Recorder::device('Bearer ' . $p3['token'])['id'];
ck('another user cannot revoke your device', !Recorder::revoke(2, $spareId));
ck('you can revoke your own', Recorder::revoke(1, $spareId));
ck('a revoked device is refused', Recorder::device('Bearer ' . $p3['token']) === null);
ck('devices() lists only the live ones', count(Recorder::devices(1)) === 1);

$db->exec("UPDATE lms_users SET status = 'suspended' WHERE id = 3");
$p4 = Recorder::pair(Recorder::createPairing(3), 'Chidi');
ck("a suspended user's device is refused", Recorder::device('Bearer ' . $p4['token']) === null);

/* ── 2. which meeting ───────────────────────────────────────────────── */
$db->prepare("INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,status,created_at,meet_code,bot_state) VALUES (3,'Board meeting','',?,60,'once','workspace',0,'scheduled',?,'abc-defg-hij','')")
   ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
$board = (int) $db->lastInsertId();
$db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)')->execute([$board, 'a@x.co']);

$s = Recorder::start($dev, ['meet_code' => 'abc-defg-hij', 'title' => 'Meet - abc', 'url' => 'https://meet.google.com/abc-defg-hij']);
ck('a recording finds the scheduled meeting by its code', !empty($s['ok']) && (int) $s['meeting']['id'] === $board && empty($s['meeting']['new']));
$rid = (int) $s['recording_id'];
ck('start reports the chunk size the host allows', (int) $s['chunk_bytes'] === 256 * 1024);

$s2 = Recorder::start($dev, ['meet_code' => 'zzz-zzzz-zzz', 'title' => 'Partner call']);
ck('an unknown code makes a plain record', !empty($s2['ok']) && !empty($s2['meeting']['new']));
$new = $db->query('SELECT * FROM meetings WHERE id = ' . (int) $s2['meeting']['id'])->fetch(PDO::FETCH_ASSOC);
ck('the plain record never opens a Meet room', (string) $new['provider'] === '' && (string) $new['origin'] === 'recorder');
ck('the recorder is an attendee of the plain record', Meetings::isParticipant(1, (int) $new['id']));
ck('its title says what it is', str_starts_with((string) $new['title'], 'Partner call · '));

$db->exec("UPDATE meetings SET bot_state = 'pending' WHERE id = $board");
$s3 = Recorder::start($dev, ['meet_code' => 'abc-defg-hij']);
ck('start says when the notetaker is already on its way', !empty($s3['notetaker_active']));
ck('and that another recording of the meeting is live', (int) $s3['others_recording'] >= 1);
Recorder::cancel($dev, (int) $s3['recording_id']);

$sB = Recorder::start($devB, ['meet_code' => 'not a meet code', 'meeting_id' => $board]);
ck("asking for a meeting you're not in gets your own record instead", (int) $sB['meeting']['id'] !== $board);
ck('a bad meet code is ignored', (string) $db->query('SELECT meet_code FROM recordings WHERE id = ' . (int) $sB['recording_id'])->fetchColumn() === '');

/* ── 3. uploads ─────────────────────────────────────────────────────── */
$seg0 = random_bytes(300000);
$parts = str_split($seg0, 200000);
ck('a chunk is stored', !empty($send($dev, $rid, 0, 0, $parts[0])['ok']));
ck('the same chunk again is harmless', !empty($send($dev, $rid, 0, 0, $parts[0])['already']));
$bad = Recorder::chunk($dev, $rid, 0, 1, hash('sha256', 'other'), $tmp($parts[1]), strlen($parts[1]));
ck('a checksum mismatch is refused and retried', empty($bad['ok']) && !empty($bad['retry']));
ck('a chunk over the host limit is refused', (int) ($send($dev, $rid, 0, 2, random_bytes(300 * 1024))['status'] ?? 0) === 413);
ck("another user's device cannot add to it", (int) ($send($devB, $rid, 0, 1, $parts[1])['status'] ?? 0) === 404);
$early = Recorder::segmentDone($dev, $rid, 0, 2, 0, 'audio/webm;codecs=opus');
ck('a segment with a missing chunk says which', empty($early['ok']) && ($early['missing'] ?? null) === [1]);
$send($dev, $rid, 0, 1, $parts[1]);
$done = Recorder::segmentDone($dev, $rid, 0, 2, 0, 'audio/webm;codecs=opus');
ck('the segment assembles', !empty($done['ok']) && (int) $done['bytes'] === strlen($seg0));
$path = (string) $db->query("SELECT path FROM recording_segments WHERE recording_id = $rid AND seg_no = 0")->fetchColumn();
ck('the file is exactly the bytes sent, in order', is_file($path) && hash_file('sha256', $path) === hash('sha256', $seg0));
ck('the chunk files are gone', !glob(dirname($path) . '/*.part'));
ck('assembling twice is harmless', !empty(Recorder::segmentDone($dev, $rid, 0, 2, 0, 'audio/webm')['already']));
ck('a chunk for an assembled segment is a no-op', !empty($send($dev, $rid, 0, 0, $parts[0])['already']));
ck('the recordings folder denies the web', is_file(Recorder::baseDir() . '/.htaccess'));

$seg1 = random_bytes(1000);
$send($dev, $rid, 1, 0, $seg1);
Recorder::segmentDone($dev, $rid, 1, 1, 600, 'audio/webm');
$e = Recorder::start($dev, ['title' => 'Empty part']);
ck('an empty segment (stop right after a rollover) is accepted as done', !empty(Recorder::segmentDone($dev, (int) $e['recording_id'], 0, 0, 0, 'audio/webm')['ok'])
    && (string) $db->query('SELECT state FROM recording_segments WHERE recording_id = ' . (int) $e['recording_id'])->fetchColumn() === 'done');
Recorder::cancel($dev, (int) $e['recording_id']);
$fin = Recorder::finish($dev, $rid, 3);
ck('finish names the segment still owed', ($fin['missing_segments'] ?? null) === [2]);
$send($dev, $rid, 2, 0, random_bytes(500));
Recorder::segmentDone($dev, $rid, 2, 1, 1200, 'audio/webm');
ck('finish with everything owes nothing', (Recorder::finish($dev, $rid, 3)['missing_segments'] ?? null) === []);
ck("another user's device cannot read its status", (int) (Recorder::status($devB, $rid)['status'] ?? 0) === 404);

/* ── 4. the cron ────────────────────────────────────────────────────── */
$calls = 0;
$fake = static function (string $bytes, string $mime) use (&$calls): array {
    $calls++;
    if ($calls === 2) return ['ok' => false, 'error' => 'Provider busy.'];   // the second call fails once
    return ['ok' => true, 'text' => 'Part ' . $calls . ' (' . strlen($bytes) . ' bytes)'];
};
$r1 = Recorder::sweep(20, $fake);
ck('a failing provider stops the tick instead of looping', $r1['transcribed'] === 1 && $calls === 2);
ck('the failed part waits for the next tick', (string) $db->query("SELECT state FROM recording_segments WHERE recording_id = $rid AND seg_no = 1")->fetchColumn() === 'ready');
$r2 = Recorder::sweep(20, $fake);
ck('the next tick finishes the parts', $r2['transcribed'] === 2);
ck('and writes the minutes', $r2['finalised'] >= 1);
$tr = $db->query("SELECT source, raw_text FROM meeting_transcripts WHERE meeting_id = $board")->fetch(PDO::FETCH_ASSOC);
ck('the transcript is on the meeting, from the recorder', $tr && $tr['source'] === 'recorder');
ck('the parts are in order with their start times', $tr && strpos($tr['raw_text'], "[00:00:00]\nPart 1") === 0
    && strpos($tr['raw_text'], '[00:10:00]') < strpos($tr['raw_text'], '[00:20:00]'));
ck('the recording is done', (string) $db->query("SELECT status FROM recordings WHERE id = $rid")->fetchColumn() === 'done');

$sd = Recorder::start($dev, ['meet_code' => 'abc-defg-hij']);
$rd = (int) $sd['recording_id'];
ck('start knows the meeting has minutes now', !empty($sd['has_transcript']));
$send($dev, $rd, 0, 0, 'abc');
Recorder::segmentDone($dev, $rd, 0, 1, 0, 'audio/webm');
Recorder::finish($dev, $rd, 1);
Recorder::sweep(20, static fn() => ['ok' => true, 'text' => 'A second take']);
ck('a second transcript does not overwrite the first', (string) $db->query("SELECT raw_text FROM meeting_transcripts WHERE meeting_id = $board")->fetchColumn() === $tr['raw_text']);
$dup = $db->query("SELECT status, transcript FROM recordings WHERE id = $rd")->fetch(PDO::FETCH_ASSOC);
ck('it is kept with the recording instead', $dup['status'] === 'duplicate' && str_contains($dup['transcript'], 'A second take'));

$sf = Recorder::start($dev, ['title' => 'Silent']);
$rf = (int) $sf['recording_id'];
$send($dev, $rf, 0, 0, 'xyz');
Recorder::segmentDone($dev, $rf, 0, 1, 0, 'audio/webm');
Recorder::finish($dev, $rf, 1);
for ($i = 0; $i < 4; $i++) Recorder::sweep(20, static fn() => ['ok' => false, 'error' => 'No speech.']);
ck('a part that keeps failing gives up after three tries', (int) $db->query("SELECT attempts FROM recording_segments WHERE recording_id = $rf")->fetchColumn() === 3);
ck('a recording with no words is marked failed, not left hanging', (string) $db->query("SELECT status FROM recordings WHERE id = $rf")->fetchColumn() === 'failed');

$sa = Recorder::start($dev, ['title' => 'Laptop died']);
$ra = (int) $sa['recording_id'];
$send($dev, $ra, 0, 0, 'abc');
Recorder::segmentDone($dev, $ra, 0, 1, 0, 'audio/webm');
$db->exec("UPDATE recordings SET updated_at = '2000-01-01 00:00:00' WHERE id = $ra");
$rs = Recorder::sweep(20, static fn() => ['ok' => true, 'text' => 'What we got']);
ck('a recording the browser abandoned is closed with what arrived', $rs['abandoned'] === 1
    && (string) $db->query("SELECT status FROM recordings WHERE id = $ra")->fetchColumn() === 'done');

putenv('AV_REC_RETAIN_DAYS=30');
$db->exec("UPDATE recordings SET updated_at = '2000-01-01 00:00:00' WHERE id = $rid");
$purged = Recorder::purge();
ck('audio past retention is deleted', $purged >= 3 && !is_file($path));
ck('the transcript outlives the audio', (bool) $db->query("SELECT 1 FROM meeting_transcripts WHERE meeting_id = $board")->fetchColumn());

/* ── 5. cancel ──────────────────────────────────────────────────────── */
$sc = Recorder::start($dev, ['title' => 'Oops']);
$rc = (int) $sc['recording_id'];
$send($dev, $rc, 0, 0, 'abc');
ck("another user's device cannot cancel it", (int) (Recorder::cancel($devB, $rc)['status'] ?? 0) === 404);
ck('cancel works for the owner', !empty(Recorder::cancel($dev, $rc)['ok']));
ck('cancel deletes the audio', !is_dir(Recorder::baseDir() . '/' . $rc));
ck('cancel retires the plain record it made', (string) $db->query('SELECT status FROM meetings WHERE id = ' . (int) $sc['meeting']['id'])->fetchColumn() === 'cancelled');
ck('a cancelled recording takes no more chunks', (int) ($send($dev, $rc, 0, 1, 'x')['status'] ?? 0) === 409);
ck('finished minutes cannot be cancelled away', (int) (Recorder::cancel($dev, $rid)['status'] ?? 0) === 409);

/* ── stitch on its own ──────────────────────────────────────────────── */
ck('stitch marks a lost part in place', Recorder::stitch([
    ['seg_no' => 1, 'state' => 'failed', 'offset_sec' => 600, 'text' => ''],
    ['seg_no' => 0, 'state' => 'done', 'offset_sec' => 0, 'text' => 'Hello'],
]) === "[00:00:00]\nHello\n\n[00:10:00]\n(This part could not be transcribed.)");
ck('stitch of nothing but failures is empty', Recorder::stitch([['seg_no' => 0, 'state' => 'failed', 'offset_sec' => 0, 'text' => '']]) === '');
ck('stitch passes an hour', str_contains(Recorder::stitch([['seg_no' => 0, 'state' => 'done', 'offset_sec' => 3725, 'text' => 'x']]), '[01:02:05]'));

putenv('AV_REC_RETAIN_DAYS'); putenv('AV_REC_CHUNK_MAX'); putenv('AV_REC_DIR');

/* ── v1.1: what the transcriber is told, and marked moments ─────────── */
reset_users();
foreach (['recordings', 'recording_segments', 'meeting_transcripts', 'meeting_attendees', 'meetings'] as $t) $db->exec('DELETE FROM ' . $t);
$db->exec("UPDATE lms_users SET name = 'Adaeze Okonkwo' WHERE id = 1");
$db->exec("UPDATE lms_users SET name = 'Babatunde Fashola' WHERE id = 2");
$db->prepare("INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,status,created_at,meet_code) VALUES (1,'VanQuest budget review','Q4 grants; venue for the summit',?,60,'once','workspace',0,'scheduled',?,'hhh-hhhh-hhh')")
   ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
$hm = (int) $db->lastInsertId();
$db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)')->execute([$hm, 'b@x.co']);
$p5 = Recorder::pair(Recorder::createPairing(1), 'Ada');
$devH = Recorder::device('Bearer ' . $p5['token']);
$rh = (int) Recorder::start($devH, ['meet_code' => 'hhh-hhhh-hhh'])['recording_id'];

$h0 = Recorder::hints($rh, 0);
ck('the transcriber is told the meeting title', str_contains($h0['whisper'], 'VanQuest budget review') && str_contains($h0['gemini'], 'VanQuest budget review'));
ck('and the names of the people in it, organiser included', str_contains($h0['whisper'], 'Babatunde Fashola') && str_contains($h0['whisper'], 'Adaeze Okonkwo'));
ck('and the organisation\'s own words', str_contains($h0['whisper'], 'Afrovanguard') && str_contains($h0['gemini'], 'CACENTRE'));
ck('Gemini is told about Nigerian English and Pidgin, and not to translate', str_contains($h0['gemini'], 'Pidgin') && str_contains($h0['gemini'], 'do not translate'));
ck('Gemini sees the agenda', str_contains($h0['gemini'], 'Q4 grants'));
ck('the first part has no "previous words"', !str_contains($h0['gemini'], 'continues from'));
putenv('AV_REC_VOCAB=Ikeja hub, Ndukwe');
ck('AV_REC_VOCAB adds terms', str_contains(Recorder::hints($rh, 0)['whisper'], 'Ikeja hub'));
putenv('AV_REC_VOCAB');

foreach ([0, 1] as $n) { $send($devH, $rh, $n, 0, "audio$n"); Recorder::segmentDone($devH, $rh, $n, 1, $n * 600, 'audio/webm'); }
Recorder::finish($devH, $rh, 2, [700, 30, 'x', -5, 30, 999999]);
ck('marks are cleaned and kept in order', (string) $db->query("SELECT marks FROM recordings WHERE id = $rh")->fetchColumn() === '[30,700]');
$seen = [];
Recorder::sweep(20, static function (string $b, string $m, array $hint = []) use (&$seen): array {
    $seen[] = $hint;
    return ['ok' => true, 'text' => count($seen) === 1 ? 'We agreed the venue is Ikeja.' : 'Next, the grants.'];
});
ck('the cron passes the hints to the transcriber', isset($seen[0]['whisper'], $seen[0]['gemini']));
ck('the second part is told how the first one ended', isset($seen[1]) && str_contains($seen[1]['whisper'], 'the venue is Ikeja') && str_contains($seen[1]['gemini'], 'Do not repeat'));
ck('Whisper\'s prompt stays inside what it reads', mb_strlen($seen[1]['whisper']) <= 800);
$tx = (string) $db->query("SELECT raw_text FROM meeting_transcripts WHERE meeting_id = $hm")->fetchColumn();
ck('a marked moment is noted in its own part', (bool) preg_match("~\\[00:00:00\\]\nWe agreed the venue is Ikeja\\.\n★ Marked as important by the person recording, at 00:00:30\\.~u", $tx));
ck('and a later one in the later part', str_contains($tx, "Next, the grants.\n★ Marked as important by the person recording, at 00:11:40."));

/* ── v1.2: speaker names ────────────────────────────────────────────── */
$v = OpenAi::parseVerbose(json_encode(['text' => 'Hello there. Fine thanks.', 'segments' => [
    ['start' => 0.0, 'end' => 1.4, 'text' => ' Hello there.'], ['start' => 1.6, 'end' => 3.0, 'text' => ' Fine thanks.'], ['start' => 'x', 'end' => 2, 'text' => 'bad']]]));
ck("Whisper's timed sentences are read", $v['ok'] && count($v['segments']) === 2 && $v['segments'][1] === ['start' => 1.6, 'end' => 3.0, 'text' => 'Fine thanks.']);
ck('an empty Whisper answer is a failure', empty(OpenAi::parseVerbose('{"text":""}')['ok']));
ck('the talk timeline is cleaned and ordered', Recorder::cleanTalk([[5, 6, 'them'], [1, 2, 'me'], [3, 2, 'me'], [1, 2, 'who'], 'x', [-1, 0.5, 'me']]) === [[0.0, 0.5, 'me'], [1.0, 2.0, 'me'], [5.0, 6.0, 'them']]);

$utt = [['start' => 0, 'end' => 2, 'text' => 'Good morning.'], ['start' => 2, 'end' => 4, 'text' => 'Shall we start?'],
        ['start' => 4.2, 'end' => 6, 'text' => 'Yes, the budget first.'], ['start' => 6, 'end' => 8, 'text' => 'Mm.'], ['start' => 8, 'end' => 9, 'text' => 'Agreed.']];
$talk = [[0, 4.1, 'me'], [4.3, 6, 'them'], [6, 7, 'me'], [7, 8, 'them'], [8.1, 9, 'them']];
ck('lines are named by who was talking, and one speaker\'s lines join up', Recorder::labelled($utt, $talk, ['me' => 'Ada', 'them' => 'Bode'])
    === "Ada: Good morning. Shall we start?\nBode: Yes, the budget first.\nMm.\nBode: Agreed.");
ck('in a group call the others are "Others", never a guess', str_starts_with(Recorder::labelled($utt, $talk, ['me' => 'Ada', 'them' => '']) , 'Ada: ') && str_contains(Recorder::labelled($utt, $talk, ['me' => 'Ada', 'them' => '']), "\nOthers: Yes"));
ck('without a timeline nothing is labelled', Recorder::labelled($utt, [], ['me' => 'Ada', 'them' => '']) === '');

// One-to-one: the meeting has exactly one other person.
$db->exec('DELETE FROM meeting_attendees');
$db->prepare("INSERT INTO meetings (creator_id,title,agenda,scheduled_at,duration_min,frequency,context,context_id,status,created_at,meet_code) VALUES (1,'Mentoring','',?,30,'once','workspace',0,'scheduled',?,'one-oneo-one')")
   ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
$mm = (int) $db->lastInsertId();
$db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?),(?,?)')->execute([$mm, 'a@x.co', $mm, 'b@x.co']);
$r1 = (int) Recorder::start($devH, ['meet_code' => 'one-oneo-one'])['recording_id'];
ck('a one-to-one call names both people', Recorder::speakers($r1) === ['me' => 'Adaeze Okonkwo', 'them' => 'Babatunde Fashola']);
$db->exec("UPDATE recordings SET people_max = 3 WHERE id = $r1");
ck('but not once Meet showed a third person', Recorder::speakers($r1)['them'] === '');
$db->exec("UPDATE recordings SET people_max = 0 WHERE id = $r1");
$db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)')->execute([$mm, 'c@x.co']);
ck('and not when the meeting has more than one other person', Recorder::speakers($r1)['them'] === '' && Recorder::speakers($r1)['me'] === 'Adaeze Okonkwo');
$db->exec("DELETE FROM meeting_attendees WHERE email = 'c@x.co'");

// The whole way: a talk timeline with the part, Whisper's timing, the minutes.
$send($devH, $r1, 0, 0, 'mentoring-audio');
Recorder::segmentDone($devH, $r1, 0, 1, 0, 'audio/webm', [[0, 4.1, 'me'], [4.3, 9, 'them'], ['junk']]);
ck('the part keeps its talk timeline', (string) $db->query("SELECT talk FROM recording_segments WHERE recording_id = $r1")->fetchColumn() === '[[0,4.1,"me"],[4.3,9,"them"]]');
$h1 = Recorder::hints($r1, 0);
ck('Gemini is told who the person recording is and when they speak', str_contains($h1['gemini'], 'Adaeze Okonkwo is the person recording') && str_contains($h1['gemini'], '0–4'));
ck('and, one-to-one, who the other person is', str_contains($h1['gemini'], 'The only other person in the call is Babatunde Fashola'));
Recorder::finish($devH, $r1, 1, [], 2);
ck('finish keeps how many people Meet showed', (int) $db->query("SELECT people_max FROM recordings WHERE id = $r1")->fetchColumn() === 2);
Recorder::sweep(20, static fn() => ['ok' => true, 'text' => 'Good morning. Yes, the budget first.', 'segments' => [['start' => 0, 'end' => 4, 'text' => 'Good morning.'], ['start' => 4.3, 'end' => 9, 'text' => 'Yes, the budget first.']]]);
$tx1 = (string) $db->query("SELECT raw_text FROM meeting_transcripts WHERE meeting_id = $mm")->fetchColumn();
ck('the minutes\' transcript carries the names', $tx1 === "[00:00:00]\nAdaeze Okonkwo: Good morning.\nBabatunde Fashola: Yes, the budget first.");
ck('the timed sentences are kept with the part', str_contains((string) $db->query("SELECT utterances FROM recording_segments WHERE recording_id = $r1")->fetchColumn(), '"start":4.3'));
