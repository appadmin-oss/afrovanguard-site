<?php
/**
 * lib/TaskLedger.php — what happened to the work people were given, so the
 * next task goes to somebody who will do it.
 *
 * TaskMatch read only what people are labelled. Labels say what somebody
 * could do; this says what they did:
 *
 *   assigned     a task was given to them — by a person, the AI, or the sweep
 *   handed_back  they returned it to the pool, and why: no time right now,
 *                not their kind of work, or not clear what was wanted
 *   done         they finished it (collab_tasks keeps when, and the due date)
 *
 * From that, and from the tasks they have finished (collab_tasks already
 * holds those, so the record starts full), history() gives each person:
 * what they finished lately, what they handed back and why, whether they
 * finish on time, and how much they were given this week. "No time" is
 * not "not mine": the first says nothing about fit, the second does.
 *
 * And whether they are taking work at all: a person can say they are away
 * until a date (user_prefs 'away_until'), and nothing is given to them
 * until then.
 */
declare(strict_types=1);

final class TaskLedger
{
    /**
     * Why somebody hands a task back — or says they can't take it on when it
     * is given to them (Collab::declineTask). ONE list for both doors: a
     * decline and a hand-back are the same answer at different moments, and
     * the matcher reads them the same way. "Blocked" and "deprioritised" were
     * added with the accept step: both say nothing about fit, only about now.
     */
    public const REASONS = [
        'busy'          => 'No time right now',
        'unclear'       => 'Not clear what is wanted',
        'blocked'       => 'Blocked by something else',
        'not_mine'      => 'Not my kind of work',
        'deprioritised' => 'Something more important came first',
        'other'         => 'Something else',
    ];

    /** How far back history is read. */
    private const DAYS = 180;

