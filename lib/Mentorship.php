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
        CREATE INDEX IF NOT EXISTS idx_msessions ON mentor_sessions(mentorship_id, scheduled_at);
        CREATE TABLE IF NOT EXISTS mentor_cohorts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(160) NOT NULL DEFAULT '',
            segment VARCHAR(12) NOT NULL DEFAULT 'org',
            programme VARCHAR(160) NOT NULL DEFAULT '',
            starts VARCHAR(20) NOT NULL DEFAULT '',
            ends VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));

        // Idempotent column additions (segment keeps ORG and EXTERNAL pools apart;
        // approval gates org mentors; cohort_id/programme make structure admin-defined).
        self::addCol('mentor_profiles', 'segment', "VARCHAR(12) NOT NULL DEFAULT 'org'");
        self::addCol('mentor_profiles', 'approval', "VARCHAR(12) NOT NULL DEFAULT 'approved'");
        self::addCol('mentor_profiles', 'source', "VARCHAR(12) NOT NULL DEFAULT 'applied'");
        self::addCol('mentor_profiles', 'admin_note', "TEXT NOT NULL DEFAULT ''");
        self::addCol('mentorships', 'segment', "VARCHAR(12) NOT NULL DEFAULT 'org'");
        self::addCol('mentorships', 'cohort_id', 'INTEGER NOT NULL DEFAULT 0');
        self::addCol('mentorships', 'programme', "VARCHAR(160) NOT NULL DEFAULT ''");
        self::addCol('mentorships', 'origin', "VARCHAR(12) NOT NULL DEFAULT 'request'"); // request|admin
        // Session tracking: Google Meet link, attendance (drives consistency),
        // an optional transcript link, and duration.
        self::addCol('mentor_sessions', 'meet_url', "VARCHAR(400) NOT NULL DEFAULT ''");
        self::addCol('mentor_sessions', 'attendance', "VARCHAR(16) NOT NULL DEFAULT 'scheduled'"); // scheduled|attended|missed|cancelled
        self::addCol('mentor_sessions', 'attended_at', "VARCHAR(32) NOT NULL DEFAULT ''");
        self::addCol('mentor_sessions', 'transcript_url', "VARCHAR(500) NOT NULL DEFAULT ''");
        self::addCol('mentor_sessions', 'duration_min', 'INTEGER NOT NULL DEFAULT 0');
        $done = true;
    }

    /** The mentor who owns a session (via its mentorship), or 0. */
    private static function sessionMentor(int $sessionId): int
    {
        try {
            $s = Database::pdo()->prepare('SELECT m.mentor_id FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id WHERE s.id = ?');
            $s->execute([$sessionId]);
            return (int) ($s->fetchColumn() ?: 0);
        } catch (Throwable $e) { return 0; }
    }

    /** Idempotent ADD COLUMN (skips if the column already exists). */
    private static function addCol(string $table, string $col, string $type): void
    {
        try {
            if (!Database::columnExists($table, $col)) {
                Database::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $type);
            }
        } catch (Throwable $e) { /* already exists / driver quirk — safe to ignore */ }
    }

    /** Which pool a user belongs to — ORG (afrovanguard.org.ng) vs EXTERNAL.
     *  The two are never mixed in listings or matching. */
    public static function segmentOf(int $uid): string
    {
        try {
            $s = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?'); $s->execute([$uid]);
            $email = (string) $s->fetchColumn();
            return ($email !== '' && class_exists('LmsAuth') && LmsAuth::isOrgEmail($email)) ? 'org' : 'external';
        } catch (Throwable $e) { return 'external'; }
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
            // ORG members are vetted (admin approval); EXTERNAL users self-serve.
            $segment  = self::segmentOf($uid);
            $approval = $segment === 'org' ? 'pending' : 'approved';
            $db->prepare('INSERT INTO mentor_profiles (user_id, headline, bio, focus, capacity, accepting, segment, approval, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$uid, $headline, $bio, $focus, $capacity, $accepting, $segment, $approval, 'applied', $now, $now]);
            if (class_exists('Events')) { try { Events::emit('mentor.joined', ['user_id' => $uid, 'headline' => $headline, 'segment' => $segment, 'approval' => $approval]); } catch (Throwable $e) {} }
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
        // Only APPROVED mentors, and only within the viewer's own pool (org/external
        // are never mixed). Signed-out browsing defaults to the external pool.
        $seg = $viewerId ? self::segmentOf($viewerId) : 'external';
        $st = Database::pdo()->prepare(
            "SELECT p.*, u.name AS name, u.email AS email,
                    (SELECT COUNT(*) FROM mentorships m WHERE m.mentor_id = p.user_id AND m.status='active') AS mentees
             FROM mentor_profiles p JOIN lms_users u ON u.id = p.user_id
             WHERE p.accepting = 1 AND p.approval = 'approved' AND p.segment = ? ORDER BY p.updated_at DESC LIMIT " . max(1, min(100, $limit))
        );
        $st->execute([$seg]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            'consistency' => self::consistency((int) $r['id']),
        ];
    }

    /* ── sessions ────────────────────────────────────────────────── */

    /** Sanitise a Google Meet / video link (https only, bounded). '' clears it. */
    private static function cleanUrl(string $url, int $max = 500): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('~^https://~i', $url)) return '';
        return mb_substr($url, 0, $max);
    }

    public static function addSession(int $mentorId, int $mentorshipId, string $title, string $when, string $notes, string $meetUrl = '', int $durationMin = 60): array
    {
        self::ensure();
        $db = Database::pdo();
        $s = $db->prepare("SELECT id FROM mentorships WHERE id=? AND mentor_id=? AND status='active'");
        $s->execute([$mentorshipId, $mentorId]);
        if (!$s->fetchColumn()) return ['ok' => false, 'error' => 'No active mentorship to schedule on.'];
        $title = mb_substr(trim($title), 0, 160) ?: 'Mentorship session';
        $whenN = trim($when) !== '' ? gmdate('Y-m-d H:i:s', strtotime($when) ?: time()) : '';
        $dur   = self::clampDuration($durationMin ?: 60);
        $db->prepare('INSERT INTO mentor_sessions (mentorship_id, title, scheduled_at, notes, meet_url, duration_min, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$mentorshipId, $title, $whenN, mb_substr(trim($notes), 0, 2000), self::cleanUrl($meetUrl, 400), $dur, self::now()]);
        if (class_exists('Events')) { try { Events::emit('mentorship.session_scheduled', ['mentorship_id' => $mentorshipId, 'at' => $whenN]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => (int) $db->lastInsertId()];
    }

    /** Keep a session length sane: 5 min .. 10 hours. */
    private static function clampDuration(int $min): int { return max(5, min(600, $min)); }
    /** Minutes → hours as a tidy number (e.g. 90 → 1.5, 120 → 2). */
    private static function fmtHours(int $minutes): float { return round($minutes / 60, 1); }

    /** Mentor sets/updates a session's Meet link. */
    public static function setMeetLink(int $mentorId, int $sessionId, string $url): array
    {
        self::ensure();
        if (self::sessionMentor($sessionId) !== $mentorId) return ['ok' => false, 'error' => 'Not your session.'];
        Database::pdo()->prepare('UPDATE mentor_sessions SET meet_url = ? WHERE id = ?')->execute([self::cleanUrl($url, 400), $sessionId]);
        return ['ok' => true];
    }

    /** Mentor marks attendance — this is what drives the consistency score and
     *  the logged hours. When a session is marked "attended" we record its
     *  length (the passed minutes, else the planned duration, else 60) so the
     *  member's mentorship-hours count is real. */
    public static function markAttendance(int $mentorId, int $sessionId, string $status, int $durationMin = 0): array
    {
        self::ensure();
        if (!in_array($status, ['scheduled', 'attended', 'missed', 'cancelled'], true)) return ['ok' => false, 'error' => 'Invalid status.'];
        if (self::sessionMentor($sessionId) !== $mentorId) return ['ok' => false, 'error' => 'Not your session.'];
        $db = Database::pdo();
        if ($status === 'attended') {
            $cur = (int) ($db->query('SELECT duration_min FROM mentor_sessions WHERE id = ' . (int) $sessionId)->fetchColumn() ?: 0);
            $dur = self::clampDuration($durationMin > 0 ? $durationMin : ($cur > 0 ? $cur : 60));
            $db->prepare('UPDATE mentor_sessions SET attendance = ?, status = ?, attended_at = ?, duration_min = ? WHERE id = ?')
                ->execute([$status, $status, self::now(), $dur, $sessionId]);
        } else {
            $db->prepare('UPDATE mentor_sessions SET attendance = ?, status = ?, attended_at = ? WHERE id = ?')
                ->execute([$status, $status, '', $sessionId]);
        }
        return ['ok' => true, 'attendance' => $status];
    }

    /** Attach (or clear) a transcript link for a session. */
    public static function attachTranscript(int $mentorId, int $sessionId, string $url): array
    {
        self::ensure();
        if (self::sessionMentor($sessionId) !== $mentorId) return ['ok' => false, 'error' => 'Not your session.'];
        Database::pdo()->prepare('UPDATE mentor_sessions SET transcript_url = ? WHERE id = ?')->execute([self::cleanUrl($url, 500), $sessionId]);
        return ['ok' => true];
    }

    public static function sessions(int $mentorshipId): array
    {
        $st = Database::pdo()->prepare('SELECT id, title, scheduled_at, notes, status, meet_url, attendance, attended_at, transcript_url, duration_min FROM mentor_sessions WHERE mentorship_id = ? ORDER BY scheduled_at ASC, id ASC');
        $st->execute([$mentorshipId]);
        return array_map(fn($r) => [
            'id'         => (int) $r['id'],
            'title'      => (string) $r['title'],
            'when'       => (string) $r['scheduled_at'],
            'notes'      => (string) $r['notes'],
            'status'     => (string) $r['status'],
            'meet_url'   => (string) ($r['meet_url'] ?? ''),
            'attendance' => (string) ($r['attendance'] ?? 'scheduled'),
            'transcript_url' => (string) ($r['transcript_url'] ?? ''),
            'duration_min'   => (int) ($r['duration_min'] ?? 0),
            'past'       => ($r['scheduled_at'] ?? '') !== '' && strtotime((string) $r['scheduled_at']) < time(),
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Consistency for one pairing: of the sessions that have already happened,
     * how many were attended, plus the current attended-streak and next session.
     */
    public static function consistency(int $mentorshipId): array
    {
        self::ensure();
        $rows = self::sessions($mentorshipId);
        $held = 0; $attended = 0; $streak = 0; $next = null; $minutes = 0;
        // Past sessions oldest→newest already (sessions() sorts asc).
        foreach ($rows as $s) {
            $isPast = !empty($s['past']) && $s['attendance'] !== 'cancelled';
            if ($isPast) {
                $held++;
                if ($s['attendance'] === 'attended') {
                    $attended++; $streak++;
                    // Older attended sessions predate duration capture → assume 60m.
                    $minutes += ((int) $s['duration_min']) > 0 ? (int) $s['duration_min'] : 60;
                }
                elseif ($s['attendance'] === 'missed') { $streak = 0; }
            } elseif (!$s['past'] && $s['attendance'] !== 'cancelled' && $next === null) {
                $next = $s;
            }
        }
        return [
            'held'     => $held,
            'attended' => $attended,
            'rate'     => $held > 0 ? (int) round(100 * $attended / $held) : null,
            'streak'   => $streak,
            'total'    => count($rows),
            'minutes'  => $minutes,
            'hours'    => self::fmtHours($minutes),
            'next'     => $next,
        ];
    }

    /**
     * A member's upcoming mentorship sessions across all active pairings — for
     * the portal schedule/calendar. Includes the counterpart name and Meet link.
     */
    public static function upcomingSessions(int $userId, int $limit = 6): array
    {
        self::ensure();
        try {
            $sql = "SELECT s.id, s.title, s.scheduled_at, s.meet_url, m.mentor_id, m.mentee_id,
                           mu.name AS mentor_name, eu.name AS mentee_name
                    FROM mentor_sessions s
                    JOIN mentorships m ON m.id = s.mentorship_id
                    JOIN lms_users mu ON mu.id = m.mentor_id
                    JOIN lms_users eu ON eu.id = m.mentee_id
                    WHERE (m.mentee_id = ? OR m.mentor_id = ?) AND m.status = 'active'
                      AND s.attendance <> 'cancelled' AND s.scheduled_at <> '' AND s.scheduled_at >= ?
                    ORDER BY s.scheduled_at ASC LIMIT " . (int) $limit;
            $st = Database::pdo()->prepare($sql);
            $st->execute([$userId, $userId, gmdate('Y-m-d H:i:s')]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $isMentor = (int) $r['mentor_id'] === $userId;
                $out[] = [
                    'id'       => (int) $r['id'],
                    'title'    => (string) $r['title'],
                    'when'     => (string) $r['scheduled_at'],
                    'iso'      => gmdate('c', strtotime((string) $r['scheduled_at'] . ' UTC') ?: time()),
                    'meet_url' => (string) ($r['meet_url'] ?? ''),
                    'with'     => (string) ($isMentor ? $r['mentee_name'] : $r['mentor_name']),
                    'role'     => $isMentor ? 'mentoring' : 'with your mentor',
                ];
            }
            return $out;
        } catch (Throwable $e) { error_log('[mentorship] upcoming: ' . $e->getMessage()); return []; }
    }

    /** A member's overall consistency across all their active/ended pairings. */
    public static function memberConsistency(int $userId): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) held,
                        SUM(CASE WHEN s.attendance='attended' THEN 1 ELSE 0 END) attended,
                        SUM(CASE WHEN s.attendance='attended' THEN (CASE WHEN s.duration_min > 0 THEN s.duration_min ELSE 60 END) ELSE 0 END) minutes
                 FROM mentor_sessions s JOIN mentorships m ON m.id = s.mentorship_id
                 WHERE (m.mentee_id = ? OR m.mentor_id = ?)
                   AND s.attendance <> 'cancelled'
                   AND s.scheduled_at <> '' AND s.scheduled_at < ?"
            );
            $st->execute([$userId, $userId, gmdate('Y-m-d H:i:s')]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['held' => 0, 'attended' => 0, 'minutes' => 0];
            $held = (int) $r['held']; $att = (int) $r['attended']; $mins = (int) ($r['minutes'] ?? 0);
            return ['held' => $held, 'attended' => $att, 'rate' => $held > 0 ? (int) round(100 * $att / $held) : null,
                    'minutes' => $mins, 'hours' => self::fmtHours($mins)];
        } catch (Throwable $e) { return ['held' => 0, 'attended' => 0, 'rate' => null, 'minutes' => 0, 'hours' => 0.0]; }
    }

    /* ════════════════════════════════════════════════════════════════
       ADMIN — staff management of mentors, mentees, pairings & cohorts.
       Org and External pools are kept strictly separate throughout.
       ════════════════════════════════════════════════════════════════ */

    /** At-a-glance counts, split by segment, for the admin dashboard. */
    public static function adminStats(): array
    {
        self::ensure();
        $db = Database::pdo();
        $c = function (string $sql) use ($db): int { try { return (int) $db->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
        $seg = function (string $s): array { return ['org' => 0, 'external' => 0] + []; };
        $bySeg = function (string $sql) use ($db): array {
            $out = ['org' => 0, 'external' => 0];
            try { foreach ($db->query($sql) as $r) { $out[$r['segment'] === 'org' ? 'org' : 'external'] = (int) $r['n']; } } catch (Throwable $e) {}
            return $out;
        };
        return [
            'mentors_pending'  => $bySeg("SELECT segment, COUNT(*) n FROM mentor_profiles WHERE approval='pending' GROUP BY segment"),
            'mentors_approved' => $bySeg("SELECT segment, COUNT(*) n FROM mentor_profiles WHERE approval='approved' GROUP BY segment"),
            'pairs_active'     => $bySeg("SELECT segment, COUNT(*) n FROM mentorships WHERE status='active' GROUP BY segment"),
            'pairs_pending'    => $bySeg("SELECT segment, COUNT(*) n FROM mentorships WHERE status='pending' GROUP BY segment"),
            'sessions'         => $c("SELECT COUNT(*) FROM mentor_sessions"),
            'inactive'         => count(self::inactivePairs(21)),
        ];
    }

    /** List mentor profiles for admin (filter by segment / approval / search). */
    public static function adminMentors(string $segment = '', string $approval = '', string $q = '', int $limit = 200): array
    {
        self::ensure();
        $where = ['1=1']; $args = [];
        if ($segment === 'org' || $segment === 'external') { $where[] = 'p.segment = ?'; $args[] = $segment; }
        if (in_array($approval, ['pending', 'approved', 'declined'], true)) { $where[] = 'p.approval = ?'; $args[] = $approval; }
        if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR p.headline LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
        $sql = "SELECT p.*, u.name, u.email,
                       (SELECT COUNT(*) FROM mentorships m WHERE m.mentor_id=p.user_id AND m.status='active') AS active_mentees
                FROM mentor_profiles p JOIN lms_users u ON u.id=p.user_id
                WHERE " . implode(' AND ', $where) . " ORDER BY (p.approval='pending') DESC, p.updated_at DESC LIMIT " . max(1, min(500, $limit));
        $st = Database::pdo()->prepare($sql); $st->execute($args);
        return array_map(function ($r) {
            return [
                'user_id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'],
                'segment' => (string) $r['segment'], 'approval' => (string) $r['approval'], 'source' => (string) ($r['source'] ?? 'applied'),
                'headline' => (string) $r['headline'], 'focus' => (string) $r['focus'],
                'capacity' => (int) $r['capacity'], 'accepting' => (int) $r['accepting'], 'active_mentees' => (int) $r['active_mentees'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* ── undo primitives (used by the audit layer to reverse actions) ── */
    public static function setMentorApproval(int $uid, string $status): bool
    {
        if (!in_array($status, ['pending', 'approved', 'declined'], true)) return false;
        $accepting = $status === 'approved' ? null : 0; // declining/parking stops new requests
        $db = Database::pdo();
        if ($accepting === null) $db->prepare('UPDATE mentor_profiles SET approval=?, updated_at=? WHERE user_id=?')->execute([$status, self::now(), $uid]);
        else $db->prepare('UPDATE mentor_profiles SET approval=?, accepting=?, updated_at=? WHERE user_id=?')->execute([$status, $accepting, self::now(), $uid]);
        return true;
    }
    public static function setPairStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) return false;
        Database::pdo()->prepare('UPDATE mentorships SET status=?, updated_at=? WHERE id=?')->execute([$status, self::now(), $id]);
        return true;
    }
    public static function setPairMentor(int $id, int $mentorId): bool
    {
        Database::pdo()->prepare('UPDATE mentorships SET mentor_id=?, updated_at=? WHERE id=?')->execute([$mentorId, self::now(), $id]);
        return true;
    }
    public static function deletePair(int $id): bool
    {
        Database::pdo()->prepare('DELETE FROM mentorships WHERE id=?')->execute([$id]);
        return true;
    }
    /** Dispatcher the audit layer calls to reverse a mentorship action. */
    public static function applyUndo(string $op, array $a): bool
    {
        self::ensure();
        switch ($op) {
            case 'mentor_approval': return self::setMentorApproval((int) ($a['uid'] ?? 0), (string) ($a['to'] ?? 'pending'));
            case 'pair_status':     return self::setPairStatus((int) ($a['id'] ?? 0), (string) ($a['to'] ?? 'ended'));
            case 'pair_mentor':     return self::setPairMentor((int) ($a['id'] ?? 0), (int) ($a['to'] ?? 0));
            case 'pair_delete':     return self::deletePair((int) ($a['id'] ?? 0));
        }
        return false;
    }

    /** Mentor profile approval state (for building undo payloads). */
    public static function approvalOf(int $uid): string
    {
        $s = Database::pdo()->prepare('SELECT approval FROM mentor_profiles WHERE user_id=?'); $s->execute([$uid]);
        return (string) ($s->fetchColumn() ?: '');
    }
    public static function pairRow(int $id): ?array
    {
        $s = Database::pdo()->prepare('SELECT * FROM mentorships WHERE id=?'); $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Admin adds a mentor directly by email (invite-only path for org staff). */
    public static function adminAddMentor(string $email, array $in): array
    {
        self::ensure();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email.'];
        $s = Database::pdo()->prepare('SELECT id FROM lms_users WHERE email=?'); $s->execute([$email]);
        $uid = (int) $s->fetchColumn();
        if (!$uid) return ['ok' => false, 'error' => 'No member account with that email yet — they must sign in once first.'];
        $segment = self::segmentOf($uid);
        $now = self::now();
        $headline = mb_substr(trim((string) ($in['headline'] ?? 'Afrovanguard mentor')), 0, 160);
        $focus = mb_substr(trim((string) ($in['focus'] ?? '')), 0, 255);
        $capacity = max(1, min(50, (int) ($in['capacity'] ?? 3)));
        $db = Database::pdo();
        if (self::isMentor($uid)) {
            $db->prepare("UPDATE mentor_profiles SET approval='approved', accepting=1, source='admin', headline=?, focus=?, capacity=?, updated_at=? WHERE user_id=?")
               ->execute([$headline, $focus, $capacity, $now, $uid]);
        } else {
            $db->prepare("INSERT INTO mentor_profiles (user_id, headline, bio, focus, capacity, accepting, segment, approval, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?, 'approved','admin',?,?)")
               ->execute([$uid, $headline, '', $focus, $capacity, 1, $segment, $now, $now]);
        }
        return ['ok' => true, 'user_id' => $uid, 'segment' => $segment];
    }

    /** All pairings for admin (filter by segment / status / cohort / search). */
    public static function adminPairings(string $segment = '', string $status = '', int $cohortId = -1, string $q = '', int $limit = 300): array
    {
        self::ensure();
        $where = ['1=1']; $args = [];
        if ($segment === 'org' || $segment === 'external') { $where[] = 'm.segment = ?'; $args[] = $segment; }
        if (in_array($status, self::STATUSES, true)) { $where[] = 'm.status = ?'; $args[] = $status; }
        if ($cohortId >= 0) { $where[] = 'm.cohort_id = ?'; $args[] = $cohortId; }
        if ($q !== '') { $where[] = '(mu.name LIKE ? OR mu.email LIKE ? OR eu.name LIKE ? OR eu.email LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like, $like); }
        $sql = "SELECT m.*, mu.name AS mentor_name, mu.email AS mentor_email, eu.name AS mentee_name, eu.email AS mentee_email,
                       (SELECT COUNT(*) FROM mentor_sessions s WHERE s.mentorship_id=m.id) AS session_count,
                       (SELECT MAX(scheduled_at) FROM mentor_sessions s WHERE s.mentorship_id=m.id) AS last_session
                FROM mentorships m JOIN lms_users mu ON mu.id=m.mentor_id JOIN lms_users eu ON eu.id=m.mentee_id
                WHERE " . implode(' AND ', $where) . " ORDER BY m.updated_at DESC LIMIT " . max(1, min(1000, $limit));
        $st = Database::pdo()->prepare($sql); $st->execute($args);
        return array_map(function ($r) {
            return [
                'id' => (int) $r['id'], 'status' => (string) $r['status'], 'segment' => (string) $r['segment'],
                'origin' => (string) ($r['origin'] ?? 'request'), 'cohort_id' => (int) $r['cohort_id'], 'programme' => (string) $r['programme'],
                'mentor' => ['id' => (int) $r['mentor_id'], 'name' => (string) $r['mentor_name'], 'email' => (string) $r['mentor_email']],
                'mentee' => ['id' => (int) $r['mentee_id'], 'name' => (string) $r['mentee_name'], 'email' => (string) $r['mentee_email']],
                'message' => (string) $r['message'], 'sessions' => (int) $r['session_count'], 'last_session' => (string) ($r['last_session'] ?? ''),
                'since' => (string) $r['updated_at'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Admin manually pairs a mentee with a mentor (same segment only). */
    public static function adminAssign(int $mentorId, int $menteeId, int $cohortId = 0, string $programme = ''): array
    {
        self::ensure();
        if ($mentorId <= 0 || $menteeId <= 0 || $mentorId === $menteeId) return ['ok' => false, 'error' => 'Pick a different mentor and mentee.'];
        $p = self::profile($mentorId);
        if (!$p) return ['ok' => false, 'error' => 'That mentor has no profile.'];
        if (($p['approval'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'Approve the mentor before assigning mentees.'];
        if (self::segmentOf($mentorId) !== self::segmentOf($menteeId)) return ['ok' => false, 'error' => 'Org and external members can’t be paired together.'];
        if (self::relation($menteeId, $mentorId)) return ['ok' => false, 'error' => 'These two already have a pending/active mentorship.'];
        if (self::activeMenteeCount($mentorId) >= (int) $p['capacity']) return ['ok' => false, 'error' => 'That mentor is at capacity.'];
        $seg = self::segmentOf($mentorId); $now = self::now();
        $db = Database::pdo();
        $db->prepare("INSERT INTO mentorships (mentor_id, mentee_id, status, message, segment, cohort_id, programme, origin, created_at, updated_at) VALUES (?,?, 'active','', ?,?,?, 'admin', ?,?)")
           ->execute([$mentorId, $menteeId, $seg, max(0, $cohortId), mb_substr($programme, 0, 160), $now, $now]);
        $id = (int) $db->lastInsertId();
        self::notify($menteeId, 'You’ve been matched with a mentor', 'A mentor has been assigned to you on Afrovanguard — say hello and book your first session.', '/mentorship/');
        self::notify($mentorId, 'A mentee has been assigned to you', 'You’ve been paired with a new mentee on Afrovanguard.', '/mentorship/');
        if (class_exists('Events')) { try { Events::emit('mentorship.assigned', ['id' => $id, 'mentor_id' => $mentorId, 'mentee_id' => $menteeId]); } catch (Throwable $e) {} }
        return ['ok' => true, 'id' => $id];
    }

    /** Move an active/pending pairing to a different mentor (same segment). */
    public static function adminReassign(int $mentorshipId, int $newMentorId): array
    {
        self::ensure();
        $m = self::pairRow($mentorshipId);
        if (!$m) return ['ok' => false, 'error' => 'Pairing not found.'];
        if (self::segmentOf($newMentorId) !== (string) $m['segment']) return ['ok' => false, 'error' => 'New mentor must be in the same pool.'];
        $p = self::profile($newMentorId);
        if (!$p || ($p['approval'] ?? '') !== 'approved') return ['ok' => false, 'error' => 'New mentor isn’t approved.'];
        $prev = (int) $m['mentor_id'];
        self::setPairMentor($mentorshipId, $newMentorId);
        return ['ok' => true, 'prev_mentor' => $prev];
    }

    /** Active pairings with no session in the last $days days. */
    public static function inactivePairs(int $days = 21): array
    {
        self::ensure();
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $sql = "SELECT m.*, mu.name AS mentor_name, eu.name AS mentee_name,
                       (SELECT MAX(scheduled_at) FROM mentor_sessions s WHERE s.mentorship_id=m.id) AS last_session
                FROM mentorships m JOIN lms_users mu ON mu.id=m.mentor_id JOIN lms_users eu ON eu.id=m.mentee_id
                WHERE m.status='active' AND COALESCE((SELECT MAX(scheduled_at) FROM mentor_sessions s WHERE s.mentorship_id=m.id), m.updated_at) < ?
                ORDER BY m.updated_at ASC";
        $st = Database::pdo()->prepare($sql); $st->execute([$cut]);
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'segment' => (string) $r['segment'], 'mentor' => (string) $r['mentor_name'], 'mentee' => (string) $r['mentee_name'],
            'last_session' => (string) ($r['last_session'] ?? ''), 'since' => (string) $r['updated_at'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* ── cohorts (admin-defined structure: ongoing / rounds / programme) ── */
    public static function listCohorts(string $segment = ''): array
    {
        self::ensure();
        $sql = "SELECT c.*, (SELECT COUNT(*) FROM mentorships m WHERE m.cohort_id=c.id) AS pairs FROM mentor_cohorts c";
        $args = [];
        if ($segment === 'org' || $segment === 'external') { $sql .= ' WHERE c.segment = ?'; $args[] = $segment; }
        $sql .= ' ORDER BY c.created_at DESC';
        $st = Database::pdo()->prepare($sql); $st->execute($args);
        return array_map(fn($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'segment' => (string) $r['segment'], 'programme' => (string) $r['programme'],
            'starts' => (string) $r['starts'], 'ends' => (string) $r['ends'], 'status' => (string) $r['status'], 'pairs' => (int) $r['pairs'],
        ], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    public static function createCohort(array $in): array
    {
        self::ensure();
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 160);
        if ($name === '') return ['ok' => false, 'error' => 'Name the cohort.'];
        $segment = (($in['segment'] ?? '') === 'external') ? 'external' : 'org';
        Database::pdo()->prepare('INSERT INTO mentor_cohorts (name, segment, programme, starts, ends, status, created_at) VALUES (?,?,?,?,?,?,?)')
           ->execute([$name, $segment, mb_substr((string) ($in['programme'] ?? ''), 0, 160), (string) ($in['starts'] ?? ''), (string) ($in['ends'] ?? ''), 'open', self::now()]);
        return ['ok' => true, 'id' => (int) Database::pdo()->lastInsertId()];
    }
    public static function setCohortStatus(int $id, string $status): array
    {
        self::ensure();
        if (!in_array($status, ['open', 'closed', 'archived'], true)) return ['ok' => false, 'error' => 'Bad status.'];
        Database::pdo()->prepare('UPDATE mentor_cohorts SET status=? WHERE id=?')->execute([$status, $id]);
        return ['ok' => true];
    }

    /** Find member accounts for admin assignment, scoped to a segment. */
    public static function findUsers(string $q, string $segment = '', int $limit = 20): array
    {
        self::ensure();
        $q = trim($q); if ($q === '') return [];
        $st = Database::pdo()->prepare("SELECT id, name, email FROM lms_users WHERE (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 60");
        $like = '%' . $q . '%'; $st->execute([$like, $like]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $seg = (class_exists('LmsAuth') && LmsAuth::isOrgEmail((string) $r['email'])) ? 'org' : 'external';
            if (($segment === 'org' || $segment === 'external') && $seg !== $segment) continue;
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'segment' => $seg];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Flat rows for CSV export of pairings. */
    public static function exportPairings(string $segment = ''): array
    {
        $rows = [['Segment', 'Status', 'Origin', 'Mentor', 'Mentor email', 'Mentee', 'Mentee email', 'Programme', 'Sessions', 'Last session', 'Since']];
        foreach (self::adminPairings($segment, '', -1, '', 1000) as $p) {
            $rows[] = [$p['segment'], $p['status'], $p['origin'], $p['mentor']['name'], $p['mentor']['email'], $p['mentee']['name'], $p['mentee']['email'], $p['programme'], $p['sessions'], $p['last_session'], $p['since']];
        }
        return $rows;
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
