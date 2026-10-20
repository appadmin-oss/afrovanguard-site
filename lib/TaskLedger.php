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
    /** Why somebody hands a task back. */
    public const REASONS = [
        'busy'     => 'No time right now',
        'not_mine' => 'Not my kind of work',
        'unclear'  => 'Not clear what is wanted',
        'other'    => 'Something else',
    ];

    /** How far back history is read. */
    private const DAYS = 180;

    public static function ensure(): void
    {
        static $done = false; if ($done) return; $done = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS task_assign_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL DEFAULT 0,
            event VARCHAR(16) NOT NULL DEFAULT '',
            reason VARCHAR(16) NOT NULL DEFAULT '',
            via VARCHAR(12) NOT NULL DEFAULT '',
            title VARCHAR(300) NOT NULL DEFAULT '',
            at VARCHAR(32) NOT NULL DEFAULT ''
        )");
        try { Database::pdo()->exec('CREATE INDEX IF NOT EXISTS ix_task_assign_log_user ON task_assign_log(user_id, event, at)'); } catch (Throwable $e) {}
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
        foreach ($ids as $i) $out[$i] = ['done' => [], 'handed_back' => [], 'on_time' => 0, 'late' => 0, 'given_week' => 0];
        if (!$ids) return $out;
        $now = $now ?? time();
        $in = implode(',', $ids);
        $since = gmdate('Y-m-d H:i:s', $now - self::DAYS * 86400);
        $pdo = Database::pdo();
        try {
            $st = $pdo->prepare("SELECT assignee_id, title, due, done_at FROM collab_tasks WHERE done = 1 AND assignee_id IN ($in) AND done_at >= ? ORDER BY done_at DESC");
            $st->execute([$since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $o = &$out[(int) $r['assignee_id']];
                if (count($o['done']) < 30) $o['done'][] = (string) $r['title'];
                $due = (string) $r['due'];
                if ($due !== '' && (string) $r['done_at'] !== '') { if (substr((string) $r['done_at'], 0, 10) <= $due) $o['on_time']++; else $o['late']++; }
                unset($o);
            }
        } catch (Throwable $e) {}
        try {
            self::ensure();
            $st = $pdo->prepare("SELECT user_id, event, reason, title, at FROM task_assign_log WHERE user_id IN ($in) AND at >= ? AND event IN ('handed_back', 'assigned') ORDER BY at DESC");
            $st->execute([$since]);
            $week = gmdate('Y-m-d H:i:s', $now - 7 * 86400);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $o = &$out[(int) $r['user_id']];
                if ($r['event'] === 'handed_back' && count($o['handed_back']) < 20) $o['handed_back'][] = ['title' => (string) $r['title'], 'reason' => (string) $r['reason'], 'at' => (string) $r['at']];
                if ($r['event'] === 'assigned' && (string) $r['at'] >= $week) $o['given_week']++;
                unset($o);
            }
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
}
