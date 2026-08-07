<?php
/**
 * lib/Notifications.php — a unified per-user notifications inbox + delivery.
 *
 * Anything in the portal that needs a member's attention (a due reminder, an
 * upcoming mentorship session, a task assigned to them, an @mention) pushes a
 * notification here; the portal bell shows the unread count and the list.
 * dispatchDue() is called from the web-cron to turn time-based events (reminders
 * coming due, sessions about to start) into notifications — so a reminder that
 * only lived in the app now actually reaches you.
 *
 * Portable DB layer; every read is fail-safe.
 */
declare(strict_types=1);

final class Notifications
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            kind VARCHAR(24) NOT NULL DEFAULT 'info',
            title VARCHAR(300) NOT NULL DEFAULT '',
            body VARCHAR(600) NOT NULL DEFAULT '',
            url VARCHAR(400) NOT NULL DEFAULT '',
            dedupe_key VARCHAR(120) NOT NULL DEFAULT '',
            read_at VARCHAR(32) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_notif_user ON user_notifications(user_id, read_at);
        CREATE INDEX IF NOT EXISTS idx_notif_dedupe ON user_notifications(user_id, dedupe_key);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /**
     * Create a notification. If $dedupeKey is given and one already exists for
     * this user with that key, nothing is inserted (returns 0). Returns new id.
     */
    public static function push(int $uid, string $kind, string $title, string $body = '', string $url = '', string $dedupeKey = ''): int
    {
        self::ensure();
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($uid <= 0 || $title === '') return 0;
        $db = Database::pdo();
        if ($dedupeKey !== '') {
            $ex = $db->prepare('SELECT 1 FROM user_notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1');
            $ex->execute([$uid, $dedupeKey]);
            if ($ex->fetchColumn()) return 0;
        }
        $db->prepare('INSERT INTO user_notifications (user_id, kind, title, body, url, dedupe_key, read_at, created_at) VALUES (?,?,?,?,?,?,\'\',?)')
            ->execute([$uid, mb_substr($kind, 0, 24), $title, trim(mb_substr(trim($body), 0, 600)),
                trim(mb_substr(trim($url), 0, 400)), mb_substr($dedupeKey, 0, 120), gmdate('Y-m-d H:i:s')]);
        return (int) $db->lastInsertId();
    }

    public static function unreadCount(int $uid): int
    {
        self::ensure();
        if ($uid <= 0) return 0;
        try {
            $st = Database::pdo()->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND read_at = ''");
            $st->execute([$uid]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    public static function listFor(int $uid, int $limit = 30): array
    {
        self::ensure();
        if ($uid <= 0) return [];
        $limit = max(1, min(100, $limit));
        try {
            $st = Database::pdo()->prepare('SELECT * FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$uid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'    => (int) $r['id'],
                'kind'  => (string) $r['kind'],
                'title' => (string) $r['title'],
                'body'  => (string) $r['body'],
                'url'   => (string) $r['url'],
                'read'  => (string) $r['read_at'] !== '',
                'ago'   => function_exists('av_ago') ? av_ago((string) $r['created_at']) : (string) $r['created_at'],
            ];
        }
        return $out;
    }

    public static function markRead(int $uid, array $ids): bool
    {
        self::ensure();
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if ($uid <= 0 || !$ids) return false;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare('UPDATE user_notifications SET read_at = ? WHERE user_id = ? AND read_at = \'\' AND id IN (' . $in . ')');
        $st->execute(array_merge([gmdate('Y-m-d H:i:s'), $uid], $ids));
        return true;
    }

    public static function markAllRead(int $uid): bool
    {
        self::ensure();
        if ($uid <= 0) return false;
        $st = Database::pdo()->prepare("UPDATE user_notifications SET read_at = ? WHERE user_id = ? AND read_at = ''");
        $st->execute([gmdate('Y-m-d H:i:s'), $uid]);
        return true;
    }

    /**
     * Turn time-based events into notifications. Idempotent (dedupe keys), so it
     * is safe to call every cron tick. Also emails when a Mailer is configured.
     * Returns counts per source.
     */
    public static function dispatchDue(): array
    {
        self::ensure();
        $db  = Database::pdo();
        $now = gmdate('Y-m-d H:i:s');
        $made = ['reminders' => 0, 'sessions' => 0];

        // 1) Reminders that have come due. Due is the wall-clock the member
        //    picked (their timezone), so we fire when THEIR clock reaches it —
        //    not when UTC does (which would be an hour late for WAT users).
        try {
            $st = $db->query("SELECT id, user_id, text, due FROM user_reminders WHERE done = 0 AND due <> ''");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $tz = function_exists('av_user_tz') ? av_user_tz((int) $r['user_id']) : (defined('AV_TZ') ? AV_TZ : 'UTC');
                if ((string) $r['due'] > av_now_tz('Y-m-d H:i', $tz)) continue;   // not due yet in their tz
                $id = self::push((int) $r['user_id'], 'reminder', 'Reminder: ' . (string) $r['text'],
                    'This reminder is due.', '/portal/#tools', 'rem:' . (int) $r['id']);
                if ($id) { $made['reminders']++; self::email((int) $r['user_id'], 'Reminder: ' . (string) $r['text'], 'This reminder is now due.'); }
            }
        } catch (Throwable $e) { error_log('[notif] reminders: ' . $e->getMessage()); }

        // 2) Mentorship sessions starting within the next hour.
        try {
            $soon = gmdate('Y-m-d H:i:s', time() + 3600);
            $st = $db->prepare(
                "SELECT s.id, s.title, s.scheduled_at, m.mentor_id, m.mentee_id
                 FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                 WHERE s.attendance <> 'cancelled' AND s.started_at = '' AND s.scheduled_at <> ''
                   AND s.scheduled_at > ? AND s.scheduled_at <= ?"
            );
            $st->execute([$now, $soon]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $title = 'Session soon: ' . ((string) $r['title'] ?: 'Mentorship session');
                foreach ([(int) $r['mentor_id'], (int) $r['mentee_id']] as $person) {
                    $nid = self::push($person, 'session', $title, 'Starting within the hour.', '/portal/#mentorship', 'sess:' . (int) $r['id']);
                    if ($nid) { $made['sessions']++; self::email($person, $title, 'Your mentorship session starts within the hour.'); }
                }
            }
        } catch (Throwable $e) { error_log('[notif] sessions: ' . $e->getMessage()); }

        return $made;
    }

    /** Best-effort email; silently no-ops when no Mailer is configured. */
    private static function email(int $uid, string $subject, string $line): void
    {
        try {
            if (!class_exists('Mailer') || !Mailer::configured()) return;
            $st = Database::pdo()->prepare('SELECT email, name FROM lms_users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u || empty($u['email'])) return;
            $html = Mailer::shell($subject, [htmlspecialchars($line)], ['text' => 'Open the portal', 'url' => (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/portal/']);
            Mailer::send((string) $u['email'], $subject, $html);
        } catch (Throwable $e) { error_log('[notif] email: ' . $e->getMessage()); }
    }
}
