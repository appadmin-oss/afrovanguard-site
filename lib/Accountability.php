<?php
/**
 * lib/Accountability.php — the engine that makes the rules bite.
 *
 * The concept report's Phase 3 (§28), and the part it says "alone could produce
 * a major improvement". Everything it needs already existed: the rules were
 * declared in AvRules, the wording was written in AvPrompts, the relationships
 * and sessions were in Mentorship, the delivery was in Notifications. Nothing
 * read any of it. Thirteen rules resolved to values no code consulted, and the
 * system prompt told the model about a cadence the engine never measured.
 *
 * This class is the missing middle. Three things, all rule-driven:
 *
 *   §13  THE WEEKLY CYCLE. Find the sessions coming due inside
 *        mentorship.reminder_lead_hours and remind both people. Find the
 *        pairings with nothing on the calendar at all and ask them to schedule
 *        one, because a relationship with no next meeting is how a mentorship
 *        quietly dies — principle 3 of §39.
 *
 *   §14  THE ESCALATION LADDER. A missed meeting is handled between the pair
 *        first (escalation.steps, default 3: gentle, firmer, escalate). Only on
 *        the last step, and only when escalation.notify_chain is on, is the
 *        mentor's own mentor told. escalation.cooldown_days stops a quiet month
 *        becoming a pile of notices. The report is emphatic and so is the
 *        wording here: this is early intervention, never punishment.
 *
 *   §15  RELATIONSHIP HEALTH. Green / Amber / Red from the health.* thresholds,
 *        returned with the BEHAVIOUR that produced it rather than as a bare
 *        grade. §3A is explicit that character must not collapse into a number,
 *        and a red dot with no reason beside it is a number.
 *
 * A missed meeting, for escalation, is a past session nobody marked attended —
 * whether it was recorded as missed or simply never recorded at all. §6's worked
 * example is exactly the second case: "No attendance record was received."
 *
 * Two safety properties, because this sends real messages to real people:
 *
 *   • COLD START. The first run stamps a watermark and only ever escalates
 *     sessions scheduled on or after it. Without that, switching the engine on
 *     would escalate every historical miss in the database at once, to everyone's
 *     mentor, in one tick — the opposite of early intervention.
 *   • IDEMPOTENCE. The sweep runs once per day however often cron ticks, every
 *     notification carries a dedupe key, and every escalation is recorded before
 *     it is sent. Running it twice sends nothing twice.
 *
 * Fail-safe throughout: a broken query returns an empty result and logs, never
 * an exception into a cron tick or a page render.
 */
declare(strict_types=1);

final class Accountability
{
    private static bool $ready = false;

    /** Escalations only ever consider sessions scheduled on or after this date. */
    private const META_FROM = 'accountability_from';
    /** Last date the daily sweep completed (UTC Y-m-d). */
    private const META_RUN  = 'accountability_last_run';

    /* ════════════════════════════════════════════════════════════════
       Storage
       ════════════════════════════════════════════════════════════════ */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_escalations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mentorship_id INTEGER NOT NULL,
                session_id INTEGER NOT NULL DEFAULT 0,
                step INTEGER NOT NULL DEFAULT 1,
                reason VARCHAR(191) NOT NULL DEFAULT '',
                notified TEXT NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_avesc ON av_escalations(mentorship_id, id);");

