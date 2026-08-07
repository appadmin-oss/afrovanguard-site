<?php
/**
 * lib/Standup.php — Async daily standups: the enterprise check-in ritual.
 *
 * Each member posts one update per day — what they did, what's next, and any
 * blockers — and the team sees today's board at a glance. One entry per user
 * per day (editable through the day). Portable DB layer, same as the app.
 */
declare(strict_types=1);

final class Standup
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS team_standups (
            user_id INTEGER NOT NULL,
            day VARCHAR(10) NOT NULL DEFAULT '',
            done TEXT NOT NULL DEFAULT '',
            next TEXT NOT NULL DEFAULT '',
            blockers TEXT NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, day)
        );
        CREATE INDEX IF NOT EXISTS idx_standup_day ON team_standups(day);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** The team's day key (UTC). */
    public static function today(): string { return gmdate('Y-m-d'); }

    /** Post or update the caller's standup for today. Returns true on success. */
    public static function submit(int $uid, string $done, string $next, string $blockers): bool
    {
        self::ensure();
        if ($uid <= 0) return false;
        $done     = trim(mb_substr(trim($done), 0, 1000));
        $next     = trim(mb_substr(trim($next), 0, 1000));
        $blockers = trim(mb_substr(trim($blockers), 0, 1000));
        if ($done === '' && $next === '' && $blockers === '') return false;
        $day = self::today();
        $db  = Database::pdo();
        // Portable upsert: delete today's row for this user, re-insert.
        $db->prepare('DELETE FROM team_standups WHERE user_id = ? AND day = ?')->execute([$uid, $day]);
        $db->prepare('INSERT INTO team_standups (user_id, day, done, next, blockers, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $day, $done, $next, $blockers, gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /** Remove the caller's standup for today. */
    public static function remove(int $uid): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare('DELETE FROM team_standups WHERE user_id = ? AND day = ?');
        $st->execute([$uid, self::today()]);
        return $st->rowCount() > 0;
    }

    /** The caller's own standup for today (or null). */
    public static function mine(int $uid): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT done, next, blockers FROM team_standups WHERE user_id = ? AND day = ?');
        $st->execute([$uid, self::today()]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Today's board: everyone's standup, most recent first. */
    public static function board(int $viewerId, int $limit = 60): array
    {
        self::ensure();
        $limit = max(1, min(200, $limit));
        $db = Database::pdo();
        try {
            $st = $db->prepare(
                'SELECT s.*, u.name AS author FROM team_standups s LEFT JOIN lms_users u ON u.id = s.user_id
                 WHERE s.day = ? ORDER BY s.updated_at DESC, s.user_id DESC LIMIT ' . $limit
            );
            $st->execute([self::today()]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id'  => (int) $r['user_id'],
                'author'   => (string) ($r['author'] ?: 'A member'),
                'done'     => (string) $r['done'],
                'next'     => (string) $r['next'],
                'blockers' => (string) $r['blockers'],
                'mine'     => (int) $r['user_id'] === $viewerId,
                'ago'      => self::ago((string) $r['updated_at']),
            ];
        }
        return $out;
    }

    private static function ago(string $ts): string
    {
        $t = strtotime($ts . ' UTC') ?: 0; if (!$t) return '';
        $d = max(0, time() - $t);
        if ($d < 60) return 'just now';
        if ($d < 3600) return floor($d / 60) . 'm ago';
        if ($d < 86400) return floor($d / 3600) . 'h ago';
        return floor($d / 86400) . 'd ago';
    }
}
