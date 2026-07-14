<?php
/**
 * lib/Collab.php — the collaboration engine behind the portals.
 *
 * Gives the member portal a "collaborative workspace" feel (à la Google
 * Workspace / Zoom Workplace) without a realtime backend, on the plain
 * PHP+SQLite stack:
 *
 *   • Presence  — a heartbeat table; "who's online now" + a live count.
 *   • Activity  — a team feed fed by the existing Events seam.
 *   • Tasks     — lightweight personal/assignable action items.
 *
 * Everything is idempotent and best-effort: tables are created on first use
 * with driver-aware DDL, and nothing here can break a page render.
 */
declare(strict_types=1);

final class Collab
{
    const ONLINE_WINDOW = 180;   // seconds since last heartbeat to count as "online"

    /* ────────────────────────── presence ────────────────────────── */
    private static function ensurePresence(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS presence (
            user_id INTEGER PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT 'online',
            last_seen INTEGER NOT NULL DEFAULT 0
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /** Record a heartbeat for the user. status: online | away | busy. */
    public static function heartbeat(int $uid, string $status = 'online'): void
    {
        if ($uid <= 0) return;
        self::ensurePresence();
        $status = in_array($status, ['online', 'away', 'busy'], true) ? $status : 'online';
        $now = time();
        $pdo = Database::pdo();
        // portable upsert: try update, else insert
        $n = $pdo->prepare('UPDATE presence SET status = ?, last_seen = ? WHERE user_id = ?');
        $n->execute([$status, $now, $uid]);
        if ($n->rowCount() === 0) {
            try { $pdo->prepare('INSERT INTO presence (user_id, status, last_seen) VALUES (?,?,?)')->execute([$uid, $status, $now]); }
            catch (Throwable $e) { /* raced with another insert — fine */ }
        }
    }

    /** Members seen within the window: [{id,name,initials,status,ago}]. Org-only. */
    public static function onlineUsers(int $limit = 40): array
    {
        self::ensurePresence();
        $cut = time() - self::ONLINE_WINDOW;
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT p.user_id, p.status, p.last_seen, u.name, u.email
                FROM presence p JOIN lms_users u ON u.id = p.user_id
                WHERE p.last_seen >= ? ORDER BY p.last_seen DESC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute([$cut]);
        $now = time();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'       => (int) $r['user_id'],
                'name'     => (string) $r['name'],
                'initials' => self::initials((string) $r['name'], (string) $r['email']),
                'status'   => (string) $r['status'],
                'ago'      => self::ago($now - (int) $r['last_seen']),
            ];
        }
        return $out;
    }

    public static function onlineCount(): int
    {
        self::ensurePresence();
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM presence WHERE last_seen >= ?');
        $st->execute([time() - self::ONLINE_WINDOW]);
        return (int) $st->fetchColumn();
    }