            // Stamp the cold-start watermark the first time the table exists, so
            // history that predates the engine is never escalated retroactively.
            if (Database::metaGet(self::META_FROM) === null) {
                Database::metaSet(self::META_FROM, gmdate('Y-m-d'));
            }
        } catch (Throwable $e) { error_log('[accountability] ensure: ' . $e->getMessage()); }
    }

    /** The date from which misses count. Nothing before it is ever escalated. */
    public static function watermark(): string
    {
        self::ensure();
        try { return (string) (Database::metaGet(self::META_FROM) ?? gmdate('Y-m-d')); }
        catch (Throwable $e) { return gmdate('Y-m-d'); }
    }

    /* ════════════════════════════════════════════════════════════════
       §15 — Relationship health
       ════════════════════════════════════════════════════════════════ */

    /**
     * Green / Amber / Red for one pairing, with the evidence.
     *
     * Every threshold comes from the Health rules; nothing here invents one.
     * `reasons` is the point of the return value — §3A wants behavioural
     * evidence a leader can read, not a grade they have to trust.
     *
     * @return array{status:string,rate:?int,miss_streak:int,quiet_days:?int,reasons:string[]}
     */
    public static function health(int $mentorshipId): array
    {
        $out = ['status' => 'green', 'rate' => null, 'miss_streak' => 0, 'quiet_days' => null, 'reasons' => []];
        if ($mentorshipId <= 0 || !class_exists('Mentorship')) return $out;

        try {
            $sessions = Mentorship::sessions($mentorshipId);
            $c        = Mentorship::consistency($mentorshipId);
        } catch (Throwable $e) {
            error_log('[accountability] health: ' . $e->getMessage());
            return $out;
        }

        $amberPct = self::rule('health.amber_attendance_pct', 70);
        $redPct   = self::rule('health.red_attendance_pct', 40);
        $redRun   = self::rule('health.red_missed_streak', 3);
        $quietMax = self::rule('mentorship.inactive_days', 21);

        $out['rate']        = $c['rate'] !== null ? (int) $c['rate'] : null;
        $out['miss_streak'] = self::missStreak($sessions);
        $out['quiet_days']  = self::quietDays($sessions);
        $everMet            = (int) ($c['attended'] ?? 0) > 0;

        $red = [];
        $amb = [];

        if ($out['miss_streak'] >= $redRun) {
            $red[] = $out['miss_streak'] . ' meetings in a row with no attendance recorded';
        } elseif ($out['miss_streak'] > 0) {
            $amb[] = $out['miss_streak'] === 1 ? 'the last meeting was not attended'
                                               : $out['miss_streak'] . ' meetings in a row were not attended';
        }

        if ($out['rate'] !== null) {
            if ($out['rate'] < $redPct)        $red[] = 'attendance is ' . $out['rate'] . '%, under the ' . $redPct . '% Red threshold';
            elseif ($out['rate'] < $amberPct)  $amb[] = 'attendance is ' . $out['rate'] . '%, under the ' . $amberPct . '% Amber threshold';
        }

        if ($out['quiet_days'] !== null && $out['quiet_days'] >= $quietMax) {
            if (!$everMet) $red[] = 'the pairing has never met, and was made ' . $out['quiet_days'] . ' days ago';
            else           $amb[] = 'no meeting for ' . $out['quiet_days'] . ' days';
        }

        // A pairing with nothing on the calendar is how one silently dies (§39.3).
        if (empty($c['next'])) $amb[] = 'no next meeting is scheduled';

        if ($red)      { $out['status'] = 'red';   $out['reasons'] = array_merge($red, $amb); }
        elseif ($amb)  { $out['status'] = 'amber'; $out['reasons'] = $amb; }
        else {
            $out['reasons'] = $out['rate'] !== null
                ? ['attendance is ' . $out['rate'] . '%, with a next meeting scheduled']
                : ['scheduled and on track'];
        }
        return $out;
    }

    /** Consecutive past sessions at the tail with no attendance recorded. */
    public static function missStreak(array $sessions): int
    {
        $n = 0;
        foreach (array_reverse($sessions) as $s) {
            if (empty($s['past']) || ($s['attendance'] ?? '') === 'cancelled') continue;
            if (($s['attendance'] ?? '') === 'attended') break;
            $n++;   // 'missed' recorded, or 'scheduled' and never recorded at all
        }
        return $n;
    }

    /** Days since the last attended session; null when there has never been one. */
    private static function quietDays(array $sessions): ?int
    {
        $last = null;
        foreach ($sessions as $s) {
            if (($s['attendance'] ?? '') === 'attended' && ($s['when'] ?? '') !== '') {
                $t = strtotime((string) $s['when']);
                if ($t && ($last === null || $t > $last)) $last = $t;
            }
        }
        if ($last === null) {
            // Never met: measure from the oldest session on record instead, so a
            // brand-new pairing is not instantly flagged.
            foreach ($sessions as $s) {
                $t = ($s['when'] ?? '') !== '' ? strtotime((string) $s['when']) : 0;
                if ($t && ($last === null || $t < $last)) $last = $t;
            }
            if ($last === null) return null;
        }
        return (int) floor((time() - $last) / 86400);
    }

    /* ════════════════════════════════════════════════════════════════
       §13 — The weekly cycle
       ════════════════════════════════════════════════════════════════ */

    /**
     * Active pairings, with the names joined in.
     *
     * The names ride along because every caller needs them and the alternative
     * is a lookup per pairing — the Studio's health board would issue two
     * queries per row on top of the session read it already does.
     *
     * @return list<array{id:int,mentor_id:int,mentee_id:int,mentor:string,mentee:string,segment:string}>
     */
    public static function activePairs(): array
    {
        self::ensure();
        try {
            if (class_exists('Mentorship')) Mentorship::ensure();
            $rows = Database::pdo()->query(
                "SELECT m.id, m.mentor_id, m.mentee_id, m.segment,
                        mu.name AS mentor_name, eu.name AS mentee_name
                   FROM mentorships m
                   JOIN lms_users mu ON mu.id = m.mentor_id
                   JOIN lms_users eu ON eu.id = m.mentee_id
                  WHERE m.status = 'active' ORDER BY m.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(fn($r) => [
                'id'        => (int) $r['id'],
                'mentor_id' => (int) $r['mentor_id'],
                'mentee_id' => (int) $r['mentee_id'],
                'mentor'    => (string) ($r['mentor_name'] ?? ''),
                'mentee'    => (string) ($r['mentee_name'] ?? ''),
                'segment'   => (string) ($r['segment'] ?? 'org'),
            ], $rows);
        } catch (Throwable $e) { error_log('[accountability] pairs: ' . $e->getMessage()); return []; }
    }

    /**
     * Sessions starting inside the reminder window that nobody has been reminded
     * about today. Step 2 of the cycle.
     */
    public static function dueSoon(): array
    {
        $lead = max(1, self::rule('mentorship.reminder_lead_hours', 24));
        $now  = time();
        $out  = [];
        foreach (self::activePairs() as $p) {
            try { $sessions = Mentorship::sessions($p['id']); }
            catch (Throwable $e) { continue; }
            foreach ($sessions as $s) {
                if (!empty($s['past']) || ($s['attendance'] ?? '') === 'cancelled') continue;
                $t = ($s['when'] ?? '') !== '' ? strtotime((string) $s['when']) : 0;
                if (!$t || $t < $now) continue;
                if (($t - $now) <= $lead * 3600) $out[] = $p + ['session' => $s];
            }
        }
        return $out;
    }

    /**
     * Pairings with no future session booked, whose last meeting is older than
     * the cadence. Step 3 of the cycle: the AI prompts them to schedule one.
     */
    public static function needsScheduling(): array
    {
        $cadence = max(1, self::rule('mentorship.cadence_days', 7));
        $out = [];
        foreach (self::activePairs() as $p) {
            try { $c = Mentorship::consistency($p['id']); }
            catch (Throwable $e) { continue; }
            if (!empty($c['next'])) continue;                 // something is booked
            $quiet = self::quietDays(Mentorship::sessions($p['id']));
            if ($quiet === null || $quiet >= $cadence) $out[] = $p + ['quiet_days' => $quiet];
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
       §14 — The escalation ladder
       ════════════════════════════════════════════════════════════════ */

    /** Escalations already recorded for a pairing, newest first. */
    public static function historyFor(int $mentorshipId): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM av_escalations WHERE mentorship_id = ? ORDER BY id DESC');
            $st->execute([$mentorshipId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[accountability] history: ' . $e->getMessage()); return []; }
    }

    /**
     * Should this pairing escalate right now, and at which step?
     *
     * @return array{due:bool,step:int,session_id:int,reason:string,why_not:string}
     */
    public static function escalationDue(int $mentorshipId): array
    {
        $no = ['due' => false, 'step' => 0, 'session_id' => 0, 'reason' => '', 'why_not' => ''];
        try { $sessions = Mentorship::sessions($mentorshipId); }
        catch (Throwable $e) { return array_merge($no, ['why_not' => 'sessions unavailable']); }

        // The most recent unattended past session on or after the watermark.
        $from = strtotime(self::watermark() . ' 00:00:00 UTC') ?: 0;
        $miss = null;
        foreach (array_reverse($sessions) as $s) {
            if (empty($s['past']) || ($s['attendance'] ?? '') === 'cancelled') continue;
            if (($s['attendance'] ?? '') === 'attended') break;
            $t = ($s['when'] ?? '') !== '' ? strtotime((string) $s['when']) : 0;
            if ($t && $t >= $from) { $miss = $s; break; }
        }
        if (!$miss) return array_merge($no, ['why_not' => 'no unattended session since ' . self::watermark()]);

        $history = self::historyFor($mentorshipId);

        // Already escalated for this exact session — nothing further to do.
        foreach ($history as $h) {
            if ((int) $h['session_id'] === (int) $miss['id']) {
                return array_merge($no, ['why_not' => 'already escalated for session ' . (int) $miss['id']]);
            }
        }

        // Cooldown: a quiet month must not produce a pile of notices.
        $cooldown = max(1, self::rule('escalation.cooldown_days', 7));
        if ($history) {
            $lastAt = strtotime((string) ($history[0]['created_at'] ?? '')) ?: 0;
            if ($lastAt && (time() - $lastAt) < $cooldown * 86400) {
                return array_merge($no, ['why_not' => 'within the ' . $cooldown . '-day cooldown']);
            }
        }

        $steps = max(1, self::rule('escalation.steps', 3));
        $step  = min(count($history) + 1, $steps);
        return [
            'due' => true, 'step' => $step, 'session_id' => (int) $miss['id'],
            'reason' => 'no attendance recorded for the meeting on ' . substr((string) $miss['when'], 0, 16),
            'why_not' => '',
        ];
    }

    /** The mentor's own mentor, for the final rung of the ladder. 0 when none. */
    public static function leaderOf(int $mentorId): int
    {
        if ($mentorId <= 0) return 0;
        try {
            $st = Database::pdo()->prepare(
                "SELECT mentor_id FROM mentorships WHERE mentee_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1"
            );
            $st->execute([$mentorId]);
            return (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) { return 0; }
    }

    /* ════════════════════════════════════════════════════════════════
       The daily sweep
       ════════════════════════════════════════════════════════════════ */

    /**
     * One accountability pass. Safe to call on every cron tick — it runs at most
     * once per UTC day unless forced.
     *
     * @return array counts, plus 'skipped' when the day's run already happened
     */
    public static function sweep(bool $force = false): array
    {
        self::ensure();
        $out = ['reminders' => 0, 'schedule_nudges' => 0, 'escalations' => 0, 'chain_notices' => 0, 'skipped' => false];

        if (class_exists('AvRules') && !AvRules::bool('ai.enabled')) {
            // The master switch governs the whole layer, not only model calls:
            // the nudges speak as the assistant, so they stop when it does.
            return array_merge($out, ['skipped' => true, 'why' => 'ai.enabled is off']);
        }

        $today = gmdate('Y-m-d');
        if (!$force) {
            try { if ((string) (Database::metaGet(self::META_RUN) ?? '') === $today) return array_merge($out, ['skipped' => true, 'why' => 'already ran today']); }
            catch (Throwable $e) { /* fall through and run */ }
        }

        try {
            /* §13 step 2 — remind the meetings that are nearly here. */
            foreach (self::dueSoon() as $d) {
                $when = substr((string) ($d['session']['when'] ?? ''), 0, 16);
                $sid  = (int) ($d['session']['id'] ?? 0);
                $out['reminders'] += self::notify($d['mentee_id'], 'mentorship',
                    'Your accountability meeting is coming up',
                    'Your meeting with your mentor is scheduled for ' . $when . '.',
                    '/portal/mentorship', 'acct:remind:' . $sid . ':' . $today);
                $out['reminders'] += self::notify($d['mentor_id'], 'mentorship',
                    'Mentorship meeting coming up',
                    'Your meeting with your mentee is scheduled for ' . $when . '.',
                    '/portal/mentorship', 'acct:remind:m:' . $sid . ':' . $today);
            }

            /* §13 step 3 — nothing booked at all. */
            foreach (self::needsScheduling() as $p) {
                $out['schedule_nudges'] += self::notify($p['mentor_id'], 'mentorship',
                    'No meeting is scheduled',
                    'There is no next meeting booked with your mentee. Please schedule one.',
                    '/portal/mentorship', 'acct:sched:' . $p['id'] . ':' . $today);
            }

            /* §14 — the ladder. */
            $steps = max(1, self::rule('escalation.steps', 3));
            $chain = self::ruleBool('escalation.notify_chain', true);
            foreach (self::activePairs() as $p) {
                $e = self::escalationDue($p['id']);
                if (empty($e['due'])) continue;

                $health = self::health($p['id']);
                $ctx = 'Mentorship pairing #' . $p['id'] . '. ' . $e['reason'] . '. '
                     . 'Relationship health: ' . strtoupper($health['status']) . ' — ' . implode('; ', $health['reasons']) . '. '
                     . 'This is step ' . $e['step'] . ' of ' . $steps . '.';

                // Record BEFORE sending. A crash mid-send must not re-escalate
                // the same session tomorrow; a missed notice is recoverable,
                // a duplicated escalation up someone's chain is not.
                $notified = [$p['mentor_id'], $p['mentee_id']];
                $leader   = ($e['step'] >= $steps && $chain) ? self::leaderOf($p['mentor_id']) : 0;
                if ($leader > 0) $notified[] = $leader;
                self::record($p['id'], $e['session_id'], $e['step'], $e['reason'], $notified);

                $streak   = (int) $health['miss_streak'];
                $toMentee = self::nudge((int) $e['step'], 'the mentee', $ctx, $streak);
                $toMentor = self::nudge((int) $e['step'], 'the mentor', $ctx, $streak);
                self::notify($p['mentee_id'], 'mentorship', self::subjectFor((int) $e['step'], $steps, $streak), $toMentee,
                    '/portal/mentorship', 'acct:esc:' . $e['session_id'] . ':mentee');
                self::notify($p['mentor_id'], 'mentorship', self::subjectFor((int) $e['step'], $steps, $streak), $toMentor,
                    '/portal/mentorship', 'acct:esc:' . $e['session_id'] . ':mentor');
                $out['escalations']++;

                if ($leader > 0) {
                    $toLeader = self::nudge((int) $e['step'], 'the mentor\'s own leader, about the mentor they oversee', $ctx, $streak);
                    self::notify($leader, 'mentorship', 'A mentorship in your chain needs attention', $toLeader,
                        '/portal/mentorship', 'acct:esc:' . $e['session_id'] . ':leader');
                    $out['chain_notices']++;
                }
            }
        } catch (Throwable $e) {
            error_log('[accountability] sweep: ' . $e->getMessage());
            $out['error'] = $e->getMessage();
        }

        try { Database::metaSet(self::META_RUN, $today); } catch (Throwable $e) { /* best effort */ }
        return $out;
    }

    /* ── internals ─────────────────────────────────────────────────── */

    private static function record(int $pairId, int $sessionId, int $step, string $reason, array $notified): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO av_escalations (mentorship_id, session_id, step, reason, notified, created_at) VALUES (?,?,?,?,?,?)'
            )->execute([$pairId, $sessionId, $step, mb_substr($reason, 0, 191), implode(',', $notified), gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('[accountability] record: ' . $e->getMessage()); }
    }

    private static function notify(int $uid, string $kind, string $title, string $body, string $url, string $dedupe): int
    {
        if ($uid <= 0 || !class_exists('Notifications')) return 0;
        try { return Notifications::push($uid, $kind, $title, $body, $url, $dedupe) > 0 ? 1 : 0; }
        catch (Throwable $e) { error_log('[accountability] notify: ' . $e->getMessage()); return 0; }
    }

    /**
     * The step counts interventions DELIVERED, not meetings missed — you cannot
     * send a second notice before the first, and §14's ladder is a sequence of
     * communications with a chance to recover between each. But the wording must
     * still describe what actually happened: a pairing whose first-ever notice
     * arrives after three silent meetings should not read "a meeting was missed".
     */
    private static function subjectFor(int $step, int $steps, int $streak): string
    {
        if ($step >= $steps)  return 'Your mentorship needs attention';
        if ($streak >= 3)     return $streak . ' meetings in a row have been missed';
        if ($streak === 2)    return 'Two meetings in a row have been missed';
        return $step <= 1 ? 'A meeting was missed' : 'A second meeting was missed';
    }

    /**
     * The message text. The AI writes it when it can; a deterministic template
     * writes it when it cannot.
     *
     * The fallback is not a degraded mode — it is the guarantee. This is an
     * accountability system, and silence is the exact failure it exists to
     * prevent, so a provider outage must never mean nobody is told.
     *
     * (Provider order matches Meetings and Mentorship. That is the sixth copy of
     * this fallback chain in the codebase — AI-AUDIT.md A-13 tracks collapsing
     * them into one; this does not make it worse, but it does not fix it either.)
     */
    private static function nudge(int $step, string $subject, string $context, int $streak = 1): string
    {
        $tone = self::ruleStr('escalation.tone', 'supportive');
        if (class_exists('AvPrompts') && AvPrompts::isKey('accountability.nudge')) {
            $sys = AvPrompts::render('accountability.nudge', ['step' => (string) $step, 'subject' => $subject, 'context' => $context]);
            if (trim($sys) !== '') {
                $user = 'Write the message now. Tone: ' . $tone . '.';
                $txt  = '';
                try {
                    // Bulk: three sentences a member reads once. The support tier
                    // writes these as well as a frontier model does, and this is
                    // the highest-volume model call in the system — one per
                    // overdue pairing per day. AvRouter handles the fallbacks.
                    $r = class_exists('AvAgent')
                        ? AvAgent::complete($sys, $user, ['job' => 'bulk', 'max_tokens' => 300,
                                                          'temperature' => 0.3, 'actor' => 'accountability.nudge'])
                        : ['ok' => false];
                    if (!empty($r['ok'])) $txt = (string) $r['text'];
                } catch (Throwable $e) { error_log('[accountability] nudge: ' . $e->getMessage()); }
                $txt = trim($txt);
                if ($txt !== '') return mb_substr($txt, 0, 600);
            }
        }
        return self::fallbackNudge($step, $tone, $streak);
    }

    /** Wording that needs no model, and no apology for being used. */
    private static function fallbackNudge(int $step, string $tone, int $streak = 1): string
    {
        $close = $tone === 'firm'
            ? 'Please reschedule and record the outcome.'
            : ($tone === 'neutral' ? 'Please reschedule when you can and record the outcome.'
                                   : 'Please reschedule when you can — and say so if something is making it hard.');
        // Describe the situation, not the rung. A first notice can still be about
        // a third silent meeting, and saying "a meeting was missed" there reads as
        // though nobody was paying attention — which is the whole complaint.
        $what = $streak >= 2
            ? $streak . ' accountability meetings in a row have no attendance recorded.'
            : 'A scheduled accountability meeting has no attendance recorded.';

        if ($step <= 1)  return $what . ' ' . $close;
        if ($step === 2) return $what . ' Keeping this relationship active matters more than catching up perfectly. ' . $close;
        return $what . ' The next person in the mentorship chain has been told, so you are not carrying it alone. The purpose is support, not sanction. ' . $close;
    }

    private static function rule(string $key, int $default): int
    {
        if (!class_exists('AvRules')) return $default;
        $v = AvRules::int($key);
        return $v > 0 ? $v : $default;
    }
    private static function ruleBool(string $key, bool $default): bool
    {
        return class_exists('AvRules') ? AvRules::bool($key) : $default;
    }
    private static function ruleStr(string $key, string $default): string
    {
        if (!class_exists('AvRules')) return $default;
        $v = AvRules::str($key);
        return $v !== '' ? $v : $default;
    }
}
