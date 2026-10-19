<?php
/**
 * lib/MeetingMinutes.php — a meeting's whole life, after the invite.
 *
 * lib/Meetings.php schedules a meeting, sends the invite, can send a notetaker
 * and can turn a transcript into a summary. What a volunteer team also needs —
 * and did not have — is everything around that:
 *
 *   · ANSWERS: coming / can't come / maybe, per occurrence of a series, so the
 *     chair knows who to expect and an apology is on record.
 *   · REMINDERS, without anybody sending them: a day before (email, with the
 *     calendar file) and an hour before (a portal notice).
 *   · MINUTES as a document with an owner and a lifecycle:
 *       draft (the secretary writes) → review (the chair reads) → published
 *       (sent to everybody, actions filed). A chair who writes their own
 *       minutes publishes straight away. Published minutes can be amended;
 *       everybody is told they changed, and every version is kept.
 *   · A DRAFT THAT WRITES ITSELF: the moment a meeting ends the minutes exist,
 *     already holding the agenda, who said they were coming and who sent
 *     apologies, the actions still open from last time (matters arising), and
 *     — when there was a transcript — its summary, decisions and actions. The
 *     secretary can also paste rough notes and have them tidied into the
 *     sections (when AI is switched on; nothing depends on it).
 *   · ATTENDANCE: present / late / apologies / absent, marked in the minutes;
 *     whoever is unmarked at publication was absent.
 *   · FOLLOW-THROUGH on publication: each action becomes a tracked commitment
 *     owned by the person named, with a due date and their notice; everybody
 *     present is credited the meeting's length in volunteer hours; and if the
 *     minutes are not out two days after the meeting the secretary and chair
 *     are reminded.
 *
 * Every occurrence of a series has its own answers, attendance and minutes,
 * keyed by its start (UTC, "Y-m-d H:i:s").
 */
declare(strict_types=1);

final class MeetingMinutes
{
    public const ANSWERS = ['yes' => 'Coming', 'no' => "Can't come", 'maybe' => 'Maybe'];
    public const MARKS = ['present' => 'Present', 'late' => 'Late', 'apologies' => 'Apologies', 'absent' => 'Absent'];
    public const STATUSES = ['draft' => 'Draft', 'review' => 'With the chair', 'published' => 'Published'];
    /** Minutes are asked for this long after a meeting, then the reminder goes out. */
    public const OVERDUE_HOURS = 48;
    /** How far back the hub and the sweep look for meetings that need minutes. */
    public const LOOKBACK_DAYS = 14;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Meetings::ensure();
        Volunteers::ensure();
        if (class_exists('Commitments')) Commitments::ensure();
        $pdo = Database::pdo();
        Database::execSchema($pdo, "
        CREATE TABLE IF NOT EXISTS meeting_rsvps (
            meeting_id INTEGER NOT NULL,
            occurrence VARCHAR(20) NOT NULL,
            user_id INTEGER NOT NULL,
            answer VARCHAR(8) NOT NULL DEFAULT '',
            note VARCHAR(200) NOT NULL DEFAULT '',
            answered_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS meeting_minutes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            meeting_id INTEGER NOT NULL,
            occurrence VARCHAR(20) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            secretary_id INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL,
            attendance TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            seeded_from VARCHAR(40) NOT NULL DEFAULT '',
            submitted_at VARCHAR(32) NOT NULL DEFAULT '',
            approved_by INTEGER NOT NULL DEFAULT 0,
            published_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_by INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS meeting_minutes_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            minutes_id INTEGER NOT NULL,
            version INTEGER NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            attendance TEXT NOT NULL,
            by_id INTEGER NOT NULL DEFAULT 0,
            at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS meeting_nudges (
            meeting_id INTEGER NOT NULL,
            occurrence VARCHAR(20) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            sent_at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        foreach ([
            'CREATE UNIQUE INDEX uq_meeting_rsvp ON meeting_rsvps (meeting_id, occurrence, user_id)',
            'CREATE UNIQUE INDEX uq_meeting_minutes ON meeting_minutes (meeting_id, occurrence)',
            'CREATE INDEX idx_minutes_hist ON meeting_minutes_history (minutes_id)',
            'CREATE UNIQUE INDEX uq_meeting_nudge ON meeting_nudges (meeting_id, occurrence, kind)',
        ] as $ix) { try { $pdo->exec($ix); } catch (Throwable $e) {} }
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /* ══ Meetings, occurrences, people ══════════════════════════════════════ */

    public static function meeting(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Starts of a meeting's occurrences between two moments, oldest first: its rule (Meetings::rule). */
    public static function occurrences(array $m, int $from, int $to): array
    {
        return Meetings::startsBetween($m, $from, $to);
    }

    /** The occurrence a reader means: the one on now or next, else the last one. */
    public static function occurrenceKey(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }

    private static function validOccurrence(array $m, string $occ): bool
    {
        $ts = strtotime($occ . ' UTC');
        if ($ts === false) return false;
        return in_array($ts, self::occurrences($m, $ts - 1, $ts + 1), true);
    }

    private static function userEmail(int $uid): string
    {
        $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?');
        $st->execute([$uid]);
        return strtolower((string) ($st->fetchColumn() ?: ''));
    }

    /** Invitees as people: [{id, name, email}], id 0 for an address with no account. */
    public static function invitees(int $meetingId): array
    {
        $st = Database::pdo()->prepare('SELECT DISTINCT a.email, u.id, u.name FROM meeting_attendees a LEFT JOIN lms_users u ON LOWER(u.email) = LOWER(a.email) WHERE a.meeting_id = ? ORDER BY u.name, a.email');
        $st->execute([$meetingId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtolower((string) $r['email'])] = ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['name'] ?: $r['email']), 'email' => strtolower((string) $r['email'])];
        }
        $m = self::meeting($meetingId);
        if ($m) {
            $ce = self::userEmail((int) $m['creator_id']);
            if ($ce !== '' && !isset($out[$ce])) {
                $st = Database::pdo()->prepare('SELECT id, name FROM lms_users WHERE id = ?');
                $st->execute([(int) $m['creator_id']]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if ($c) $out[$ce] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'email' => $ce];
            }
        }
        return array_values($out);
    }

    private static function teamOf(array $m): ?array
    {
        return (string) $m['context'] === 'team' && (int) $m['context_id'] > 0 ? Volunteers::team((int) $m['context_id']) : null;
    }

    public static function canSee(array $u, int $meetingId): bool
    {
        $uid = (int) $u['id'];
        if (Meetings::isParticipant($uid, $meetingId)) return true;
        $m = self::meeting($meetingId);
        if (!$m) return false;
        $t = self::teamOf($m);
        return $t && (Volunteers::roleIn((int) $t['id'], $uid) !== '' || Volunteers::canRunTeams($u));
    }

    /** The chair: who called it — and, for a team meeting, the team's leads. */
    public static function isChair(array $u, array $m): bool
    {
        if ((int) $m['creator_id'] === (int) $u['id']) return true;
        $t = self::teamOf($m);
        return $t !== null && Volunteers::canManageTeam($u, (int) $t['id']);
    }

    /** Who the minutes are asked of: the one named on them, else the team's secretary, else the chair. */
    private static function secretaryFor(array $m, ?array $min = null): int
    {
        if ($min && (int) $min['secretary_id'] > 0) return (int) $min['secretary_id'];
        $t = self::teamOf($m);
        if ($t) foreach ($t['members'] as $p) if ($p['role'] === 'secretary') return (int) $p['id'];
        return (int) $m['creator_id'];
    }

    /** May this person write (or capture into) the minutes of this occurrence? */
    public static function canWriteFor(array $u, int $meetingId, string $occ): bool
    {
        $m = self::meeting($meetingId);
        return $m !== null && self::canWrite($u, $m, $occ !== '' ? self::row($meetingId, $occ) : null);
    }

    private static function canWrite(array $u, array $m, ?array $min): bool
    {
        return self::isChair($u, $m) || self::secretaryFor($m, $min) === (int) $u['id'];
    }

    /* ══ Scheduling for a team ══════════════════════════════════════════════ */

    /** A team meeting: the whole team is invited, and joins later are added to what is still to come. */
    public static function scheduleForTeam(array $u, int $teamId, array $in): array
    {
        self::ensure();
        $t = Volunteers::team($teamId);
        if (!$t || $t['archived']) return ['ok' => false, 'error' => 'No such team.'];
        if (Volunteers::roleIn($teamId, (int) $u['id']) === '' && !Volunteers::canRunTeams($u)) return ['ok' => false, 'error' => 'Only somebody on the team can call its meeting.'];
        $emails = array_column($t['members'], 'email');
        foreach (preg_split('/[,;\s]+/', (string) ($in['extra'] ?? '')) ?: [] as $e) if (filter_var(trim($e), FILTER_VALIDATE_EMAIL)) $emails[] = strtolower(trim($e));
        $r = Meetings::schedule((int) $u['id'], [
            'title' => (string) ($in['title'] ?? '') !== '' ? (string) $in['title'] : $t['name'] . ' meeting',
            'when' => (string) ($in['when'] ?? ''), 'duration' => (int) ($in['duration'] ?? 60), 'frequency' => (string) ($in['frequency'] ?? 'once'),
            'agenda' => (string) ($in['agenda'] ?? ''), 'attendees' => array_values(array_unique($emails)),
            'context' => 'team', 'context_id' => $teamId, 'auto_record' => !empty($in['auto_record']),
        ] + (array_key_exists('repeat', $in) ? ['repeat' => $in['repeat']] : []));
        return $r;
    }

    /**
     * Change a team meeting: its organiser, the team's lead or secretary, or
     * somebody who runs teams may. Extra guests are added to the team's own
     * people, as when it was called (Meetings::update does the rest).
     */
    public static function updateForTeam(array $u, int $meetingId, array $in): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m) return ['ok' => false, 'error' => 'No such meeting.'];
        $uid = (int) $u['id'];
        $t = (string) $m['context'] === 'team' ? Volunteers::team((int) $m['context_id']) : null;
        if (!self::mayEdit($u, $m)) return ['ok' => false, 'error' => 'Only the organiser or the team’s lead can change this meeting.'];
        $fields = array_intersect_key($in, array_flip(['title', 'when', 'duration', 'frequency', 'repeat', 'agenda', 'auto_record']));
        if (array_key_exists('extra', $in) && $t) {
            $emails = array_column($t['members'], 'email');
            foreach (preg_split('/[,;\s]+/', (string) $in['extra']) ?: [] as $e) if (filter_var(trim($e), FILTER_VALIDATE_EMAIL)) $emails[] = strtolower(trim($e));
            $fields['attendees'] = array_values(array_unique($emails));
        }
        return Meetings::update($uid, $meetingId, $fields, true);
    }

