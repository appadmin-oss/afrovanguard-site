<?php
/**
 * lib/Meetings.php — the standardized Afrovanguard meeting system.
 *
 * One consistent way to schedule a meeting anywhere in the platform (the
 * portal workspace and mentorship both use this), so every meeting behaves the
 * same:
 *
 *   • A working join link EVERY time — Google Meet when the org's Google
 *     Workspace calendar is connected, otherwise an auto-provisioned built-in
 *     room (Jitsi, no account/config needed). Scheduling never dead-ends.
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
            google_event_id VARCHAR(128) NOT NULL DEFAULT '',
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
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        self::$ready = true;
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function freqLabel(string $f): string { return self::FREQ[$f][0] ?? self::FREQ['once'][0]; }
    private static function freqKey(string $f): string { return isset(self::FREQ[$f]) ? $f : 'once'; }

    /**
     * The shared link provisioner — the heart of "standardized". Returns
     * ['url','provider','google_event_id']. Prefers a real Google Meet event
     * (and invites attendees, with recurrence); falls back to a stable built-in
     * room so a link is ALWAYS produced.
     */
    public static function provisionLink(string $title, string $startIso, int $durationMin, array $emails, string $agenda, string $frequency, string $roomSalt): array
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
        return ['url' => self::roomUrl($roomSalt), 'provider' => 'jitsi', 'google_event_id' => ''];
    }

    /** A stable, unguessable built-in room URL — same for everyone with the
     *  link, no Google/account needed. Host overridable via AV_MEET_ROOM_BASE. */
    public static function roomUrl(string $salt): string
    {
        $secret = function_exists('av_secret') ? (string) av_secret() : (defined('APP_KEY') ? (string) APP_KEY : 'av');
        $room = 'Afrovanguard-' . substr(hash('sha256', 'mtg|' . $salt . '|' . $secret), 0, 22);
        $base = rtrim((string) (getenv('AV_MEET_ROOM_BASE') ?: 'https://meet.jit.si'), '/');
        return $base . '/' . $room . '#config.prejoinPageEnabled=false';
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
        $emails  = self::cleanEmails($in['attendees'] ?? []);

        $db = Database::pdo();
        $db->prepare('INSERT INTO meetings (creator_id, title, agenda, scheduled_at, duration_min, frequency, context, context_id, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $title, $agenda, $whenUtc, $dur, $freq, $context, (int) ($in['context_id'] ?? 0), 'scheduled', self::now()]);
        $id = (int) $db->lastInsertId();

        $link = self::provisionLink($title, gmdate('c', $ts), $dur, $emails, $agenda, $freq, 'mtg-' . $id);
        $db->prepare('UPDATE meetings SET meet_url = ?, provider = ?, google_event_id = ? WHERE id = ?')
           ->execute([$link['url'], $link['provider'], $link['google_event_id'], $id]);

        $ins = $db->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?,?)');
        foreach ($emails as $e) $ins->execute([$id, $e]);

        if (class_exists('Events')) { try { Events::emit('meeting.scheduled', ['id' => $id, 'by' => $uid, 'at' => $whenUtc]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $id, 'meeting' => self::get($uid, $id)];
    }

    /** Meetings the user created or is invited to, upcoming first then recent. */
    public static function listFor(int $uid, int $limit = 60): array
    {
        self::ensure();
        $email = self::userEmail($uid);
        $db = Database::pdo();
        $st = $db->prepare(
            'SELECT DISTINCT m.* FROM meetings m
             LEFT JOIN meeting_attendees a ON a.meeting_id = m.id
             WHERE m.status <> \'cancelled\' AND (m.creator_id = ? OR a.email = ?)
             ORDER BY m.scheduled_at DESC LIMIT ?'
        );
        $st->execute([$uid, $email, max(1, min(200, $limit))]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => self::shape($r), $rows);
    }

    public static function get(int $uid, int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $m = self::shape($r);
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
        if (!class_exists('AvBot') || !AvBot::configured()) {
            return ['ok' => false, 'error' => 'AI summarisation is not configured (set ANTHROPIC_API_KEY).'];
        }
        $sys = 'You are a meeting-minutes assistant for the Afrovanguard organisation. '
             . 'Read the raw meeting transcript and return STRICT JSON only — no prose, no markdown fences. '
             . 'Schema: {"summary": string (3-5 sentence overview), '
             . '"highlights": string[] (key discussion points), '
             . '"decisions": string[] (decisions made), '
             . '"action_items": [{"task": string, "owner": string}] (owner "" if unassigned)}. '
             . 'Keep it faithful to the transcript; do not invent facts.';
        $res = AvBot::reply("Transcript:\n\n" . mb_substr($rawText, 0, 11000), [], ['system' => $sys, 'max_tokens' => 1500]);
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'AI error.')];
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
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::configured()) {
            return ['ok' => false, 'error' => 'Google Workspace isn’t connected — paste the transcript instead.'];
        }
        $m = self::get($uid, $id);
        if (!$m) return ['ok' => false, 'error' => 'Meeting not found.'];
        try {
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

    /** ── helpers ──────────────────────────────────────────────────────── */

    private static function shape(array $r): array
    {
        $freq = self::freqKey((string) ($r['frequency'] ?? 'once'));
        return [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'agenda'      => (string) $r['agenda'],
            'scheduled_at'=> (string) $r['scheduled_at'],
            'when_iso'    => gmdate('c', strtotime((string) $r['scheduled_at'] . ' UTC') ?: time()),
            'duration_min'=> (int) $r['duration_min'],
            'frequency'   => $freq,
            'frequency_label' => self::freqLabel($freq),
            'context'     => (string) $r['context'],
            'context_id'  => (int) $r['context_id'],
            'provider'    => (string) $r['provider'],
            'meet_url'    => (string) $r['meet_url'],
            'status'      => (string) $r['status'],
            'is_owner'    => false,
            'creator_id'  => (int) $r['creator_id'],
        ];
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
