<?php
/**
 * lib/MeetingLive.php — what happens in a meeting, captured as it happens.
 *
 * A meeting used to leave nothing behind unless somebody pasted a transcript.
 * Who came, when they came, how long they stayed, what was said: all of it was
 * typed up afterwards from memory, or not at all. This collects it while the
 * meeting runs, from whichever sources are available, into one event log per
 * occurrence — and when the meeting ends, turns that log into attendance and a
 * transcript that the minutes are drafted from (MeetingMinutes).
 *
 * Sources, best first; any combination works and nothing requires all:
 *
 *   · GOOGLE MEET (Meet REST API v2, polled by the cron while a meeting is on
 *     and shortly after): every participant with when they first joined and
 *     last left, their Workspace account mapped to an email through the
 *     directory. No bot in the call. Needs the Workspace service account with
 *     meetings.space.readonly — the same credentials Meetings already uses.
 *   · THE NOTETAKER BOT (Attendee, self-hosted): with AV_ATTENDEE_WEBHOOK_SECRET
 *     set, the bot pushes joins, leaves and each finished utterance here as they
 *     happen, signed (X-Webhook-Signature, verified in AttendeeBot).
 *   · CHECK-IN IN THE ROOM: the live room shows a QR code (chillerlan/php-qrcode)
 *     that changes every minute; scanning it with a phone checks that person
 *     in. A photo of it sent to somebody at home stops working a minute later.
 *   · CAPTIONS FROM THE ROOM: the secretary's browser can caption the room
 *     (the Web Speech API, in Chrome, Edge and Safari) and stream each finished
 *     sentence here. Free, no account, and enough for an in-person meeting.
 *   · "I'M HERE": a volunteer can mark themselves in from the hub. Recorded as
 *     self-reported, and weighed as such.
 *
 * Every event carries its source and an external id, and is stored once
 * however often it arrives — the bot retries, the cron re-reads Google, phones
 * scan twice.
 */
declare(strict_types=1);

final class MeetingLive
{
    /** Joining this long after the start still counts as on time. */
    public const LATE_GRACE_MIN = 10;
    /** The live window: from this long before the start… */
    public const OPEN_BEFORE_MIN = 30;
    /** …to this long after the end. */
    public const OPEN_AFTER_MIN = 60;
    public const SOURCES = ['google' => 'Google Meet', 'bot' => 'Notetaker', 'checkin' => 'Checked in', 'self' => 'Said they were here', 'browser' => 'Room captions', 'manual' => 'Added by hand'];

    private static bool $ready = false;
    private static array $emailCache = [];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS meeting_live_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id INTEGER NOT NULL,
            occurrence VARCHAR(20) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            source VARCHAR(12) NOT NULL,
            ext_id VARCHAR(120) NOT NULL,
            person_key VARCHAR(160) NOT NULL DEFAULT '',
            name VARCHAR(160) NOT NULL DEFAULT '',
            email VARCHAR(200) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0,
            at VARCHAR(32) NOT NULL,
            text TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS meeting_google_people (
            google_user VARCHAR(80) NOT NULL PRIMARY KEY,
            email VARCHAR(200) NOT NULL DEFAULT '',
            checked_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE UNIQUE INDEX uq_live_event ON meeting_live_events (meeting_id, source, ext_id)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_live_occ ON meeting_live_events (meeting_id, occurrence, id)'); } catch (Throwable $e) {}
    }

