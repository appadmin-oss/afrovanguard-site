<?php
/**
 * tests/meetbot.test.php — the AI notetaker in Google Meet.
 *
 * No provider is configured in the suite, so nothing here reaches Recall.ai.
 * That is deliberate: what these tests pin is the logic that decides WHETHER a
 * bot may be sent, WHO may send it, WHEN it should join, and what happens when
 * dispatch fails — all of which used to be either absent or wrong:
 *
 *   1. A bot could only be requested at schedule time, by ticking auto-record
 *      before the meeting existed. There was no way to add the AI to a meeting
 *      you were already in.
 *   2. The bot was created the moment the meeting was scheduled, with no join
 *      time — so a bot for next Tuesday tried to join an empty room today.
 *   3. A failed dispatch was written to bot_state and never retried, so a bot
 *      that could not be created simply never arrived.
 *   4. Nothing told attendees a meeting would be transcribed.
 *
 * Run via tests/run.php (provides ck() and reset_users()).
 */
declare(strict_types=1);

require_once AV_ROOT . '/lib/Meetings.php';
require_once AV_ROOT . '/lib/RecallBot.php';
require_once AV_ROOT . '/lib/AvRules.php';

/** Reach a private static without loosening the class's own visibility. */
$bcall = static function (string $m, array $args) {
    $r = new ReflectionMethod('Meetings', $m);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
};

/** Return the rules to their defaults so each block starts clean. */
$brules = static function (): void {
    AvRules::ensure();
    AvRules::resetAll('test');
};

reset_users();
Meetings::ensure();
$brules();
$db = Database::pdo();
$db->exec('DELETE FROM meetings');
$db->exec('DELETE FROM meeting_attendees');

