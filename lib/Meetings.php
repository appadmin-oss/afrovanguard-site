<?php
/**
 * lib/Meetings.php — the standardized Afrovanguard meeting system.
 *
 * One consistent way to schedule a meeting anywhere in the platform (the
 * portal workspace and mentorship both use this), so every meeting behaves the
 * same:
 *
 *   • A Google Meet link created via a real Google Calendar event, so the
 *     meeting syncs to everyone's calendar and an invite is sent. Google Meet
 *     is the only provider (no fallback).
 *   • A frequency / cadence (one-off, daily, weekdays, weekly, fortnightly,
 *     monthly) carried as a real Google recurrence rule when Google is on.
 *   • Otter-style structured minutes AFTER the meeting: paste (or auto-pull
 *     from the Google Meet transcript on Drive) the raw transcript and the
 *     Afrovanguard bot turns it into a summary, key points, decisions and
 *     assigned action items.
 *
 * Storage is portable (SQLite default; MySQL/Postgres via Database::translateDDL).
 */
declare(strict_types=1);

final class Meetings
{
    /** Cadence options → [human label, RRULE tail or '' for one-off]. */
    const FREQ = [
        'once'     => ['One-off',        ''],
        'daily'    => ['Every day',      'FREQ=DAILY'],
        'weekdays' => ['Every weekday',  'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR'],
        'weekly'   => ['Every week',     'FREQ=WEEKLY'],
        'biweekly' => ['Every 2 weeks',  'FREQ=WEEKLY;INTERVAL=2'],
        'monthly'  => ['Every month',    'FREQ=MONTHLY'],
    ];

    private static bool $ready = false;