    private static function meeting(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The occurrence whose live window holds a moment, or '' if none does. */
    public static function occurrenceAt(array $m, int $ts): string
    {
        $dur = (int) $m['duration_min'] * 60;
        foreach (MeetingMinutes::occurrences($m, $ts - $dur - self::OPEN_AFTER_MIN * 60, $ts + self::OPEN_BEFORE_MIN * 60) as $s) {
            if ($ts >= $s - self::OPEN_BEFORE_MIN * 60 && $ts <= $s + $dur + self::OPEN_AFTER_MIN * 60) return MeetingMinutes::occurrenceKey($s);
        }
        return '';
    }

    /** Is it on now (or about to be): the live room is open. */
    public static function isOpen(array $m, string $occ, ?int $now = null): bool
    {
        $now = $now ?? time();
        $s = strtotime($occ . ' UTC');
        return $s !== false && $now >= $s - self::OPEN_BEFORE_MIN * 60 && $now <= $s + (int) $m['duration_min'] * 60 + self::OPEN_AFTER_MIN * 60;
    }

    /** Who an event is about: a site account when one can be found. */
    private static function resolve(int $meetingId, string $name, string $email, int $uid): array
    {
        $pdo = Database::pdo();
        $email = strtolower(trim($email));
        if ($uid <= 0 && $email !== '') {
            $st = $pdo->prepare('SELECT id, name FROM lms_users WHERE LOWER(email) = ?');
            $st->execute([$email]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $uid = (int) $r['id']; if ($name === '') $name = (string) $r['name']; }
        }
        if ($uid <= 0 && trim($name) !== '') {
            /* A display name matched against the people invited — and only
               when exactly one of them has it. */
            $hits = [];
            foreach (MeetingMinutes::invitees($meetingId) as $p) {
                if ($p['id'] > 0 && (mb_strtolower(trim($p['name'])) === mb_strtolower(trim($name)) || (class_exists('NgvFines') && NgvFines::nameScore($name, $p['name']) >= 90))) $hits[] = $p;
            }
            if (count($hits) === 1) { $uid = $hits[0]['id']; $email = $email ?: $hits[0]['email']; }
        }
        if ($uid > 0 && ($name === '' || $email === '')) {
            $st = $pdo->prepare('SELECT name, email FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = $name ?: (string) $r['name']; $email = $email ?: strtolower((string) $r['email']); }
        }
        $key = $uid > 0 ? 'u:' . $uid : ($email !== '' ? 'e:' . $email : 'n:' . mb_strtolower(trim($name)));
        return ['key' => mb_substr($key, 0, 160), 'name' => mb_substr($name, 0, 160), 'email' => mb_substr($email, 0, 200), 'user_id' => $uid];
    }

    /** Store one event, once. Returns true when it was new. */
    public static function record(int $meetingId, string $occ, string $kind, string $source, string $extId, array $who, int $at, string $text = ''): bool
    {
        self::ensure();
        if (!in_array($kind, ['join', 'leave', 'utterance', 'note'], true) || !isset(self::SOURCES[$source]) || $extId === '') return false;
        $p = self::resolve($meetingId, (string) ($who['name'] ?? ''), (string) ($who['email'] ?? ''), (int) ($who['user_id'] ?? 0));
        try {
            $st = Database::pdo()->prepare(Database::insertIgnore('meeting_live_events', ['meeting_id', 'occurrence', 'kind', 'source', 'ext_id', 'person_key', 'name', 'email', 'user_id', 'at', 'text', 'created_at']));
            $st->execute([$meetingId, $occ, $kind, $source, mb_substr($extId, 0, 120), $p['key'], $p['name'], $p['email'], $p['user_id'], gmdate('Y-m-d H:i:s', $at), mb_substr($text, 0, 4000), gmdate('Y-m-d H:i:s')]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) { error_log('[live] record: ' . $e->getMessage()); return false; }
    }

    /* ══ Source: the notetaker bot (Attendee) ═══════════════════════════════ */

    /** Attendee's live webhook. Verified, then joins/leaves/utterances stored. */
    public static function fromAttendee(string $raw, string $signature): array
    {
        if (!class_exists('AttendeeBot') || !AttendeeBot::verifyWebhook($raw, $signature)) return ['ok' => false, 'error' => 'Bad signature.', 'status' => 401];
        $p = json_decode($raw, true);
        if (!is_array($p)) return ['ok' => false, 'error' => 'Bad body.', 'status' => 400];
        $trigger = (string) ($p['trigger'] ?? '');
        $botId = (string) ($p['bot_id'] ?? '');
        $mid = (int) ($p['bot_metadata']['av_meeting_id'] ?? 0);
        if ($mid > 0 && !self::meeting($mid)) $mid = 0;
        if ($mid <= 0 && $botId !== '') {
            $st = Database::pdo()->prepare('SELECT id FROM meetings WHERE bot_ref = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$botId]);
            $mid = (int) ($st->fetchColumn() ?: 0);
        }
        $m = $mid > 0 ? self::meeting($mid) : null;
        if (!$m) return ['ok' => true, 'note' => 'not a meeting of ours'];
        $d = (array) ($p['data'] ?? []);
        $at = isset($d['timestamp_ms']) && (int) $d['timestamp_ms'] > 1_000_000_000_000 ? intdiv((int) $d['timestamp_ms'], 1000) : time();
        $occ = self::occurrenceAt($m, $at);
        if ($occ === '') return ['ok' => true, 'note' => 'outside the meeting'];
        $idem = (string) ($p['idempotency_key'] ?? '');
        if ($trigger === 'participant_events.join_leave') {
            $kind = (string) ($d['event_type'] ?? '') === 'leave' ? 'leave' : 'join';
            self::record($mid, $occ, $kind, 'bot', 'ev:' . ((string) ($d['id'] ?? '') ?: $idem), ['name' => (string) ($d['participant_name'] ?? '')], $at);
            return ['ok' => true, 'stored' => $kind];
        }
        if ($trigger === 'transcript.update') {
            $text = trim((string) ($d['transcription']['transcript'] ?? ''));
            if ($text === '') return ['ok' => true, 'note' => 'empty'];
            self::record($mid, $occ, 'utterance', 'bot', 'tr:' . ($idem ?: sha1($raw)), ['name' => (string) ($d['speaker_name'] ?? '')], $at, $text);
            return ['ok' => true, 'stored' => 'utterance'];
        }
        return ['ok' => true, 'note' => 'ignored ' . $trigger];
    }

    /* ══ Source: Google Meet ════════════════════════════════════════════════ */

    private static function googleEmail(string $googleUser): string
    {
        if ($googleUser === '') return '';
        if (isset(self::$emailCache[$googleUser])) return self::$emailCache[$googleUser];
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT email, checked_at FROM meeting_google_people WHERE google_user = ?');
        $st->execute([$googleUser]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && ((string) $r['email'] !== '' || (string) $r['checked_at'] > gmdate('Y-m-d H:i:s', time() - 86400))) return self::$emailCache[$googleUser] = (string) $r['email'];
        $email = (string) (GoogleWorkspace::directoryEmailForId($googleUser) ?? '');
        $pdo->prepare('DELETE FROM meeting_google_people WHERE google_user = ?')->execute([$googleUser]);
        $pdo->prepare('INSERT INTO meeting_google_people (google_user, email, checked_at) VALUES (?,?,?)')->execute([$googleUser, $email, gmdate('Y-m-d H:i:s')]);
        return self::$emailCache[$googleUser] = $email;
    }

    /**
     * Who to read Meet records as: the host whose calendar the meeting is on
     * (they own its space), the site's organiser, then the meeting's creator.
     */
    private static function readers(array $m): array
    {
        $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
        $st->execute([(int) $m['creator_id']]);
        return array_values(array_unique(array_filter([strtolower((string) ($m['google_host'] ?? '')), GoogleWorkspace::organiser(), strtolower((string) ($st->fetchColumn() ?: ''))])));
    }

    /** Read who is in the Meet now (and who has left), into the log. */
    public static function pollGoogle(array $m, string $occ): int
    {
        self::ensure();
        if (!class_exists('GoogleWorkspace') || !GoogleWorkspace::meetEnabled()) return 0;
        $code = (string) ($m['meet_code'] ?? '') ?: GoogleWorkspace::meetCodeFromUrl((string) $m['meet_url']);
        if ($code === '') return 0;
        $start = strtotime($occ . ' UTC') ?: 0;
        foreach (self::readers($m) as $who) {
            $conf = GoogleWorkspace::meetConference($code, $who, $start);
            if ($conf) return self::recordParticipants($m, $occ, GoogleWorkspace::conferenceParticipants($conf['name'], $who, !$conf['live']));
        }
        return 0;
    }

    /** Google's participants into the log: a join and a leave for each time they were in. */
    public static function recordParticipants(array $m, string $occ, array $people): int
    {
        $n = 0;
        foreach ($people as $p) {
            if ($p['id'] === '') continue;
            $who = ['name' => $p['name'], 'email' => $p['kind'] === 'signedin' ? self::googleEmail($p['google_user']) : ''];
            foreach ($p['sessions'] as $i => $x) {
                if ($x['start'] <= 0) continue;
                $ref = $x['id'] !== '' ? $x['id'] : $p['id'] . ':' . $i;
                if (self::record((int) $m['id'], $occ, 'join', 'google', 'j:' . $ref, $who, $x['start'])) $n++;
                if ($x['end'] !== null && self::record((int) $m['id'], $occ, 'leave', 'google', 'l:' . $ref, $who, (int) $x['end'])) $n++;
            }
        }
        return $n;
    }

    /* ══ Source: the room ═══════════════════════════════════════════════════ */

    /** The check-in code for a minute (the current one by default). */
    public static function checkinCode(int $meetingId, string $occ, ?int $minute = null): string
    {
        $minute = $minute ?? intdiv(time(), 60);
        return substr(hash_hmac('sha256', 'meeting-checkin|' . $meetingId . '|' . $occ . '|' . $minute, av_secret()), 0, 12);
    }

    public static function checkinUrl(int $meetingId, string $occ): string
    {
        return (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/portal/volunteer.php?' . http_build_query(['action' => 'checkin', 'm' => $meetingId, 'o' => $occ, 'c' => self::checkinCode($meetingId, $occ)]);
    }

    /** The QR to put on the screen in the room, as inline SVG. */
    public static function checkinQr(int $meetingId, string $occ): string
    {
        return GatePass::svg(self::checkinUrl($meetingId, $occ));
    }

    /** A phone scanned the code: this minute's or the last minute's counts. */
    public static function checkin(array $u, int $meetingId, string $occ, string $code): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || (string) $m['status'] === 'cancelled') return ['ok' => false, 'error' => 'No such meeting.'];
        if (!self::isOpen($m, $occ)) return ['ok' => false, 'error' => 'Check-in is open from half an hour before the meeting until an hour after it.'];
        $now = intdiv(time(), 60);
        if (!hash_equals(self::checkinCode($meetingId, $occ, $now), $code) && !hash_equals(self::checkinCode($meetingId, $occ, $now - 1), $code)) {
            return ['ok' => false, 'error' => 'That code has changed. Scan the one on the screen now.'];
        }
        self::addInvitee($meetingId, (string) ($u['email'] ?? ''));
        $new = self::record($meetingId, $occ, 'join', 'checkin', 'c:' . (int) $u['id'], ['user_id' => (int) $u['id']], time());
        return ['ok' => true, 'already' => !$new, 'title' => (string) $m['title']];
    }

    /** "I'm here" from the hub, during the meeting. */
    public static function here(array $u, int $meetingId, string $occ): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !MeetingMinutes::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        $s = strtotime($occ . ' UTC') ?: 0;
        if (time() < $s - 15 * 60 || time() > $s + (int) $m['duration_min'] * 60) return ['ok' => false, 'error' => 'You can say you are here while the meeting is on.'];
        $new = self::record($meetingId, $occ, 'join', 'self', 's:' . (int) $u['id'], ['user_id' => (int) $u['id']], time());
        return ['ok' => true, 'already' => !$new];
    }

    /** A finished sentence from the room's captions (the secretary's browser). */
    public static function caption(array $u, int $meetingId, string $occ, string $text, string $clientId, string $speaker = ''): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !MeetingMinutes::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        if (!self::isOpen($m, $occ)) return ['ok' => false, 'error' => 'The meeting is not on.'];
        $text = trim($text);
        if ($text === '' || !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $clientId)) return ['ok' => false, 'error' => 'Nothing to add.'];
        self::record($meetingId, $occ, 'utterance', 'browser', 'b:' . (int) $u['id'] . ':' . $clientId, ['name' => $speaker !== '' ? mb_substr($speaker, 0, 80) : 'Room'], time(), $text);
        return ['ok' => true];
    }