/** A meeting row, straight to the table so no provider is contacted. */
$mk = static function (array $over = []) use ($db): int {
    $row = array_merge([
        'creator_id' => 1, 'title' => 'Test meeting', 'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'duration_min' => 30, 'meet_url' => 'https://meet.google.com/abc-defg-hij',
        'auto_record' => 0, 'bot_state' => '', 'status' => 'scheduled',
    ], $over);
    $db->prepare('INSERT INTO meetings (creator_id, title, scheduled_at, duration_min, meet_url, auto_record, bot_state, status, created_at, context, context_id, frequency, agenda, provider, meet_code, google_event_id) VALUES (?,?,?,?,?,?,?,?,?,\'workspace\',0,\'once\',\'\',\'google\',\'\',\'\')')
       ->execute([$row['creator_id'], $row['title'], $row['scheduled_at'], $row['duration_min'], $row['meet_url'],
                  $row['auto_record'], $row['bot_state'], $row['status'], gmdate('Y-m-d H:i:s')]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO meeting_attendees (meeting_id, email, user_id) VALUES (?,?,?)')->execute([$id, 'a@x.co', 1]);
    $db->prepare('INSERT INTO meeting_attendees (meeting_id, email, user_id) VALUES (?,?,?)')->execute([$id, 'b@x.co', 2]);
    return $id;
};

/* ---- Availability: a provider AND leadership's switch ---- */
// Nothing is wired in the suite, so no provider is available.
ck('bot: no provider means none configured', Meetings::botConfigured() === false);
ck('bot: no provider means not allowed', Meetings::botAllowed() === false);
ck('bot: on-demand needs a provider too', Meetings::botOnDemandAllowed() === false);

// The master switch must be able to say no even when a provider IS wired.
putenv('AV_MEET_BOT_PROVIDER=webhook');
putenv('AV_MEET_BOT_JOIN_URL=https://example.invalid/join');
ck('bot: a forced provider is detected', Meetings::botProvider() === 'webhook');
ck('bot: a wired provider is allowed by default', Meetings::botAllowed() === true);

AvRules::save(['meetings.ai_notetaker' => '0'], 'test');
ck('bot: the master switch blocks it', Meetings::botAllowed() === false);
ck('bot: the master switch blocks on-demand too', Meetings::botOnDemandAllowed() === false);
$brules();
ck('bot: clearing the rule restores it', Meetings::botAllowed() === true);

// On-demand can be switched off without disabling scheduled recording.
AvRules::save(['meetings.bot_on_demand' => '0'], 'test');
ck('bot: on-demand can be switched off alone', Meetings::botOnDemandAllowed() === false && Meetings::botAllowed() === true);
$brules();

/* ---- Who may add it ---- */
$id = $mk();
$out = Meetings::inviteBot(3, $id);           // user 3 is not on this meeting
ck('bot: a non-participant cannot add it', empty($out['ok']) && strpos((string) $out['error'], 'Not your meeting') !== false);

$out = Meetings::inviteBot(2, 999999);        // no such meeting
ck('bot: an unknown meeting is refused', empty($out['ok']));

// An invited attendee — not just the creator — may add it.
$out = Meetings::inviteBot(2, $id);
ck('bot: an attendee may add it', array_key_exists('ok', $out));

/* ---- A meeting with no Meet link has nothing to join ---- */
$noLink = $mk(['meet_url' => '']);
$out = Meetings::inviteBot(1, $noLink);
ck('bot: refuses a meeting with no Meet link', empty($out['ok']) && strpos((string) $out['error'], 'no Google Meet link') !== false);

/* ---- Idempotency: a double-tap must not put two bots in the room ---- */
$live = $mk(['bot_state' => 'in_call', 'auto_record' => 1]);
$out = Meetings::inviteBot(1, $live);
ck('bot: a live bot is not sent twice', !empty($out['ok']) && !empty($out['already']));

$joining = $mk(['bot_state' => 'requested', 'auto_record' => 1]);
ck('bot: a requested bot is not re-sent', !empty(Meetings::inviteBot(1, $joining)['already']));

// A previous failure, though, SHOULD be retryable.
$failed = $mk(['bot_state' => 'error', 'auto_record' => 1]);
$out = Meetings::inviteBot(1, $failed);
ck('bot: a failed dispatch can be retried', empty($out['already']));

/* ---- Removing it ---- */
$rm = $mk(['bot_state' => 'in_call', 'auto_record' => 1]);
ck('bot: a non-participant cannot remove it', empty(Meetings::removeBot(3, $rm)['ok']));
$out = Meetings::removeBot(2, $rm);           // an attendee, not the owner
ck('bot: an attendee may remove it', !empty($out['ok']));
$row = $db->query('SELECT bot_state, auto_record FROM meetings WHERE id = ' . $rm)->fetch(PDO::FETCH_ASSOC);
ck('bot: removal records the state', (string) $row['bot_state'] === 'removed');
// Without this the nightly sweep would send the bot straight back in.
ck('bot: removal clears auto_record', (int) $row['auto_record'] === 0);

/* ---- Join time: the bug that made scheduled bots useless ---- */
$brules();
$soon = gmdate('Y-m-d H:i:s', time() + 3600);
$iso  = $bcall('botJoinAtIso', [$soon]);
ck('bot: join time is a valid timestamp', strtotime($iso) !== false);
// Default lead is 2 minutes, so it joins slightly BEFORE the meeting starts.
ck('bot: joins before the meeting starts', strtotime($iso) < strtotime($soon . ' UTC'));
ck('bot: joins about two minutes early', abs((strtotime($soon . ' UTC') - strtotime($iso)) - 120) <= 5);

AvRules::save(['meetings.bot_join_lead_min' => '10'], 'test');
$iso10 = $bcall('botJoinAtIso', [$soon]);
ck('bot: the lead time is configurable', abs((strtotime($soon . ' UTC') - strtotime($iso10)) - 600) <= 5);
$brules();

// A meeting that already started must not be given a join time in the past —
// providers reject that outright, so the bot would never be sent at all.
$past = gmdate('Y-m-d H:i:s', time() - 7200);
ck('bot: a past meeting joins now, not in the past', strtotime($bcall('botJoinAtIso', [$past])) >= time());

/* ---- The sweep ---- */
$db->exec('DELETE FROM meetings');
putenv('AV_MEET_BOT_PROVIDER=none');
ck('bot: the sweep does nothing with no provider', Meetings::dispatchDueBots() === 0);

putenv('AV_MEET_BOT_PROVIDER=webhook');
$brules();
// Due shortly, wants a bot, none dispatched yet → picked up.
$due  = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 120), 'auto_record' => 1, 'bot_state' => 'pending']);
// Far in the future → left alone until closer to the time.
$far  = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400), 'auto_record' => 1, 'bot_state' => 'pending']);
// Doesn't want one at all.
$none = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 120), 'auto_record' => 0]);
// Already has a live bot → must not be sent a second one.
$has  = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 120), 'auto_record' => 1, 'bot_state' => 'requested']);

