<?php
/**
 * lib/LmsDrip.php — the Academy emails that arrive because TIME passed, not
 * because somebody clicked something.
 *
 * Three of the designed templates (modules/EMAILS.md §4) are sent by a clock
 * rather than an action, and each needed the thing behind it:
 *
 *   academy_lesson_available   a lesson can now be opened. Lessons had no
 *                              release date at all, so lessons.available_at
 *                              exists now: empty means open, a timestamp
 *                              means "not before this".
 *   academy_continue           weekly, while a course is in progress and the
 *                              learner has not touched it for seven days.
 *   academy_live_class         24 hours before a class somebody booked.
 *                              Live classes did not exist; they do now, as a
 *                              date, a host and a join link people book.
 *
 * ── THE RULE THAT MATTERS MORE THAN THE FEATURE ─────────────────────────────
 * A sweep that re-reads the same rows every tick will mail the same person
 * every tick. Every one of these records that it has sent, in lms_drip_sent,
 * keyed by (kind, user, subject id) — so the cron can run every five minutes
 * for a year and each learner still gets each of these once. That table is
 * the feature; the queries around it are the easy part.
 *
 * Nothing here ever throws into the cron: a failed sweep is logged and the
 * tick continues.
 */
declare(strict_types=1);

final class LmsDrip
{
    /** How long a course may sit untouched before the weekly nudge. */
    public const IDLE_DAYS = 7;
    /** How far ahead of a live class the reminder goes. */
    public const CLASS_LEAD_HOURS = 24;
    /** Never nudge the same learner about the same course twice in this window. */
    public const NUDGE_EVERY_DAYS = 7;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS lms_drip_sent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind VARCHAR(32) NOT NULL DEFAULT '',
            user_id INTEGER NOT NULL DEFAULT 0,
            subject_id INTEGER NOT NULL DEFAULT 0,
            sent_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS lms_live_classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL DEFAULT 0,
            title VARCHAR(200) NOT NULL DEFAULT '',
            host_name VARCHAR(120) NOT NULL DEFAULT '',
            starts_at VARCHAR(32) NOT NULL DEFAULT '',
            join_url VARCHAR(500) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS lms_live_bookings (
            class_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (class_id, user_id)
        );";
        try { Database::execSchema($db, $ddl); }
        catch (Throwable $e) { error_log('[lmsdrip] ensure: ' . $e->getMessage()); }

        // Lessons gain a release date. Empty = open, which is every lesson
        // that existed before this — nothing is taken away by adding it.
        try {
            if (!Database::columnExists('lessons', 'available_at')) {
                $db->exec("ALTER TABLE lessons ADD COLUMN available_at VARCHAR(32) NOT NULL DEFAULT ''");
            }
        } catch (Throwable $e) { /* already there */ }