    /** Somebody who was not on the invite but came: they are invited from now on. */
    private static function addInvitee(int $meetingId, string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;
        $st = Database::pdo()->prepare('SELECT 1 FROM meeting_attendees WHERE meeting_id = ? AND LOWER(email) = ?');
        $st->execute([$meetingId, $email]);
        if (!$st->fetchColumn()) Database::pdo()->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?, ?)')->execute([$meetingId, $email]);
    }

    /* ══ Reading the log ════════════════════════════════════════════════════ */

    public static function events(int $meetingId, string $occ, int $sinceId = 0, string $kind = ''): array
    {
        self::ensure();
        $sql = 'SELECT * FROM meeting_live_events WHERE meeting_id = ? AND occurrence = ? AND id > ?' . ($kind !== '' ? ' AND kind = ?' : '') . ' ORDER BY at, id LIMIT 2000';
        $st = Database::pdo()->prepare($sql);
        $st->execute($kind !== '' ? [$meetingId, $occ, $sinceId, $kind] : [$meetingId, $occ, $sinceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Everybody the log has seen, one row a person: first in, last out,
     * minutes in the room, whether they were late, and what said so.
     */
    public static function people(array $m, string $occ, ?int $now = null): array
    {
        $now = $now ?? time();
        $start = strtotime($occ . ' UTC') ?: 0;
        $end = $start + (int) $m['duration_min'] * 60;
        $by = [];
        foreach (self::events((int) $m['id'], $occ) as $e) {
            if (!in_array($e['kind'], ['join', 'leave'], true)) continue;
            $k = (string) $e['person_key'];
            $p = &$by[$k];
            $p = $p ?? ['key' => $k, 'name' => (string) $e['name'], 'email' => (string) $e['email'], 'user_id' => (int) $e['user_id'], 'first_in' => 0, 'last_out' => 0, 'in' => false, 'sources' => [], 'spans' => [], 'open' => 0];
            $t = strtotime((string) $e['at'] . ' UTC') ?: 0;
            if ($p['name'] === '' && $e['name'] !== '') $p['name'] = (string) $e['name'];
            $p['sources'][(string) $e['source']] = true;
            if ($e['kind'] === 'join') {
                if (!$p['first_in'] || $t < $p['first_in']) $p['first_in'] = $t;
                if (!$p['in']) { $p['in'] = true; $p['open'] = $t; }
            } else {
                $p['last_out'] = max($p['last_out'], $t);
                if ($p['in']) { $p['spans'][] = [$p['open'], $t]; $p['in'] = false; }
            }
            unset($p);
        }
        $out = [];
        foreach ($by as $p) {
            /* Somebody still "in" is counted to now, or to the end of the
               meeting once it is over — whichever is sooner. Check-ins and
               "I'm here" have no leave, so they are counted to the end. */
            if ($p['in']) $p['spans'][] = [$p['open'], max($p['open'], min($now, $end))];
            $mins = 0;
            foreach ($p['spans'] as [$a, $b]) $mins += max(0, min($b, $end) - max($a, $start));
            $selfOnly = array_keys($p['sources']) === ['self'];
            $out[] = ['key' => $p['key'], 'name' => $p['name'] ?: 'Guest', 'email' => $p['email'], 'user_id' => $p['user_id'],
                      'first_in' => $p['first_in'] ? gmdate('c', $p['first_in']) : '', 'in_now' => $p['in'] && $now <= $end + self::OPEN_AFTER_MIN * 60,
                      'minutes' => (int) round($mins / 60), 'late' => $p['first_in'] > $start + self::LATE_GRACE_MIN * 60,
                      'late_by' => max(0, (int) round(($p['first_in'] - $start) / 60)), 'sources' => array_keys($p['sources']), 'self_reported' => $selfOnly];
        }
        usort($out, static fn($a, $b) => strcmp($a['first_in'], $b['first_in']));
        return $out;
    }

    public static function hasData(int $meetingId, string $occ): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT 1 FROM meeting_live_events WHERE meeting_id = ? AND occurrence = ? LIMIT 1');
        $st->execute([$meetingId, $occ]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Attendance from the log, for the minutes: present or late for each
     * account seen; for the rest invited, apologies if they said they could
     * not come, otherwise absent. Only accounts — a guest has no record to mark.
     */
    public static function attendance(array $m, string $occ, array $answers = []): array
    {
        $marks = [];
        foreach (self::people($m, $occ) as $p) if ($p['user_id'] > 0) $marks[(string) $p['user_id']] = $p['late'] ? 'late' : 'present';
        foreach (MeetingMinutes::invitees((int) $m['id']) as $p) {
            if ($p['id'] <= 0 || isset($marks[(string) $p['id']])) continue;
            $marks[(string) $p['id']] = ($answers[$p['id']]['answer'] ?? '') === 'no' ? 'apologies' : 'absent';
        }
        return $marks;
    }

    /** The log's words, as a transcript: "Name: what they said", in order. */
    public static function transcript(int $meetingId, string $occ): string
    {
        $lines = [];
        foreach (self::events($meetingId, $occ, 0, 'utterance') as $e) $lines[] = ((string) $e['name'] !== '' ? $e['name'] . ': ' : '') . trim((string) $e['text']);
        return implode("\n", $lines);
    }

    /** For the live room: who is here, what was said since $sinceId, and the check-in code. */
    public static function room(array $u, int $meetingId, string $occ, int $sinceId = 0): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !MeetingMinutes::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        if ($occ === '') $occ = self::occurrenceAt($m, time());
        if ($occ === '') return ['ok' => false, 'error' => 'This meeting is not on now.'];
        $chair = MeetingMinutes::isChair($u, $m);
        $words = [];
        $last = $sinceId;
        foreach (self::events($meetingId, $occ, $sinceId) as $e) {
            $last = max($last, (int) $e['id']);
            if ($e['kind'] === 'utterance') $words[] = ['id' => (int) $e['id'], 'name' => (string) $e['name'], 'text' => (string) $e['text'], 'at' => gmdate('c', strtotime((string) $e['at'] . ' UTC') ?: time()), 'source' => (string) $e['source']];
        }
        $invited = MeetingMinutes::invitees($meetingId);
        $people = self::people($m, $occ);
        $seen = array_flip(array_filter(array_column($people, 'user_id')));
        $s = strtotime($occ . ' UTC') ?: 0;
        return ['ok' => true, 'meeting' => ['id' => $meetingId, 'title' => (string) $m['title'], 'occurrence' => $occ, 'when' => MeetingMinutes::when($occ),
                'start' => gmdate('c', $s), 'end' => gmdate('c', $s + (int) $m['duration_min'] * 60), 'meet_url' => (string) $m['meet_url'], 'is_chair' => $chair,
                'agenda' => MeetingMinutes::agendaItems((string) $m['agenda']), 'can_capture' => MeetingMinutes::canWriteFor($u, $meetingId, $occ)],
                'people' => $people, 'missing' => array_values(array_filter($invited, static fn($p) => $p['id'] > 0 && !isset($seen[$p['id']]))),
                'words' => $words, 'last_id' => $last, 'open' => self::isOpen($m, $occ),
                'checkin' => self::isOpen($m, $occ) ? ['url' => self::checkinUrl($meetingId, $occ), 'qr' => self::checkinQr($meetingId, $occ), 'refresh_in' => 60 - (time() % 60)] : null,
                'sources' => ['google' => class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled() && (string) $m['meet_url'] !== '',
                              'bot' => (string) $m['bot_state'] !== '' && (string) $m['bot_provider'] === 'attendee' && class_exists('AttendeeBot') && AttendeeBot::webhookSecret() !== '']];
    }

    /* ══ After the meeting ══════════════════════════════════════════════════ */

    /** How long finishing waits for Google's transcript before settling for what it has. */
    public const FINISH_WAIT = 26 * 3600;
    public const WAITING = 'waiting';

    /**
     * Once a meeting is over, its transcript — the best there is:
     *   1. Google's own, speaker by speaker, with Gemini's notes after it,
     *      when the meeting's Meet was set to transcribe (Meetings::
     *      configureCapture). Google can take a while; until it is written
     *      this answers WAITING and the sweep asks again (up to FINISH_WAIT).
     *      Attendance is read again here, every join and leave.
     *   2. Otherwise the log's own words: the notetaker or the room captions.
     *   3. Otherwise, with nothing else, Google's transcript in Drive.
     * Returns what it did, or WAITING.
     */
    public static function finish(array $m, string $occ, ?int $now = null): string
    {
        self::ensure();
        $now = $now ?? time();
        $id = (int) $m['id'];
        $st = Database::pdo()->prepare('SELECT 1 FROM meeting_transcripts WHERE meeting_id = ? AND created_at >= ? LIMIT 1');
        $st->execute([$id, $occ]);
        if ($st->fetchColumn()) return 'has transcript';
        $start = strtotime($occ . ' UTC') ?: 0;
        $age = $now - ($start + (int) $m['duration_min'] * 60);
        $google = (string) $m['provider'] === 'google' && class_exists('GoogleWorkspace') && GoogleWorkspace::meetEnabled();
        $capture = (string) ($m['meet_capture'] ?? '');
        if ($google && in_array($capture, ['all', 'transcript'], true)) {
            $code = (string) ($m['meet_code'] ?? '') ?: GoogleWorkspace::meetCodeFromUrl((string) $m['meet_url']);
            foreach (self::readers($m) as $who) {
                $conf = GoogleWorkspace::meetConference($code, $who, $start);
                if (!$conf) continue;
                if ($conf['live']) return self::WAITING;
                self::recordParticipants($m, $occ, GoogleWorkspace::conferenceParticipants($conf['name'], $who, true));
                $t = GoogleWorkspace::conferenceTranscript($conf['name'], $who);
                if ($t['state'] === 'ready') {
                    $notes = GoogleWorkspace::conferenceNotes($conf['name'], $who);
                    Meetings::saveTranscript((int) $m['creator_id'], $id, $t['text'] . ($notes !== '' ? "\n\n— Notes by Gemini —\n" . $notes : ''), 'google');
                    return 'transcript from Google';
                }
                if ($t['state'] === 'pending' && $age < self::FINISH_WAIT) return self::WAITING;
                break;
            }
            /* No conference found yet: Meet's records can lag the call. */
            if ($age < 2 * 3600 && self::transcript($id, $occ) === '') return self::WAITING;
        }
        $text = self::transcript($id, $occ);
        if (mb_strlen($text) >= 40) {
            Meetings::saveTranscript((int) $m['creator_id'], $id, $text, 'bot');
            return 'transcript from the room';
        }
        if ($google) {
            $r = Meetings::pullGoogleTranscript((int) $m['creator_id'], $id);
            if (!empty($r['ok'])) return 'transcript from Google';
            if (!empty($r['pending']) && $age < self::FINISH_WAIT) return self::WAITING;
        }
        return 'no transcript';
    }
}