$n = Meetings::dispatchDueBots();
ck('bot: the sweep dispatches exactly the due meeting', $n === 1);
ck('bot: the sweep left the distant meeting pending', (string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $far)->fetchColumn() === 'pending');
ck('bot: the sweep ignored the opted-out meeting', (string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $none)->fetchColumn() === '');
ck('bot: the sweep did not re-send a live bot', (string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $has)->fetchColumn() === 'requested');
// The join URL is unreachable, so dispatch fails — and that must be recorded,
// not silently swallowed, or the retry can never happen.
ck('bot: a failed dispatch is recorded', in_array((string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $due)->fetchColumn(), ['error', 'unconfigured'], true));

// A meeting that started while the cron was between runs is still picked up.
$db->exec('DELETE FROM meetings');
$late = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 300), 'auto_record' => 1, 'bot_state' => 'pending']);
ck('bot: a just-started meeting is still swept', Meetings::dispatchDueBots() === 1);

// Long past is not: nobody wants a notetaker joining yesterday's call.
$db->exec('DELETE FROM meetings');
$mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 4 * 3600), 'auto_record' => 1, 'bot_state' => 'pending']);
ck('bot: a long-finished meeting is left alone', Meetings::dispatchDueBots() === 0);

/* ---- Announcement: people are told, unless leadership says otherwise ---- */
$brules();
ck('bot: announcement is on by default', AvRules::bool('meetings.bot_announce') === true);
AvRules::save(['meetings.bot_announce' => '0'], 'test');
ck('bot: announcement can be switched off', AvRules::bool('meetings.bot_announce') === false);
$brules();

/* ---- RecallBot guards when unconfigured ---- */
ck('recall: unconfigured reports so', RecallBot::configured() === false);
ck('recall: createBot refuses without a key', empty(RecallBot::createBot('https://meet.google.com/x')['ok']));
ck('recall: removeBot refuses without a key', empty(RecallBot::removeBot('bot_1')['ok']));
ck('recall: botStatus is empty without a key', RecallBot::botStatus('bot_1') === '');
ck('recall: createBot refuses an empty URL', empty(RecallBot::createBot('')['ok']));

putenv('AV_MEET_BOT_PROVIDER');
putenv('AV_MEET_BOT_JOIN_URL');
$brules();
$db->exec('DELETE FROM meetings');
$db->exec('DELETE FROM meeting_attendees');
reset_users();

/* ════════════════════════════════════════════════════════════════
   Mentorship sessions — minutes and their own notetaker.
   These sessions used to be the only Afrovanguard meeting with no record of
   what was said: they could hold a LINK to a transcript, so the text lived
   elsewhere and nothing could read it.
   ════════════════════════════════════════════════════════════════ */
require_once AV_ROOT . '/lib/Mentorship.php';
reset_users();
$brules();
Mentorship::ensure();
$db->exec('DELETE FROM mentorships');
$db->exec('DELETE FROM mentor_sessions');
try { $db->exec('DELETE FROM mentor_session_minutes'); } catch (Throwable $e) {}

$db->exec("INSERT INTO mentorships (mentor_id, mentee_id, status, created_at, updated_at) VALUES (1,2,'active','2026-01-01','2026-01-01')");
$pairId = (int) $db->query('SELECT id FROM mentorships WHERE mentor_id=1')->fetchColumn();
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, meet_url, attendance, created_at) VALUES (?,?,?,?,'scheduled',?)")
   ->execute([$pairId, 'Check-in', gmdate('Y-m-d H:i:s', time() + 3600), 'https://meet.google.com/abc-defg-hij', gmdate('Y-m-d H:i:s')]);
$sesId = (int) $db->lastInsertId();