    /* ────────────────────────── activity ────────────────────────── */
    private static function ensureActivity(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NOT NULL DEFAULT 0,
            actor_name VARCHAR(160) NOT NULL DEFAULT '',
            verb VARCHAR(60) NOT NULL DEFAULT '',
            object VARCHAR(300) NOT NULL DEFAULT '',
            url VARCHAR(400) NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /** Append a team-activity entry (best-effort). */
    public static function log(int $actorId, string $actorName, string $verb, string $object = '', string $url = ''): void
    {
        try {
            self::ensureActivity();
            Database::pdo()->prepare('INSERT INTO activity (actor_id, actor_name, verb, object, url, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$actorId, mb_substr($actorName, 0, 160), mb_substr($verb, 0, 60), mb_substr($object, 0, 300), mb_substr($url, 0, 400), gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('[collab] activity log: ' . $e->getMessage()); }
    }

    /** Most recent team activity: [{actor,initials,verb,object,url,ago}]. */
    public static function recentActivity(int $limit = 18): array
    {
        self::ensureActivity();
        $limit = max(1, min(50, $limit));
        $rows = Database::pdo()->query('SELECT * FROM activity ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'actor'    => (string) $r['actor_name'] ?: 'Someone',
                'initials' => self::initials((string) $r['actor_name'], ''),
                'verb'     => (string) $r['verb'],
                'object'   => (string) $r['object'],
                'url'      => (string) $r['url'],
                'ago'      => self::ago($now - (int) (strtotime((string) $r['created_at'] . ' UTC') ?: $now)),
            ];
        }
        return $out;
    }

    /** Subscribe to domain events → team activity. Called once from bootstrap. */
    public static function bootEvents(): void
    {
        static $done = false; if ($done || !class_exists('Events')) return; $done = true;
        Events::on('member.created', fn($p) => self::log(0, (string) ($p['name'] ?? 'A new member'), 'joined Afrovanguard', '', ''));
        Events::on('workspace.connected', fn($p) => self::log((int) ($p['user_id'] ?? 0), self::nameOf((int) ($p['user_id'] ?? 0)), 'connected their Google Workspace', '', '/workspace'));
        Events::on('mentorship.session_scheduled', fn($p) => self::log(0, 'A mentor', 'scheduled a mentorship session', '', '/mentorship/'));
        Events::on('diary.published', fn($p) => self::log((int) ($p['author_id'] ?? 0), (string) ($p['author'] ?? 'A member'), 'published a Diary entry', (string) ($p['title'] ?? ''), (string) ($p['url'] ?? '/diary/')));
        Events::on('course.completed', fn($p) => self::log((int) ($p['user_id'] ?? 0), (string) ($p['name'] ?? 'A learner'), 'completed a course', (string) ($p['course'] ?? ''), ''));
    }

    /* ────────────────────────── tasks ────────────────────────── */
    private static function ensureTasks(): void
    {
        static $d = false; if ($d) return; $d = true;
        $pdo = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS collab_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            creator_id INTEGER NOT NULL DEFAULT 0,
            assignee_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(300) NOT NULL DEFAULT '',
            done INTEGER NOT NULL DEFAULT 0,
            due TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT ''
        )";
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
    }

    /** Create a task. Assignee defaults to the creator. Returns the new id or 0. */
    public static function addTask(int $creatorId, string $title, int $assigneeId = 0, string $due = ''): int
    {
        $title = trim(mb_substr(trim($title), 0, 300));
        if ($creatorId <= 0 || $title === '') return 0;
        self::ensureTasks();
        $assignee = $assigneeId > 0 ? $assigneeId : $creatorId;
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($due)) ? trim($due) : '';
        Database::pdo()->prepare('INSERT INTO collab_tasks (creator_id, assignee_id, title, done, due, created_at) VALUES (?,?,?,0,?,?)')
            ->execute([$creatorId, $assignee, $title, $due, gmdate('Y-m-d H:i:s')]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** Toggle done — only the assignee or creator may. Returns the new state (or null). */
    public static function toggleTask(int $uid, int $taskId): ?bool
    {
        self::ensureTasks();
        $t = self::ownedTask($uid, $taskId);
        if (!$t) return null;
        $new = $t['done'] ? 0 : 1;
        Database::pdo()->prepare('UPDATE collab_tasks SET done = ? WHERE id = ?')->execute([$new, $taskId]);
        return (bool) $new;
    }

    public static function deleteTask(int $uid, int $taskId): bool
    {
        self::ensureTasks();
        if (!self::ownedTask($uid, $taskId)) return false;
        Database::pdo()->prepare('DELETE FROM collab_tasks WHERE id = ?')->execute([$taskId]);
        return true;
    }

    /** The user's tasks (assigned to them or created by them), open first. */
    public static function myTasks(int $uid, int $limit = 50): array
    {
        self::ensureTasks();
        $limit = max(1, min(100, $limit));
        $st = Database::pdo()->prepare(
            'SELECT * FROM collab_tasks WHERE assignee_id = ? OR creator_id = ? ORDER BY done ASC, id DESC LIMIT ' . $limit
        );
        $st->execute([$uid, $uid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $assignee = (int) $r['assignee_id'];
            $creator  = (int) $r['creator_id'];
            $out[] = [
                'id'       => (int) $r['id'],
                'title'    => (string) $r['title'],
                'done'     => (bool) $r['done'],
                'due'      => (string) $r['due'],
                'mine'     => $assignee === $uid,
                // Allocation context for the UI.
                'assignee_id'   => $assignee,
                'assignee_name' => $assignee === $uid ? 'You' : self::nameOf($assignee),
                'assigned_out'  => $creator === $uid && $assignee !== $uid, // I gave this to someone
                'creator_name'  => $creator === $uid ? 'You' : self::nameOf($creator),
            ];
        }
        return $out;
    }

    /** Members who can be assigned a task (org members), for the allocation picker. */
    public static function roster(int $limit = 200): array
    {
        try {
            $domain = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
            $pdo = Database::pdo();
            $st = $pdo->prepare("SELECT id, name, email FROM lms_users WHERE LOWER(email) LIKE ? ORDER BY name ASC LIMIT " . max(1, min(500, $limit)));
            $st->execute(['%@' . $domain]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['id' => (int) $r['id'], 'name' => (string) ($r['name'] ?: explode('@', (string) $r['email'])[0])];
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    private static function ownedTask(int $uid, int $taskId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM collab_tasks WHERE id = ? AND (assignee_id = ? OR creator_id = ?)');
        $st->execute([$taskId, $uid, $uid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ────────────────────────── helpers ────────────────────────── */
    private static function nameOf(int $uid): string
    {
        if ($uid <= 0) return 'A member';
        try { $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $st->execute([$uid]); return (string) ($st->fetchColumn() ?: 'A member'); }
        catch (Throwable $e) { return 'A member'; }
    }

    private static function initials(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '' && $email !== '') $name = explode('@', $email)[0];
        $p = preg_split('/\s+/', $name) ?: [];
        $s = strtoupper(substr((string) ($p[0] ?? ''), 0, 1) . substr((string) ($p[1] ?? ''), 0, 1));
        return $s !== '' ? $s : 'A';
    }

    private static function ago(int $sec): string
    {
        if ($sec < 45) return 'just now';
        if ($sec < 3600) return floor($sec / 60) . 'm ago';
        if ($sec < 86400) return floor($sec / 3600) . 'h ago';
        return floor($sec / 86400) . 'd ago';
    }
}