    /** ── Schema (idempotent) ─────────────────────────────────────────── */
    public static function ensure(): void
    {
        if (self::$ready) return;
        $db  = Database::pdo();
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ddl = "
        CREATE TABLE IF NOT EXISTS meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_id INTEGER NOT NULL,
            title VARCHAR(200) NOT NULL DEFAULT '',
            agenda TEXT NOT NULL DEFAULT '',
            scheduled_at VARCHAR(32) NOT NULL DEFAULT '',
            duration_min INTEGER NOT NULL DEFAULT 30,
            frequency VARCHAR(16) NOT NULL DEFAULT 'once',
            context VARCHAR(16) NOT NULL DEFAULT 'workspace',
            context_id INTEGER NOT NULL DEFAULT 0,
            provider VARCHAR(12) NOT NULL DEFAULT '',
            meet_url VARCHAR(500) NOT NULL DEFAULT '',
            meet_code VARCHAR(60) NOT NULL DEFAULT '',
            google_event_id VARCHAR(128) NOT NULL DEFAULT '',
            auto_record INTEGER NOT NULL DEFAULT 0,
            bot_state VARCHAR(16) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_meetings_when ON meetings(scheduled_at);
        CREATE INDEX IF NOT EXISTS idx_meetings_creator ON meetings(creator_id, status);
        CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id INTEGER NOT NULL,
            email VARCHAR(200) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_mtg_att ON meeting_attendees(meeting_id);
        CREATE TABLE IF NOT EXISTS meeting_transcripts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id INTEGER NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'paste',
            raw_text TEXT NOT NULL DEFAULT '',
            summary TEXT NOT NULL DEFAULT '',
            highlights TEXT NOT NULL DEFAULT '',
            decisions TEXT NOT NULL DEFAULT '',
            action_items TEXT NOT NULL DEFAULT '',
            structured INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_mtg_tr ON meeting_transcripts(meeting_id);";
        Database::execSchema($db, $ddl);
        // Idempotent column adds for databases created before these fields existed.
        self::addCol('meetings', 'meet_code', "VARCHAR(60) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'auto_record', 'INTEGER NOT NULL DEFAULT 0');
        self::addCol('meetings', 'bot_state', "VARCHAR(16) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'bot_provider', "VARCHAR(16) NOT NULL DEFAULT ''");
        self::addCol('meetings', 'bot_ref', "VARCHAR(128) NOT NULL DEFAULT ''");
        self::$ready = true;
    }

    private static function addCol(string $table, string $col, string $decl): void
    {
        try {
            if (!Database::columnExists($table, $col)) {
                Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $decl);
            }
        } catch (Throwable $e) { /* already exists / race — fine */ }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function freqLabel(string $f): string { return self::FREQ[$f][0] ?? self::FREQ['once'][0]; }
    private static function freqKey(string $f): string { return isset(self::FREQ[$f]) ? $f : 'once'; }

    /**
     * Provision a Google Meet link by creating a real Google Calendar event —
     * this is what syncs the meeting to everyone's calendar and sends the
     * invite (sendUpdates=all) to the organiser + attendees, with the cadence
     * as an RRULE. Google Meet is the ONLY provider. Returns
     * ['url','provider','google_event_id']; url is '' when Google Workspace
     * Calendar isn't connected (caller surfaces a "connect Google" warning).
     */
    public static function provisionLink(string $title, string $startIso, int $durationMin, array $emails, string $agenda, string $frequency): array
    {
        $frequency = self::freqKey($frequency);
        if (class_exists('GoogleWorkspace') && GoogleWorkspace::calendarWriteEnabled()) {
            try {
                $rrule = self::FREQ[$frequency][1] ?? '';
                $ev = GoogleWorkspace::createMeetEvent(
                    $title, $startIso, $durationMin, $emails,
                    $agenda !== '' ? $agenda : 'Afrovanguard meeting.',
                    null, true,
                    $rrule !== '' ? ['RRULE:' . $rrule] : []
                );
                if ($ev && !empty($ev['meet_url'])) {
                    return ['url' => (string) $ev['meet_url'], 'provider' => 'google', 'google_event_id' => (string) ($ev['id'] ?? '')];
                }
            } catch (Throwable $e) { error_log('[meetings] google: ' . $e->getMessage()); }
        }
        return ['url' => '', 'provider' => '', 'google_event_id' => ''];
    }

    /** ── Schedule a meeting (workspace or mentorship) ─────────────────── */
    public static function schedule(int $uid, array $in): array
    {
        self::ensure();
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') return ['ok' => false, 'error' => 'Give the meeting a title.'];
        $when = trim((string) ($in['when'] ?? ''));
        if ($when === '') return ['ok' => false, 'error' => 'Pick a date and time.'];
        // The datetime-local value is the user's wall-clock in their timezone.
        $tz   = function_exists('av_user_tz') ? av_user_tz($uid) : 'Africa/Lagos';
        $ts   = self::localToTs($when, $tz);
        $whenUtc = gmdate('Y-m-d H:i:s', $ts);
        $dur  = max(5, min(600, (int) ($in['duration'] ?? 30)));
        $freq = self::freqKey((string) ($in['frequency'] ?? 'once'));
        $agenda = mb_substr(trim((string) ($in['agenda'] ?? '')), 0, 2000);
        $context = (string) ($in['context'] ?? 'workspace');
        if (!in_array($context, ['workspace', 'mentorship'], true)) $context = 'workspace';
        // Invite the organiser too, so everyone involved gets the calendar invite.
        $emails = self::cleanEmails($in['attendees'] ?? []);
        $me = self::userEmail($uid);
        if ($me !== '' && filter_var($me, FILTER_VALIDATE_EMAIL)) { array_unshift($emails, $me); $emails = array_values(array_unique($emails)); }
        $autoRec = !empty($in['auto_record']) ? 1 : 0;

        $db = Database::pdo();
        $db->prepare('INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, auto_record, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $title, $agenda, $whenUtc, $dur, $freq, $context, (int) ($in['context_id'] ?? 0), $autoRec, 'scheduled', self::now()]);
        $id = (int) $db->lastInsertId();

        // Google Meet is the only provider: create the Calendar event (which
        // syncs calendars + sends the invite) and take its Meet link.
        $link = self::provisionLink($title, gmdate('c', $ts), $dur, $emails, $agenda, $freq);
        $code = class_exists('GoogleWorkspace') ? GoogleWorkspace::meetCodeFromUrl($link['url']) : '';
        $db->prepare('UPDATE meetings SET meet_url = ?, provider = ?, google_event_id = ?, meet_code = ? WHERE id = ?')
           ->execute([$link['url'], $link['provider'], $link['google_event_id'], $code, $id]);

        $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)');
        foreach ($emails as $e) $ins->execute([$id, $e]);

        // ── Tell the humans. ─────────────────────────────────────────────
        //
        // Until now the ONLY notification was Google Calendar's own invite, sent
        // as a side effect of creating the event. So on any deployment where
        // Google Workspace Calendar was not connected — which is the default —
        // a meeting was scheduled and NOBODY WAS TOLD. The row existed, the
        // portal listed it for people who thought to look, and that was the
        // whole of it. That is the bug behind "the issues with meetings".
        //
        // The invite now goes out from us as well, always. When Google did
        // create the event this is a second touch rather than the only one,
        // which is the correct trade: a duplicate invite is a minor annoyance,
        // a missed meeting is not.
        self::sendInvites($id, $title, $ts, $dur, $freq, $agenda, $emails, $link['url'], $uid);

        // The recording bot (optional): dispatch to the selected provider.
        $provider = $autoRec ? self::botProvider() : '';
        if ($autoRec) self::requestBot($id, $link['url'], $provider);

        if (class_exists('Events')) { try { Events::emit('meeting.scheduled', ['id' => $id, 'by' => $uid, 'at' => $whenUtc]); } catch (Throwable $e) {} }
        $out = ['ok' => true, 'id' => $id, 'meeting' => self::get($uid, $id)];
        if ($link['url'] === '') $out['warning'] = 'Meeting saved, but no Google Meet link could be created — connect Google Workspace Calendar so links and invites are sent.';
        return $out;
    }

    /** Meetings the user created or is invited to, upcoming first then recent. */
    public static function listFor(int $uid, int $limit = 60): array
    {
        self::ensure();
        $email = self::userEmail($uid);
        $db = Database::pdo();
        // The limit is interpolated, not bound. It is clamped to an int on the
        // line above, so this is not an injection surface — and it has to be:
        // the connection runs with ATTR_EMULATE_PREPARES = false, where a bound
        // parameter in LIMIT is sent as a STRING and MySQL/Postgres reject
        // `LIMIT '60'` outright. On SQLite it happens to work, which is why the
        // whole meetings list would have died the day this moved to MySQL and
        // not one moment sooner.
        $lim = max(1, min(200, $limit));
        $st = $db->prepare(
            'SELECT DISTINCT m.* FROM meetings m
             LEFT JOIN meeting_attendees a ON a.meeting_id = m.id
             WHERE m.status <> \'cancelled\' AND (m.creator_id = ? OR a.email = ?)
             ORDER BY m.scheduled_at DESC LIMIT ' . $lim
        );
        $st->execute([$uid, $email]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = array_map(fn($r) => self::shape($r, $uid), $rows);

        // ── Upcoming first, soonest at the top; then the past, most recent
        // first. The SQL cannot express this in one ORDER BY without dialect-
        // specific tricks, and the docblock has always claimed it: a plain
        // `scheduled_at DESC` put next month's catch-up above this afternoon's
        // stand-up, so the list was least useful exactly when it mattered.
        $now = time();
        // Sorted on next_at, not scheduled_at — otherwise a weekly meeting sorts
        // by the day the series began and sinks into the past forever.
        $ts  = static fn (array $m): int => strtotime($m['next_at'] . ' UTC') ?: 0;
        usort($out, static function (array $a, array $b) use ($now, $ts): int {
            $fa = $ts($a) >= $now; $fb = $ts($b) >= $now;
            if ($fa !== $fb) return $fa ? -1 : 1;          // future block before past block
            return $fa ? $ts($a) <=> $ts($b)               // soonest upcoming first
                       : $ts($b) <=> $ts($a);              // most recent past first
        });
        return $out;
    }

    /**
     * One meeting, for somebody entitled to see it.
     *
     * ── THIS TOOK $uid AND IGNORED IT ────────────────────────────────────
     *
     * The signature always promised an authorisation check and the body never
     * performed one, so `portal/meetings.php?action=get&id=N` handed ANY signed-in
     * member ANY meeting by guessing an integer: the agenda, the whole attendee
     * list, and — the part that matters — the transcript, with its summary,
     * decisions and assigned action items. A private conversation about somebody's
     * performance was one URL away from everyone with a login.
     *
     * `isParticipant()` already existed and {@see saveTranscript} already called
     * it. This path simply never did. Returning null rather than a distinct error
     * keeps the endpoint's 404 honest: a member who is not on a meeting should not
     * even be able to learn that it exists.
     */
    public static function get(int $uid, int $id): ?array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return null;
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $m = self::shape($r, $uid);
        $m['attendees'] = self::attendees($id);
        $m['transcript'] = self::transcript($id);
        return $m;
    }

    public static function cancel(int $uid, int $id): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT creator_id, google_event_id FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['ok' => false, 'error' => 'Meeting not found.'];
        if ((int) $r['creator_id'] !== $uid) return ['ok' => false, 'error' => 'Only the organiser can cancel.'];
        $db->prepare('UPDATE meetings SET status = \'cancelled\' WHERE id = ?')->execute([$id]);
        if (!empty($r['google_event_id']) && class_exists('GoogleWorkspace')) {
            try { GoogleWorkspace::deleteCalendarEvent((string) $r['google_event_id']); } catch (Throwable $e) {}
        }
        return ['ok' => true];
    }

    /** ── Transcripts: store raw + AI-structure Otter-style ────────────── */
    public static function saveTranscript(int $uid, int $id, string $rawText, string $source = 'paste'): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        $rawText = trim($rawText);
        if ($rawText === '') return ['ok' => false, 'error' => 'Paste the transcript text first.'];
        $rawText = mb_substr($rawText, 0, 60000);
        $source  = in_array($source, ['paste', 'upload', 'google', 'bot'], true) ? $source : 'paste';
        $db = Database::pdo();
        $db->prepare('DELETE FROM meeting_transcripts WHERE meeting_id = ?')->execute([$id]);
        $db->prepare('INSERT INTO meeting_transcripts (meeting_id, source, raw_text, structured, created_at) VALUES (?,?,?,0,?)')
           ->execute([$id, $source, $rawText, self::now()]);
        $db->prepare('UPDATE meetings SET status = \'done\' WHERE id = ? AND status = \'scheduled\'')->execute([$id]);

        $st = self::structure($rawText);
        if ($st['ok']) {
            $db->prepare('UPDATE meeting_transcripts SET summary=?, highlights=?, decisions=?, action_items=?, structured=1 WHERE meeting_id=?')
               ->execute([
                   $st['summary'],
                   json_encode($st['highlights'], JSON_UNESCAPED_UNICODE),
                   json_encode($st['decisions'], JSON_UNESCAPED_UNICODE),
                   json_encode($st['action_items'], JSON_UNESCAPED_UNICODE),
                   $id,
               ]);
        }
        return ['ok' => true, 'structured' => $st['ok'], 'note' => $st['ok'] ? '' : ($st['error'] ?? ''), 'transcript' => self::transcript($id)];
    }