// Only the two people in the relationship may touch its record.
ck('session: a stranger cannot save a transcript', empty(Mentorship::saveSessionTranscript(3, $sesId, 'hello')['ok']));
ck('session: a stranger cannot read the minutes', Mentorship::sessionMinutes(3, $sesId) === null);
ck('session: a stranger cannot add the notetaker', empty(Mentorship::inviteSessionBot(3, $sesId)['ok']));
ck('session: a stranger cannot remove the notetaker', empty(Mentorship::removeSessionBot(3, $sesId)['ok']));
ck('session: an empty transcript is refused', empty(Mentorship::saveSessionTranscript(1, $sesId, '   ')['ok']));

// Either party may save one — a mentee is as entitled to the record as the mentor.
$sv = Mentorship::saveSessionTranscript(2, $sesId, "Mentor: How did it go?\nMentee: I finished the course.");
ck('session: the mentee may save a transcript', !empty($sv['ok']));
// No AI provider in the suite, so the raw text stands and structuring reports why.
ck('session: unstructured without AI', empty($sv['structured']));
ck('session: it says why it could not structure', trim((string) ($sv['note'] ?? '')) !== '');

$min = Mentorship::sessionMinutes(1, $sesId);
ck('session: the mentor can read what the mentee saved', is_array($min));
ck('session: the raw transcript is kept', strpos((string) $min['raw_text'], 'finished the course') !== false);
ck('session: minutes report their source', (string) $min['source'] === 'paste');

// Saving again replaces rather than accumulating.
Mentorship::saveSessionTranscript(1, $sesId, 'Second version of the transcript.');
$rows = (int) $db->query('SELECT COUNT(*) FROM mentor_session_minutes WHERE session_id = ' . $sesId)->fetchColumn();
ck('session: re-saving replaces the previous minutes', $rows === 1);

ck('session: structureSession refuses an empty transcript', empty(Mentorship::structureSession('')['ok']));
ck('session: structureSession explains a missing provider', !empty(Mentorship::structureSession('Some words.')['error']));

// sessions() must expose enough for the UI to offer the right controls.
$list = Mentorship::sessions($pairId);
ck('session: sessions() returns the row', count($list) === 1);
ck('session: sessions() reports has_minutes', $list[0]['has_minutes'] === true);
ck('session: sessions() reports bot_state', array_key_exists('bot_state', $list[0]));

// The notetaker needs a provider AND leadership's switch, exactly as meetings do.
putenv('AV_MEET_BOT_PROVIDER=none');
ck('session: no provider means the notetaker is refused', empty(Mentorship::inviteSessionBot(1, $sesId)['ok']));
putenv('AV_MEET_BOT_PROVIDER=webhook');
putenv('AV_MEET_BOT_JOIN_URL=https://example.invalid/join');
AvRules::save(['meetings.ai_notetaker' => '0'], 'test');
ck('session: the master switch blocks the notetaker', empty(Mentorship::inviteSessionBot(1, $sesId)['ok']));
$brules();

// A session with no Meet link has nothing for a bot to join.
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, meet_url, attendance, created_at) VALUES (?,?,?,'','scheduled',?)")
   ->execute([$pairId, 'No link', gmdate('Y-m-d H:i:s', time() + 3600), gmdate('Y-m-d H:i:s')]);
$noLinkSes = (int) $db->lastInsertId();
$nl = Mentorship::inviteSessionBot(1, $noLinkSes);
ck('session: a session with no Meet link is refused', empty($nl['ok']) && strpos((string) $nl['error'], 'no Google Meet link') !== false);

// A live bot is not sent twice.
$db->prepare("UPDATE mentor_sessions SET bot_state='in_call' WHERE id=?")->execute([$sesId]);
ck('session: a live notetaker is not re-sent', !empty(Mentorship::inviteSessionBot(1, $sesId)['already']));
// Either party may remove it.
ck('session: the mentee may remove the notetaker', !empty(Mentorship::removeSessionBot(2, $sesId)['ok']));
ck('session: removal is recorded', (string) $db->query('SELECT bot_state FROM mentor_sessions WHERE id=' . $sesId)->fetchColumn() === 'removed');

