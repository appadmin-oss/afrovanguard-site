<?php
/**
 * lib/Mentorship.php — the Afrovanguard mentor network.
 *
 * Real pairing on the portable DB layer: members opt in as mentors (a profile
 * with focus areas + capacity); anyone signed in can browse them and request
 * mentorship; the mentor accepts/declines; either party can end it; mentors
 * schedule sessions on an active pairing. Requests and acceptances email the
 * other party (Mailer) and emit events (→ webhooks / the bot / integrations).
 *
 * Driver-aware DDL (SQLite output byte-identical; MySQL/Postgres translated),
 * like the rest of the app.
 */
declare(strict_types=1);

final class Mentorship
{
    const STATUSES = ['pending', 'active', 'declined', 'ended'];

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS mentor_profiles (
            user_id INTEGER PRIMARY KEY,
            headline VARCHAR(160) NOT NULL DEFAULT '',
            bio TEXT NOT NULL DEFAULT '',
            focus VARCHAR(255) NOT NULL DEFAULT '',
            capacity INTEGER NOT NULL DEFAULT 3,
            accepting INTEGER NOT NULL DEFAULT 1,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS mentorships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentor_id INTEGER NOT NULL,
            mentee_id INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            message TEXT NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_mentorships_mentor ON mentorships(mentor_id, status);
        CREATE INDEX IF NOT EXISTS idx_mentorships_mentee ON mentorships(mentee_id, status);
        CREATE TABLE IF NOT EXISTS mentor_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mentorship_id INTEGER NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT '',
            scheduled_at VARCHAR(32) NOT NULL DEFAULT '',
            notes TEXT NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_msessions ON mentor_sessions(mentorship_id, scheduled_at);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /* ── mentor profiles ─────────────────────────────────────────── */

    public static function isMentor(int $uid): bool
    {
        self::ensure();
        $s = Database::pdo()->prepare('SELECT 1 FROM mentor_profiles WHERE user_id = ?'); $s->execute([$uid]);
        return (bool) $s->fetchColumn();
    }

    public static function profile(int $uid): ?array
    {
        self::ensure();
        $s = Database::pdo()->prepare('SELECT * FROM mentor_profiles WHERE user_id = ?'); $s->execute([$uid]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Opt in / update a mentor profile (members only — enforced by the API). */
    public static function becomeMentor(int $uid, array $in): array
    {
        self::ensure();
        $headline = mb_substr(trim((string) ($in['headline'] ?? '')), 0, 160);
        if ($headline === '') return ['ok' => false, 'error' => 'Add a short headline (what you mentor on).'];
        $bio      = mb_substr(trim((string) ($in['bio'] ?? '')), 0, 4000);
        $focus    = mb_substr(trim((string) ($in['focus'] ?? '')), 0, 255);
        $capacity = max(1, min(50, (int) ($in['capacity'] ?? 3)));
        $accepting = !empty($in['accepting']) ? 1 : 0;
        $db = Database::pdo();
        $now = self::now();
        if (self::isMentor($uid)) {
            $db->prepare('UPDATE mentor_profiles SET headline=?, bio=?, focus=?, capacity=?, accepting=?, updated_at=? WHERE user_id=?')
               ->execute([$headline, $bio, $focus, $capacity, $accepting, $now, $uid]);
        } else {
            $db->prepare('INSERT INTO mentor_profiles (user_id, headline, bio, focus, capacity, accepting, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$uid, $headline, $bio, $focus, $capacity, $accepting, $now, $now]);
            if (class_exists('Events')) { try { Events::emit('mentor.joined', ['user_id' => $uid, 'headline' => $headline]); } catch (Throwable $e) {} }
        }
        return ['ok' => true, 'profile' => self::profile($uid)];
    }

    private static function activeMenteeCount(int $mentorId): int
    {
        $s = Database::pdo()->prepare("SELECT COUNT(*) FROM mentorships WHERE mentor_id = ? AND status = 'active'");
        $s->execute([$mentorId]);
        return (int) $s->fetchColumn();
    }

    /** Accepting mentors with capacity left (excludes the viewer). */
    public static function availableMentors(int $viewerId = 0, int $limit = 50): array
    {
        self::ensure();
        $rows = Database::pdo()->query(
            "SELECT p.*, u.name AS name, u.email AS email,
                    (SELECT COUNT(*) FROM mentorships m WHERE m.mentor_id = p.user_id AND m.status='active') AS mentees
             FROM mentor_profiles p JOIN lms_users u ON u.id = p.user_id
             WHERE p.accepting = 1 ORDER BY p.updated_at DESC LIMIT " . max(1, min(100, $limit))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['user_id'] === $viewerId) continue;
            $r['full'] = (int) $r['mentees'] >= (int) $r['capacity'];
            $out[] = self::shapeMentor($r, $viewerId);
        }
        return $out;
    }

    private static function shapeMentor(array $r, int $viewerId): array
    {
        $rel = $viewerId ? self::relation($viewerId, (int) $r['user_id']) : null;
        return [
            'user_id'  => (int) $r['user_id'],
            'name'     => (string) $r['name'],
            'initial'  => mb_strtoupper(mb_substr((string) $r['name'], 0, 1)),
            'headline' => (string) $r['headline'],
            'bio'      => (string) $r['bio'],
            'focus'    => array_values(array_filter(array_map('trim', explode(',', (string) $r['focus'])))),
            'mentees'  => (int) ($r['mentees'] ?? 0),
            'capacity' => (int) $r['capacity'],
            'full'     => !empty($r['full']),
            'my_status' => $rel['status'] ?? null,   // pending|active|… with this viewer, if any
        ];
    }

    /* ── requests & pairing ──────────────────────────────────────── */

    /** Existing pending/active mentorship between a mentee and mentor, if any. */
    private static function relation(int $menteeId, int $mentorId): ?array
    {
        $s = Database::pdo()->prepare("SELECT * FROM mentorships WHERE mentee_id=? AND mentor_id=? AND status IN ('pending','active') ORDER BY id DESC LIMIT 1");
        $s->execute([$menteeId, $mentorId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function request(int $menteeId, int $mentorId, string $message): array
    {
        self::ensure();
        if ($menteeId <= 0 || $mentorId <= 0 || $menteeId === $mentorId) return ['ok' => false, 'error' => 'Invalid request.'];
        $p = self::profile($mentorId);
        if (!$p || (int) $p['accepting'] !== 1) return ['ok' => false, 'error' => 'This mentor isn’t accepting requests right now.'];
        if (self::activeMenteeCount($mentorId) >= (int) $p['capacity']) return ['ok' => false, 'error' => 'This mentor is at capacity. Try another mentor.'];
        if (self::relation($menteeId, $mentorId)) return ['ok' => false, 'error' => 'You already have a request or active mentorship with this mentor.'];
        $db = Database::pdo(); $now = self::now();
        $db->prepare('INSERT INTO mentorships (mentor_id, mentee_id, status, message, created_at, updated_at) VALUES (?,?,?,?,?,?)')
           ->execute([$mentorId, $menteeId, 'pending', mb_substr(trim($message), 0, 2000), $now, $now]);
        $id = (int) $db->lastInsertId();
        self::notify($mentorId, 'New mentorship request', 'A member has requested you as their mentor on Afrovanguard.', '/mentorship/');
        if (class_exists('Events')) { try { Events::emit('mentorship.requested', ['id' => $id, 'mentor_id' => $mentorId, 'mentee_id' => $menteeId]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $id];
    }

    /** Mentor accepts/declines a pending request they own. */
    public static function respond(int $mentorId, int $mentorshipId, bool $accept): array
    {
        self::ensure();
        $db = Database::pdo();
        $s = $db->prepare("SELECT * FROM mentorships WHERE id=? AND mentor_id=? AND status='pending'");
        $s->execute([$mentorshipId, $mentorId]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) return ['ok' => false, 'error' => 'Request not found.'];
        if ($accept) {
            $p = self::profile($mentorId);
            if ($p && self::activeMenteeCount($mentorId) >= (int) $p['capacity']) return ['ok' => false, 'error' => 'You’re at capacity — end a mentorship or raise your capacity first.'];
        }
        $status = $accept ? 'active' : 'declined';
        $db->prepare('UPDATE mentorships SET status=?, updated_at=? WHERE id=?')->execute([$status, self::now(), $mentorshipId]);
        self::notify((int) $m['mentee_id'], 'Mentorship ' . ($accept ? 'accepted' : 'declined'),
            $accept ? 'Your mentor accepted — say hello and book your first session.' : 'Your mentorship request wasn’t taken up this time. Browse other mentors anytime.', '/mentorship/');
        if (class_exists('Events')) { try { Events::emit('mentorship.' . ($accept ? 'accepted' : 'declined'), ['id' => $mentorshipId, 'mentor_id' => $mentorId, 'mentee_id' => (int) $m['mentee_id']]); } catch (Throwable $e) {} }
        return ['ok' => true, 'status' => $status];
    }

    /** Either party ends an active mentorship. */
    public static function end(int $uid, int $mentorshipId): array
    {
        self::ensure();
        $db = Database::pdo();
        $s = $db->prepare("SELECT * FROM mentorships WHERE id=? AND (mentor_id=? OR mentee_id=?) AND status='active'");
        $s->execute([$mentorshipId, $uid, $uid]);
        if (!$s->fetch()) return ['ok' => false, 'error' => 'Mentorship not found.'];
        $db->prepare('UPDATE mentorships SET status=?, updated_at=? WHERE id=?')->execute(['ended', self::now(), $mentorshipId]);
        return ['ok' => true];
    }

    /* ── reads ───────────────────────────────────────────────────── */

    private static function pairRows(string $where, array $args): array
    {
        $sql = "SELECT m.*, mu.name AS mentor_name, mu.email AS mentor_email, eu.name AS mentee_name, eu.email AS mentee_email
                FROM mentorships m
                JOIN lms_users mu ON mu.id = m.mentor_id
                JOIN lms_users eu ON eu.id = m.mentee_id
                WHERE $where ORDER BY m.updated_at DESC";
        $st = Database::pdo()->prepare($sql); $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** As a mentee: my mentors (active + pending). */
    public static function myMentors(int $menteeId): array
    {
        self::ensure();
        return array_map(fn($r) => self::shapePair($r, 'mentor'), self::pairRows("m.mentee_id = ? AND m.status IN ('pending','active')", [$menteeId]));
    }

    /** As a mentor: my mentees + incoming requests. */
    public static function myMentees(int $mentorId): array
    {
        self::ensure();
        return array_map(fn($r) => self::shapePair($r, 'mentee'), self::pairRows("m.mentor_id = ? AND m.status IN ('pending','active')", [$mentorId]));
    }

    private static function shapePair(array $r, string $otherIs): array
    {
        $name = $otherIs === 'mentor' ? (string) $r['mentor_name'] : (string) $r['mentee_name'];
        return [
            'id'       => (int) $r['id'],
            'status'   => (string) $r['status'],
            'message'  => (string) $r['message'],
            'name'     => $name,
            'initial'  => mb_strtoupper(mb_substr($name, 0, 1)),
            'since'    => (string) $r['updated_at'],
            'sessions' => self::sessions((int) $r['id']),
        ];
    }

    /* ── sessions ────────────────────────────────────────────────── */

    public static function addSession(int $mentorId, int $mentorshipId, string $title, string $when, string $notes): array
    {
        self::ensure();
        $db = Database::pdo();
        $s = $db->prepare("SELECT id FROM mentorships WHERE id=? AND mentor_id=? AND status='active'");
        $s->execute([$mentorshipId, $mentorId]);
        if (!$s->fetchColumn()) return ['ok' => false, 'error' => 'No active mentorship to schedule on.'];
        $title = mb_substr(trim($title), 0, 160) ?: 'Mentorship session';
        $whenN = trim($when) !== '' ? gmdate('Y-m-d H:i:s', strtotime($when) ?: time()) : '';
        $db->prepare('INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, notes, created_at) VALUES (?,?,?,?,?)')
           ->execute([$mentorshipId, $title, $whenN, mb_substr(trim($notes), 0, 2000), self::now()]);
        if (class_exists('Events')) { try { Events::emit('mentorship.session_scheduled', ['mentorship_id' => $mentorshipId, 'at' => $whenN]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => (int) $db->lastInsertId()];
    }

    public static function sessions(int $mentorshipId): array
    {
        $st = Database::pdo()->prepare('SELECT id, title, scheduled_at, notes, status FROM mentor_sessions WHERE mentorship_id = ? ORDER BY scheduled_at ASC, id ASC');
        $st->execute([$mentorshipId]);
        return array_map(fn($r) => [
            'id'    => (int) $r['id'],
            'title' => (string) $r['title'],
            'when'  => (string) $r['scheduled_at'],
            'notes' => (string) $r['notes'],
            'status' => (string) $r['status'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* ── email (best-effort) ─────────────────────────────────────── */
    private static function notify(int $userId, string $subject, string $line, string $path): void
    {
        if (!class_exists('Mailer')) return;
        try {
            $s = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?'); $s->execute([$userId]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u || empty($u['email'])) return;
            $site = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : 'https://afrovanguard.org.ng';
            $html = Mailer::shell('Mentorship', ['Hi ' . htmlspecialchars((string) $u['name'], ENT_QUOTES) . ',', $line],
                ['url' => $site . $path, 'text' => 'Open mentorship'], $subject);
            Mailer::send((string) $u['email'], $subject . ' — Afrovanguard', $html);
        } catch (Throwable $e) { error_log('[mentorship] notify: ' . $e->getMessage()); }
    }
}
