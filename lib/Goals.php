<?php
/**
 * lib/Goals.php — team goals: what we mean to achieve, owned by one person.
 *
 * The row and its edits. How a goal is measured, judged and forecast — and
 * how its steps move it — is lib/GoalTrack.php. Progress is not typed: it is
 * the goal's number, checked in by its owner, or its steps done.
 */
declare(strict_types=1);

final class Goals
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            author_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL DEFAULT '',
            target VARCHAR(200) NOT NULL DEFAULT '',
            progress INTEGER NOT NULL DEFAULT 0,
            closed INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        Database::execSchema($db, $ddl);
        $done = true;
    }

    /**
     * Create a goal. Returns new id or 0. $more: purpose, unit, direction,
     * baseline, target_value, start_on, due_on (see measure()).
     */
    public static function create(int $authorId, string $title, string $target = '', array $more = []): int
    {
        self::ensure();
        $title  = trim(mb_substr(trim($title), 0, 300));
        $target = trim(mb_substr(trim($target), 0, 200));
        if ($authorId <= 0 || $title === '') return 0;
        $m = self::measure($more);
        if (isset($m['error'])) return 0;
        $now = gmdate('Y-m-d H:i:s');
        Database::pdo()->prepare('INSERT INTO team_goals (author_id, title, target, progress, closed, created_at, updated_at) VALUES (?,?,?,0,0,?,?)')
            ->execute([$authorId, $title, $target, $now, $now]);
        $id = (int) Database::pdo()->lastInsertId();
        if ($m) self::setMeasure($id, $m);
        return $id;
    }

    /**
     * The measurable half of a goal, cleaned: purpose, unit, direction,
     * baseline, target_value, start_on, due_on — only the keys given. Returns
     * ['error' => …] for a mistake worth refusing (a target that is not a
     * number, a due date before the start).
     */
    public static function measure(array $in): array
    {
        if (class_exists('GoalTrack')) GoalTrack::ensure();
        $c = [];
        $num = static function ($v) { $v = is_string($v) ? str_replace([',', ' ', '₦'], '', trim($v)) : $v; return ($v === null || $v === '') ? null : (is_numeric($v) ? (float) $v : false); };
        if (array_key_exists('purpose', $in)) $c['purpose'] = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $in['purpose']) ?? '', 0, 500));
        if (array_key_exists('unit', $in)) $c['unit'] = trim(mb_substr((string) $in['unit'], 0, 24));
        if (array_key_exists('direction', $in)) $c['direction'] = in_array($in['direction'], ['increase', 'decrease', 'maintain'], true) ? $in['direction'] : 'increase';
        foreach (['baseline', 'target_value'] as $k) if (array_key_exists($k, $in)) {
            $v = $num($in[$k]);
            if ($v === false) return ['error' => ($k === 'baseline' ? 'Where it starts' : 'The target') . ' is a number.'];
            $c[$k] = $v;
        }
        foreach (['start_on', 'due_on'] as $k) if (array_key_exists($k, $in)) {
            $d = trim((string) $in[$k]);
            if ($d !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)))) return ['error' => 'Dates are like 2026-12-31.'];
            $c[$k] = $d;
        }
        if (($c['start_on'] ?? '') !== '' && ($c['due_on'] ?? '') !== '' && $c['due_on'] < $c['start_on']) return ['error' => 'It cannot be due before it starts.'];
        return $c;
    }

    private static function setMeasure(int $goalId, array $m): void
    {
        if (!$m) return;
        $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($m)));
        Database::pdo()->prepare("UPDATE team_goals SET $sets, updated_at = ? WHERE id = ?")->execute([...array_values($m), gmdate('Y-m-d H:i:s'), $goalId]);
        if (class_exists('GoalTrack')) GoalTrack::refresh($goalId);
    }

    /** Edit a goal's title/target and its measure (author only). */
    public static function edit(int $uid, int $goalId, string $title, string $target = '', array $more = []): bool|string
    {
        self::ensure();
        $title  = trim(mb_substr(trim($title), 0, 300));
        $target = trim(mb_substr(trim($target), 0, 200));
        if ($uid <= 0 || $goalId <= 0 || $title === '') return false;
        $m = self::measure($more);
        if (isset($m['error'])) return (string) $m['error'];
        $st = Database::pdo()->prepare('UPDATE team_goals SET title = ?, target = ?, updated_at = ? WHERE id = ? AND author_id = ? AND cac_goal_id = 0');
        $st->execute([$title, $target, gmdate('Y-m-d H:i:s'), $goalId, $uid]);
        if ($st->rowCount() === 0) return false;
        self::setMeasure($goalId, $m);
        return true;
    }

    /**
     * Progress used to be typed (±10). It is measured now (GoalTrack): a goal
     * with a number is checked in, one without follows its steps. Kept for
     * old callers; it recomputes and says whether the goal is the caller's.
     */
    public static function setProgress(int $uid, int $goalId, int $pct): bool
    {
        $g = self::get($goalId);
        if (!$g || $g['author_id'] !== $uid) return false;
        if (class_exists('GoalTrack')) GoalTrack::refresh($goalId);
        return true;
    }

    /**
     * Close a goal (author only): achieved, or dropped — and a line on how it
     * went, which is the part anybody reads a year later. Its open steps go
     * with it, so nobody keeps working toward a goal that is over.
     */
    public static function close(int $uid, int $goalId, string $status = 'achieved', string $note = ''): bool
    {
        self::ensure();
        if (class_exists('GoalTrack')) GoalTrack::ensure();
        $status = in_array($status, ['achieved', 'abandoned'], true) ? $status : 'achieved';
        $st = Database::pdo()->prepare('UPDATE team_goals SET closed = 1, status = ?, closed_note = ?, updated_at = ? WHERE id = ? AND author_id = ? AND cac_goal_id = 0');
        $st->execute([$status, trim(mb_substr($note, 0, 300)), gmdate('Y-m-d H:i:s'), $goalId, $uid]);
        if ($st->rowCount() === 0) return false;
        try { Database::pdo()->prepare('DELETE FROM collab_tasks WHERE goal_id = ? AND done = 0 AND assignee_id = 0')->execute([$goalId]); } catch (Throwable $e) {}
        return true;
    }

    /** Delete a goal (author only). */
    public static function remove(int $uid, int $goalId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('DELETE FROM team_goals WHERE id = ? AND author_id = ?');
        $st->execute([$goalId, $uid]);
        return $st->rowCount() > 0;
    }

    /** A single goal by id (or null). */
    public static function get(int $goalId): ?array
    {
        self::ensure();
        if ($goalId <= 0) return null;
        try {
            $st = Database::pdo()->prepare('SELECT * FROM team_goals WHERE id = ?');
            $st->execute([$goalId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        if (!$r) return null;
        return [
            'id'       => (int) $r['id'],
            'title'    => (string) $r['title'],
            'target'   => (string) $r['target'],
            'progress' => (int) $r['progress'],
            'closed'   => (int) $r['closed'] === 1,
            'author_id'=> (int) $r['author_id'],
        ];
    }

    /** Recent goals, open first, each as GoalTrack shapes it (with its author's name). */
    public static function listGoals(int $viewerId, int $limit = 40): array
    {
        self::ensure();
        if (class_exists('GoalTrack')) GoalTrack::ensure();
        $limit = max(1, min(100, $limit));
        try {
            $rows = Database::pdo()->query(
                'SELECT g.*, u.name AS author FROM team_goals g LEFT JOIN lms_users u ON u.id = g.author_id
                 ORDER BY g.closed ASC, g.updated_at DESC, g.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $base = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'target' => (string) $r['target'], 'progress' => (int) $r['progress'],
                     'closed' => (int) $r['closed'] === 1, 'mine' => (int) $r['author_id'] === $viewerId];
            $shape = class_exists('GoalTrack') ? GoalTrack::shape($r, $viewerId) : [];
            /* `progress` stays the whole percent older readers expect; the fraction is `pct`/`progress_f`. */
            if ($shape) { $shape['progress_f'] = $shape['progress']; unset($shape['progress']); }
            $out[] = $shape + $base + ['author' => (string) ($r['author'] ?: 'A member')];
        }
        return $out;
    }
}