// The sweep picks up pending sessions the same way meetings are swept.
$db->exec('DELETE FROM mentor_sessions');
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, meet_url, attendance, bot_state, created_at) VALUES (?,?,?,?,'scheduled','pending',?)")
   ->execute([$pairId, 'Due soon', gmdate('Y-m-d H:i:s', time() + 120), 'https://meet.google.com/abc-defg-hij', gmdate('Y-m-d H:i:s')]);
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, meet_url, attendance, bot_state, created_at) VALUES (?,?,?,?,'scheduled','pending',?)")
   ->execute([$pairId, 'Far off', gmdate('Y-m-d H:i:s', time() + 7 * 86400), 'https://meet.google.com/abc-defg-hij', gmdate('Y-m-d H:i:s')]);
ck('session: the sweep dispatches only the due session', Mentorship::dispatchDueSessionBots() === 1);

// A cancelled session must never get a notetaker.
$db->exec('DELETE FROM mentor_sessions');
$db->prepare("INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, meet_url, attendance, bot_state, created_at) VALUES (?,?,?,?,'cancelled','pending',?)")
   ->execute([$pairId, 'Cancelled', gmdate('Y-m-d H:i:s', time() + 120), 'https://meet.google.com/abc-defg-hij', gmdate('Y-m-d H:i:s')]);
ck('session: a cancelled session is never swept', Mentorship::dispatchDueSessionBots() === 0);

// An unknown bot id is refused rather than silently attaching to something.
ck('session: an unknown bot id is refused', empty(Mentorship::ingestSessionFromRecall('bot_does_not_exist')['ok']));

putenv('AV_MEET_BOT_PROVIDER');
putenv('AV_MEET_BOT_JOIN_URL');
$brules();
reset_users();

/* ════════════════════════════════════════════════════════════════
   Attendee — the free, self-hostable notetaker.

   Recall is billed per meeting-hour, which for a movement running weekly
   mentorship sessions is a bill that grows with exactly the behaviour the
   system exists to encourage. Attendee is the same capability as an open
   project you host yourself.
   ════════════════════════════════════════════════════════════════ */
require_once AV_ROOT . '/lib/AttendeeBot.php';
$brules();
putenv('AV_MEET_BOT_PROVIDER');
putenv('AV_MEET_BOT_JOIN_URL');
putenv('AV_ATTENDEE_API_KEY');

ck('attendee: unconfigured without a key', !AttendeeBot::configured());
ck('attendee: createBot refuses without a key', empty(AttendeeBot::createBot('https://meet.google.com/x')['ok']));
ck('attendee: removeBot refuses without a key', empty(AttendeeBot::removeBot('bot_1')['ok']));
ck('attendee: botStatus is empty without a key', AttendeeBot::botStatus('bot_1') === '');
ck('attendee: fetchTranscript is empty without a key', AttendeeBot::fetchTranscript('bot_1') === '');
ck('attendee: transcript is not ready without a key', !AttendeeBot::transcriptReady('bot_1'));

putenv('AV_ATTENDEE_API_KEY=test-key-not-real');
ck('attendee: a key configures it', AttendeeBot::configured());
ck('attendee: createBot refuses an empty URL', empty(AttendeeBot::createBot('')['ok']));

// The hosted service is NOT free, so the distinction has to be visible.
putenv('AV_ATTENDEE_BASE_URL');
ck('attendee: defaults to the hosted service', !AttendeeBot::selfHosted());
ck('attendee: the hosted base is used', strpos(AttendeeBot::base(), 'attendee.dev') !== false);
putenv('AV_ATTENDEE_BASE_URL=https://meetbot.example.org');
ck('attendee: a custom instance reads as self-hosted', AttendeeBot::selfHosted());
ck('attendee: the custom base carries the API path', AttendeeBot::base() === 'https://meetbot.example.org/api/v1');
putenv('AV_ATTENDEE_BASE_URL=https://meetbot.example.org/');
ck('attendee: a trailing slash is tolerated', AttendeeBot::base() === 'https://meetbot.example.org/api/v1');