    /** $db: another connection to provision (tests/mysql.test.php); the app's own by default. */
    public static function ensure(?PDO $db = null): void
    {
        static $done = false;
        if ($db === null) { if ($done) return; $done = true; }
        $db = $db ?? Database::pdo();
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS task_assign_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL DEFAULT 0,
            event VARCHAR(16) NOT NULL DEFAULT '',
            reason VARCHAR(16) NOT NULL DEFAULT '',
            via VARCHAR(12) NOT NULL DEFAULT '',
            title VARCHAR(300) NOT NULL DEFAULT '',
            at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        /* Through execSchema, which drops the IF NOT EXISTS MySQL does not
           have and treats "already there" as done. A bare exec was a syntax
           error on MySQL, swallowed here, so the index history() reads by
           was never created there. */
        Database::execSchema($db, 'CREATE INDEX IF NOT EXISTS ix_task_assign_log_user ON task_assign_log(user_id, event, at)');
        /* Every move of a task's date, and why. The agreed date stays on the
           task (collab_tasks.agreed_due); this is the record of how far, and
           by whom, it was moved from there — what a giver reads when a task
           they were promised for Tuesday is now due in a fortnight. */
        Database::execSchema($db, "CREATE TABLE IF NOT EXISTS task_due_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL DEFAULT 0,
            by_id INTEGER NOT NULL DEFAULT 0,
            from_due VARCHAR(10) NOT NULL DEFAULT '',
            to_due VARCHAR(10) NOT NULL DEFAULT '',
            why VARCHAR(300) NOT NULL DEFAULT '',
            at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        Database::execSchema($db, 'CREATE INDEX IF NOT EXISTS ix_task_due_changes_task ON task_due_changes(task_id)');
    }

    /** Keep one move of a task's date. Never fatal, as record() is not. */
    public static function logDueChange(int $taskId, int $byId, string $from, string $to, string $why): void
    {
        if ($taskId <= 0) return;
        try {
            self::ensure();
            Database::pdo()->prepare('INSERT INTO task_due_changes (task_id, by_id, from_due, to_due, why, at) VALUES (?,?,?,?,?,?)')
                ->execute([$taskId, $byId, mb_substr($from, 0, 10), mb_substr($to, 0, 10), mb_substr($why, 0, 300), gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('[task-ledger] due: ' . $e->getMessage()); }
    }

    /** A task's date moves, oldest first: [['by_id','from','to','why','at']…]. */
    public static function dueChanges(int $taskId): array
    {
        try {
            self::ensure();
            $st = Database::pdo()->prepare('SELECT by_id, from_due, to_due, why, at FROM task_due_changes WHERE task_id = ? ORDER BY id ASC');
            $st->execute([$taskId]);
            return array_map(static fn($r) => ['by_id' => (int) $r['by_id'], 'from' => (string) $r['from_due'], 'to' => (string) $r['to_due'], 'why' => (string) $r['why'], 'at' => (string) $r['at']], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) { return []; }
    }

    /**
     * The date a task is measured against: the one its giver and its owner
     * agreed, not the one it was later moved to. A task moved three times and
     * finished on the last date is late — that is the point of keeping the
     * first. Rows from before the agreement was kept fall back to their date.
     */
    public static function agreedDue(array $r): string
    {
        return (string) (($r['agreed_due'] ?? '') !== '' ? $r['agreed_due'] : (($r['first_due'] ?? '') !== '' ? $r['first_due'] : ($r['due'] ?? '')));
    }

    /** Keep one event. Never fatal: losing a line of history is not worth a failed click. */
    public static function record(int $taskId, int $userId, string $event, string $title, string $reason = '', string $via = '', ?int $now = null): void
    {
        if ($taskId <= 0 || $userId <= 0) return;
        try {
            self::ensure();
            Database::pdo()->prepare('INSERT INTO task_assign_log (task_id, user_id, event, reason, via, title, at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$taskId, $userId, mb_substr($event, 0, 16), isset(self::REASONS[$reason]) ? $reason : '', mb_substr($via, 0, 12),
                           mb_substr($title, 0, 300), gmdate('Y-m-d H:i:s', $now ?? time())]);
        } catch (Throwable $e) { error_log('[task-ledger] ' . $e->getMessage()); }
    }

    /**
     * What each person did with work, for the people asked about:
     * id => ['done' => [title…] newest first, 'handed_back' => [['title','reason','at']…],
     *        'on_time' => n, 'late' => n, 'given_week' => n].
     */
    public static function history(array $ids, ?int $now = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        $out = [];
        foreach ($ids as $i) $out[$i] = ['done' => [], 'handed_back' => [], 'on_time' => 0, 'late' => 0, 'given_week' => 0, 'accept_hours' => null];
        if (!$ids) return $out;
        $now = $now ?? time();
        $in = implode(',', $ids);
        $since = gmdate('Y-m-d H:i:s', $now - self::DAYS * 86400);
        $pdo = Database::pdo();
        try {
            $st = $pdo->prepare("SELECT * FROM collab_tasks WHERE done = 1 AND assignee_id IN ($in) AND done_at >= ? ORDER BY done_at DESC");
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $o = &$out[(int) $r['assignee_id']];
                if (count($o['done']) < 30) $o['done'][] = (string) $r['title'];
                /* Against the AGREED date: moving a date (with a reason, and
                   the giver told) is allowed, but it does not make late work
                   on time — otherwise the matcher learns that moving the date
                   is as good as keeping it. */
                $due = self::agreedDue($r);
                /* done_at is UTC; the due date is a Lagos day. Finished at 23:30 UTC on
                   the due date is past midnight in Lagos — late, as the report says. */
                if ($due !== '' && (string) $r['done_at'] !== '') {
                    $doneDay = TaskReport::today((int) (strtotime((string) $r['done_at'] . ' UTC') ?: 0));
                    if ($doneDay <= $due) $o['on_time']++; else $o['late']++;
                }
                unset($o);
            }
        } catch (Throwable $e) {}
        try {
            self::ensure();
            /* A decline is a hand-back before the work began: the same reasons, read the same way. */
            $st = $pdo->prepare("SELECT user_id, event, reason, title, at FROM task_assign_log WHERE user_id IN ($in) AND at >= ? AND event IN ('handed_back', 'declined', 'assigned') ORDER BY at DESC");
            $st->execute([$since]);
            $week = gmdate('Y-m-d H:i:s', $now - 7 * 86400);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $o = &$out[(int) $r['user_id']];
                if (($r['event'] === 'handed_back' || $r['event'] === 'declined') && count($o['handed_back']) < 20) $o['handed_back'][] = ['title' => (string) $r['title'], 'reason' => (string) $r['reason'], 'at' => (string) $r['at']];
                if ($r['event'] === 'assigned' && (string) $r['at'] >= $week) $o['given_week']++;
                unset($o);
            }
        } catch (Throwable $e) {}
        /* How long work given to them waits before they say yes: the mean
           hours from given to accepted. Slow to accept is a signal of load
           before it is one of lateness. */
        try {
            $st = $pdo->prepare("SELECT assignee_id, assigned_by, assigned_at, accepted_at FROM collab_tasks WHERE assignee_id IN ($in) AND accepted_at <> '' AND assigned_at <> '' AND accepted_at >= ?");
            $st->execute([$since]);
            $sum = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hrs = ((strtotime((string) $r['accepted_at'] . ' UTC') ?: 0) - (strtotime((string) $r['assigned_at'] . ' UTC') ?: 0)) / 3600;
                /* Taken by themselves (claimed, or their own) is accepted at once and says nothing. */
                if ($hrs < 0 || (int) $r['assigned_by'] === (int) $r['assignee_id']) continue;
                $sum[(int) $r['assignee_id']][] = $hrs;
            }
            foreach ($sum as $i => $l) $out[$i]['accept_hours'] = round(array_sum($l) / count($l), 1);
        } catch (Throwable $e) {}
        return $out;
    }

