<?php
/**
 * lib/MeetingClock.php — in-meeting time management (report §10).
 *
 *   "The meeting assistant should monitor the scheduled meeting duration.
 *    Rather than allowing meetings to drift endlessly, it can issue private or
 *    visible reminders.
 *
 *      20 minutes remaining — 'There are three agenda items remaining.'
 *      10 minutes remaining — 'Two agenda items remain unresolved.'
 *       5 minutes remaining — 'The meeting is approaching its scheduled end.
 *                              Outstanding items: 2. Consider assigning
 *                              follow-up actions or extending.'
 *
 *    The exact warning points can be configured." (§10)
 *
 * Two things in that spec do the design work.
 *
 * OUTSTANDING ITEMS, not elapsed minutes. Every example counts agenda items
 * still unresolved. A bare countdown is a clock; "five minutes, two items" is
 * the sentence that changes what a chair does next. So the agenda produced by
 * §7 gets a tick-state here, and the warning reports against it. Without that
 * this feature is decoration.
 *
 * PRIVATE OR VISIBLE. The spec allows either, which matters because this
 * codebase cannot reliably do "visible":
 *
 *   • The PORTAL countdown is exact and needs no scheduler. Anyone with the
 *     meeting open sees the clock and the warning the moment it lands. This is
 *     the primary channel.
 *   • NOTIFICATIONS are the backstop for people who are not looking at the
 *     portal. Cron ticks every few minutes, so a mark is fired only inside a
 *     tolerance window and recorded — a "5 minutes remaining" notice arriving
 *     after the meeting ended is worse than none, so a stale mark is skipped,
 *     not delivered late.
 *   • POSTING INTO THE MEETING ITSELF is NOT implemented. Attendee and Recall
 *     both expose a send-chat endpoint, but writing a third-party API call that
 *     cannot be exercised from here would be inventing an integration rather
 *     than building one. `deliver()` is the single seam where it belongs; the
 *     day someone can test against a real provider, it is one method.
 *
 * Nothing here ends or extends a meeting. It says the time is going.
 */
declare(strict_types=1);

final class MeetingClock
{
    private static bool $ready = false;

    /**
     * How late a warning may be and still be worth sending, in seconds.
     *
     * Cron granularity is the constraint: the portal is exact, this is not. Two
     * minutes keeps "10 minutes remaining" honest and lets a genuinely missed
     * mark stay missed.
     */
    private const TOLERANCE = 120;