// Auto-detection must prefer the free option when both are configured.
putenv('AV_MEET_BOT_PROVIDER');
putenv('AV_RECALL_API_KEY=recall-key-not-real');
ck('attendee: preferred over Recall when both are set', Meetings::botProvider() === 'attendee');
putenv('AV_ATTENDEE_API_KEY');
ck('attendee: Recall is still used when Attendee is absent', Meetings::botProvider() === 'recall');
putenv('AV_RECALL_API_KEY');
putenv('AV_ATTENDEE_API_KEY=test-key-not-real');

// An explicit choice still wins over the preference.
putenv('AV_MEET_BOT_PROVIDER=google');
ck('attendee: an explicit provider overrides the preference', Meetings::botProvider() === 'google');
putenv('AV_MEET_BOT_PROVIDER=attendee');
ck('attendee: it can be chosen explicitly', Meetings::botProvider() === 'attendee');

// Attendee has no dependable join-later field, so a distant meeting waits for
// the sweep rather than being dispatched at schedule time.
$db->exec('DELETE FROM meetings');
$far = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400), 'auto_record' => 1, 'bot_state' => 'pending']);
ck('attendee: a distant meeting is left for the sweep', Meetings::dispatchDueBots() === 0);
$due = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() + 120), 'auto_record' => 1, 'bot_state' => 'pending']);
ck('attendee: a due meeting is dispatched', Meetings::dispatchDueBots() === 1);
// The instance is unreachable in the suite, so dispatch fails — and that must be
// recorded so the sweep can retry rather than the bot silently never arriving.
ck('attendee: a failed dispatch is recorded',
   in_array((string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $due)->fetchColumn(), ['error', 'unconfigured'], true));

/* ---- Polling: no public webhook required ---- */
$db->exec('DELETE FROM meetings');
ck('poll: nothing to do with no bots in flight', Meetings::pollBotTranscripts() === 0);

// Only bots actually in flight are polled. A finished, a removed and a
// never-dispatched meeting must all be left alone.
$done    = $mk(['bot_state' => 'done', 'auto_record' => 1]);
$removed = $mk(['bot_state' => 'removed', 'auto_record' => 1]);
$never   = $mk(['bot_state' => '', 'auto_record' => 1]);
ck('poll: finished and untouched meetings are skipped', Meetings::pollBotTranscripts() === 0);

// A bot in flight IS polled — the instance is unreachable here, so nothing is
// ingested, but it must not throw and must not corrupt the row.
$db->exec('DELETE FROM meetings');
$flying = $mk(['bot_state' => 'requested', 'auto_record' => 1]);
$db->prepare("UPDATE meetings SET bot_provider='attendee', bot_ref='bot-abc' WHERE id=?")->execute([$flying]);
ck('poll: an unreachable bot ingests nothing', Meetings::pollBotTranscripts() === 0);
ck('poll: the row survives an unreachable poll',
   (string) $db->query('SELECT bot_state FROM meetings WHERE id = ' . $flying)->fetchColumn() === 'requested');

// A meeting older than a day is stale and must not be polled forever.
$db->exec('DELETE FROM meetings');
$stale = $mk(['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400), 'bot_state' => 'requested', 'auto_record' => 1]);
$db->prepare("UPDATE meetings SET bot_provider='attendee', bot_ref='bot-old' WHERE id=?")->execute([$stale]);
ck('poll: a stale meeting is not polled', Meetings::pollBotTranscripts() === 0);

// The master switch stops polling as well as dispatch.
$db->exec('DELETE FROM meetings');
$f2 = $mk(['bot_state' => 'requested', 'auto_record' => 1]);
$db->prepare("UPDATE meetings SET bot_provider='attendee', bot_ref='bot-xyz' WHERE id=?")->execute([$f2]);
AvRules::save(['meetings.ai_notetaker' => '0'], 'test');
ck('poll: the master switch stops polling too', Meetings::pollBotTranscripts() === 0);
$brules();

putenv('AV_MEET_BOT_PROVIDER');
putenv('AV_ATTENDEE_API_KEY');
putenv('AV_ATTENDEE_BASE_URL');
$db->exec('DELETE FROM meetings');
$db->exec('DELETE FROM meeting_attendees');
reset_users();
