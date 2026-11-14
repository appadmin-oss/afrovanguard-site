<?php
/**
 * lib/CourseTeam.php — who teaches a course, and what each of them may do.
 *
 * ── THE ONE RULE ────────────────────────────────────────────────────────────
 * Every "may this person do this to this course?" question in the Academy is
 * answered here, by `can()`, and nowhere else. teach.php, the grading screens,
 * the Studio and anything built later (ratings, observations, scorecards) ask
 * this class. A second copy of the rule is how one screen lets an assistant
 * release grades that another screen correctly refuses.
 *
 * A course has a TEAM (`course_instructors`), each member holding one role:
 *
 *   lead        runs the course: edits it, manages the team, sets the pass
 *               mark — and everything an instructor may do.
 *   instructor  creates and edits graded items, changes weights, marks,
 *               agrees moderated scores and releases grades.
 *   assistant   marks. Cannot release a grade or change a weight.
 *
 * The rights table is `RIGHTS` below; read it rather than the prose.
 *
 * Site staff — an LMS account whose role is `admin` or `coordinator` — may do
 * everything on every course without being on its team, because they are the
 * people who set teams up and sort them out when a lead leaves.
 *
 * Two invariants hold whatever the caller does:
 *
 *   1. A course with a team always has at least one lead. Removing or demoting
 *      the last lead is refused, and the first person put on a team must be
 *      its lead.
 *   2. `courses.instructor_id` mirrors the lead (the earliest-added one), so
 *      every older reader of that column — the Studio's "instructor email", the
 *      public course page before this, the CSV — keeps telling the truth.
 *
 * Teams began as `courses.instructor_id` (one instructor per course). `ensure()`
 * copies each of those into the team as its lead the first time it runs on a
 * database, and on every request after that for any course that still has no
 * team — so a course assigned through an old code path is never orphaned.
 *
 * `$by` on the write methods is the acting user's id. `STUDIO` (0) means the
 * Studio's admin console, whose session `require_admin()` has already
 * authorised; nothing a learner sends can produce it, because every
 * page that acts for a signed-in person passes that person's real id (> 0).
 */
declare(strict_types=1);

final class CourseTeam
{
    public const ROLES = ['lead', 'instructor', 'assistant'];
    /** The acting party when the Studio (already authorised upstream) makes a change. */
    public const STUDIO = 0;
    /** Site-wide LMS roles that may act on every course. */
    public const STAFF_ROLES = ['admin', 'coordinator'];

    /** What each role may do. Read by can(); documented in docs/academy-grading.md. */
    public const RIGHTS = [
        'view'           => ['lead', 'instructor', 'assistant'],  // see the course's teaching pages
        'mark'           => ['lead', 'instructor', 'assistant'],  // record a mark against a rubric
        'manage_items'   => ['lead', 'instructor'],               // create, edit, publish, retire graded items
        'change_weights' => ['lead', 'instructor'],
        'moderate'       => ['lead', 'instructor'],               // agree a final score where marks disagree
        'release'        => ['lead', 'instructor'],               // let learners see grades
        'edit_course'    => ['lead'],
        'manage_team'    => ['lead'],
        'set_pass_mark'  => ['lead'],
    ];

    private const LABELS = ['lead' => 'Lead instructor', 'instructor' => 'Instructor', 'assistant' => 'Teaching assistant'];

    private static bool $ready = false;

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS course_instructors (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id  INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            role       VARCHAR(16) NOT NULL DEFAULT 'instructor',
            added_by   INTEGER NOT NULL DEFAULT 0,
            added_at   VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE (course_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_course_instructors_user ON course_instructors (user_id)";
    }

    /** Create the table (every engine) and fold legacy single instructors in as leads. Once per process. */
    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        $db = Database::pdo();
        Database::execSchema($db, self::ddl());
        self::adoptLegacy();
    }

    /** For tests and long-running scripts that change the database underneath. */
    public static function reset(): void { self::$ready = false; }

