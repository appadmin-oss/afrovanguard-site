<?php
/**
 * lib/GoalTrack.php — how a goal is tracked, and how its tasks move it.
 *
 * ── A RESULT, AND THE STEPS TOWARD IT ────────────────────────────────────────
 * A goal used to be a title, a line of target text and a percentage that was
 * both typed (±10) and overwritten by steps done ÷ steps total. That measured
 * activity, not results: five steps ticked toward "500 paying members" read
 * 100% with the membership where it started.
 *
 * Now a goal can say what it measures — a unit, where it started (baseline),
 * where it must get to (target), which way is good (direction), and by when —
 * and its progress is that number's movement, as CACENTRE's goals are measured
 * (includes/os/OsGoals.php there: the same formula, the same thresholds, so a
 * goal mirrored from CACENTRE reads the same on both sites). The steps still
 * count: they are the leading signal, weighted by size (Collab::POINTS), shown
 * beside the result. A goal with no number to measure is tracked by its steps,
 * and says so.
 *
 * When the steps are mostly done and the number has hardly moved, the goal
 * says that too — the steps were not the ones that move it — and the next
 * round of planning is told (planningContext()).
 *
 * ── PACE, NOT JUST PERCENT ───────────────────────────────────────────────────
 * 40% means one thing a week in and another a week from the deadline. Health
 * compares progress with the share of the goal's time gone: on track at 0.8 of
 * pace or better, at risk from 0.5, behind below — CACENTRE's pulse.on_track_
 * ratio and pulse.at_risk_ratio defaults. Not judged before it starts.
 *
 * ── A FORECAST FROM WHAT ACTUALLY GOT DONE ──────────────────────────────────
 * Steps finished in the last four weeks (by size) give a weekly rate; what is
 * left at that rate gives a likely finish date, set against the due date. For
 * a measured goal, the readings' own trend does the same for the number.
 *
 * ── CHECK-INS ────────────────────────────────────────────────────────────────
 * Weekly, by the owner, in under a minute: the number now, how confident they
 * are of hitting it (1–10, read as a probability), what changed, what is in
 * the way. A goal not checked in for a week asks its owner, in the to-do
 * pop-up. A goal mirrored from CACENTRE is measured there and checked in there.
 */
declare(strict_types=1);

require_once __DIR__ . '/Goals.php';