    /**
     * Turn a raw transcript into Otter-style structured minutes via the
     * Afrovanguard bot (Anthropic). Returns summary + highlights + decisions +
     * action_items (each action = {task, owner}). Best-effort: if AI isn't
     * configured or the JSON is unparseable, ok=false and the raw text still
     * stands.
     */
    public static function structure(string $rawText): array
    {
        $rawText = trim($rawText);
        if ($rawText === '') return ['ok' => false, 'error' => 'Empty transcript.'];
        $sys = 'You are a meeting-minutes assistant for the Afrovanguard organisation. '
             . 'Read the raw meeting transcript and return STRICT JSON only — no prose, no markdown fences. '
             . 'Schema: {"summary": string (3-5 sentence overview), '
             . '"highlights": string[] (key discussion points), '
             . '"decisions": string[] (decisions made), '
             . '"action_items": [{"task": string, "owner": string}] (owner "" if unassigned)}. '
             . 'Keep it faithful to the transcript; do not invent facts.';
        $prompt = "Transcript:\n\n" . mb_substr($rawText, 0, 20000);

        // Prefer Gemini Flash for meeting logging; fall back to the Anthropic bot.
        $res = null;
        if (class_exists('Gemini') && Gemini::configured()) {
            $res = Gemini::generate($prompt, ['system' => $sys, 'max_tokens' => 2048, 'temperature' => 0.1]);
        }
        if ((!$res || empty($res['ok'])) && class_exists('AvBot') && AvBot::configured()) {
            $res = AvBot::reply(mb_substr($prompt, 0, 11000), [], ['system' => $sys, 'max_tokens' => 1500]);
        }
        if (!$res || empty($res['ok'])) {
            return ['ok' => false, 'error' => ($res['error'] ?? null) ? (string) $res['error'] : 'AI summarisation is not configured (set AV_GEMINI_API_KEY).'];
        }
        $json = self::extractJson((string) $res['text']);
        if (!is_array($json)) return ['ok' => false, 'error' => 'Could not parse the AI summary.'];
        $acts = [];
        foreach (($json['action_items'] ?? []) as $a) {
            if (is_array($a)) $acts[] = ['task' => (string) ($a['task'] ?? ''), 'owner' => (string) ($a['owner'] ?? '')];
            elseif (is_string($a)) $acts[] = ['task' => $a, 'owner' => ''];
        }
        return [
            'ok'           => true,
            'summary'      => (string) ($json['summary'] ?? ''),
            'highlights'   => array_values(array_filter(array_map('strval', (array) ($json['highlights'] ?? [])))),
            'decisions'    => array_values(array_filter(array_map('strval', (array) ($json['decisions'] ?? [])))),
            'action_items' => array_values(array_filter($acts, fn($a) => $a['task'] !== '')),
        ];
    }