    /* ── Away ──────────────────────────────────────────────────────────── */

    /** Not taking new tasks until this date (inclusive), or '' to be back. */
    public static function setAway(int $uid, string $until): array
    {
        $until = trim($until);
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || !checkdate((int) substr($until, 5, 2), (int) substr($until, 8, 2), (int) substr($until, 0, 4)))) {
            return ['ok' => false, 'error' => 'Pick a date.'];
        }
        if ($until !== '' && $until < gmdate('Y-m-d')) return ['ok' => false, 'error' => 'That date has passed.'];
        if ($until !== '' && $until > gmdate('Y-m-d', time() + 120 * 86400)) return ['ok' => false, 'error' => 'At most four months ahead.'];
        try { Prefs::set($uid, 'away_until', $until); }
        catch (Throwable $e) { error_log('[task-ledger] away: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not save that.']; }
        return ['ok' => true, 'away_until' => $until];
    }

    /** id => 'YYYY-MM-DD' for everyone away today or later. */
    public static function away(array $ids, ?int $now = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if (!$ids) return [];
        $today = gmdate('Y-m-d', $now ?? time());
        $out = [];
        try {
            Prefs::ensure();
            foreach (Database::pdo()->query("SELECT user_id, pref_value FROM user_prefs WHERE pref_key = 'away_until' AND user_id IN (" . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_NUM) as [$id, $v]) {
                if ((string) $v >= $today) $out[(int) $id] = (string) $v;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    public static function awayUntil(int $uid): string { return self::away([$uid])[$uid] ?? ''; }

    /* ── The escalation ladder ─────────────────────────────────────────── */

    /**
     * Which step of the ladder a piece of work is on, today. ONE rule for
     * tasks and meeting commitments (Collab, Commitments), as Goalspace runs
     * one engine for every task:
     *
     *   'due'       the due day          remind the owner
     *   'day1'      the day after        remind the owner again
     *   'escalate'  day 3 overdue        tell whoever gave it (a meeting's chair),
     *                                    who can reassign it, set a new date or drop it
     *   'weekly'    later, on a Monday   one roll-up a week, not a daily nag
     *   ''          any other day        nothing
     *
     * It replaces a notice that went to the owner every day for as long as a
     * task stayed overdue: after a week of those people stop reading
     * notifications, and the one person who could unstick the work — the
     * giver — was never told at all.
     *
     * A step fires only ON its day: a missed cron day skips it rather than
     * sending yesterday's reminder late. Dates are Lagos days (TaskReport::today).
     */
    public static function ladder(string $due, string $today): string
    {
        $due = substr(trim($due), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) return '';
        $days = (int) round((strtotime($today . ' 12:00 UTC') - strtotime($due . ' 12:00 UTC')) / 86400);
        if ($days === 0) return 'due';
        if ($days === 1) return 'day1';
        if ($days === 3) return 'escalate';
        if ($days > 3 && date('N', strtotime($today . ' 12:00 UTC')) === '1') return 'weekly';
        return '';
    }

    /**
     * Run the ladder over everything open with a date: tasks held by
     * somebody, and owned meeting commitments. Idempotent per step per day —
     * every notice carries a dedupe key naming the step and the date it is
     * about, so the five-minute web cron sends each once; a new date starts
     * the ladder again. Returns counts.
     */
    public static function chaseAll(?int $now = null): array
    {
        $now = $now ?? time();
        $today = TaskReport::today($now);
        $made = ['due' => 0, 'day1' => 0, 'escalate' => 0, 'weekly' => 0, 'tasks' => 0, 'commitments' => 0];
        if (!class_exists('Notifications')) return $made;
        $items = [];
        try { foreach (Collab::ladderItems() as $it) $items[] = $it; } catch (Throwable $e) { error_log('[ladder] tasks: ' . $e->getMessage()); }
        try { if (class_exists('Commitments')) foreach (Commitments::ladderItems() as $it) $items[] = $it; } catch (Throwable $e) { error_log('[ladder] commitments: ' . $e->getMessage()); }
        $week = [];
        foreach ($items as $it) {
            $step = self::ladder((string) $it['due'], $today);
            if ($step === '') continue;
            $key = 'ladder:' . $it['kind'] . ':' . (int) $it['id'] . ':' . $it['due'] . ':' . $step;
            $title = (string) $it['title'];
            $n = 0;
            try {
                if ($step === 'due') {
                    $n = Notifications::push((int) $it['owner'], $it['kind'], 'Due today: ' . $title, 'Due today. Finish it, or move the date and say why.', (string) $it['url'], $key);
                    if ($n) Notifications::email((int) $it['owner'], 'Due today: ' . $title, 'This is due today. If it will not be done, move the date and say why — whoever gave it to you is told.', (string) $it['url']);
                } elseif ($step === 'day1') {
                    $n = Notifications::push((int) $it['owner'], $it['kind'], 'Overdue: ' . $title, 'This was due yesterday. Finish it, or set a new date and say why.', (string) $it['url'], $key);
                    if ($n) Notifications::email((int) $it['owner'], 'Overdue: ' . $title, 'This was due yesterday. Finish it, or set a new date and say why.', (string) $it['url']);
                } elseif ($step === 'escalate') {
                    $g = (int) $it['giver'];
                    if ($g > 0 && $g !== (int) $it['owner']) {
                        $line = $it['owner_name'] . ' has not finished it. Reassign it, set a new date, or drop it.';
                        $n = Notifications::push($g, 'escalation', '3 days late: ' . $title, $line, (string) $it['giver_url'], $key);
                        if ($n) Notifications::email($g, '3 days late: ' . $title, $line, (string) $it['giver_url']);
                    }
                } else {
                    /* Collected, and sent as ONE line per person below. */
                    foreach (array_unique(array_filter([(int) $it['owner'], (int) $it['giver']])) as $to) $week[$to][] = $title;
                }
            } catch (Throwable $e) { error_log('[ladder] ' . $e->getMessage()); }
            if ($n) { $made[$step]++; $made[$it['kind'] === 'task' ? 'tasks' : 'commitments']++; }
        }
        $wk = (new DateTimeImmutable($today . ' 12:00', new DateTimeZone('UTC')))->format('o-\WW');
        foreach ($week as $to => $titles) {
            $titles = array_values(array_unique($titles));
            $body = implode(' · ', array_slice($titles, 0, 6)) . (count($titles) > 6 ? ' · and ' . (count($titles) - 6) . ' more' : '');
            try {
                if (Notifications::push((int) $to, 'escalation', 'Still overdue: ' . count($titles) . ' item' . (count($titles) === 1 ? '' : 's'), $body, '/portal/#tasks', 'ladder-week:' . $wk)) $made['weekly']++;
            } catch (Throwable $e) { error_log('[ladder] weekly: ' . $e->getMessage()); }
        }
        return $made;
    }
}