final class GoalTrack
{
    public const ON_TRACK = 0.8;
    public const AT_RISK = 0.5;
    public const CHECKIN_DAYS = 7;
    public const DIRECTIONS = ['increase', 'decrease', 'maintain'];
    /** Readings and steps older than this do not count toward a rate. */
    private const RATE_WINDOW_DAYS = 28;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Goals::ensure();
        $pdo = Database::pdo();
        foreach ([
            'purpose' => "VARCHAR(500) NOT NULL DEFAULT ''", 'unit' => "VARCHAR(24) NOT NULL DEFAULT ''",
            'direction' => "VARCHAR(10) NOT NULL DEFAULT 'increase'", 'baseline' => 'REAL', 'target_value' => 'REAL', 'current_value' => 'REAL',
            'start_on' => "VARCHAR(10) NOT NULL DEFAULT ''", 'due_on' => "VARCHAR(10) NOT NULL DEFAULT ''",
            'confidence' => 'INTEGER NOT NULL DEFAULT 0', 'checked_in_at' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'status' => "VARCHAR(12) NOT NULL DEFAULT 'active'", 'closed_note' => "VARCHAR(300) NOT NULL DEFAULT ''",
            /* Also CacGoals's, so a site that never linked CACENTRE can still ask "is this a mirror?". */
            'cac_goal_id' => 'INTEGER NOT NULL DEFAULT 0', 'cac_code' => "VARCHAR(32) NOT NULL DEFAULT ''",
        ] as $c => $decl) {
            try { if (!Database::columnExists('team_goals', $c)) $pdo->exec("ALTER TABLE team_goals ADD COLUMN $c $decl"); } catch (Throwable $e) {}
        }
        Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS goal_checkins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            goal_id INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL DEFAULT 0,
            value REAL,
            confidence INTEGER NOT NULL DEFAULT 0,
            note VARCHAR(500) NOT NULL DEFAULT '',
            blocker VARCHAR(300) NOT NULL DEFAULT '',
            at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { $pdo->exec('CREATE INDEX IF NOT EXISTS ix_goal_checkins_goal ON goal_checkins(goal_id, at)'); } catch (Throwable $e) {}
    }

    /** The goal row, raw. */
    public static function row(int $goalId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM team_goals WHERE id = ?');
        $st->execute([$goalId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        $v = is_string($v) ? str_replace([',', ' ', '₦'], '', trim($v)) : $v;
        return is_numeric($v) ? (float) $v : null;
    }

    public static function measured(array $g): bool { return self::num($g['target_value'] ?? null) !== null; }

    /**
     * How far the number has come, as a fraction, honouring baseline and
     * direction (CACENTRE's OsGoals::progressOf). Unclamped: 1.2 is twenty
     * percent more than was promised.
     */
    public static function progressOf(array $g, ?float $current): ?float
    {
        $target = self::num($g['target_value'] ?? null);
        if ($target === null) return null;
        $baseline = self::num($g['baseline'] ?? null) ?? 0.0;
        $current = $current ?? $baseline;
        if (($g['direction'] ?? 'increase') === 'maintain') {
            if ($target == 0.0) return $current == 0.0 ? 1.0 : 0.0;
            return round(max(0.0, 1 - abs($current - $target) / abs($target)), 4);
        }
        $span = $target - $baseline;
        if (abs($span) < 0.000001) return $current == $target ? 1.0 : 0.0;
        return round(($current - $baseline) / $span, 4);
    }

    /**
     * The steps under a goal, weighted by size:
     * ['total','done','open','points','points_done','blocked','stuck','fraction'|null].
     */
    public static function steps(int $goalId): array
    {
        $out = ['total' => 0, 'done' => 0, 'open' => 0, 'points' => 0, 'points_done' => 0, 'blocked' => 0, 'stuck' => 0, 'doing' => 0, 'fraction' => null];
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE goal_id = ?');
        try { $st->execute([$goalId]); } catch (Throwable $e) { return $out; }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $p = Collab::points((string) ($t['size'] ?? ''));
            $out['total']++; $out['points'] += $p;
            if ((int) $t['done'] === 1) { $out['done']++; $out['points_done'] += $p; continue; }
            $out['open']++;
            if (($t['state'] ?? '') === 'blocked') $out['blocked']++;
            if (($t['state'] ?? '') === 'doing') $out['doing']++;
            if (Collab::flagOf($t)['flag'] !== '') $out['stuck']++;
        }
        if ($out['points'] > 0) $out['fraction'] = round($out['points_done'] / $out['points'], 4);
        return $out;
    }

    /** The goal's progress: the number's when it is measured, the steps' otherwise. Null when there is neither. */
    public static function progress(array $g, ?array $steps = null): ?float
    {
        if (self::measured($g)) return self::progressOf($g, self::num($g['current_value'] ?? null));
        $steps = $steps ?? self::steps((int) $g['id']);
        return $steps['fraction'];
    }

    /** When the goal's clock started: its start date, else the day it was set. */
    private static function startOf(array $g): string
    {
        return (string) ($g['start_on'] ?? '') !== '' ? (string) $g['start_on'] : substr((string) ($g['created_at'] ?? ''), 0, 10);
    }

    /** Share of the goal's time gone, or null before it starts or with no due date. */
    public static function elapsed(array $g, ?string $on = null): ?float
    {
        $on = $on ?? gmdate('Y-m-d');
        $start = self::startOf($g);
        $due = (string) ($g['due_on'] ?? '');
        if ($due === '' || $start === '' || $on < $start) return null;
        if ($on >= $due) return 1.0;
        $total = strtotime($due) - strtotime($start);
        return $total <= 0 ? 1.0 : round((strtotime($on) - strtotime($start)) / $total, 4);
    }

    /**
     * on_track / at_risk / behind from progress against time, as CACENTRE
     * judges it; or completed, not_started, no_date, unmeasured, closed.
     */
    public static function health(array $g, ?float $progress, ?string $on = null): array
    {
        if ((int) ($g['closed'] ?? 0) === 1) return ['state' => 'closed', 'elapsed' => null, 'ratio' => null, 'expected' => null];
        if ($progress === null) return ['state' => 'unmeasured', 'elapsed' => null, 'ratio' => null, 'expected' => null];
        if ($progress >= 1.0) return ['state' => 'completed', 'elapsed' => self::elapsed($g, $on), 'ratio' => null, 'expected' => null];
        if ((string) ($g['due_on'] ?? '') === '') return ['state' => 'no_date', 'elapsed' => null, 'ratio' => null, 'expected' => null];
        $el = self::elapsed($g, $on);
        if ($el === null) return ['state' => 'not_started', 'elapsed' => null, 'ratio' => null, 'expected' => null];
        if ($el <= 0.0) return ['state' => 'on_track', 'elapsed' => $el, 'ratio' => null, 'expected' => 0.0];
        $ratio = round($progress / $el, 4);
        return ['state' => $ratio >= self::ON_TRACK ? 'on_track' : ($ratio >= self::AT_RISK ? 'at_risk' : 'behind'), 'elapsed' => $el, 'ratio' => $ratio, 'expected' => $el];
    }

    /**
     * When it will likely be done, from what actually got done:
     * ['by' => 'steps'|'number', 'date' => Y-m-d|null, 'rate' => per week,
     *  'late_days' => int|null, 'note' => words].
     */
    public static function forecast(array $g, array $steps, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $due = (string) ($g['due_on'] ?? '');
        $finish = static function (float $weeks) use ($now, $due): array {
            $date = gmdate('Y-m-d', (int) ($now + $weeks * 7 * 86400));
            $late = $due !== '' ? (int) round((strtotime($date) - strtotime($due)) / 86400) : null;
            return ['date' => $date, 'late_days' => $late];
        };
        if (self::measured($g)) {
            /* The number's trend: the oldest reading in the window to the newest. */
            $st = Database::pdo()->prepare('SELECT value, at FROM goal_checkins WHERE goal_id = ? AND value IS NOT NULL AND at >= ? ORDER BY at ASC');
            $st->execute([(int) $g['id'], gmdate('Y-m-d H:i:s', $now - 60 * 86400)]);
            $r = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($r) < 2) return null;
            $first = $r[0]; $last = $r[count($r) - 1];
            $days = max(1.0, (strtotime($last['at'] . ' UTC') - strtotime($first['at'] . ' UTC')) / 86400);
            $pFirst = self::progressOf($g, (float) $first['value']); $pLast = self::progressOf($g, (float) $last['value']);
            if ($pFirst === null || $pLast === null) return null;
            $perWeek = ($pLast - $pFirst) / $days * 7;
            if ($pLast >= 1.0) return null;
            if ($perWeek <= 0.0001) return ['by' => 'number', 'date' => null, 'rate' => round($perWeek, 4), 'late_days' => null, 'note' => 'The number has not moved toward the target in its recent readings.'];
            return ['by' => 'number', 'rate' => round($perWeek, 4), 'note' => ''] + $finish((1.0 - $pLast) / $perWeek);
        }
        if ($steps['points'] <= 0 || $steps['open'] === 0) return null;
        $st = Database::pdo()->prepare("SELECT size FROM collab_tasks WHERE goal_id = ? AND done = 1 AND done_at <> '' AND done_at >= ?");
        $st->execute([(int) $g['id'], gmdate('Y-m-d H:i:s', $now - self::RATE_WINDOW_DAYS * 86400)]);
        $pts = 0; foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) $pts += Collab::points((string) $s);
        $perWeek = $pts / (self::RATE_WINDOW_DAYS / 7);
        $left = $steps['points'] - $steps['points_done'];
        if ($perWeek <= 0) return ['by' => 'steps', 'date' => null, 'rate' => 0.0, 'late_days' => null, 'note' => 'No step has been finished in four weeks.'];
        return ['by' => 'steps', 'rate' => round($perWeek, 2), 'note' => ''] + $finish($left / $perWeek);
    }

    /** The last check-in, or null. */
    public static function lastCheckin(int $goalId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT c.*, u.name FROM goal_checkins c LEFT JOIN lms_users u ON u.id = c.user_id WHERE c.goal_id = ? ORDER BY c.at DESC, c.id DESC LIMIT 1');
        $st->execute([$goalId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function checkins(int $goalId, int $n = 8): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT c.*, u.name FROM goal_checkins c LEFT JOIN lms_users u ON u.id = c.user_id WHERE c.goal_id = ? ORDER BY c.at DESC, c.id DESC LIMIT ' . max(1, min(50, $n)));
        $st->execute([$goalId]);
        return array_map(static fn($c) => ['value' => $c['value'] === null ? null : (float) $c['value'], 'confidence' => (int) $c['confidence'], 'note' => (string) $c['note'],
            'blocker' => (string) $c['blocker'], 'at' => (string) $c['at'], 'by' => (string) ($c['name'] ?? '')], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Is a check-in owed? Open, not mirrored from CACENTRE, and a week since the last (or since it was set). */
    public static function checkinDue(array $g, ?int $now = null): bool
    {
        if ((int) ($g['closed'] ?? 0) === 1 || (int) ($g['cac_goal_id'] ?? 0) > 0) return false;
        $now = $now ?? time();
        $since = (string) (($g['checked_in_at'] ?? '') ?: ($g['created_at'] ?? ''));
        return $since === '' || strtotime($since . ' UTC') < $now - self::CHECKIN_DAYS * 86400;
    }

    /**
     * Things worth saying about a goal — none a reason to refuse anything,
     * each a real problem.
     */
    public static function warnings(array $g, array $steps, ?float $progress, array $health, ?array $forecast): array
    {
        $out = [];
        if ((int) ($g['closed'] ?? 0) === 1) return $out;
        $mirror = (int) ($g['cac_goal_id'] ?? 0) > 0;
        if (!self::measured($g)) $out[] = ['kind' => 'unmeasured', 'text' => 'No number to measure, so progress is only steps done — which is effort, not the result. Give it a unit and a target.'];
        if ((string) ($g['due_on'] ?? '') === '') $out[] = ['kind' => 'no_date', 'text' => 'No due date, so nobody can tell whether it is on track.'];
        if (self::measured($g) && $steps['fraction'] !== null && $steps['fraction'] >= 0.6 && ($progress ?? 0) < $steps['fraction'] * 0.5) {
            $out[] = ['kind' => 'diverging', 'text' => 'Most of its steps are done but the number has barely moved: these steps may not be the ones that move it. Rethink them at the next check-in.'];
        }
        if ($steps['blocked'] > 0) $out[] = ['kind' => 'blocked', 'text' => $steps['blocked'] . ' step' . ($steps['blocked'] === 1 ? ' is' : 's are') . ' blocked.'];
        if ($steps['stuck'] > $steps['blocked']) $out[] = ['kind' => 'stuck', 'text' => ($steps['stuck'] - $steps['blocked']) . ' step' . ($steps['stuck'] - $steps['blocked'] === 1 ? ' has' : 's have') . ' not moved in a long while.'];
        if ($forecast && ($forecast['late_days'] ?? 0) > 3) $out[] = ['kind' => 'late', 'text' => 'At the pace so far it finishes around ' . gmdate('j M', strtotime($forecast['date'])) . ', ' . $forecast['late_days'] . ' days after it is due.'];
        if ($forecast && $forecast['date'] === null && $forecast['note'] !== '' && !in_array($health['state'], ['not_started', 'completed'], true)) $out[] = ['kind' => 'stalled', 'text' => $forecast['note']];
        if (!$mirror && (int) ($g['confidence'] ?? 0) > 0 && (int) $g['confidence'] <= 4) $out[] = ['kind' => 'confidence', 'text' => 'Its owner gives it ' . (int) $g['confidence'] . ' in 10. Ask what would raise that.'];
        if (!$mirror && $steps['open'] === 0 && ($progress ?? 0) < 1.0) $out[] = ['kind' => 'no_steps', 'text' => 'Nothing is open toward it. The next steps are drawn at the next planning round, or add one.'];
        return $out;
    }

    /** Everything a person needs about one goal, for the goals screen and the pop-up. */
    public static function shape(array $g, int $viewerId = 0, ?int $now = null): array
    {
        $now = $now ?? time();
        $steps = self::steps((int) $g['id']);
        $progress = self::progress($g, $steps);
        $health = self::health($g, $progress, gmdate('Y-m-d', $now));
        $forecast = (int) ($g['closed'] ?? 0) === 1 ? null : self::forecast($g, $steps, $now);
        $last = self::lastCheckin((int) $g['id']);
        $mirror = (int) ($g['cac_goal_id'] ?? 0) > 0;
        return [
            'id' => (int) $g['id'], 'title' => (string) $g['title'], 'purpose' => (string) ($g['purpose'] ?? ''), 'target' => (string) ($g['target'] ?? ''),
            'measured' => self::measured($g), 'unit' => (string) ($g['unit'] ?? ''), 'direction' => (string) (($g['direction'] ?? '') ?: 'increase'),
            'baseline' => self::num($g['baseline'] ?? null), 'target_value' => self::num($g['target_value'] ?? null), 'current_value' => self::num($g['current_value'] ?? null),
            'start_on' => (string) ($g['start_on'] ?? ''), 'due_on' => (string) ($g['due_on'] ?? ''),
            'progress' => $progress === null ? null : round($progress, 4),
            'pct' => $progress === null ? null : (int) round(100 * max(0.0, $progress)),
            'steps' => $steps, 'health' => $health, 'forecast' => $forecast,
            'confidence' => (int) ($g['confidence'] ?? 0), 'checked_in_at' => (string) ($g['checked_in_at'] ?? ''),
            'last_checkin' => $last ? ['note' => (string) $last['note'], 'blocker' => (string) $last['blocker'], 'at' => (string) $last['at'], 'by' => (string) ($last['name'] ?? '')] : null,
            'checkin_due' => self::checkinDue($g, $now),
            'warnings' => self::warnings($g, $steps, $progress, $health, $forecast),
            'closed' => (int) ($g['closed'] ?? 0) === 1, 'status' => (string) (($g['status'] ?? '') ?: 'active'), 'closed_note' => (string) ($g['closed_note'] ?? ''),
            'mirror' => $mirror, 'code' => (string) ($g['cac_code'] ?? ''),
            'author_id' => (int) $g['author_id'], 'mine' => (int) $g['author_id'] === $viewerId,
        ];
    }

    /**
     * Record a check-in: the number now (for a measured goal), confidence
     * 1–10, what changed, what is in the way. The owner's to make; a goal
     * mirrored from CACENTRE is checked in there.
     */
    public static function checkin(int $uid, int $goalId, array $in, ?int $now = null): array
    {
        self::ensure();
        $g = self::row($goalId);
        if (!$g || (int) $g['closed'] === 1) return ['ok' => false, 'error' => 'No such open goal.'];
        if ((int) ($g['cac_goal_id'] ?? 0) > 0) return ['ok' => false, 'error' => 'This goal is measured at CACENTRE — check in there, and its number comes here.'];
        if ((int) $g['author_id'] !== $uid) return ['ok' => false, 'error' => 'Only the goal’s owner checks in on it.'];
        $value = self::num($in['value'] ?? null);
        if (self::measured($g) && $value === null && trim((string) ($in['value'] ?? '')) !== '') return ['ok' => false, 'error' => 'The number is a number.'];
        $conf = (int) ($in['confidence'] ?? 0);
        if ($conf < 1 || $conf > 10) return ['ok' => false, 'error' => 'How confident are you of hitting it, 1 to 10?'];
        $note = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) ($in['note'] ?? '')) ?? '', 0, 500));
        $blocker = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) ($in['blocker'] ?? '')) ?? '', 0, 300));
        $at = gmdate('Y-m-d H:i:s', $now ?? time());
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO goal_checkins (goal_id, user_id, value, confidence, note, blocker, at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$goalId, $uid, $value, $conf, $note, $blocker, $at]);
        $pdo->prepare('UPDATE team_goals SET current_value = COALESCE(?, current_value), confidence = ?, checked_in_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$value, $conf, $at, $at, $goalId]);
        self::refresh($goalId);
        if (class_exists('Collab')) Collab::log($uid, self::nameOf($uid), 'checked in on a goal', (string) $g['title'], '/portal/#tools');
        return ['ok' => true, 'goal' => self::shape(self::row($goalId), $uid, $now)];
    }

    /** Recompute the cached progress (team_goals.progress, a whole percent) from the number or the steps. */
    public static function refresh(int $goalId): void
    {
        if ($goalId <= 0) return;
        try {
            $g = self::row($goalId);
            if (!$g) return;
            /* A mirror's number is CACENTRE's (lib/CacGoals.php writes it). */
            $p = self::progress($g);
            if ($p === null) return;
            Database::pdo()->prepare('UPDATE team_goals SET progress = ?, updated_at = ? WHERE id = ?')
                ->execute([(int) round(100 * max(0.0, min(9.99, $p))), gmdate('Y-m-d H:i:s'), $goalId]);
        } catch (Throwable $e) { error_log('[goal-track] refresh: ' . $e->getMessage()); }
    }

    /**
     * What the planner should know before drawing the next steps: the number,
     * the pace, the owner's last word, and whether the steps so far moved it.
     */
    public static function planningContext(int $goalId): string
    {
        try {
            $g = self::row($goalId);
            if (!$g) return '';
            $s = self::shape($g);
            $bits = [];
            if ($s['purpose'] !== '') $bits[] = 'WHY: ' . $s['purpose'];
            if ($s['measured']) $bits[] = 'MEASURE: ' . self::fmt($s['current_value'] ?? $s['baseline'] ?? 0) . ' ' . $s['unit'] . ' now, from ' . self::fmt($s['baseline'] ?? 0) . ', target ' . self::fmt($s['target_value']) . ' (' . $s['direction'] . ')';
            if ($s['due_on'] !== '') $bits[] = 'DUE: ' . $s['due_on'] . ' — ' . str_replace('_', ' ', $s['health']['state']);
            if ($s['steps']['total'] > 0) $bits[] = 'STEPS SO FAR: ' . $s['steps']['done'] . ' of ' . $s['steps']['total'] . ' done';
            if ($s['last_checkin'] && $s['last_checkin']['blocker'] !== '') $bits[] = 'IN THE WAY (owner, last check-in): ' . $s['last_checkin']['blocker'];
            if ($s['last_checkin'] && $s['last_checkin']['note'] !== '') $bits[] = 'LATEST: ' . $s['last_checkin']['note'];
            foreach ($s['warnings'] as $w) if ($w['kind'] === 'diverging') $bits[] = 'NOTE: the steps done so far have not moved the number — plan different steps that act on it directly.';
            return $bits ? "\n\n" . implode("\n", $bits) : '';
        } catch (Throwable $e) { return ''; }
    }

    /** Open goals this person owns that are owed a check-in. */
    public static function dueFor(int $uid, ?int $now = null): array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM team_goals WHERE author_id = ? AND closed = 0 AND cac_goal_id = 0 ORDER BY id');
        try { $st->execute([$uid]); } catch (Throwable $e) { return []; }
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), static fn($g) => self::checkinDue($g, $now)));
    }

    public static function fmt(?float $v): string
    {
        if ($v === null) return '—';
        if (floor($v) == $v) return number_format($v, 0);
        return rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    private static function nameOf(int $uid): string
    {
        $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
        $st->execute([$uid]);
        return (string) ($st->fetchColumn() ?: 'A member');
    }
}
