<?php
/**
 * lib/Goals.php — team goals / OKRs with progress tracking.
 *
 * Members set an objective (optional target metric) and nudge progress 0–100%.
 * Everyone sees the shared list with progress bars. Author can close/remove.
 * Portable DB layer.
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
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Create a goal. Returns new id or 0. */
    public static function create(int $authorId, string $title, string $target = ''): int
    {
        self::ensure();
        $title  = trim(mb_substr(trim($title), 0, 300));
        $target = trim(mb_substr(trim($target), 0, 200));
        if ($authorId <= 0 || $title === '') return 0;
        $now = gmdate('Y-m-d H:i:s');
        Database::pdo()->prepare('INSERT INTO team_goals (author_id, title, target, progress, closed, created_at, updated_at) VALUES (?,?,?,0,0,?,?)')
            ->execute([$authorId, $title, $target, $now, $now]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Edit a goal's title/target (author only). */
    public static function edit(int $uid, int $goalId, string $title, string $target = ''): bool
    {
        self::ensure();
        $title  = trim(mb_substr(trim($title), 0, 300));
        $target = trim(mb_substr(trim($target), 0, 200));
        if ($uid <= 0 || $goalId <= 0 || $title === '') return false;
        $st = Database::pdo()->prepare('UPDATE team_goals SET title = ?, target = ?, updated_at = ? WHERE id = ? AND author_id = ?');
        $st->execute([$title, $target, gmdate('Y-m-d H:i:s'), $goalId, $uid]);
        return $st->rowCount() > 0;
    }

    /** Set progress 0–100. Auto-closes at 100, reopens below. Author only. */
    public static function setProgress(int $uid, int $goalId, int $pct): bool
    {
        self::ensure();
        $pct = max(0, min(100, $pct));
        $closed = $pct >= 100 ? 1 : 0;
        $st = Database::pdo()->prepare('UPDATE team_goals SET progress = ?, closed = ?, updated_at = ? WHERE id = ? AND author_id = ?');
        $st->execute([$pct, $closed, gmdate('Y-m-d H:i:s'), $goalId, $uid]);
        return $st->rowCount() > 0;
    }

    /** Close a goal (author only). */
    public static function close(int $uid, int $goalId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('UPDATE team_goals SET closed = 1, updated_at = ? WHERE id = ? AND author_id = ?');
        $st->execute([gmdate('Y-m-d H:i:s'), $goalId, $uid]);
        return $st->rowCount() > 0;
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

    /** Recent goals, open first. */
    public static function listGoals(int $viewerId, int $limit = 40): array
    {
        self::ensure();
        $limit = max(1, min(100, $limit));
        try {
            $rows = Database::pdo()->query(
                'SELECT g.*, u.name AS author FROM team_goals g LEFT JOIN lms_users u ON u.id = g.author_id
                 ORDER BY g.closed ASC, g.updated_at DESC, g.id DESC LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'title'    => (string) $r['title'],
                'target'   => (string) $r['target'],
                'progress' => (int) $r['progress'],
                'closed'   => (int) $r['closed'] === 1,
                'author'   => (string) ($r['author'] ?: 'A member'),
                'mine'     => (int) $r['author_id'] === $viewerId,
            ];
        }
        return $out;
    }
}
