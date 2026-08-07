<?php
/**
 * lib/Reminders.php — personal reminders (per-user), available to any signed-in
 * portal user across devices.
 *
 * Add a reminder with an optional due date/time; check it off; overdue + upcoming
 * surface first. Portable DB layer.
 */
declare(strict_types=1);

final class Reminders
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS user_reminders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            text VARCHAR(300) NOT NULL DEFAULT '',
            due VARCHAR(20) NOT NULL DEFAULT '',
            done INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_rem_user ON user_reminders(user_id);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Normalise a datetime-local string ("YYYY-MM-DDTHH:MM") to "YYYY-MM-DD HH:MM", or ''. */
    private static function normDue(string $due): string
    {
        $due = trim($due);
        if ($due === '') return '';
        $t = strtotime($due);
        return $t ? gmdate('Y-m-d H:i', $t) : '';
    }

    /** Add a reminder. Returns new id or 0. */
    public static function add(int $uid, string $text, string $due = ''): int
    {
        self::ensure();
        $text = trim(mb_substr(trim($text), 0, 300));
        if ($uid <= 0 || $text === '') return 0;
        Database::pdo()->prepare('INSERT INTO user_reminders (user_id, text, due, done, created_at) VALUES (?,?,?,0,?)')
            ->execute([$uid, $text, self::normDue($due), gmdate('Y-m-d H:i:s')]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Edit a reminder's text/due (owner only). */
    public static function edit(int $uid, int $id, string $text, string $due = ''): bool
    {
        self::ensure();
        $text = trim(mb_substr(trim($text), 0, 300));
        if ($uid <= 0 || $id <= 0 || $text === '') return false;
        $st = Database::pdo()->prepare('UPDATE user_reminders SET text = ?, due = ? WHERE id = ? AND user_id = ?');
        $st->execute([$text, self::normDue($due), $id, $uid]);
        return $st->rowCount() > 0;
    }

    /** Toggle (or set) done. Owner only. */
    public static function toggle(int $uid, int $id, ?bool $done = null): bool
    {
        self::ensure();
        if ($uid <= 0 || $id <= 0) return false;
        $db = Database::pdo();
        if ($done === null) {
            $st = $db->prepare('SELECT done FROM user_reminders WHERE id = ? AND user_id = ?');
            $st->execute([$id, $uid]);
            $cur = $st->fetchColumn();
            if ($cur === false) return false;
            $done = ((int) $cur) === 0;
        }
        $up = $db->prepare('UPDATE user_reminders SET done = ? WHERE id = ? AND user_id = ?');
        $up->execute([$done ? 1 : 0, $id, $uid]);
        return $up->rowCount() > 0;
    }

    /** Delete a reminder. Owner only. */
    public static function remove(int $uid, int $id): bool
    {
        self::ensure();
        if ($uid <= 0 || $id <= 0) return false;
        $st = Database::pdo()->prepare('DELETE FROM user_reminders WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        return $st->rowCount() > 0;
    }

    /** The caller's reminders: open (by due, undated last) first, done last. */
    public static function listFor(int $uid, int $limit = 100): array
    {
        self::ensure();
        if ($uid <= 0) return [];
        $limit = max(1, min(300, $limit));
        try {
            $st = Database::pdo()->prepare('SELECT * FROM user_reminders WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$uid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        // Compare against the member's own wall-clock so "Today"/overdue line up
        // with the clock on their wall (WAT), not the server's UTC.
        $tz         = function_exists('av_user_tz') ? av_user_tz($uid) : (defined('AV_TZ') ? AV_TZ : 'UTC');
        $nowLocal   = function_exists('av_now_tz') ? av_now_tz('Y-m-d H:i', $tz) : gmdate('Y-m-d H:i');
        $todayLocal = substr($nowLocal, 0, 10);
        $out = [];
        foreach ($rows as $r) {
            $due = (string) $r['due'];
            $out[] = [
                'id'       => (int) $r['id'],
                'text'     => (string) $r['text'],
                'due'      => $due,
                'due_label'=> $due !== '' ? self::dueLabel($due, $todayLocal) : '',
                'overdue'  => $due !== '' && (int) $r['done'] === 0 && $due < $nowLocal,
                'done'     => (int) $r['done'] === 1,
                'sort'     => $due !== '' ? $due : '9999-99-99 99:99',
            ];
        }
        // Open first (by soonest due, undated last), done last.
        usort($out, function ($a, $b) {
            if ($a['done'] !== $b['done']) return $a['done'] <=> $b['done'];
            return strcmp($a['sort'], $b['sort']);
        });
        foreach ($out as &$o) unset($o['sort']);
        return $out;
    }

    /** Label a 'Y-m-d H:i' wall-clock relative to the member's local today. */
    private static function dueLabel(string $due, string $todayLocal): string
    {
        $dayStr = substr($due, 0, 10);
        $time   = substr($due, 11, 5);
        $diff   = (int) round(((strtotime($dayStr . ' UTC') ?: 0) - (strtotime($todayLocal . ' UTC') ?: 0)) / 86400);
        $hasTime = $time !== '' && $time !== '00:00';
        $ts = strtotime($dayStr . ' UTC') ?: 0;
        if ($diff === 0)  return $hasTime ? 'Today ' . $time : 'Today';
        if ($diff === 1)  return $hasTime ? 'Tomorrow ' . $time : 'Tomorrow';
        if ($diff === -1) return 'Yesterday';
        if ($diff < 0)    return abs($diff) . 'd ago';
        if ($diff < 7)    return gmdate('D', $ts) . ($hasTime ? ' ' . $time : '');
        return gmdate('M j', $ts) . ($hasTime ? ' ' . $time : '');
    }
}