    /**
     * Every course with an instructor_id and no team gets that person as its
     * lead. Idempotent: a course that has a team is never touched, so running
     * this on every request costs one INSERT … SELECT that inserts nothing.
     */
    public static function adoptLegacy(): int
    {
        $db = Database::pdo();
        try {
            if (!Database::tableExists('courses') || !Database::columnExists('courses', 'instructor_id')) return 0;
            $st = $db->prepare(
                "INSERT INTO course_instructors (course_id, user_id, role, added_by, added_at)
                 SELECT c.id, c.instructor_id, 'lead', 0, ? FROM courses c
                  WHERE c.instructor_id IS NOT NULL AND c.instructor_id > 0
                    AND NOT EXISTS (SELECT 1 FROM course_instructors t WHERE t.course_id = c.id)"
            );
            $st->execute([gmdate('Y-m-d H:i:s')]);
            return $st->rowCount();
        } catch (Throwable $e) { error_log('[team] adopt legacy instructors: ' . $e->getMessage()); return 0; }
    }

    /* ══ Reading ══════════════════════════════════════════════════════════ */

    /** The role this user holds on this course's team, or null. Site staff are not on a team by virtue of being staff. */
    public static function roleOf(int $courseId, int $userId): ?string
    {
        if ($courseId <= 0 || $userId <= 0) return null;
        self::ensure();
        $st = Database::pdo()->prepare('SELECT role FROM course_instructors WHERE course_id = ? AND user_id = ?');
        $st->execute([$courseId, $userId]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string) $r;
    }

    /** Whether this user holds a site-wide staff role (admin or coordinator). */
    public static function isStaff(int $userId): bool
    {
        if ($userId <= 0) return false;
        $st = Database::pdo()->prepare('SELECT role FROM lms_users WHERE id = ?');
        $st->execute([$userId]);
        return in_array((string) $st->fetchColumn(), self::STAFF_ROLES, true);
    }

    /**
     * May this user do $action on this course? The one answer to that question.
     * Unknown actions are refused, so a typo fails closed rather than open.
     */
    public static function can(int $courseId, int $userId, string $action): bool
    {
        if (!isset(self::RIGHTS[$action]) || $courseId <= 0 || $userId <= 0) return false;
        if (self::isStaff($userId)) return true;
        $role = self::roleOf($courseId, $userId);
        return $role !== null && in_array($role, self::RIGHTS[$action], true);
    }