        Database::ensureIndex($db, 'idx_drip_sent', 'lms_drip_sent', 'kind, user_id, subject_id');
        Database::ensureIndex($db, 'idx_live_when', 'lms_live_classes', 'starts_at');
    }

    /** True the FIRST time this (kind, user, subject) is claimed. */
    private static function claim(string $kind, int $userId, int $subjectId, int $repeatAfterDays = 0): bool
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT sent_at FROM lms_drip_sent WHERE kind = ? AND user_id = ? AND subject_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$kind, $userId, $subjectId]);
        $last = (string) ($st->fetchColumn() ?: '');
        if ($last !== '') {
            if ($repeatAfterDays <= 0) return false;
            $age = time() - (strtotime($last . ' UTC') ?: 0);
            if ($age < $repeatAfterDays * 86400) return false;
        }
        $db->prepare('INSERT INTO lms_drip_sent (kind, user_id, subject_id, sent_at) VALUES (?,?,?,?)')
           ->execute([$kind, $userId, $subjectId, gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /** Is this lesson open yet? Used by the access check as well as the sweep. */
    public static function lessonOpen(array $lesson): bool
    {
        $at = trim((string) ($lesson['available_at'] ?? ''));
        if ($at === '') return true;
        return (strtotime($at . ' UTC') ?: 0) <= time();
    }

    /* ── 1. A lesson has just unlocked ───────────────────────────────────── */
    public static function sweepLessons(int $max = 200): int
    {
        self::ensure();
        $sent = 0;
        try {
            $db = Database::pdo();
            $since = gmdate('Y-m-d H:i:s', time() - 2 * 86400);   // caught up to two days late
            $now   = gmdate('Y-m-d H:i:s');
            $st = $db->prepare(
                "SELECT l.id, l.title, l.slug, l.duration_min, l.position, l.course_id,
                        c.title AS course_title, c.slug AS course_slug,
                        u.id AS user_id, u.name, u.email
                   FROM lessons l
                   JOIN courses c ON c.id = l.course_id
                   JOIN course_enrolment e ON e.course_id = l.course_id
                   JOIN lms_users u ON u.id = e.user_id
                  WHERE l.available_at <> '' AND l.available_at <= ? AND l.available_at >= ?
                    AND u.status = 'active' AND u.email <> ''
                  ORDER BY l.available_at ASC
                  LIMIT " . max(1, $max));
            $st->execute([$now, $since]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if (!self::claim('lesson', (int) $r['user_id'], (int) $r['id'])) continue;
                self::send((string) $r['email'], 'academy_lesson_available', [
                    'first_name'      => self::first((string) $r['name']),
                    'course_title'    => (string) $r['course_title'],
                    'lesson_no'       => (string) ((int) $r['position'] ?: 1),
                    'lesson_title'    => (string) $r['title'],
                    'lesson_duration' => ((int) $r['duration_min'] ?: 10) . ' min',
                    'lesson_url'      => self::url('/academy/' . $r['course_slug'] . '/learn/' . $r['slug']),
                ]);
                $sent++;
            }
        } catch (Throwable $e) { error_log('[lmsdrip] lessons: ' . $e->getMessage()); }
        return $sent;
    }

    /* ── 2. A course left sitting ────────────────────────────────────────── */
    public static function sweepContinue(int $max = 100): int
    {
        self::ensure();
        $sent = 0;
        try {
            $db = Database::pdo();
            $idle = gmdate('Y-m-d H:i:s', time() - self::IDLE_DAYS * 86400);
            $st = $db->prepare(
                "SELECT e.user_id, e.course_id, c.title AS course_title, c.slug AS course_slug,
                        u.name, u.email,
                        (SELECT COUNT(*) FROM lessons l WHERE l.course_id = e.course_id) AS total,
                        (SELECT COUNT(*) FROM lesson_progress p WHERE p.user_id = e.user_id AND p.course_id = e.course_id) AS done,
                        (SELECT MAX(p2.completed_at) FROM lesson_progress p2 WHERE p2.user_id = e.user_id AND p2.course_id = e.course_id) AS last_at
                   FROM course_enrolment e
                   JOIN courses c ON c.id = e.course_id
                   JOIN lms_users u ON u.id = e.user_id
                  WHERE u.status = 'active' AND u.email <> ''
                  LIMIT " . max(1, $max * 4));
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $total = (int) $r['total']; $done = (int) $r['done'];
                if ($total <= 0 || $done <= 0 || $done >= $total) continue;   // not started, or finished
                $last = (string) ($r['last_at'] ?? '');
                if ($last === '' || $last > $idle) continue;                   // touched recently
                if (!self::claim('continue', (int) $r['user_id'], (int) $r['course_id'], self::NUDGE_EVERY_DAYS)) continue;

                $next = $db->prepare(
                    "SELECT title, slug FROM lessons
                      WHERE course_id = ? AND id NOT IN (SELECT lesson_id FROM lesson_progress WHERE user_id = ? AND course_id = ?)
                      ORDER BY position ASC, id ASC LIMIT 1");
                $next->execute([(int) $r['course_id'], (int) $r['user_id'], (int) $r['course_id']]);
                $n = $next->fetch(PDO::FETCH_ASSOC) ?: ['title' => 'the next lesson', 'slug' => ''];

                self::send((string) $r['email'], 'academy_continue', [
                    'first_name'        => self::first((string) $r['name']),
                    'course_title'      => (string) $r['course_title'],
                    'progress_pct'      => (int) round($done / $total * 100),
                    'lessons_done'      => (string) $done,
                    'lessons_total'     => (string) $total,
                    'next_lesson_title' => (string) $n['title'],
                    'lesson_url'        => self::url('/academy/' . $r['course_slug'] . '/learn/' . $n['slug']),
                ]);
                $sent++;
                if ($sent >= $max) break;
            }
        } catch (Throwable $e) { error_log('[lmsdrip] continue: ' . $e->getMessage()); }
        return $sent;
    }

    /* ── 3. A live class tomorrow ────────────────────────────────────────── */
    public static function sweepLiveClasses(int $max = 200): int
    {
        self::ensure();
        $sent = 0;
        try {
            $db = Database::pdo();
            $from = gmdate('Y-m-d H:i:s');
            $to   = gmdate('Y-m-d H:i:s', time() + self::CLASS_LEAD_HOURS * 3600);
            $st = $db->prepare(
                "SELECT k.id, k.title, k.host_name, k.starts_at, k.join_url,
                        u.id AS user_id, u.name, u.email
                   FROM lms_live_classes k
                   JOIN lms_live_bookings b ON b.class_id = k.id
                   JOIN lms_users u ON u.id = b.user_id
                  WHERE k.starts_at > ? AND k.starts_at <= ?
                    AND u.status = 'active' AND u.email <> ''
                  LIMIT " . max(1, $max));
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if (!self::claim('liveclass', (int) $r['user_id'], (int) $r['id'])) continue;
                $ts = strtotime(((string) $r['starts_at']) . ' UTC') ?: time();
                self::send((string) $r['email'], 'academy_live_class', [
                    'first_name'   => self::first((string) $r['name']),
                    'class_title'  => (string) $r['title'],
                    'class_date'   => date('l j F Y', $ts),
                    'class_time'   => date('g:ia', $ts) . ' WAT',
                    'host_name'    => (string) $r['host_name'],
                    'join_url'     => (string) $r['join_url'],
                    'calendar_url' => self::url('/academy/class.php?id=' . (int) $r['id'] . '&f=ics'),
                ]);
                $sent++;
            }
        } catch (Throwable $e) { error_log('[lmsdrip] live classes: ' . $e->getMessage()); }
        return $sent;
    }

    /** One tick of all three, for tasks/cron.php. */
    public static function cronTick(): array
    {
        return [
            'lessons'      => self::sweepLessons(),
            'continue'     => self::sweepContinue(),
            'live_classes' => self::sweepLiveClasses(),
        ];
    }

    private static function send(string $to, string $template, array $vars): void
    {
        if (!class_exists('Mailer') || !method_exists('Mailer', 'sendTemplate')) return;
        try { Mailer::sendTemplate($to, $template, $vars); }
        catch (Throwable $e) { error_log('[lmsdrip] ' . $template . ': ' . $e->getMessage()); }
    }

    private static function first(string $name): string
    {
        return explode(' ', trim($name))[0] ?: 'there';
    }

    private static function url(string $path): string
    {
        return (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '') . $path;
    }
}