    /**
     * Best-effort auto-pull of a Google Meet transcript. Meet writes transcripts
     * to the host's Drive as a Doc named "<title> - Transcript"; find the most
     * recent match and read its text. Needs the Workspace service account +
     * Drive read scope; otherwise returns null and the user pastes manually.
     */
    public static function pullGoogleTranscript(int $uid, int $id): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        $m = self::get($uid, $id);
        if (!$m) return ['ok' => false, 'error' => 'Meeting not found.'];
        // Recall.ai meeting → pull straight from the bot.
        if (($m['bot_provider'] ?? '') === 'recall' && ($m['bot_ref'] ?? '') !== '') {
            $r = self::ingestFromRecall((string) $m['bot_ref']);
            if (!empty($r['ok'])) return array_merge($r, ['transcript' => self::transcript($id)]);
            // fall through to Google if Recall isn't ready
        }
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::configured()) {
            return ['ok' => false, 'error' => 'Google Workspace isn’t connected — paste the transcript instead.'];
        }
        try {
            // 1) The official Meet transcript via the Meet REST API (best source).
            $code = (string) ($m['meet_code'] ?? '');
            if ($code !== '') {
                $host = self::userEmail((int) $m['creator_id']);
                $afterTs = strtotime((string) $m['scheduled_at'] . ' UTC') ?: 0;
                $t = GoogleWorkspace::meetTranscriptText($code, $host, $afterTs);
                if ($t) return self::saveTranscript($uid, $id, $t, 'google');
            }
            // 2) Fallback: the transcript Doc Meet writes to the host's Drive.
            $files = GoogleWorkspace::driveFiles(null, 60);
            $needle = mb_strtolower($m['title']);
            $best = null;
            foreach ($files as $f) {
                $name = mb_strtolower((string) ($f['name'] ?? ''));
                if ($name === '') continue;
                if (mb_strpos($name, 'transcript') !== false && ($needle === '' || mb_strpos($name, mb_substr($needle, 0, 24)) !== false)) { $best = $f; break; }
            }
            if (!$best || empty($best['id'])) return ['ok' => false, 'error' => 'No matching Meet transcript found on Drive yet. It can take a few minutes after the call — or paste it here.'];
            $text = GoogleWorkspace::driveText((string) $best['id']);
            if (!$text) return ['ok' => false, 'error' => 'Found the transcript file but couldn’t read it.'];
            return self::saveTranscript($uid, $id, $text, 'google');
        } catch (Throwable $e) {
            error_log('[meetings] pull transcript: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not fetch the transcript.'];
        }
    }

    /**
     * Transcribe an uploaded recording with Gemini Flash, then structure it.
     * $mime e.g. audio/mpeg, audio/webm, audio/wav.
     */
    public static function transcribeAudio(int $uid, int $id, string $bytes, string $mime): array
    {
        self::ensure();
        if (!self::isParticipant($uid, $id)) return ['ok' => false, 'error' => 'Not your meeting.'];
        if (!class_exists('Gemini') || !Gemini::configured()) {
            return ['ok' => false, 'error' => 'Audio transcription (Gemini) isn’t configured (set AV_GEMINI_API_KEY).'];
        }
        $res = Gemini::transcribeAudio($bytes, $mime);
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Transcription failed.')];
        return self::saveTranscript($uid, $id, (string) $res['text'], 'gemini');
    }

    /** ── The recording bot ────────────────────────────────────────────── */

    /** A recording bot is available if ANY provider is wired. */
    public static function botConfigured(): bool { return self::botProvider() !== ''; }

    /**
     * Which recording-bot backend to use. Forced by AV_MEET_BOT_PROVIDER
     * (recall|google|webhook|none), else auto-detected: Recall.ai → custom
     * webhook worker → Google Meet native transcription.
     */
    public static function botProvider(): string
    {
        $p = strtolower(trim((string) getenv('AV_MEET_BOT_PROVIDER')));
        if (in_array($p, ['recall', 'google', 'webhook', 'none'], true)) return $p === 'none' ? '' : $p;
        if (class_exists('RecallBot') && RecallBot::configured()) return 'recall';
        if (trim((string) getenv('AV_MEET_BOT_JOIN_URL')) !== '') return 'webhook';
        if (class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled()) return 'google';
        return '';
    }

    /**
     * Dispatch the recording bot for a meeting to the chosen provider:
     *   • recall  — send a Recall.ai bot to join, record & transcribe; it posts
     *               transcript events back to the recall_webhook endpoint.
     *   • google  — Google records/transcribes the space natively; nothing to
     *               request now (we pull the transcript afterwards).
     *   • webhook — POST the join details to a custom recorder (AV_MEET_BOT_JOIN_URL)
     *               that calls back into bot_ingest.
     * Best-effort; on failure the manual paste / Google-transcript paths remain.
     */
    private static function requestBot(int $id, string $meetUrl, string $provider): void
    {
        $db = Database::pdo();
        $state = 'unconfigured'; $ref = '';

        if ($provider === 'recall' && class_exists('RecallBot') && RecallBot::configured()) {
            $wh = self::siteUrl('/portal/meetings.php?action=recall_webhook');
            $wt = RecallBot::webhookToken();
            if ($wt !== '') $wh .= '&t=' . rawurlencode($wt);
            $res = RecallBot::createBot($meetUrl, $wh);
            if (!empty($res['ok'])) { $state = 'requested'; $ref = (string) $res['bot_id']; }
            else { $state = 'error'; error_log('[meetings] recall: ' . (string) ($res['error'] ?? '')); }
        } elseif ($provider === 'google') {
            // Google Meet is recording/transcribing natively (space created with
            // auto-transcription ON) — pull the transcript after the call.
            $state = 'native';
        } elseif ($provider === 'webhook') {
            $ok = false;
            try {
                if (function_exists('curl_init')) {
                    $payload = [
                        'meeting_id' => $id, 'join_url' => $meetUrl,
                        'callback' => self::siteUrl('/portal/meetings.php?action=bot_ingest'),
                        'token' => self::botToken($id),
                    ];
                    $ch = curl_init(trim((string) getenv('AV_MEET_BOT_JOIN_URL')));
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
                        CURLOPT_HTTPHEADER => ['content-type: application/json'],
                    ]);
                    $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                    $ok = is_string($r) && $code < 400;
                }
            } catch (Throwable $e) { error_log('[meetings] bot join: ' . $e->getMessage()); }
            $state = $ok ? 'requested' : 'error';
        }
        $db->prepare('UPDATE meetings SET bot_state = ?, bot_provider = ?, bot_ref = ? WHERE id = ?')
           ->execute([$state, $provider, $ref, $id]);
    }

    private static function siteUrl(string $path): string
    {
        return rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/') . $path;
    }

    /**
     * Recall.ai webhook: on a terminal bot status, fetch the transcript and
     * store structured minutes. Auth is the shared webhook token (query ?t=).
     * Returns the API shape.
     */
    public static function recallWebhook(string $token, array $payload): array
    {
        self::ensure();
        $expected = class_exists('RecallBot') ? RecallBot::webhookToken() : '';
        if ($expected === '' || !hash_equals($expected, (string) $token)) return ['ok' => false, 'error' => 'Bad token.'];
        $event = (string) ($payload['event'] ?? '');
        $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $botId = (string) ($data['bot_id'] ?? ($data['bot']['id'] ?? ''));
        if ($botId === '') return ['ok' => true, 'note' => 'no bot id'];
        // Act on completion; ignore in-progress chatter.
        $status = (string) ($data['status']['code'] ?? $data['status'] ?? '');
        $terminal = in_array($status, ['done', 'call_ended', 'analysis_done', 'completed'], true) || strpos($event, 'done') !== false;
        if (!$terminal) return ['ok' => true, 'note' => 'ignored ' . $event . '/' . $status];
        return self::ingestFromRecall($botId);
    }

    /** Find the meeting for a Recall bot id, fetch its transcript, structure it. */
    public static function ingestFromRecall(string $botId): array
    {
        self::ensure();
        if (!class_exists('RecallBot')) return ['ok' => false, 'error' => 'Recall not available.'];
        $st = Database::pdo()->prepare('SELECT id FROM meetings WHERE bot_ref = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$botId]);
        $mid = (int) ($st->fetchColumn() ?: 0);
        if ($mid <= 0) return ['ok' => false, 'error' => 'Unknown bot.'];
        $text = RecallBot::fetchTranscript($botId);
        if ($text === '') return ['ok' => false, 'error' => 'Transcript not ready.'];
        return self::botIngest($mid, self::botToken($mid), $text);
    }

    /** HMAC token the recorder must echo back when posting a transcript. */
    public static function botToken(int $id): string
    {
        $secret = function_exists('av_secret') ? (string) av_secret() : (defined('APP_KEY') ? (string) APP_KEY : 'av');
        return hash_hmac('sha256', 'bot|' . $id, $secret);
    }

    /**
     * Ingestion endpoint for the recorder bot: it posts back the transcript (and
     * we structure it) once the meeting ends. Auth is the per-meeting HMAC token
     * (no user session — the bot is a service). Returns the API shape.
     */
    public static function botIngest(int $id, string $token, string $transcript): array
    {
        self::ensure();
        if (!hash_equals(self::botToken($id), (string) $token)) return ['ok' => false, 'error' => 'Bad token.'];
        $transcript = trim($transcript);
        if ($transcript === '') return ['ok' => false, 'error' => 'Empty transcript.'];
        $db = Database::pdo();
        // Save raw + structured minutes. Ownership check is bypassed (service),
        // so write directly rather than via saveTranscript (which is user-scoped).
        $db->prepare('DELETE FROM meeting_transcripts WHERE meeting_id = ?')->execute([$id]);
        $db->prepare('INSERT INTO meeting_transcripts (meeting_id, source, raw_text, structured, created_at) VALUES (?,?,?,0,?)')
           ->execute([$id, 'bot', mb_substr($transcript, 0, 60000), self::now()]);
        $db->prepare('UPDATE meetings SET status = \'done\', bot_state = \'done\' WHERE id = ?')->execute([$id]);
        $st = self::structure($transcript);
        if ($st['ok']) {
            $db->prepare('UPDATE meeting_transcripts SET summary=?, highlights=?, decisions=?, action_items=?, structured=1 WHERE meeting_id=?')
               ->execute([$st['summary'], json_encode($st['highlights'], JSON_UNESCAPED_UNICODE), json_encode($st['decisions'], JSON_UNESCAPED_UNICODE), json_encode($st['action_items'], JSON_UNESCAPED_UNICODE), $id]);
        }
        return ['ok' => true, 'structured' => $st['ok']];
    }

    /** ── helpers ──────────────────────────────────────────────────────── */

    /**
     * A row → the API shape.
     *
     * `$viewerId` is what makes `is_owner` true. It used to be hardcoded `false`
     * for every meeting and every viewer, so the field was not a fact about the
     * meeting, it was a constant — an organiser reading their own meeting was
     * told they did not own it. Nothing in the current UI trusted it (the JS
     * compares `creator_id` itself), which is precisely why it stayed wrong: a
     * lie nobody consults is a lie waiting for the next caller.
     */
    /**
     * For a repeating meeting, the next occurrence at or after now.
     *
     * ── WHY THIS IS COMPUTED AND NOT STORED ──────────────────────────────
     *
     * `scheduled_at` is the FIRST occurrence and never moves; the cadence goes
     * to Google as an RRULE and Google expands it on its side. Nothing expanded
     * it on ours, so a weekly stand-up set up in March showed "12 March" for the
     * rest of the year and sorted into the past the week after it started —
     * the recurring meetings, the ones people actually depend on, were the ones
     * the list handled worst.
     *
     * Deriving it keeps a single source of truth (the series start + the rule)
     * instead of a stored "next" that drifts whenever a job fails to run.
     * Month steps use DateTime's own arithmetic so a series that starts on the
     * 31st behaves the same way Google's does.
     */
    private static function nextOccurrence(string $startUtc, string $freq, ?int $now = null): string
    {
        $now = $now ?? time();
        $ts  = strtotime($startUtc . ' UTC');
        if ($ts === false) return $startUtc;
        if ($freq === 'once' || $ts >= $now) return $startUtc;

        $dt = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
        $step = match ($freq) {
            'daily'    => '+1 day',
            'weekly'   => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly'  => '+1 month',
            'weekdays' => '+1 day',
            default    => '',
        };
        if ($step === '') return $startUtc;

        // Bounded walk: a series started years ago must not spin. 800 steps
        // covers two years of daily and far more of anything else; past that we
        // hand back the start rather than guess.
        for ($i = 0; $i < 800 && $dt->getTimestamp() < $now; $i++) {
            $dt->modify($step);
            if ($freq === 'weekdays') {
                while (in_array((int) $dt->format('N'), [6, 7], true)) $dt->modify('+1 day');
            }
        }
        return $dt->getTimestamp() >= $now ? $dt->format('Y-m-d H:i:s') : $startUtc;
    }

    private static function shape(array $r, int $viewerId = 0): array
    {
        $freq = self::freqKey((string) ($r['frequency'] ?? 'once'));
        $next = self::nextOccurrence((string) $r['scheduled_at'], $freq);
        return [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'agenda'      => (string) $r['agenda'],
            'scheduled_at'=> (string) $r['scheduled_at'],
            // `next_at` is what a reader wants: for a one-off it is the meeting,
            // for a series it is the occurrence that has not happened yet.
            // `scheduled_at` stays as the series start so nothing downstream
            // that already relies on it changes meaning.
            'next_at'     => $next,
            'is_series'   => $freq !== 'once',
            'when_iso'    => gmdate('c', strtotime($next . ' UTC') ?: time()),
            'duration_min'=> (int) $r['duration_min'],
            'frequency'   => $freq,
            'frequency_label' => self::freqLabel($freq),
            'context'     => (string) $r['context'],
            'context_id'  => (int) $r['context_id'],
            'provider'    => (string) $r['provider'],
            'meet_url'    => (string) $r['meet_url'],
            'meet_code'   => (string) ($r['meet_code'] ?? ''),
            'auto_record' => (int) ($r['auto_record'] ?? 0) === 1,
            'bot_state'   => (string) ($r['bot_state'] ?? ''),
            'bot_provider'=> (string) ($r['bot_provider'] ?? ''),
            'bot_ref'     => (string) ($r['bot_ref'] ?? ''),
            'status'      => (string) $r['status'],
            'is_owner'    => $viewerId > 0 && (int) $r['creator_id'] === $viewerId,
            'creator_id'  => (int) $r['creator_id'],
        ];
    }

    /**
     * Email everyone the meeting details. Best-effort, never fatal.
     *
     * ── WHY IT SENDS EVEN WHEN GOOGLE ALREADY DID ────────────────────────
     *
     * Google's invite only reaches people whose address Calendar accepted, and
     * only while Workspace stays connected. Making our own send conditional on
     * Google having failed would mean the notification path that matters most is
     * the one that is never exercised — it would sit untested until the day
     * Calendar was disconnected, and then be discovered by someone missing a
     * meeting. Sending both ways means the path is warm.
     *
     * Times are rendered in each recipient's own timezone where the platform
     * knows it, because "14:00" without a zone is how a Lagos meeting gets
     * missed by somebody in Nairobi.
     *
     * @param list<string> $emails
     */
    private static function sendInvites(
        int $id, string $title, int $ts, int $durationMin, string $freq,
        string $agenda, array $emails, string $meetUrl, int $organiserId
    ): void {
        if (!class_exists('Mailer') || $emails === []) return;

        $organiser = '';
        try {
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$organiserId]);
            $organiser = trim((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}

        $site  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        $cad   = self::freqLabel($freq);
        $subj  = 'Meeting: ' . $title;

        foreach ($emails as $to) {
            try {
                // Per-recipient timezone when we know the member; the organiser's
                // otherwise. av_user_tz() falls back to Africa/Lagos itself.
                $tz = 'Africa/Lagos';
                try {
                    $u = Database::pdo()->prepare('SELECT id FROM lms_users WHERE LOWER(email) = ?');
                    $u->execute([strtolower($to)]);
                    $rid = (int) ($u->fetchColumn() ?: 0);
                    if ($rid > 0 && function_exists('av_user_tz')) $tz = av_user_tz($rid);
                } catch (Throwable $e) {}

                $when = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($tz));
                $whenLine = $when->format('l j F Y, H:i') . ' (' . $tz . ')';

                $lines = [
                    ($organiser !== '' ? htmlspecialchars($organiser) . ' has' : 'You have been') . ' invited you to <strong>' . htmlspecialchars($title) . '</strong>.',
                    '<strong>When:</strong> ' . htmlspecialchars($whenLine) . '<br>'
                        . '<strong>Length:</strong> ' . $durationMin . ' minutes<br>'
                        . '<strong>Repeats:</strong> ' . htmlspecialchars($cad),
                ];
                if ($agenda !== '') $lines[] = '<strong>Agenda</strong><br>' . nl2br(htmlspecialchars($agenda));
                if ($meetUrl === '') {
                    $lines[] = 'A video link has not been attached to this meeting yet — the organiser will share one before it starts.';
                }

                $cta = $meetUrl !== ''
                    ? ['text' => 'Join the meeting', 'url' => $meetUrl]
                    : ['text' => 'Open it in the portal', 'url' => $site . '/portal/#meetings'];

                $html = Mailer::shell('You’re invited: ' . $title, $lines, $cta,
                    'Meeting invitation from Afrovanguard.');
                Mailer::send($to, $subj, $html);
            } catch (Throwable $e) {
                error_log('[meetings] invite to ' . $to . ': ' . $e->getMessage());
            }
        }
    }

    private static function attendees(int $id): array
    {
        $st = Database::pdo()->prepare('SELECT email FROM meeting_attendees WHERE meeting_id = ?');
        $st->execute([$id]);
        return array_values(array_filter(array_map(fn($r) => (string) $r['email'], $st->fetchAll(PDO::FETCH_ASSOC) ?: [])));
    }

    private static function transcript(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meeting_transcripts WHERE meeting_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'source'       => (string) $r['source'],
            'structured'   => (int) $r['structured'] === 1,
            'summary'      => (string) $r['summary'],
            'highlights'   => json_decode((string) ($r['highlights'] ?: '[]'), true) ?: [],
            'decisions'    => json_decode((string) ($r['decisions'] ?: '[]'), true) ?: [],
            'action_items' => json_decode((string) ($r['action_items'] ?: '[]'), true) ?: [],
            'has_raw'      => trim((string) $r['raw_text']) !== '',
            'created_at'   => (string) $r['created_at'],
        ];
    }

    private static function isParticipant(int $uid, int $id): bool
    {
        $st = Database::pdo()->prepare('SELECT creator_id FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return false;
        if ((int) $r['creator_id'] === $uid) return true;
        $email = self::userEmail($uid);
        if ($email === '') return false;
        $a = Database::pdo()->prepare('SELECT 1 FROM meeting_attendees WHERE meeting_id = ? AND email = ?');
        $a->execute([$id, $email]);
        return (bool) $a->fetchColumn();
    }

    private static function cleanEmails($raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[,;\s]+/', (string) $raw);
        $out = [];
        foreach ((array) $list as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = strtolower($e);
        }
        return array_values(array_slice($out, 0, 50));
    }

    private static function userEmail(int $uid): string
    {
        try {
            $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            return strtolower((string) ($st->fetchColumn() ?: ''));
        } catch (Throwable $e) { return ''; }
    }

    /** Interpret a "Y-m-dTH:i" wall-clock in the user's tz as a UTC timestamp. */
    private static function localToTs(string $local, string $tz): int
    {
        $local = str_replace('T', ' ', trim($local));
        try {
            $dt = new DateTime($local, new DateTimeZone($tz));
            return $dt->getTimestamp();
        } catch (Throwable $e) {
            return strtotime($local) ?: time();
        }
    }

    /** Pull the first JSON object out of a model response (tolerates fences). */
    private static function extractJson(string $s): ?array
    {
        $s = trim($s);
        $s = preg_replace('/^```(?:json)?|```$/m', '', $s) ?? $s;
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        $j = json_decode(substr($s, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }
}