    /** The team, lead first, then instructors, then assistants; earliest-added first within a role. */
    public static function members(int $courseId): array
    {
        self::ensure();
        $st = Database::pdo()->prepare(
            "SELECT t.user_id, t.role, t.added_at, t.added_by, u.name, u.email
               FROM course_instructors t JOIN lms_users u ON u.id = t.user_id
              WHERE t.course_id = ?
              ORDER BY CASE t.role WHEN 'lead' THEN 0 WHEN 'instructor' THEN 1 ELSE 2 END, t.id"
        );
        $st->execute([$courseId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['user_id'] = (int) $r['user_id'];
            $r['label'] = self::label((string) $r['role']);
            $out[] = $r;
        }
        return $out;
    }

    /** Courses this user is on the team of (any status), with their role. */
    public static function coursesFor(int $userId): array
    {
        if ($userId <= 0) return [];
        self::ensure();
        $st = Database::pdo()->prepare(
            "SELECT c.id, c.slug, c.title, c.status, t.role AS team_role
               FROM course_instructors t JOIN courses c ON c.id = t.course_id
              WHERE t.user_id = ? ORDER BY c.sort, c.title"
        );
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function label(string $role): string { return self::LABELS[$role] ?? ucfirst($role); }

    /* ══ Writing ══════════════════════════════════════════════════════════ */

    /**
     * Put a person on the team, or change the role they hold.
     * @return array{ok:bool, error?:string, changed?:bool}
     */
    public static function add(int $courseId, int $userId, string $role, int $by): array
    {
        self::ensure();
        if (!in_array($role, self::ROLES, true)) return ['ok' => false, 'error' => 'A team role is lead, instructor or assistant.'];
        if (!self::authorised($courseId, $by)) return ['ok' => false, 'error' => 'Only the course lead or Academy staff can change the teaching team.'];
        if (!self::userExists($userId)) return ['ok' => false, 'error' => 'No Academy account found for that person.'];
        if (!self::courseExists($courseId)) return ['ok' => false, 'error' => 'Course not found.'];

        $current = self::roleOf($courseId, $userId);
        if ($current === $role) return ['ok' => true, 'changed' => false];
        $leads = self::leadCount($courseId);
        if ($current === null && $leads === 0 && $role !== 'lead') {
            return ['ok' => false, 'error' => 'This course has no lead yet. The first person on a team is its lead.'];
        }
        if ($current === 'lead' && $role !== 'lead' && $leads <= 1) {
            return ['ok' => false, 'error' => 'A course always keeps at least one lead. Make someone else lead first.'];
        }
        $db = Database::pdo();
        if ($current === null) {
            $db->prepare(Database::insertIgnore('course_instructors', ['course_id', 'user_id', 'role', 'added_by', 'added_at']))
               ->execute([$courseId, $userId, $role, max(0, $by), gmdate('Y-m-d H:i:s')]);
        } else {
            $db->prepare('UPDATE course_instructors SET role = ? WHERE course_id = ? AND user_id = ?')->execute([$role, $courseId, $userId]);
        }
        // Leads and instructors need the instructor console; give a plain learner
        // that role, as assigning an instructor always has. Staff keep theirs.
        if ($role !== 'assistant') {
            $db->prepare("UPDATE lms_users SET role = 'instructor' WHERE id = ? AND role IN ('learner','member')")->execute([$userId]);
        }
        self::syncLegacy($courseId);
        self::audit($current === null ? 'team.add' : 'team.role', $courseId, $userId, ($current ?? 'none') . ' → ' . $role, $by);
        return ['ok' => true, 'changed' => true];
    }

    /** Take a person off the team. Refused for the last lead. */
    public static function remove(int $courseId, int $userId, int $by): array
    {
        self::ensure();
        if (!self::authorised($courseId, $by)) return ['ok' => false, 'error' => 'Only the course lead or Academy staff can change the teaching team.'];
        $current = self::roleOf($courseId, $userId);
        if ($current === null) return ['ok' => true, 'changed' => false];
        if ($current === 'lead' && self::leadCount($courseId) <= 1) {
            return ['ok' => false, 'error' => 'A course always keeps at least one lead. Make someone else lead first.'];
        }
        Database::pdo()->prepare('DELETE FROM course_instructors WHERE course_id = ? AND user_id = ?')->execute([$courseId, $userId]);
        self::syncLegacy($courseId);
        self::audit('team.remove', $courseId, $userId, 'was ' . $current, $by);
        return ['ok' => true, 'changed' => true];
    }

    /** Mirror the earliest-added lead into courses.instructor_id for older readers. */
    public static function syncLegacy(int $courseId): void
    {
        try {
            if (!Database::columnExists('courses', 'instructor_id')) return;
            $st = Database::pdo()->prepare("SELECT user_id FROM course_instructors WHERE course_id = ? AND role = 'lead' ORDER BY id LIMIT 1");
            $st->execute([$courseId]);
            $lead = $st->fetchColumn();
            Database::pdo()->prepare('UPDATE courses SET instructor_id = ? WHERE id = ?')
                ->execute([$lead === false ? null : (int) $lead, $courseId]);
        } catch (Throwable $e) { error_log('[team] sync instructor_id: ' . $e->getMessage()); }
    }

    /* ══ Internals ════════════════════════════════════════════════════════ */

    private static function authorised(int $courseId, int $by): bool
    {
        return $by === self::STUDIO || self::can($courseId, $by, 'manage_team');
    }

    private static function leadCount(int $courseId): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM course_instructors WHERE course_id = ? AND role = 'lead'");
        $st->execute([$courseId]);
        return (int) $st->fetchColumn();
    }

    private static function userExists(int $id): bool
    {
        if ($id <= 0) return false;
        $st = Database::pdo()->prepare('SELECT 1 FROM lms_users WHERE id = ?'); $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    private static function courseExists(int $id): bool
    {
        if ($id <= 0) return false;
        $st = Database::pdo()->prepare('SELECT 1 FROM courses WHERE id = ?'); $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    /** Who acted, as the audit trail names them: the account's email, or the Studio's own actor. */
    public static function actor(int $by): string
    {
        if ($by === self::STUDIO) return function_exists('av_admin_actor') ? av_admin_actor() : 'studio';
        $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?'); $st->execute([$by]);
        $e = (string) ($st->fetchColumn() ?: '');
        return $e !== '' ? $e : 'user#' . $by;
    }

    private static function audit(string $action, int $courseId, int $userId, string $detail, int $by): void
    {
        try { (new LmsRepository())->audit($action, 'course#' . $courseId . ' user#' . $userId, $detail, self::actor($by)); }
        catch (Throwable $e) { error_log('[team] audit: ' . $e->getMessage()); }
    }
}