    /**
     * How long after its scheduled end an occurrence is still "the current one".
     *
     * Without this a recurring meeting running seven minutes over rolls straight
     * to next week's occurrence and reports "starts in 10,028 minutes" — so the
     * overrun, which is the single thing §10 exists to catch, becomes invisible
     * on exactly the meetings that have one.
     */
    private const OVERRUN_GRACE = 1800;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_meeting_clock (
                meeting_id INTEGER NOT NULL,
                occurrence VARCHAR(32) NOT NULL DEFAULT '',
                resolved TEXT NOT NULL DEFAULT '',
                fired TEXT NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT '',
                PRIMARY KEY (meeting_id, occurrence)
            );");
        } catch (Throwable $e) { error_log('[clock] ensure: ' . $e->getMessage()); }
    }

    /** Is in-meeting timing switched on? */
    public static function enabled(): bool
    {
        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) return false;
        return true;
    }

    /**
     * The configured warning points, minutes-remaining, largest first.
     *
     * Nonsense is dropped rather than coerced: a mark longer than the meeting
     * would fire before it started, and zero is the end, not a warning.
     */
    public static function marks(int $durationMin = 0): array
    {
        $raw = class_exists('AvRules') ? AvRules::list('meetings.warn_minutes') : ['20', '10', '5'];
        $out = [];
        foreach ($raw as $v) {
            $n = (int) trim((string) $v);
            if ($n <= 0) continue;
            if ($durationMin > 0 && $n >= $durationMin) continue;
            $out[$n] = true;
        }
        $out = array_keys($out);
        rsort($out, SORT_NUMERIC);
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       Where a meeting is right now
       ════════════════════════════════════════════════════════════════ */

    /**
     * The live picture for one meeting.
     *
     * @return array{
     *   ok:bool, running:bool, starts_in:?int, remaining:?int, elapsed:?int,
     *   duration:int, occurrence:string, ends_at:string,
     *   items:list<array{index:int,item:string,minutes:int,done:bool}>,
     *   outstanding:int, mark:?int, message:string, overrun:bool
     * }
     */
    public static function state(int $meetingId): array
    {
        self::ensure();
        $out = ['ok' => false, 'running' => false, 'starts_in' => null, 'remaining' => null,
                'elapsed' => null, 'duration' => 0, 'occurrence' => '', 'ends_at' => '',
                'items' => [], 'outstanding' => 0, 'mark' => null, 'message' => '', 'overrun' => false];

        try {
            $st = Database::pdo()->prepare('SELECT * FROM meetings WHERE id = ?');
            $st->execute([$meetingId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m || (string) $m['status'] === 'cancelled') return $out;
        } catch (Throwable $e) { error_log('[clock] state: ' . $e->getMessage()); return $out; }

        $dur   = max(1, (int) $m['duration_min']);
        $start = self::occurrenceStart((string) $m['scheduled_at'], (string) $m['frequency'], $dur);
        if ($start === 0) return $out;
        $end   = $start + $dur * 60;
        $now   = time();

        $out['ok']         = true;
        $out['duration']   = $dur;
        $out['occurrence'] = gmdate('Y-m-d H:i:s', $start);
        $out['ends_at']    = gmdate('Y-m-d H:i:s', $end);

        if ($now < $start)      { $out['starts_in'] = (int) ceil(($start - $now) / 60); }
        elseif ($now <= $end)   { $out['running'] = true; }
        else                    { $out['overrun'] = true; }

        $out['elapsed']   = $now >= $start ? (int) floor(($now - $start) / 60) : null;
        $out['remaining'] = $now >= $start ? (int) ceil(($end - $now) / 60) : null;   // negative once overrun

        // The agenda, with whatever has been ticked off.
        $agenda = trim((string) $m['agenda']);
        $items  = $agenda !== '' && class_exists('Agenda') ? Agenda::itemsFromText($agenda) : [];
        $done   = self::resolvedSet($meetingId, $out['occurrence']);
        foreach ($items as $i => $it) {
            $out['items'][] = ['index' => $i, 'item' => (string) $it['item'],
                               'minutes' => (int) $it['minutes'], 'done' => isset($done[$i])];
            if (!isset($done[$i])) $out['outstanding']++;
        }

        // The mark currently in force: the smallest configured warning at or
        // above the minutes remaining.
        if ($out['running'] && $out['remaining'] !== null) {
            foreach (self::marks($dur) as $mk) {
                if ($out['remaining'] <= $mk) $out['mark'] = $mk;   // keeps narrowing
            }
        }
        $out['message'] = self::wording($out);
        return $out;
    }

    /**
     * The sentence §10 asks for.
     *
     * Deterministic — a countdown is arithmetic, and phrasing it with a model
     * would spend a call per meeting per mark to say something the spec already
     * wrote out.
     */
    public static function wording(array $s): string
    {
        $n = (int) ($s['outstanding'] ?? 0);
        $items = $n === 1 ? '1 agenda item is unresolved' : $n . ' agenda items are unresolved';
        if (empty($s['items'])) $items = 'No agenda was set for this meeting';

        if (!empty($s['overrun'])) {
            $over = abs((int) ($s['remaining'] ?? 0));
            return 'This meeting is ' . $over . ' minute' . ($over === 1 ? '' : 's') . ' past its scheduled end. '
                 . $items . '. Consider assigning follow-up actions rather than continuing.';
        }
        if (empty($s['running']) || ($s['mark'] ?? null) === null) return '';

        $left = (int) $s['remaining'];
        if ($left <= 5) {
            return $left . ' minute' . ($left === 1 ? '' : 's') . ' remaining. ' . $items
                 . '. Consider assigning follow-up actions or extending the meeting.';
        }
        return $left . ' minutes remaining. ' . $items . '.';
    }

    /* ════════════════════════════════════════════════════════════════
       Ticking items off
       ════════════════════════════════════════════════════════════════ */

    /**
     * Mark an agenda item resolved, or put it back.
     *
     * Any participant may — a meeting is not run by one person's cursor, and
     * §10's value is the outstanding count being true, which it will not be if
     * only the chair can keep it.
     */
    public static function setResolved(int $uid, int $meetingId, int $index, bool $done): array
    {
        self::ensure();
        if (!class_exists('Meetings') || !Meetings::get($uid, $meetingId)) {
            return ['ok' => false, 'error' => 'Not your meeting.'];
        }
        $s = self::state($meetingId);
        if (empty($s['ok'])) return ['ok' => false, 'error' => 'That meeting has no clock.'];
        if ($index < 0 || $index >= count($s['items'])) return ['ok' => false, 'error' => 'No such agenda item.'];

        $set = self::resolvedSet($meetingId, $s['occurrence']);
        if ($done) $set[$index] = true; else unset($set[$index]);
        self::store($meetingId, $s['occurrence'], array_keys($set), null);
        return ['ok' => true, 'state' => self::state($meetingId)];
    }

    /* ════════════════════════════════════════════════════════════════
       The sweep
       ════════════════════════════════════════════════════════════════ */

    /**
     * Fire any warning that has just come due, once each.
     *
     * A mark is only sent inside TOLERANCE of its moment. Cron granularity means
     * a mark can be missed entirely, and a warning that arrives after the fact
     * is worse than a warning that never came — so a stale one is recorded as
     * fired and skipped, rather than delivered late.
     */
    public static function sweep(): array
    {
        self::ensure();
        $out = ['warned' => 0, 'meetings' => 0, 'stale' => 0];
        if (!self::enabled()) return array_merge($out, ['off' => true]);

        try {
            $rows = Database::pdo()->query(
                "SELECT id, duration_min, scheduled_at, frequency FROM meetings
                  WHERE status = 'scheduled' AND scheduled_at <> ''"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[clock] sweep: ' . $e->getMessage()); return $out; }

        $now = time();
        foreach ($rows as $r) {
            $id  = (int) $r['id'];
            $dur = max(1, (int) $r['duration_min']);
            $start = self::occurrenceStart((string) $r['scheduled_at'], (string) $r['frequency'], $dur);
            if ($start === 0) continue;
            $end = $start + $dur * 60;
            if ($now < $start || $now > $end) continue;             // not running
            $out['meetings']++;

            $occ   = gmdate('Y-m-d H:i:s', $start);
            $fired = self::firedSet($id, $occ);
            $state = null;

            foreach (self::marks($dur) as $mk) {
                if (isset($fired[$mk])) continue;
                $at   = $end - $mk * 60;
                if ($now < $at) continue;                            // not yet
                if ($now - $at > self::TOLERANCE) {                  // missed the window
                    $fired[$mk] = true; $out['stale']++;
                    continue;
                }
                $state = $state ?? self::state($id);
                if (self::deliver($id, $mk, $state) > 0) $out['warned']++;
                $fired[$mk] = true;
            }
            self::store($id, $occ, null, array_keys($fired));
        }
        return $out;
    }

    /**
     * Send one warning to everyone in the meeting.
     *
     * THE SEAM. Today this is a portal notification — §10's "private" reminder.
     * A visible one, posted into the meeting's own chat by the notetaker, belongs
     * here and nowhere else: both providers expose a send-chat endpoint, and it
     * is deliberately not called, because an API request written from memory and
     * never exercised is an invented integration, not a built one.
     */
    private static function deliver(int $meetingId, int $mark, array $state): int
    {
        if (!class_exists('Notifications')) return 0;
        $msg = (string) ($state['message'] ?? '');
        if (trim($msg) === '') return 0;

        $n = 0;
        foreach (self::participants($meetingId) as $uid) {
            $n += Notifications::push($uid, 'meeting', $mark . ' minutes left in your meeting', $msg,
                '/portal/meetings', 'clock:' . $meetingId . ':' . ($state['occurrence'] ?? '') . ':' . $mark) > 0 ? 1 : 0;
        }
        return $n;
    }

    /** Everyone in the meeting who has an account. */
    private static function participants(int $meetingId): array
    {
        $ids = [];
        try {
            $db = Database::pdo();
            $c = $db->prepare('SELECT creator_id FROM meetings WHERE id = ?');
            $c->execute([$meetingId]);
            $cid = (int) ($c->fetchColumn() ?: 0);
            if ($cid > 0) $ids[$cid] = true;

            $a = $db->prepare('SELECT u.id FROM meeting_attendees a JOIN lms_users u ON LOWER(u.email) = LOWER(a.email) WHERE a.meeting_id = ?');
            $a->execute([$meetingId]);
            foreach ($a->fetchAll(PDO::FETCH_COLUMN) ?: [] as $x) { $x = (int) $x; if ($x > 0) $ids[$x] = true; }
        } catch (Throwable $e) { error_log('[clock] participants: ' . $e->getMessage()); }
        return array_keys($ids);
    }

    /* ── internals ─────────────────────────────────────────────────── */

    /**
     * The start of the occurrence that is running now, or the next one.
     *
     * Meetings::nextOccurrence() steps until the START is in the future, which
     * skips straight past a meeting currently in progress — the one case a clock
     * cares about. This steps until the END is in the future instead.
     */
    public static function occurrenceStart(string $startUtc, string $freq, int $durationMin): int
    {
        $ts = strtotime(trim($startUtc) . ' UTC');
        if ($ts === false || $ts === 0) return 0;
        $step = match ($freq) {
            'daily', 'weekdays' => '+1 day',
            'weekly'            => '+1 week',
            'biweekly'          => '+2 weeks',
            'monthly'           => '+1 month',
            default             => '',
        };
        $end = $ts + max(1, $durationMin) * 60;
        if ($step === '' || $end + self::OVERRUN_GRACE >= time()) return $ts;

        try {
            $dt = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
            for ($i = 0; $i < 800; $i++) {
                $dt->modify($step);
                if ($freq === 'weekdays' && (int) $dt->format('N') > 5) continue;
                if ($dt->getTimestamp() + max(1, $durationMin) * 60 + self::OVERRUN_GRACE >= time()) return $dt->getTimestamp();
            }
        } catch (Throwable $e) { /* fall through */ }
        return $ts;
    }

    /** @return array<int,true> resolved item indexes */
    private static function resolvedSet(int $meetingId, string $occurrence): array
    {
        return self::readSet($meetingId, $occurrence, 'resolved');
    }
    /** @return array<int,true> marks already fired */
    private static function firedSet(int $meetingId, string $occurrence): array
    {
        return self::readSet($meetingId, $occurrence, 'fired');
    }
    private static function readSet(int $meetingId, string $occurrence, string $col): array
    {
        try {
            $st = Database::pdo()->prepare("SELECT {$col} FROM av_meeting_clock WHERE meeting_id = ? AND occurrence = ?");
            $st->execute([$meetingId, $occurrence]);
            // NOT `?: ''` — the very first agenda item is index 0, so the stored
            // set is the string "0", which is falsy in PHP. That silently lost
            // exactly one tick: the first item on every agenda.
            $col = $st->fetchColumn();
            $raw = ($col === false || $col === null) ? '' : (string) $col;
            $out = [];
            foreach (array_filter(explode(',', $raw), 'strlen') as $v) $out[(int) $v] = true;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Upsert one column, leaving the other as it is. Portable across drivers. */
    private static function store(int $meetingId, string $occurrence, ?array $resolved, ?array $fired): void
    {
        try {
            $db = Database::pdo();
            $cur = $db->prepare('SELECT resolved, fired FROM av_meeting_clock WHERE meeting_id = ? AND occurrence = ?');
            $cur->execute([$meetingId, $occurrence]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            $r = $resolved !== null ? implode(',', $resolved) : (string) ($row['resolved'] ?? '');
            $f = $fired    !== null ? implode(',', $fired)    : (string) ($row['fired'] ?? '');
            if ($row) {
                $db->prepare('UPDATE av_meeting_clock SET resolved = ?, fired = ?, updated_at = ? WHERE meeting_id = ? AND occurrence = ?')
                   ->execute([$r, $f, gmdate('Y-m-d H:i:s'), $meetingId, $occurrence]);
            } else {
                $db->prepare('INSERT INTO av_meeting_clock (meeting_id, occurrence, resolved, fired, updated_at) VALUES (?,?,?,?,?)')
                   ->execute([$meetingId, $occurrence, $r, $f, gmdate('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) { error_log('[clock] store: ' . $e->getMessage()); }
    }
}