    /** May this person change the meeting? Its organiser, whoever runs teams, the team's lead or secretary. */
    public static function mayEdit(array $u, array $m): bool
    {
        $uid = (int) $u['id'];
        if ((int) $m['creator_id'] === $uid || Volunteers::canRunTeams($u)) return true;
        return (string) $m['context'] === 'team' && in_array(Volunteers::roleIn((int) $m['context_id'], $uid), ['lead', 'secretary'], true);
    }

    /** Somebody joined a team: invite them to its meetings still to come. */
    public static function syncTeamAttendees(int $teamId): int
    {
        self::ensure();
        $t = Volunteers::team($teamId);
        if (!$t) return 0;
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT * FROM meetings WHERE context = 'team' AND context_id = ? AND status = 'scheduled'");
        $st->execute([$teamId]);
        $added = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if (!self::occurrences($m, time(), time() + 400 * 86400)) continue;
            $have = array_column(self::invitees((int) $m['id']), 'email');
            foreach ($t['members'] as $p) {
                $e = strtolower($p['email']);
                if ($e === '' || in_array($e, $have, true)) continue;
                $pdo->prepare('INSERT INTO meeting_attendees (meeting_id, email) VALUES (?, ?)')->execute([(int) $m['id'], $e]);
                $added++;
            }
        }
        return $added;
    }

    /* ══ Answers ════════════════════════════════════════════════════════════ */

    public static function rsvp(array $u, int $meetingId, string $occ, string $answer, string $note = ''): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !self::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        if (!isset(self::ANSWERS[$answer])) return ['ok' => false, 'error' => 'Coming, can\'t come, or maybe.'];
        if (!self::validOccurrence($m, $occ)) return ['ok' => false, 'error' => 'That meeting is not on that day.'];
        if (strtotime($occ . ' UTC') + (int) $m['duration_min'] * 60 < time()) return ['ok' => false, 'error' => 'That meeting has happened.'];
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM meeting_rsvps WHERE meeting_id = ? AND occurrence = ? AND user_id = ?')->execute([$meetingId, $occ, (int) $u['id']]);
        $pdo->prepare('INSERT INTO meeting_rsvps (meeting_id, occurrence, user_id, answer, note, answered_at) VALUES (?,?,?,?,?,?)')
            ->execute([$meetingId, $occ, (int) $u['id'], $answer, mb_substr(trim($note), 0, 200), self::now()]);
        /* An apology is worth the chair knowing about at once. */
        if ($answer === 'no' && (int) $m['creator_id'] !== (int) $u['id'] && class_exists('Notifications')) {
            try { Notifications::push((int) $m['creator_id'], 'volunteer', $u['name'] . ' sends apologies', $m['title'] . ' · ' . self::when($occ) . ($note !== '' ? ' — ' . $note : ''), '/portal/#volunteer', 'apol:' . $meetingId . ':' . $occ . ':' . $u['id']); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'answers' => self::answers($meetingId, $occ)];
    }

    public static function answers(int $meetingId, string $occ): array
    {
        $st = Database::pdo()->prepare('SELECT r.user_id, r.answer, r.note, u.name FROM meeting_rsvps r LEFT JOIN lms_users u ON u.id = r.user_id WHERE r.meeting_id = ? AND r.occurrence = ?');
        $st->execute([$meetingId, $occ]);
        $out = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'by' => []];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['answer']] = ($out[(string) $r['answer']] ?? 0) + 1;
            $out['by'][(int) $r['user_id']] = ['answer' => (string) $r['answer'], 'note' => (string) $r['note'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /* ══ Minutes ════════════════════════════════════════════════════════════ */

    private static function row(int $meetingId, string $occ): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meeting_minutes WHERE meeting_id = ? AND occurrence = ?');
        $st->execute([$meetingId, $occ]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function byId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM meeting_minutes WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function emptyBody(): array
    {
        return ['summary' => '', 'agenda' => [], 'arising' => [], 'decisions' => [], 'actions' => [], 'notes' => '', 'next_meeting' => ''];
    }

    /** Agenda text to items: one a line, bullets and numbers stripped. */
    public static function agendaItems(string $agenda): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $agenda) ?: [] as $l) {
            $l = trim((string) preg_replace('/^\s*(?:[-*•·]|\d+[.)])\s*/u', '', $l));
            if ($l !== '') $out[] = ['item' => mb_substr($l, 0, 200), 'notes' => ''];
        }
        return array_slice($out, 0, 30);
    }

    /**
     * The minutes for one occurrence, made if they do not exist yet — already
     * holding everything the system knows. Nothing here needs AI.
     */
    public static function open(array $u, int $meetingId, string $occ = '', bool $create = true): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !self::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        if ($occ === '') {
            $past = self::occurrences($m, time() - 400 * 86400, time());
            $occ = $past ? self::occurrenceKey((int) end($past)) : (string) $m['scheduled_at'];
        }
        if (!self::validOccurrence($m, $occ)) return ['ok' => false, 'error' => 'That meeting is not on that day.'];
        $min = self::row($meetingId, $occ);
        if (!$min) {
            if (!$create || !self::canWrite($u, $m, null)) return ['ok' => true, 'minutes' => null, 'meeting' => self::meetingShape($m, $occ, $u)];
            $min = self::seed($m, $occ, (int) $u['id']);
        }
        /* What the room's log heard, for the writer to turn into the minutes. */
        $words = self::canWrite($u, $m, $min) && class_exists('MeetingLive') ? MeetingLive::transcript($meetingId, $occ) : '';
        return ['ok' => true, 'minutes' => self::shape($min, $m, $u), 'meeting' => self::meetingShape($m, $occ, $u), 'words' => mb_substr($words, 0, 60000)];
    }

    /** Make the draft: agenda, expected attendance, matters arising, the transcript's work. */
    private static function seed(array $m, string $occ, int $by): array
    {
        $id = (int) $m['id'];
        $body = self::emptyBody();
        $body['agenda'] = self::agendaItems((string) $m['agenda']);
        $from = ['agenda'];

        /* Matters arising: actions from this meeting's earlier minutes still open. */
        try {
            $st = Database::pdo()->prepare("SELECT c.id, c.title, c.due, c.owner_hint, u.name FROM commitments c LEFT JOIN lms_users u ON u.id = c.member_id
                                            WHERE c.source_kind = 'meeting' AND c.source_id = ? AND c.status = 'open' ORDER BY c.due, c.id LIMIT 30");
            $st->execute([$id]); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $body['arising'][] = ['id' => (int) $c['id'], 'title' => (string) $c['title'], 'owner' => (string) ($c['name'] ?: $c['owner_hint']), 'due' => (string) $c['due'], 'update' => '']; } catch (Throwable $e) {}
        if ($body['arising']) $from[] = 'arising';

        /* A transcript made after this occurrence began is this occurrence's. */
        $tr = Database::pdo()->prepare('SELECT * FROM meeting_transcripts WHERE meeting_id = ? AND structured = 1 AND created_at >= ? ORDER BY id DESC LIMIT 1');
        $tr->execute([$id, $occ]);
        if ($t = $tr->fetch(PDO::FETCH_ASSOC)) {
            $body['summary'] = (string) $t['summary'];
            $body['decisions'] = array_values(array_filter(array_map('strval', (array) json_decode((string) $t['decisions'], true))));
            foreach ((array) json_decode((string) $t['action_items'], true) as $a) {
                if (!is_array($a) || trim((string) ($a['task'] ?? '')) === '') continue;
                $owner = class_exists('Commitments') ? Commitments::resolveOwner((string) ($a['owner'] ?? '')) : 0;
                $due = isset($a['due_days']) && $a['due_days'] ? gmdate('Y-m-d', strtotime($occ . ' UTC') + (int) $a['due_days'] * 86400) : '';
                $body['actions'][] = ['task' => (string) $a['task'], 'owner_id' => $owner, 'owner_name' => (string) ($a['owner'] ?? ''), 'due' => $due];
            }
            $from[] = 'transcript';
        }

        $ans = self::answers($id, $occ)['by'];
        $att = [];
        if (class_exists('MeetingLive') && MeetingLive::hasData($id, $occ)) {
            /* The meeting was captured live: attendance is what happened. */
            $att = MeetingLive::attendance($m, $occ, $ans);
            $from[] = 'live';
        } else {
            foreach (self::invitees($id) as $p) {
                if ($p['id'] <= 0) continue;
                $a = $ans[$p['id']]['answer'] ?? '';
                $att[(string) $p['id']] = $a === 'no' ? 'apologies' : '';
            }
        }
        $now = self::now();
        $sql = Database::insertIgnore('meeting_minutes', ['meeting_id', 'occurrence', 'status', 'secretary_id', 'body', 'attendance', 'version', 'seeded_from', 'updated_by', 'created_at', 'updated_at']);
        Database::pdo()->prepare($sql)->execute([$id, $occ, 'draft', self::secretaryFor($m), json_encode($body, JSON_UNESCAPED_UNICODE), json_encode((object) $att), 1, implode(',', $from), $by, $now, $now]);
        return self::row($id, $occ) ?? [];
    }

    /**
     * Fold into a DRAFT what arrived after it was made: live attendance (only
     * into marks nobody has set) and a transcript's summary, decisions and
     * actions (only into empty sections, actions added). Never touches minutes
     * that are with the chair or published. Returns what it added.
     */
    public static function absorb(array $m, string $occ): array
    {
        self::ensure();
        $min = self::row((int) $m['id'], $occ);
        if (!$min || (string) $min['status'] !== 'draft') return [];
        $from = array_filter(explode(',', (string) $min['seeded_from']));
        $att = (array) json_decode((string) $min['attendance'], true);
        $body = (array) json_decode((string) $min['body'], true) + self::emptyBody();
        $added = [];
        if (!in_array('live', $from, true) && class_exists('MeetingLive') && MeetingLive::hasData((int) $m['id'], $occ)) {
            foreach (MeetingLive::attendance($m, $occ, self::answers((int) $m['id'], $occ)['by']) as $pid => $mark) {
                if (($att[$pid] ?? '') === '') $att[$pid] = $mark;
            }
            $from[] = 'live'; $added[] = 'attendance';
        }
        if (!in_array('transcript', $from, true)) {
            $tr = Database::pdo()->prepare('SELECT * FROM meeting_transcripts WHERE meeting_id = ? AND structured = 1 AND created_at >= ? ORDER BY id DESC LIMIT 1');
            $tr->execute([(int) $m['id'], $occ]);
            if ($t = $tr->fetch(PDO::FETCH_ASSOC)) {
                if (trim((string) $body['summary']) === '') $body['summary'] = (string) $t['summary'];
                if (!$body['decisions']) $body['decisions'] = array_values(array_filter(array_map('strval', (array) json_decode((string) $t['decisions'], true))));
                $have = array_map(static fn($a) => mb_strtolower((string) $a['task']), $body['actions']);
                foreach ((array) json_decode((string) $t['action_items'], true) as $a) {
                    $task = trim((string) ($a['task'] ?? ''));
                    if ($task === '' || in_array(mb_strtolower($task), $have, true)) continue;
                    $body['actions'][] = ['task' => $task, 'owner_id' => class_exists('Commitments') ? Commitments::resolveOwner((string) ($a['owner'] ?? '')) : 0,
                                          'owner_name' => (string) ($a['owner'] ?? ''), 'due' => !empty($a['due_days']) ? gmdate('Y-m-d', strtotime($occ . ' UTC') + (int) $a['due_days'] * 86400) : ''];
                }
                $from[] = 'transcript'; $added[] = 'transcript';
            }
        }
        if (!$added) return [];
        self::history($min, 0);
        Database::pdo()->prepare('UPDATE meeting_minutes SET body = ?, attendance = ?, seeded_from = ?, version = version + 1, updated_at = ? WHERE id = ?')
            ->execute([json_encode($body, JSON_UNESCAPED_UNICODE), json_encode((object) $att), implode(',', array_unique($from)), self::now(), (int) $min['id']]);
        return $added;
    }

    private static function cleanBody(array $in, array $old): array
    {
        $b = self::emptyBody();
        $b['summary'] = mb_substr(trim((string) ($in['summary'] ?? '')), 0, 6000);
        $b['notes'] = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 6000);
        $b['next_meeting'] = mb_substr(trim((string) ($in['next_meeting'] ?? '')), 0, 200);
        foreach (array_slice((array) ($in['agenda'] ?? []), 0, 40) as $a) {
            $item = mb_substr(trim((string) ($a['item'] ?? '')), 0, 200);
            if ($item !== '') $b['agenda'][] = ['item' => $item, 'notes' => mb_substr(trim((string) ($a['notes'] ?? '')), 0, 4000)];
        }
        $known = [];
        foreach ((array) ($old['arising'] ?? []) as $a) $known[(int) $a['id']] = $a;
        foreach ((array) ($in['arising'] ?? []) as $a) {
            $cid = (int) ($a['id'] ?? 0);
            if (!isset($known[$cid])) continue;   /* matters arising come from the record, not the form */
            $b['arising'][] = $known[$cid] + ['update' => ''];
            $b['arising'][count($b['arising']) - 1]['update'] = mb_substr(trim((string) ($a['update'] ?? '')), 0, 1000);
            $b['arising'][count($b['arising']) - 1]['done'] = !empty($a['done']);
        }
        foreach (array_slice((array) ($in['decisions'] ?? []), 0, 40) as $d) { $d = mb_substr(trim((string) $d), 0, 600); if ($d !== '') $b['decisions'][] = $d; }
        foreach (array_slice((array) ($in['actions'] ?? []), 0, 40) as $a) {
            $task = mb_substr(trim((string) ($a['task'] ?? '')), 0, 300);
            if ($task === '') continue;
            $due = trim((string) ($a['due'] ?? ''));
            $b['actions'][] = ['task' => $task, 'owner_id' => max(0, (int) ($a['owner_id'] ?? 0)), 'owner_name' => mb_substr(trim((string) ($a['owner_name'] ?? '')), 0, 120),
                               'due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : ''];
        }
        return $b;
    }

    private static function cleanAttendance(array $in, int $meetingId): array
    {
        $ids = array_filter(array_column(self::invitees($meetingId), 'id'));
        $out = [];
        foreach ($in as $k => $v) {
            $k = (int) $k; $v = (string) $v;
            if ($k > 0 && (in_array($k, $ids, true) || $v !== '') && ($v === '' || isset(self::MARKS[$v]))) $out[(string) $k] = $v;
        }
        return $out;
    }

    private static function history(array $min, int $by): void
    {
        Database::pdo()->prepare('INSERT INTO meeting_minutes_history (minutes_id, version, status, body, attendance, by_id, at) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int) $min['id'], (int) $min['version'], (string) $min['status'], (string) $min['body'], (string) $min['attendance'], $by, self::now()]);
    }

    /** Save the secretary's work. Published minutes are amended by publishing again. */
    public static function save(array $u, int $id, array $body, array $attendance, int $secretaryId = 0): array
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min) return ['ok' => false, 'error' => 'No such minutes.'];
        $m = self::meeting((int) $min['meeting_id']);
        if (!$m || !self::canWrite($u, $m, $min)) return ['ok' => false, 'error' => 'Only the secretary or the chair writes the minutes.'];
        $old = (array) json_decode((string) $min['body'], true);
        $clean = self::cleanBody($body, $old);
        $att = self::cleanAttendance($attendance, (int) $m['id']);
        self::history($min, (int) $u['id']);
        $status = (string) $min['status'] === 'published' ? 'draft' : (string) $min['status'];
        $sec = $secretaryId > 0 && self::isChair($u, $m) ? $secretaryId : (int) $min['secretary_id'];
        Database::pdo()->prepare('UPDATE meeting_minutes SET body = ?, attendance = ?, status = ?, secretary_id = ?, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), json_encode((object) $att), $status, $sec, (int) $u['id'], self::now(), $id]);
        return ['ok' => true, 'minutes' => self::shape(self::byId($id), $m, $u)];
    }

    /** Rough notes → the sections, by the assistant. The secretary reads it before anything is sent. */
    public static function fromNotes(array $u, int $id, string $notes): array
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min) return ['ok' => false, 'error' => 'No such minutes.'];
        $m = self::meeting((int) $min['meeting_id']);
        if (!$m || !self::canWrite($u, $m, $min)) return ['ok' => false, 'error' => 'Only the secretary or the chair writes the minutes.'];
        if (trim($notes) === '') return ['ok' => false, 'error' => 'Paste your notes first.'];
        $s = Meetings::structure($notes);
        if (empty($s['ok'])) return ['ok' => false, 'error' => 'The assistant could not tidy these (' . ($s['error'] ?? 'unavailable') . '). Your notes are still here — write them into the sections.'];
        $body = (array) json_decode((string) $min['body'], true) + self::emptyBody();
        if (trim((string) $body['summary']) === '') $body['summary'] = (string) $s['summary'];
        $body['decisions'] = array_values(array_unique(array_merge((array) $body['decisions'], (array) $s['decisions'])));
        foreach ((array) $s['action_items'] as $a) {
            $body['actions'][] = ['task' => (string) $a['task'], 'owner_id' => class_exists('Commitments') ? Commitments::resolveOwner((string) $a['owner']) : 0,
                                  'owner_name' => (string) $a['owner'], 'due' => !empty($a['due_days']) ? gmdate('Y-m-d', time() + (int) $a['due_days'] * 86400) : ''];
        }
        if (trim((string) $body['notes']) === '') $body['notes'] = mb_substr(trim($notes), 0, 6000);
        return self::save($u, $id, $body, (array) json_decode((string) $min['attendance'], true));
    }

    /**
     * Captured in the room, while it is said: a decision, an action (with its
     * owner) or a note goes straight into the draft. Writers only; the draft
     * is made if it does not exist yet.
     */
    public static function capture(array $u, int $meetingId, string $occ, string $type, string $text, int $ownerId = 0, string $due = ''): array
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !self::canSee($u, $meetingId)) return ['ok' => false, 'error' => 'No such meeting.'];
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'error' => 'Write it first.'];
        if (!in_array($type, ['decision', 'action', 'note'], true)) return ['ok' => false, 'error' => 'A decision, an action or a note.'];
        $o = self::open($u, $meetingId, $occ);
        if (!$o['ok'] || !$o['minutes']) return ['ok' => false, 'error' => 'Only the secretary or the chair captures into the minutes.'];
        $min = $o['minutes'];
        if ($min['status'] !== 'draft') return ['ok' => false, 'error' => 'These minutes are ' . strtolower($min['status_label']) . ' — open them to change anything.'];
        $b = $min['body'];
        if ($type === 'decision') $b['decisions'][] = $text;
        elseif ($type === 'action') $b['actions'][] = ['task' => $text, 'owner_id' => max(0, $ownerId), 'owner_name' => '', 'due' => $due];
        else $b['notes'] = trim((string) $b['notes'] . "\n- " . $text);
        $r = self::save($u, (int) $min['id'], $b, (array) $min['attendance']);
        return $r['ok'] ? ['ok' => true, 'captured' => $type, 'minutes' => $r['minutes']] : $r;
    }

    /** The secretary hands the minutes to the chair. */
    public static function submit(array $u, int $id): array
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min) return ['ok' => false, 'error' => 'No such minutes.'];
        $m = self::meeting((int) $min['meeting_id']);
        if (!$m || !self::canWrite($u, $m, $min)) return ['ok' => false, 'error' => 'Only the secretary or the chair can do that.'];
        if ((string) $min['status'] !== 'draft') return ['ok' => false, 'error' => 'These minutes are not a draft.'];
        $why = self::incomplete($min);
        if ($why) return ['ok' => false, 'error' => $why];
        Database::pdo()->prepare("UPDATE meeting_minutes SET status = 'review', submitted_at = ? WHERE id = ?")->execute([self::now(), $id]);
        if (class_exists('Notifications')) {
            try { Notifications::push((int) $m['creator_id'], 'volunteer', 'Minutes to approve', $m['title'] . ' · ' . self::when((string) $min['occurrence']), '/portal/#volunteer', 'minrev:' . $id . ':' . $min['version']); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'minutes' => self::shape(self::byId($id), $m, $u)];
    }

    /** The chair sends it back with a note. */
    public static function returnToDraft(array $u, int $id, string $note): array
    {
        self::ensure();
        $min = self::byId($id);
        $m = $min ? self::meeting((int) $min['meeting_id']) : null;
        if (!$m || !self::isChair($u, $m)) return ['ok' => false, 'error' => 'Only the chair can send minutes back.'];
        if ((string) $min['status'] !== 'review') return ['ok' => false, 'error' => 'These minutes are not waiting for approval.'];
        Database::pdo()->prepare("UPDATE meeting_minutes SET status = 'draft' WHERE id = ?")->execute([$id]);
        $sec = self::secretaryFor($m, $min);
        if (class_exists('Notifications') && $sec !== (int) $u['id']) {
            try { Notifications::push($sec, 'volunteer', 'Minutes sent back', $m['title'] . ($note !== '' ? ' — ' . mb_substr($note, 0, 180) : ''), '/portal/#volunteer', 'minback:' . $id . ':' . $min['version']); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'minutes' => self::shape(self::byId($id), $m, $u)];
    }

    private static function incomplete(array $min): string
    {
        $b = (array) json_decode((string) $min['body'], true);
        if (trim((string) ($b['summary'] ?? '')) === '' && !array_filter(array_column((array) ($b['agenda'] ?? []), 'notes')) && !($b['decisions'] ?? []))
            return 'The minutes are empty — write a summary, notes under the agenda, or the decisions.';
        foreach ((array) ($b['actions'] ?? []) as $a) if ((int) ($a['owner_id'] ?? 0) <= 0) return 'Every action needs somebody to own it: “' . mb_substr((string) $a['task'], 0, 60) . '” has nobody.';
        return '';
    }

    /**
     * Publish (approve): the chair, or a chair who wrote them. Everybody is
     * sent the minutes, actions become commitments, attendance becomes hours.
     */
    public static function publish(array $u, int $id): array
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min) return ['ok' => false, 'error' => 'No such minutes.'];
        $m = self::meeting((int) $min['meeting_id']);
        if (!$m || !self::isChair($u, $m)) return ['ok' => false, 'error' => 'Minutes are approved by the chair.'];
        $why = self::incomplete($min);
        if ($why) return ['ok' => false, 'error' => $why];
        $amended = (string) $min['published_at'] !== '';
        $occ = (string) $min['occurrence'];
        if (strtotime($occ . ' UTC') > time()) return ['ok' => false, 'error' => 'The meeting has not happened yet.'];

        $att = (array) json_decode((string) $min['attendance'], true);
        foreach (self::invitees((int) $m['id']) as $p) if ($p['id'] > 0 && ($att[(string) $p['id']] ?? '') === '') $att[(string) $p['id']] = 'absent';
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE meeting_minutes SET status = 'published', attendance = ?, approved_by = ?, published_at = ?, updated_at = ? WHERE id = ?")
            ->execute([json_encode((object) $att), (int) $u['id'], self::now(), self::now(), $id]);
        $pdo->prepare("UPDATE meetings SET status = 'done' WHERE id = ? AND frequency = 'once' AND status = 'scheduled'")->execute([(int) $m['id']]);
        $min = self::byId($id);
        $body = (array) json_decode((string) $min['body'], true);

        $filed = self::fileActions($m, $min, $body, (int) $u['id']);
        foreach ((array) ($body['arising'] ?? []) as $a) {
            if (!empty($a['done']) && class_exists('Commitments')) { try { Commitments::complete((int) $a['id'], (int) $u['id']); } catch (Throwable $e) {} }
        }
        $day = substr(self::local($occ), 0, 10);
        $credited = 0;
        /* A team meeting's hours belong to the team's project. */
        $proj = (string) $m['context'] === 'team' && ($tm = Volunteers::team((int) $m['context_id'])) ? (int) $tm['project_id'] : 0;
        foreach ($att as $pid => $mark) {
            if (in_array($mark, ['present', 'late'], true) && Volunteers::autoHours((int) $pid, $day, (int) $m['duration_min'], 'Meeting: ' . $m['title'], 'meeting', 'mtg:' . $m['id'] . ':' . $occ, $proj)) $credited++;
        }
        $sent = self::distribute($m, $min, $amended);
        if (class_exists('AdminAudit')) { try { AdminAudit::log('volunteers', $amended ? 'minutes.amend' : 'minutes.publish', 'meeting:' . $m['id'], $m['title'] . ' · ' . $occ . ' · v' . $min['version'], null, 'member'); } catch (Throwable $e) {} }
        if (class_exists('Events')) { try { Events::emit('minutes.published', ['meeting' => (int) $m['id'], 'occurrence' => $occ, 'minutes' => $id]); } catch (Throwable $e) {} }
        return ['ok' => true, 'amended' => $amended, 'actions' => $filed, 'hours_credited' => $credited, 'sent' => $sent, 'minutes' => self::shape($min, $m, $u)];
    }

    /** Each action → a commitment owned by the person named, due when the minutes say. One per action per minutes. */
    private static function fileActions(array $m, array $min, array $body, int $by): int
    {
        if (!class_exists('Commitments')) return 0;
        Commitments::ensure();
        $pdo = Database::pdo(); $n = 0; $now = self::now();
        foreach ((array) ($body['actions'] ?? []) as $a) {
            $title = (string) $a['task']; $owner = (int) $a['owner_id'];
            $due = (string) ($a['due'] ?? '') ?: gmdate('Y-m-d', time() + 7 * 86400);
            $key = 'min:' . $min['id'] . ':' . substr(sha1(mb_strtolower($title)), 0, 16);
            $st = $pdo->prepare('SELECT id, member_id, due, status FROM commitments WHERE dedupe_key = ?');
            $st->execute([$key]);
            $have = $st->fetch(PDO::FETCH_ASSOC);
            if (!$have) {
                /* The transcript may already have filed this action, with no
                   owner (Meetings::saveTranscript). Adopt that one rather than
                   file it twice. */
                $ad = $pdo->prepare("SELECT id, member_id, due, status FROM commitments WHERE source_kind = 'meeting' AND source_id = ? AND LOWER(title) = ? AND created_at >= ? AND dedupe_key NOT LIKE 'min:%' LIMIT 1");
                $ad->execute([(int) $m['id'], mb_strtolower(mb_substr($title, 0, 300)), (string) $min['occurrence']]);
                if ($have = $ad->fetch(PDO::FETCH_ASSOC)) $pdo->prepare('UPDATE commitments SET dedupe_key = ? WHERE id = ?')->execute([$key, (int) $have['id']]);
                if ($have) $have['member_id'] = -1;   /* force the owner and due to be set below */
            }
            if ($have) {
                if ((string) $have['status'] === 'open' && ((int) $have['member_id'] !== $owner || (string) $have['due'] !== $due)) {
                    $pdo->prepare('UPDATE commitments SET member_id = ?, due = ?, updated_at = ? WHERE id = ?')->execute([$owner, $due, $now, (int) $have['id']]);
                    if ((int) $have['member_id'] !== $owner) Commitments::assign((int) $have['id'], $owner, $by);
                }
                continue;
            }
            $pdo->prepare("INSERT INTO commitments (source_kind, source_id, dedupe_key, title, member_id, owner_hint, due, status, confirmed_by, confirmed_at, created_at, updated_at)
                           VALUES ('meeting', ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?)")
                ->execute([(int) $m['id'], $key, mb_substr($title, 0, 300), $owner, mb_substr((string) ($a['owner_name'] ?? ''), 0, 120), $due, $by, $now, $now, $now]);
            $n++;
            if (class_exists('Notifications')) {
                try { Notifications::push($owner, 'commitment', 'An action for you from ' . $m['title'], mb_substr($title, 0, 180) . ' — due ' . $due, '/portal/#volunteer', 'minact:' . $key); } catch (Throwable $e) {}
            }
        }
        return $n;
    }

    /** Email the minutes to everybody invited, and a portal notice. */
    private static function distribute(array $m, array $min, bool $amended): int
    {
        $html = self::html($m, $min, true);
        $opt = [];
        $pdf = self::pdf($m, $min);
        $tmp = $pdf !== null ? tempnam(sys_get_temp_dir(), 'min') : false;
        if ($tmp !== false && @file_put_contents($tmp, $pdf) !== false) $opt = ['attachment' => ['path' => $tmp, 'name' => 'minutes.pdf']];
        $subject = ($amended ? 'Minutes amended: ' : 'Minutes: ') . $m['title'] . ' — ' . date('j M Y', strtotime(self::local((string) $min['occurrence'])) ?: time());
        $sent = 0;
        foreach (self::invitees((int) $m['id']) as $p) {
            if (class_exists('Mailer') && $p['email'] !== '') {
                try { if (@Mailer::send($p['email'], $subject, Mailer::shell($subject, [$html], ['url' => self::site('/portal/#volunteer'), 'text' => 'Open in the portal'], $amended ? 'The minutes were changed.' : 'The minutes are out.'), $opt)) $sent++; } catch (Throwable $e) {}
            }
            if ($p['id'] > 0 && class_exists('Notifications')) {
                try { Notifications::push($p['id'], 'volunteer', $amended ? 'Minutes amended' : 'Minutes published', $m['title'] . ' · ' . self::when((string) $min['occurrence']), '/portal/#volunteer', 'minpub:' . $min['id'] . ':' . $min['version']); } catch (Throwable $e) {}
            }
        }
        if ($tmp !== false) @unlink($tmp);
        return $sent;
    }

    /* ══ Reading ════════════════════════════════════════════════════════════ */

    private static function site(string $path): string { return (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . $path; }

    /** A UTC occurrence in the organisation's time, "Y-m-d H:i". */
    public static function local(string $utc): string
    {
        $ts = strtotime($utc . ' UTC');
        if ($ts === false) return $utc;
        try { return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(defined('AV_TZ') ? AV_TZ : 'Africa/Lagos'))->format('Y-m-d H:i'); } catch (Throwable $e) { return gmdate('Y-m-d H:i', $ts); }
    }

    public static function when(string $utc): string
    {
        $l = self::local($utc);
        return date('D j M, g:ia', strtotime($l) ?: time());
    }

    private static function meetingShape(array $m, string $occ, array $u): array
    {
        $t = self::teamOf($m);
        return ['id' => (int) $m['id'], 'title' => (string) $m['title'], 'occurrence' => $occ, 'when' => self::when($occ), 'local' => self::local($occ),
                'duration_min' => (int) $m['duration_min'], 'meet_url' => (string) $m['meet_url'], 'frequency' => (string) $m['frequency'], 'frequency_label' => Meetings::cadence($m),
                'team' => $t ? ['id' => $t['id'], 'name' => $t['name']] : null, 'is_chair' => self::isChair($u, $m),
                'invitees' => self::invitees((int) $m['id']), 'answers' => self::answers((int) $m['id'], $occ)];
    }

    private static function shape(array $min, array $m, array $u): array
    {
        $sec = self::secretaryFor($m, $min);
        $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
        $st->execute([$sec]);
        return ['id' => (int) $min['id'], 'meeting_id' => (int) $min['meeting_id'], 'occurrence' => (string) $min['occurrence'], 'status' => (string) $min['status'],
                'status_label' => self::STATUSES[(string) $min['status']] ?? (string) $min['status'], 'version' => (int) $min['version'],
                'body' => (array) json_decode((string) $min['body'], true) + self::emptyBody(), 'attendance' => (object) (array) json_decode((string) $min['attendance'], true),
                'secretary' => ['id' => $sec, 'name' => (string) ($st->fetchColumn() ?: '')], 'seeded_from' => array_filter(explode(',', (string) $min['seeded_from'])),
                'published_at' => (string) $min['published_at'], 'can_write' => self::canWrite($u, $m, $min), 'can_publish' => self::isChair($u, $m),
                'print_url' => '/portal/volunteer.php?action=print&id=' . (int) $min['id']];
    }

    /** Notes as people write them — Markdown, safely (ChiomaMarkdown: raw HTML stripped, unsafe links dropped). */
    private static function rich(string $text): string
    {
        if (trim($text) === '') return '';
        if (class_exists('ChiomaMarkdown') && ChiomaMarkdown::available()) {
            try { return ChiomaMarkdown::render($text); } catch (Throwable $e) {}
        }
        return '<p style="margin:0;white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /** The minutes as a document — for the email, and for printing. */
    public static function html(array $m, array $min, bool $forMail = false): string
    {
        $e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $b = (array) json_decode((string) $min['body'], true) + self::emptyBody();
        $att = (array) json_decode((string) $min['attendance'], true);
        $people = [];
        foreach (self::invitees((int) $m['id']) as $p) if ($p['id'] > 0) $people[$p['id']] = $p['name'];
        $groups = [];
        foreach ($att as $pid => $mark) if ($mark !== '' && isset($people[(int) $pid])) $groups[$mark][] = $people[(int) $pid];
        $h = '<p style="margin:0 0 6px"><b>' . $e($m['title']) . '</b> — ' . $e(self::when((string) $min['occurrence'])) . '</p>';
        $t = self::teamOf($m);
        if ($t) $h .= '<p style="margin:0 0 12px;color:#5f6874">' . $e($t['name']) . '</p>';
        foreach (self::MARKS as $k => $label) if (!empty($groups[$k])) $h .= '<p style="margin:0 0 4px"><b>' . $e($label) . ':</b> ' . $e(implode(', ', $groups[$k])) . '</p>';
        if (trim((string) $b['summary']) !== '') $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Summary</h3>' . self::rich((string) $b['summary']);
        if ($b['arising']) {
            $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Matters arising</h3><ul style="margin:0;padding-left:18px">';
            foreach ($b['arising'] as $a) $h .= '<li>' . $e($a['title']) . ($a['owner'] ? ' <i>(' . $e($a['owner']) . ')</i>' : '') . (!empty($a['done']) ? ' — <b>done</b>' : '') . (($a['update'] ?? '') !== '' ? ' — ' . $e($a['update']) : '') . '</li>';
            $h .= '</ul>';
        }
        if ($b['agenda']) {
            $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Agenda and discussion</h3><ol style="margin:0;padding-left:20px">';
            foreach ($b['agenda'] as $a) $h .= '<li><b>' . $e($a['item']) . '</b>' . ($a['notes'] !== '' ? '<div>' . self::rich((string) $a['notes']) . '</div>' : '') . '</li>';
            $h .= '</ol>';
        }
        if ($b['decisions']) {
            $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Decisions</h3><ul style="margin:0;padding-left:18px">';
            foreach ($b['decisions'] as $d) $h .= '<li>' . $e($d) . '</li>';
            $h .= '</ul>';
        }
        if ($b['actions']) {
            $names = [];
            $ids = array_filter(array_map(static fn($a) => (int) $a['owner_id'], $b['actions']));
            if ($ids) foreach (Database::pdo()->query('SELECT id, name FROM lms_users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int) $r['id']] = (string) $r['name'];
            $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Actions</h3><table style="border-collapse:collapse;width:100%;font-size:14px">';
            foreach ($b['actions'] as $a) $h .= '<tr><td style="padding:4px 8px 4px 0;border-top:1px solid #e7e9ee">' . $e($a['task']) . '</td><td style="padding:4px 8px;border-top:1px solid #e7e9ee;white-space:nowrap">' . $e($names[(int) $a['owner_id']] ?? $a['owner_name']) . '</td><td style="padding:4px 0;border-top:1px solid #e7e9ee;white-space:nowrap">' . $e($a['due'] ?: '—') . '</td></tr>';
            $h .= '</table>';
        }
        if (trim((string) $b['notes']) !== '') $h .= '<h3 style="margin:16px 0 4px;font-size:15px">Other notes</h3>' . self::rich((string) $b['notes']);
        if (trim((string) $b['next_meeting']) !== '') $h .= '<p style="margin:16px 0 0"><b>Next meeting:</b> ' . $e($b['next_meeting']) . '</p>';
        return $h;
    }

    public static function printable(array $u, int $id): ?string
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min || !self::canSee($u, (int) $min['meeting_id'])) return null;
        $m = self::meeting((int) $min['meeting_id']);
        $status = (string) $min['status'] === 'published' ? '' : '<p style="background:#fffaf0;border:1px solid #f2c98a;padding:8px 12px;border-radius:8px">' . htmlspecialchars(self::STATUSES[(string) $min['status']] ?? '', ENT_QUOTES) . ' — not yet published.</p>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Minutes · ' . htmlspecialchars((string) $m['title'], ENT_QUOTES) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:32px auto;padding:0 16px;color:#15120e;line-height:1.55;background:#fff}h1{font-size:20px;margin:0 0 4px}@media print{.np{display:none}}</style></head><body>'
            . '<p class="np"><button onclick="print()">Print or save as PDF</button></p><h1>Minutes</h1>' . $status . self::html($m, $min)
            . '<p style="color:#5f6874;font-size:12px;margin-top:28px">Afrovanguard · version ' . (int) $min['version'] . ((string) $min['published_at'] !== '' ? ' · published ' . htmlspecialchars(self::local((string) $min['published_at']), ENT_QUOTES) : '') . '</p></body></html>';
    }

    /** The minutes as a PDF (dompdf), for the email and for download. Null when dompdf is not installed. */
    public static function pdf(array $m, array $min): ?string
    {
        if (!class_exists('Dompdf\\Dompdf')) return null;
        try {
            $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#15120e;line-height:1.5}h1{font-size:18px;margin:0 0 6px}h3{font-size:13px}table{width:100%}</style></head><body>'
                  . '<h1>Minutes</h1>' . self::html($m, $min)
                  . '<p style="color:#5f6874;font-size:10px;margin-top:24px">Afrovanguard · version ' . (int) $min['version'] . ((string) $min['published_at'] !== '' ? ' · published ' . htmlspecialchars(self::local((string) $min['published_at']), ENT_QUOTES) : '') . '</p></body></html>';
            $opt = new \Dompdf\Options(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true, 'defaultFont' => 'DejaVu Sans', 'tempDir' => sys_get_temp_dir()]);
            $pdf = new \Dompdf\Dompdf($opt);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            return $pdf->output();
        } catch (Throwable $e) { error_log('[minutes] pdf: ' . $e->getMessage()); return null; }
    }

    public static function pdfFor(array $u, int $id): ?array
    {
        self::ensure();
        $min = self::byId($id);
        if (!$min || !self::canSee($u, (int) $min['meeting_id'])) return null;
        $m = self::meeting((int) $min['meeting_id']);
        $bytes = self::pdf($m, $min);
        if ($bytes === null) return null;
        $name = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $m['title'])) . '-' . substr(self::local((string) $min['occurrence']), 0, 10) . '.pdf';
        return ['bytes' => $bytes, 'name' => trim((string) $name, '-')];
    }

    /** The calendar file, so a meeting lands in any calendar app — Google or not. */
    public static function ics(array $u, int $meetingId): ?string
    {
        self::ensure();
        $m = self::meeting($meetingId);
        if (!$m || !self::canSee($u, $meetingId)) return null;
        return self::icsFor($m);
    }

    /**
     * RFC 5545. As METHOD:REQUEST (with the organiser and each invitee, RSVP
     * on) a mail client shows its own Yes / No / Maybe; as PUBLISH it is a
     * plain calendar entry to add.
     */
    public static function icsFor(array $m, string $method = 'PUBLISH'): string
    {
        /* spatie/icalendar-generator (MIT): folding, escaping and the time
           zones done by a library calendar apps are tested against. The
           hand-written writer below stays as the fallback. */
        if (class_exists(\Spatie\IcalendarGenerator\Components\Calendar::class)) {
            try { return self::icsSpatie($m, $method); } catch (Throwable $e) { error_log('[minutes] ics: ' . $e->getMessage()); }
        }
        $esc = static fn(string $s): string => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
        $ts = strtotime((string) $m['scheduled_at'] . ' UTC') ?: time();
        $host = parse_url(defined('SITE_URL') ? SITE_URL : 'https://afrovanguard.org.ng', PHP_URL_HOST) ?: 'afrovanguard.org.ng';
        /* A series is anchored on the meeting clock (TZID), so "Mondays at
           10:00" is read as Mondays where the meeting is, not in UTC. */
        $rec = Meetings::recurrenceLines($m);
        $loc = Meetings::seriesStart($m);
        $tzid = 'TZID=' . $loc->getTimezone()->getName() . ':';
        $desc = trim((string) $m['agenda']) . ((string) $m['meet_url'] !== '' ? "\n\nJoin: " . $m['meet_url'] : '');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Afrovanguard//Volunteer hub//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . ($method === 'REQUEST' ? 'REQUEST' : 'PUBLISH'), 'BEGIN:VEVENT',
            'UID:meeting-' . (int) $m['id'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $rec ? 'DTSTART;' . $tzid . $loc->format('Ymd\THis') : 'DTSTART:' . gmdate('Ymd\THis\Z', $ts),
            $rec ? 'DTEND;' . $tzid . $loc->modify('+' . (int) $m['duration_min'] . ' minutes')->format('Ymd\THis') : 'DTEND:' . gmdate('Ymd\THis\Z', $ts + (int) $m['duration_min'] * 60),
            'SUMMARY:' . $esc((string) $m['title'])];
        foreach ($rec as $l) $lines[] = $l;
        $lines[] = 'SEQUENCE:0';
        if ($method === 'REQUEST') {
            $st = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?');
            $st->execute([(int) $m['creator_id']]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Afrovanguard', 'email' => 'noreply@' . $host];
            $lines[] = 'ORGANIZER;CN=' . $esc((string) $org['name']) . ':mailto:' . $org['email'];
            foreach (self::invitees((int) $m['id']) as $p) {
                $lines[] = 'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=' . $esc($p['name']) . ':mailto:' . $p['email'];
            }
        }
        if ($desc !== '') $lines[] = 'DESCRIPTION:' . $esc($desc);
        if ((string) $m['meet_url'] !== '') { $lines[] = 'URL:' . $m['meet_url']; $lines[] = 'LOCATION:' . $esc((string) $m['meet_url']); }
        if ((string) $m['status'] === 'cancelled') $lines[] = 'STATUS:CANCELLED';
        $lines[] = 'BEGIN:VALARM'; $lines[] = 'TRIGGER:-PT30M'; $lines[] = 'ACTION:DISPLAY'; $lines[] = 'DESCRIPTION:' . $esc((string) $m['title']); $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT'; $lines[] = 'END:VCALENDAR';
        $out = '';
        foreach ($lines as $l) { while (strlen($l) > 74) { $cut = 74; while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--; $out .= substr($l, 0, $cut) . "\r\n"; $l = ' ' . substr($l, $cut); } $out .= $l . "\r\n"; }
        return $out;
    }

    private static function icsSpatie(array $m, string $method): string
    {
        $ts = strtotime((string) $m['scheduled_at'] . ' UTC') ?: time();
        $host = parse_url(defined('SITE_URL') ? SITE_URL : 'https://afrovanguard.org.ng', PHP_URL_HOST) ?: 'afrovanguard.org.ng';
        $utc = new DateTimeZone('UTC');
        $url = (string) $m['meet_url'];
        $desc = trim((string) $m['agenda']) . ($url !== '' ? "\n\nJoin: " . $url : '');
        $ev = \Spatie\IcalendarGenerator\Components\Event::create((string) $m['title'])
            ->uniqueIdentifier('meeting-' . (int) $m['id'] . '@' . $host)
            ->startsAt(new DateTimeImmutable('@' . $ts))
            ->endsAt(new DateTimeImmutable('@' . ($ts + (int) $m['duration_min'] * 60)))
            ->createdAt(new DateTimeImmutable('now', $utc))
            ->sequence(0)
            ->alertMinutesBefore(30, (string) $m['title']);
        if ($desc !== '') $ev->description($desc);
        if ($url !== '') { $ev->url($url)->address($url); if (str_contains($url, 'meet.google.com')) $ev->googleConference($url); }
        $rule = Meetings::rule($m);
        if ($rule !== null) {
            /* On the meeting clock, so the rule's days are that zone's days. */
            $loc = Meetings::seriesStart($m);
            $ev->startsAt($loc)->endsAt($loc->modify('+' . (int) $m['duration_min'] . ' minutes'));
            $ev->rruleAsString(substr(Meetings::recurrenceLines($m)[0], strlen('RRULE:')));
            if (!empty($rule['skip'])) {
                $ev->doNotRepeatOn(array_map(static fn($d) => new DateTimeImmutable($d . ' ' . $loc->format('H:i:s'), $loc->getTimezone()), $rule['skip']));
            }
        }
        if ((string) $m['status'] === 'cancelled') $ev->status(\Spatie\IcalendarGenerator\Enums\EventStatus::Cancelled);
        if ($method === 'REQUEST') {
            $st = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?');
            $st->execute([(int) $m['creator_id']]);
            $org = $st->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Afrovanguard', 'email' => 'noreply@' . $host];
            $ev->organizer((string) $org['email'], (string) $org['name']);
            foreach (self::invitees((int) $m['id']) as $p) $ev->attendee((string) $p['email'], (string) $p['name'], \Spatie\IcalendarGenerator\Enums\ParticipationStatus::NeedsAction, true);
        }
        $cal = \Spatie\IcalendarGenerator\Components\Calendar::create()->productIdentifier('-//Afrovanguard//Volunteer dashboard//EN')->event($ev);
        /* A one-off is a UTC moment; a series keeps its zone (TZID + VTIMEZONE), or its days drift. */
        if ($rule === null) $cal->withoutTimezone();
        $cal->appendProperty(\Spatie\IcalendarGenerator\Properties\TextProperty::create('METHOD', $method === 'REQUEST' ? 'REQUEST' : 'PUBLISH'));
        return $cal->get();
    }

    /** The title of where a commitment came from, for "my actions". */
    public static function sourceTitle(array $c): string
    {
        if ((string) ($c['source_kind'] ?? '') !== 'meeting') return '';
        $m = self::meeting((int) $c['source_id']);
        return $m ? (string) $m['title'] : '';
    }

    /* ══ The hub ════════════════════════════════════════════════════════════ */

    /** Every meeting this person may see: invited, called, or of a team they are on. Rows, not cancelled. */
    public static function visibleMeetings(array $u): array
    {
        self::ensure();
        $ids = array_column(Meetings::listFor((int) $u['id'], 200), 'id');
        $teamIds = array_column(Volunteers::teamsFor($u), 'id');
        if ($teamIds) {
            $st = Database::pdo()->query("SELECT id FROM meetings WHERE context = 'team' AND context_id IN (" . implode(',', array_map('intval', $teamIds)) . ')');
            $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $m = self::meeting($id);
            if ($m && (string) $m['status'] !== 'cancelled') $out[$id] = $m;
        }
        return $out;
    }

    /**
     * Meetings that have happened (the last $days), one row per occurrence:
     * who came (from the published minutes, else the live log), and where its
     * minutes stand. Newest first.
     */
    public static function past(array $u, int $teamId = 0, int $days = 120): array
    {
        $now = time(); $out = [];
        foreach (self::visibleMeetings($u) as $m) {
            if ($teamId > 0 && !((string) $m['context'] === 'team' && (int) $m['context_id'] === $teamId)) continue;
            $team = self::teamOf($m);
            foreach (self::occurrences($m, $now - $days * 86400, $now) as $ts) {
                if ($ts + (int) $m['duration_min'] * 60 > $now) continue;
                $occ = self::occurrenceKey($ts);
                $min = self::row((int) $m['id'], $occ);
                $att = $min ? (array) json_decode((string) $min['attendance'], true) : [];
                if (!$att && class_exists('MeetingLive') && MeetingLive::hasData((int) $m['id'], $occ)) $att = MeetingLive::attendance($m, $occ, self::answers((int) $m['id'], $occ)['by']);
                $came = count(array_filter($att, static fn($v) => in_array($v, ['present', 'late'], true)));
                $out[] = ['id' => (int) $m['id'], 'title' => (string) $m['title'], 'team' => $team['name'] ?? '', 'team_id' => $team['id'] ?? 0, 'occurrence' => $occ, 'when' => self::when($occ), 'ts' => $ts,
                          'came' => $came, 'invited' => count(array_filter(self::invitees((int) $m['id']), static fn($p) => $p['id'] > 0)),
                          'my_mark' => (string) ($att[(string) $u['id']] ?? ''), 'minutes_id' => $min ? (int) $min['id'] : 0, 'minutes_status' => $min ? (string) $min['status'] : '',
                          'can_write' => self::canWrite($u, $m, $min)];
            }
        }
        usort($out, static fn($a, $b) => $b['ts'] <=> $a['ts']);
        return array_slice($out, 0, 200);
    }

    /** Every set of minutes this person may read, filtered. Newest meeting first. */
    public static function archive(array $u, string $status = '', int $teamId = 0, string $q = ''): array
    {
        $vis = self::visibleMeetings($u);
        if (!$vis) return [];
        $st = Database::pdo()->query('SELECT * FROM meeting_minutes WHERE meeting_id IN (' . implode(',', array_keys($vis)) . ') ORDER BY occurrence DESC LIMIT 500');
        $q = mb_strtolower(trim($q)); $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $min) {
            $m = $vis[(int) $min['meeting_id']];
            if ($status !== '' && (string) $min['status'] !== $status) continue;
            if ($teamId > 0 && !((string) $m['context'] === 'team' && (int) $m['context_id'] === $teamId)) continue;
            /* A draft is the secretary's and the chair's until it is handed over. */
            if ((string) $min['status'] === 'draft' && !self::canWrite($u, $m, $min)) continue;
            $b = (array) json_decode((string) $min['body'], true);
            if ($q !== '' && !str_contains(mb_strtolower($m['title'] . ' ' . ($b['summary'] ?? '') . ' ' . implode(' ', (array) ($b['decisions'] ?? []))), $q)) continue;
            $team = self::teamOf($m);
            $out[] = ['minutes_id' => (int) $min['id'], 'id' => (int) $m['id'], 'title' => (string) $m['title'], 'team' => $team['name'] ?? '', 'occurrence' => (string) $min['occurrence'],
                      'when' => self::when((string) $min['occurrence']), 'status' => (string) $min['status'], 'status_label' => self::STATUSES[(string) $min['status']] ?? '',
                      'version' => (int) $min['version'], 'decisions' => count((array) ($b['decisions'] ?? [])), 'actions' => count((array) ($b['actions'] ?? [])),
                      'summary' => mb_substr(trim(preg_replace('/[*_#>`]/', '', (string) ($b['summary'] ?? '')) ?? ''), 0, 180)];
        }
        return $out;
    }

    /** Per member of a team: how often they came to its meetings (published minutes, last $days). */
    public static function teamAttendance(int $teamId, int $days = 180): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT mm.attendance FROM meeting_minutes mm JOIN meetings m ON m.id = mm.meeting_id
                                        WHERE mm.status = 'published' AND m.context = 'team' AND m.context_id = ? AND mm.occurrence >= ?");
        $st->execute([$teamId, gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
            foreach ((array) json_decode((string) $a, true) as $pid => $mark) {
                $b = &$by[(int) $pid]; $b = $b ?? ['came' => 0, 'counted' => 0, 'apologies' => 0];
                if ($mark === 'apologies') $b['apologies']++;
                elseif (in_array($mark, ['present', 'late', 'absent'], true)) { $b['counted']++; if ($mark !== 'absent') $b['came']++; }
                unset($b);
            }
        }
        foreach ($by as &$b) $b['rate'] = $b['counted'] ? (int) round($b['came'] / $b['counted'] * 100) : null;
        return $by;
    }

    /** How often this person came, from published minutes in the last $days. */
    public static function attendanceOf(array $u, int $days = 180): array
    {
        $vis = self::visibleMeetings($u);
        $n = ['present' => 0, 'late' => 0, 'apologies' => 0, 'absent' => 0];
        if ($vis) {
            $st = Database::pdo()->prepare("SELECT attendance FROM meeting_minutes WHERE status = 'published' AND occurrence >= ? AND meeting_id IN (" . implode(',', array_keys($vis)) . ')');
            $st->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
                $mark = (string) (((array) json_decode((string) $a, true))[(string) $u['id']] ?? '');
                if (isset($n[$mark])) $n[$mark]++;
            }
        }
        $counted = $n['present'] + $n['late'] + $n['absent'];
        return $n + ['rate' => $counted ? (int) round(($n['present'] + $n['late']) / $counted * 100) : null, 'meetings' => array_sum($n)];
    }

    public static function forHub(array $u): array
    {
        self::ensure();
        $uid = (int) $u['id'];
        $now = time();
        $up = []; $write = []; $approve = []; $pub = [];
        $seen = [];
        foreach (self::visibleMeetings($u) as $m) {
            if (isset($seen[(int) $m['id']])) continue;
            $seen[(int) $m['id']] = true;
            $team = self::teamOf($m);
            $base = ['id' => (int) $m['id'], 'title' => (string) $m['title'], 'team' => $team['name'] ?? '', 'team_id' => $team['id'] ?? 0, 'meet_url' => (string) $m['meet_url'],
                     'agenda' => array_slice(array_column(self::agendaItems((string) $m['agenda']), 'item'), 0, 6),
                     'duration_min' => (int) $m['duration_min'], 'frequency_label' => Meetings::cadence($m), 'is_chair' => self::isChair($u, $m)];
            /* What the edit form needs, for whoever may change it (updateForTeam's rule). */
            if (self::mayEdit($u, $m)) {
                $tzU = function_exists('av_user_tz') ? av_user_tz($uid) : 'Africa/Lagos';
                $base['edit'] = ['title' => (string) $m['title'], 'agenda' => (string) $m['agenda'], 'frequency' => (string) $m['frequency'], 'repeat' => Meetings::rule($m), 'duration' => (int) $m['duration_min'],
                    'when' => (new DateTime((string) $m['scheduled_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tzU))->format('Y-m-d\\TH:i'),
                    'no_link' => (string) $m['meet_url'] === ''];
            }
            $next = self::occurrences($m, $now - (int) $m['duration_min'] * 60, $now + 60 * 86400);
            if ($next) {
                $occ = self::occurrenceKey($next[0]);
                $a = self::answers((int) $m['id'], $occ);
                $up[] = $base + ['occurrence' => $occ, 'when' => self::when($occ), 'ts' => $next[0], 'live' => $next[0] <= $now,
                                 'room' => class_exists('MeetingLive') && MeetingLive::isOpen($m, $occ, $now),
                                 'rsvp' => $a['by'][$uid]['answer'] ?? '', 'coming' => $a['yes'], 'apologies' => $a['no'], 'invited' => count(self::invitees((int) $m['id']))];
            }
            /* Occurrences that ended lately, and any whose minutes are open —
               minutes handed to the chair during the meeting count too. */
            $cand = [];
            foreach (self::occurrences($m, $now - self::LOOKBACK_DAYS * 86400, $now) as $ts) if ($ts + (int) $m['duration_min'] * 60 <= $now) $cand[$ts] = true;
            $op = Database::pdo()->prepare("SELECT occurrence FROM meeting_minutes WHERE meeting_id = ? AND status IN ('draft', 'review')");
            $op->execute([(int) $m['id']]);
            foreach ($op->fetchAll(PDO::FETCH_COLUMN) as $o) { $t = strtotime($o . ' UTC'); if ($t) $cand[$t] = true; }
            ksort($cand);
            foreach (array_keys($cand) as $ts) {
                $occ = self::occurrenceKey($ts);
                $min = self::row((int) $m['id'], $occ);
                $item = $base + ['occurrence' => $occ, 'when' => self::when($occ), 'ts' => $ts, 'minutes_id' => $min ? (int) $min['id'] : 0, 'status' => $min ? (string) $min['status'] : ''];
                $writer = self::canWrite($u, $m, $min);
                if ((!$min || (string) $min['status'] === 'draft') && $writer && self::secretaryFor($m, $min) === $uid) $write[] = $item;
                elseif ((!$min || (string) $min['status'] === 'draft') && $writer && self::isChair($u, $m) && self::secretaryFor($m, $min) === (int) $m['creator_id']) $write[] = $item;
                elseif ($min && (string) $min['status'] === 'review' && self::isChair($u, $m)) $approve[] = $item;
            }
        }
        $st = Database::pdo()->query("SELECT id, meeting_id, occurrence, published_at FROM meeting_minutes WHERE status = 'published' ORDER BY published_at DESC LIMIT 200");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($seen[(int) $r['meeting_id']])) continue;
            $m = self::meeting((int) $r['meeting_id']);
            $pub[] = ['minutes_id' => (int) $r['id'], 'id' => (int) $r['meeting_id'], 'title' => (string) $m['title'], 'team' => self::teamOf($m)['name'] ?? '',
                      'occurrence' => (string) $r['occurrence'], 'when' => self::when((string) $r['occurrence'])];
            if (count($pub) >= 10) break;
        }
        usort($up, static fn($a, $b) => $a['ts'] <=> $b['ts']);
        usort($write, static fn($a, $b) => $b['ts'] <=> $a['ts']);
        return ['upcoming' => array_slice($up, 0, 12), 'to_write' => $write, 'to_approve' => $approve, 'published' => $pub];
    }

    /* ══ The automation ═════════════════════════════════════════════════════ */

    public static function nudged(int $meetingId, string $occ, string $kind): bool
    {
        try {
            $st = Database::pdo()->prepare('SELECT 1 FROM meeting_nudges WHERE meeting_id = ? AND occurrence = ? AND kind = ?');
            $st->execute([$meetingId, $occ, $kind]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    public static function nudgeOnce(int $meetingId, string $occ, string $kind): bool
    {
        try {
            $st = Database::pdo()->prepare(Database::insertIgnore('meeting_nudges', ['meeting_id', 'occurrence', 'kind', 'sent_at']));
            $st->execute([$meetingId, $occ, $kind, self::now()]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) { return false; }
    }

    /**
     * Run by the cron (every few minutes). For team meetings:
     *   · a day before: email everybody, with the calendar file and the agenda;
     *   · an hour before: a portal notice to everybody;
     *   · when it ends: the draft minutes are made and the secretary is asked;
     *   · two days on with no minutes out: the secretary and the chair are reminded.
     * Each happens once per occurrence, whoever else runs the sweep.
     */
    public static function sweep(?int $now = null): array
    {
        self::ensure();
        $now = $now ?? time();
        $out = ['day_before' => 0, 'hour_before' => 0, 'drafted' => 0, 'overdue' => 0];
        $out += ['live_polled' => 0, 'finished' => 0, 'absorbed' => 0];
        /* Live capture, for any meeting — team or not — that is on now or
           just ended: Google's participant list is read, and once it is over
           the log becomes the transcript. */
        if (class_exists('MeetingLive')) {
            MeetingLive::ensure();
            $live = Database::pdo()->query("SELECT * FROM meetings WHERE status <> 'cancelled' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($live as $m) {
                $dur = (int) $m['duration_min'] * 60;
                /* A day back: Google can take hours to write a transcript, and
                   finishing waits for it (MeetingLive::FINISH_WAIT). */
                foreach (self::occurrences($m, $now - $dur - MeetingLive::FINISH_WAIT, $now) as $ts) {
                    $occ = self::occurrenceKey($ts);
                    $recent = $ts >= $now - $dur - 3 * 3600;
                    if ($recent && $now <= $ts + $dur + 30 * 60 && (string) $m['meet_url'] !== '') { MeetingLive::pollGoogle($m, $occ); $out['live_polled']++; }
                    /* Finishing is tried every 10 minutes for two hours, then
                       hourly, until it has a transcript or gives up; each try
                       is one per window, however often the cron runs. */
                    $since = $now - ($ts + $dur);
                    if ($since >= 15 * 60 && !self::nudged((int) $m['id'], $occ, 'finished')) {
                        $win = $since < 7200 ? 'f10:' . intdiv($since, 600) : 'f60:' . intdiv($since, 3600);
                        if (self::nudgeOnce((int) $m['id'], $occ, $win)) {
                            $r = MeetingLive::finish($m, $occ, $now);
                            if ($r !== MeetingLive::WAITING) { self::nudgeOnce((int) $m['id'], $occ, 'finished'); $out['finished']++; }
                        }
                    }
                    if ($recent && $now >= $ts + $dur && self::absorb($m, $occ)) $out['absorbed']++;
                }
            }
        }
        $rows = Database::pdo()->query("SELECT * FROM meetings WHERE context = 'team' AND status <> 'cancelled' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $m) {
            $mid = (int) $m['id']; $dur = (int) $m['duration_min'] * 60;
            foreach (self::occurrences($m, $now, $now + 86400) as $ts) {
                $occ = self::occurrenceKey($ts);
                if ($ts - $now <= 3600) {
                    if (self::nudgeOnce($mid, $occ, 'hour_before')) {
                        foreach (self::invitees($mid) as $p) {
                            if ($p['id'] > 0 && class_exists('Notifications')) { try { Notifications::push($p['id'], 'volunteer', 'Starting soon: ' . $m['title'], self::when($occ) . ((string) $m['meet_url'] !== '' ? ' · ' . $m['meet_url'] : ''), (string) $m['meet_url'] ?: '/portal/#volunteer', 'soon:' . $mid . ':' . $occ); } catch (Throwable $e) {} }
                        }
                        self::nudgeOnce($mid, $occ, 'day_before');
                        $out['hour_before']++;
                    }
                } elseif (self::nudgeOnce($mid, $occ, 'day_before')) {
                    self::remindDayBefore($m, $occ);
                    $out['day_before']++;
                }
                break;
            }
            foreach (self::occurrences($m, $now - 3 * 86400, $now) as $ts) {
                if ($ts + $dur > $now) continue;
                $occ = self::occurrenceKey($ts);
                $min = self::row($mid, $occ);
                if (!$min && self::nudgeOnce($mid, $occ, 'drafted')) {
                    $min = self::seed($m, $occ, 0);
                    $sec = self::secretaryFor($m, $min);
                    if (class_exists('Notifications')) { try { Notifications::push($sec, 'volunteer', 'Minutes to write: ' . $m['title'], 'The draft is ready with the agenda and attendance — add what was said.', '/portal/#volunteer', 'mindue:' . $mid . ':' . $occ); } catch (Throwable $e) {} }
                    $out['drafted']++;
                }
            }
            foreach (self::occurrences($m, $now - self::LOOKBACK_DAYS * 86400, $now - self::OVERDUE_HOURS * 3600) as $ts) {
                if ($ts + $dur > $now - self::OVERDUE_HOURS * 3600) continue;
                $occ = self::occurrenceKey($ts);
                $min = self::row($mid, $occ);
                if ($min && (string) $min['status'] === 'published') continue;
                if (!self::nudgeOnce($mid, $occ, 'overdue')) continue;
                $who = array_unique([self::secretaryFor($m, $min), (int) $m['creator_id']]);
                foreach ($who as $pid) {
                    if (class_exists('Notifications')) { try { Notifications::push($pid, 'volunteer', 'Minutes still not out: ' . $m['title'], self::when($occ) . ' — everybody is waiting for the actions.', '/portal/#volunteer', 'minlate:' . $mid . ':' . $occ); } catch (Throwable $e) {} }
                    if (class_exists('Notifications')) { try { Notifications::email($pid, 'Minutes still not out: ' . $m['title'], 'The minutes of ' . $m['title'] . ' on ' . self::when($occ) . ' are not published yet. Open your Volunteer hub to finish them.', '/portal/#volunteer'); } catch (Throwable $e) {} }
                }
                $out['overdue']++;
            }
        }
        return $out;
    }

    private static function remindDayBefore(array $m, string $occ): void
    {
        $ics = self::icsFor($m, 'REQUEST');
        $file = tempnam(sys_get_temp_dir(), 'ics');
        $attach = $file !== false && @file_put_contents($file, $ics) !== false ? ['attachment' => ['path' => $file, 'name' => 'invite.ics']] : [];
        $e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $agenda = self::agendaItems((string) $m['agenda']);
        foreach (self::invitees((int) $m['id']) as $p) {
            if ($p['id'] > 0 && class_exists('Notifications')) { try { Notifications::push($p['id'], 'volunteer', 'Tomorrow: ' . $m['title'], self::when($occ) . ' — are you coming?', '/portal/#volunteer', 'daybefore:' . $m['id'] . ':' . $occ); } catch (Throwable $e) {} }
            if (!class_exists('Mailer') || $p['email'] === '') continue;
            $rows = ['<b>' . $e($m['title']) . '</b> is on <b>' . $e(self::when($occ)) . '</b> (' . (int) $m['duration_min'] . ' minutes).'];
            if ($agenda) $rows[] = 'Agenda:<ol style="margin:6px 0 0;padding-left:20px">' . implode('', array_map(static fn($a) => '<li>' . $e($a['item']) . '</li>', $agenda)) . '</ol>';
            if ((string) $m['meet_url'] !== '') $rows[] = 'Join: <a href="' . $e($m['meet_url']) . '">' . $e($m['meet_url']) . '</a>';
            $rows[] = 'Tell the team whether you are coming from your Volunteer hub — it takes one tap, and an apology goes on the record.';
            try { @Mailer::send($p['email'], 'Tomorrow: ' . $m['title'], Mailer::shell('See you tomorrow', $rows, ['url' => self::site('/portal/#volunteer'), 'text' => 'Answer: coming or not'], 'Reminder: ' . $m['title']), $attach); } catch (Throwable $e2) {}
        }
        if ($file !== false) @unlink($file);
    }
}
