<?php
/**
 * lib/LmsRepository.php — modules, lessons, progress, access & membership.
 */
declare(strict_types=1);
require_once __DIR__ . '/Embeds.php';   // a lesson's video goes into a frame only through Embeds::iframeSrc()

final class LmsRepository
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    /** The lesson columns an outline needs: everything but the body, which a list never shows. */
    private const OUTLINE_COLS = 'l.id, l.module_id, l.course_id, l.slug, l.title, l.duration_min, l.is_preview, l.position, l.video_url, l.quiz_json, l.kind, l.weight, l.assignment_json, l.available_at';

    /** course_id => modules with lessons, for this request. Cleared by every write here. */
    private array $outline = [];

    /**
     * Modules (with their lessons) for a course: two queries whatever its
     * size, remembered for the rest of the request. A player page asks for
     * the outline four or five times (sidebar, position, next item, grades,
     * the certificate check); it used to be a query per module each time.
     * The lesson rows carry `available_at`, without which every access check
     * on an outline read a scheduled lesson as open.
     */
    public function curriculum(int $courseId): array
    {
        if (isset($this->outline[$courseId])) return $this->outline[$courseId];
        $mods = $this->db->prepare('SELECT * FROM modules WHERE course_id = ? ORDER BY position, id');
        $mods->execute([$courseId]);
        $modules = $mods->fetchAll();
        $ls = $this->db->prepare('SELECT ' . self::OUTLINE_COLS . ' FROM lessons l WHERE l.course_id = ? ORDER BY l.position, l.id');
        $ls->execute([$courseId]);
        $by = [];
        foreach ($ls->fetchAll() as $l) { $l['type'] = self::lessonType($l); $by[(int) $l['module_id']][] = $l; }
        foreach ($modules as &$m) $m['lessons'] = $by[(int) $m['id']] ?? [];
        unset($m);
        return $this->outline[$courseId] = $modules;
    }

    /** Forget remembered outlines (called by every write that changes one). */
    public function forgetOutlines(): void { $this->outline = []; }

    public function lessonCount(int $courseId): int
    {
        if (isset($this->outline[$courseId])) return count($this->orderedLessons($courseId));
        $s = $this->db->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?'); $s->execute([$courseId]);
        return (int) $s->fetchColumn();
    }

    public function lesson(int $courseId, string $slug): ?array
    {
        $s = $this->db->prepare('SELECT * FROM lessons WHERE course_id = ? AND slug = ?');
        $s->execute([$courseId, $slug]);
        return $s->fetch() ?: null;
    }

    /** Ordered lesson list (for prev/next + the "up next" card) — the outline, flattened. */
    public function orderedLessons(int $courseId): array
    {
        $out = [];
        foreach ($this->curriculum($courseId) as $m) { foreach ($m['lessons'] as $l) $out[] = $l; }
        return $out;
    }

    /**
     * Classify an item: video | reading | quiz | practice | assignment | discussion.
     * An explicit `kind` wins (an assignment or a discussion prompt is not
     * something the content can tell you); otherwise the content decides, and a
     * quiz marked ungraded is a practice quiz.
     */
    public static function lessonType(array $l): string
    {
        $k = (string) ($l['kind'] ?? '');
        if ($k === 'assignment' || $k === 'discussion') return $k;
        if (!empty($l['video_url'])) return 'video';
        if (!empty($l['quiz_json']) && trim((string) $l['quiz_json']) !== '') {
            $q = json_decode((string) $l['quiz_json'], true);
            return (is_array($q) && array_key_exists('graded', $q) && !$q['graded']) ? 'practice' : 'quiz';
        }
        return 'reading';
    }

    public function isMember(int $userId): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > ?) LIMIT 1");
        $s->execute([$userId, gmdate('Y-m-d H:i:s')]);
        return (bool) $s->fetchColumn();
    }

    public function isEnrolled(int $userId, int $courseId): bool
    {
        $s = $this->db->prepare('SELECT 1 FROM course_enrolment WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        return (bool) $s->fetchColumn();
    }
    /** Enrolled-learner count for a course (catalogue social proof). */
    public function enrolledCount(int $courseId): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM course_enrolment WHERE course_id = ?');
        $s->execute([$courseId]);
        return (int) $s->fetchColumn();
    }
    public function enrol(int $userId, int $courseId): void
    {
        $st = $this->db->prepare(Database::insertIgnore('course_enrolment', ['user_id', 'course_id']));
        $st->execute([$userId, $courseId]);
        if ($st->rowCount() > 0 && class_exists('Events')) Events::emit('enrollment.created', ['user_id' => $userId, 'course_id' => $courseId]);
    }

    /**
     * A learner's enrolled courses with progress + certificate state (for the
     * portal). Grouped: four queries plus one outline per course, where it was
     * five queries a course.
     */
    public function enrolledCourses(int $userId): array
    {
        $s = $this->db->prepare(
            "SELECT c.id, c.slug, c.title, c.cover_url, c.gradient
             FROM course_enrolment e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = ? ORDER BY e.created_at DESC"
        );
        $s->execute([$userId]);
        $rows = $s->fetchAll();
        if (!$rows) return [];
        $done = []; $last = []; $cert = [];
        $p = $this->db->prepare('SELECT course_id, lesson_id, completed_at FROM lesson_progress WHERE user_id = ?');
        $p->execute([$userId]);
        foreach ($p->fetchAll() as $r) {
            $cid = (int) $r['course_id'];
            $done[$cid][] = (int) $r['lesson_id'];
            if (!isset($last[$cid]) || strcmp((string) $r['completed_at'], $last[$cid]) > 0) $last[$cid] = (string) $r['completed_at'];
        }
        $c = $this->db->prepare('SELECT course_id FROM certificates WHERE user_id = ?');
        $c->execute([$userId]);
        foreach ($c->fetchAll() as $r) $cert[(int) $r['course_id']] = true;
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            $ordered = $this->orderedLessons($id);
            $ids = array_values(array_intersect($done[$id] ?? [], array_map(static fn($l) => (int) $l['id'], $ordered)));
            $total = count($ordered);
            $r['total'] = $total; $r['done'] = count($ids);
            $r['pct'] = $total ? (int) round(count($ids) / $total * 100) : 0;
            $r['complete'] = $total > 0 && count($ids) >= $total;
            $r['certified'] = isset($cert[$id]);
            $r['next'] = null;
            if (!$r['complete']) {
                foreach ($ordered as $l) { if (!in_array((int) $l['id'], $ids, true)) { $r['next'] = ['slug' => $l['slug'], 'title' => $l['title'], 'type' => $l['type'] ?? 'reading']; break; } }
            }
            // Most recent activity in this course — the freshest "continue learning" first.
            $r['last_active'] = $last[$id] ?? null;
        }
        return $rows;
    }

    /** First not-yet-completed lesson for a course (given the done id set), or null. */
    public function nextLesson(int $courseId, array $doneIds): ?array
    {
        foreach ($this->orderedLessons($courseId) as $l) {
            if (!in_array((int) $l['id'], $doneIds, true)) {
                return ['slug' => $l['slug'], 'title' => $l['title'], 'type' => $l['type'] ?? 'reading'];
            }
        }
        return null;
    }

    /** A learner's most recently edited notes, with course + lesson context (portal recap). */
    public function recentNotes(int $userId, int $limit = 4): array
    {
        $this->ensureNotes();
        $s = $this->db->prepare(
            "SELECT n.body, n.updated_at, l.title AS lesson_title, l.slug AS lesson_slug,
                    c.title AS course_title, c.slug AS course_slug
             FROM lesson_notes n
             JOIN lessons l ON l.id = n.lesson_id
             JOIN courses c ON c.id = n.course_id
             WHERE n.user_id = ? AND n.body <> ''
             ORDER BY n.updated_at DESC, l.id DESC LIMIT ?"
        );
        $s->bindValue(1, $userId, PDO::PARAM_INT);
        $s->bindValue(2, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /** Can this (maybe-null) user open this lesson? */
    /**
     * canAccess() for every lesson of an outline at once: lesson id => bool.
     * The course-level answer (membership, enrolment, grants, passes — the
     * part that queries) is worked out once; what differs per lesson (a
     * release date, a free preview) is not a query. Same rules, same order.
     */
    public function canAccessMany(?array $user, array $course, array $lessons): array
    {
        $teach = $user !== null && $this->teaches($user, $course);
        $whole = $this->canAccess($user, $course, ['is_preview' => 0]);
        $out = [];
        foreach ($lessons as $l) {
            $open = !class_exists('LmsDrip') || LmsDrip::lessonOpen($l) || $teach;
            $out[(int) $l['id']] = $open && (!empty($l['is_preview']) || $whole);
        }
        return $out;
    }

    public function canAccess(?array $user, array $course, array $lesson): bool
    {
        /* A scheduled lesson is shut until its date, whoever is asking —
           otherwise "available_at" is a label on a door that is not locked,
           and the email announcing the unlock arrives after the fact. Staff
           are exempt below, because somebody has to check it before it
           opens. */
        if (class_exists('LmsDrip') && !LmsDrip::lessonOpen($lesson)
            && !($user && $this->teaches($user, $course))) return false;
        if (!empty($lesson['is_preview'])) return true;             // previews always open
        $access = $course['access_type'] ?? 'open';
        if ($access === 'open') return true;                        // open courses: all lessons free
        if (!$user) return false;                                   // tracked/membership/paid need an account
        if ($this->teaches($user, $course)) return true;            // its own instructor, a coordinator or an admin
        if ($access === 'tracked') return true;                     // free but account-gated for tracking
        // A "member" is a paid Academy member OR an Afrovanguard org account (@afrovanguard.org.ng).
        $member = $this->isMember((int) $user['id']) || LmsAuth::isOrgMember($user);
        if ($access === 'membership') return $member;
        if ($access === 'paid') return $this->isEnrolled((int) $user['id'], (int) $course['id']) || $member;
        if ($access === 'restricted') {
            // Locked: only an explicit per-member grant, or holding the course's pass.
            if ($this->hasCourseAccess((int) $user['id'], (int) $course['id'])) return true;
            $pass = trim((string) ($course['pass_code'] ?? ''));
            return $pass !== '' && $this->hasActivePass((int) $user['id'], $pass);
        }
        return false;
    }

    /**
     * May this person see the whole course as its teacher? Coordinators and
     * admins run the Academy, so yes. An instructor teaches their OWN courses:
     * the role is not a pass to every paid or restricted course on the site.
     */
    public function teaches(array $user, array $course): bool
    {
        if (LmsAuth::atLeast($user, 'coordinator')) return true;
        return LmsAuth::canTeach($user) && (int) ($course['instructor_id'] ?? 0) > 0 && (int) $course['instructor_id'] === (int) ($user['id'] ?? 0);
    }

    /** A lesson with a quiz is completed by passing it, never by ticking it. */
    public function needsQuiz(array $lesson): bool { return $this->quiz($lesson) !== null; }

    /** GRADED quiz lessons in a course this learner has not passed yet (practice quizzes never block). */
    public function unpassedQuizzes(int $userId, int $courseId): int
    {
        $s = $this->db->prepare('SELECT DISTINCT q.lesson_id FROM quiz_attempts q JOIN lessons l ON l.id = q.lesson_id WHERE q.user_id = ? AND l.course_id = ? AND q.passed = 1');
        $s->execute([$userId, $courseId]);
        $passed = array_fill_keys(array_map('intval', array_column($s->fetchAll(), 'lesson_id')), true);
        $n = 0;
        foreach ($this->orderedLessons($courseId) as $l) {
            $q = $this->quiz($l);
            if ($q && $q['graded'] && !isset($passed[(int) $l['id']])) $n++;
        }
        return $n;
    }

    /* ── Restricted courses: per-member allowlist + named passes ──────────── */

    /** Does this member have an explicit grant to this course? */
    public function hasCourseAccess(int $userId, int $courseId): bool
    {
        if ($userId <= 0 || $courseId <= 0) return false;
        try {
            $s = $this->db->prepare('SELECT 1 FROM course_access WHERE user_id = ? AND course_id = ?');
            $s->execute([$userId, $courseId]);
            return (bool) $s->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /** Grant a member access to a restricted course (idempotent). */
    public function grantCourseAccess(int $courseId, int $userId, int $by = 0): bool
    {
        if ($courseId <= 0 || $userId <= 0) return false;
        $this->db->prepare(Database::insertIgnore('course_access', ['course_id', 'user_id', 'granted_by', 'created_at']))
            ->execute([$courseId, $userId, $by, gmdate('Y-m-d H:i:s')]);
        return true;
    }

    public function revokeCourseAccess(int $courseId, int $userId): bool
    {
        $this->db->prepare('DELETE FROM course_access WHERE course_id = ? AND user_id = ?')->execute([$courseId, $userId]);
        return true;
    }

    /** Members explicitly granted a course: [{id,name,email,created_at}]. */
    public function courseAccessList(int $courseId): array
    {
        $s = $this->db->prepare(
            'SELECT u.id, u.name, u.email, ca.created_at
             FROM course_access ca JOIN lms_users u ON u.id = ca.user_id
             WHERE ca.course_id = ? ORDER BY ca.id DESC'
        );
        $s->execute([$courseId]);
        return $s->fetchAll() ?: [];
    }

    /** Does the member hold an unexpired pass with this code? */
    public function hasActivePass(int $userId, string $code): bool
    {
        $code = trim($code);
        if ($userId <= 0 || $code === '') return false;
        try {
            $s = $this->db->prepare(
                "SELECT 1 FROM member_passes WHERE user_id = ? AND code = ?
                 AND (expires_at IS NULL OR expires_at = '' OR expires_at > ?)"
            );
            $s->execute([$userId, $code, gmdate('Y-m-d H:i:s')]);
            return (bool) $s->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /** Grant a member a pass by code (idempotent; refreshes label/expiry). */
    public function grantPass(int $userId, string $code, string $label = '', string $expiresAt = '', int $by = 0): bool
    {
        $code = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($code)));
        if ($userId <= 0 || $code === '') return false;
        $exp = trim($expiresAt) !== '' ? $expiresAt : null;
        $now = gmdate('Y-m-d H:i:s');
        $n = $this->db->prepare('UPDATE member_passes SET label = ?, expires_at = ?, granted_by = ? WHERE user_id = ? AND code = ?');
        $n->execute([$label, $exp, $by, $userId, $code]);
        if ($n->rowCount() === 0) {
            try { $this->db->prepare('INSERT INTO member_passes (user_id, code, label, granted_by, expires_at, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$userId, $code, $label, $by, $exp, $now]); }
            catch (Throwable $e) { /* raced */ }
        }
        return true;
    }

    public function revokePass(int $userId, string $code): bool
    {
        $this->db->prepare('DELETE FROM member_passes WHERE user_id = ? AND code = ?')->execute([$userId, trim($code)]);
        return true;
    }

    /** A member's passes: [{code,label,expires_at,created_at}]. */
    public function memberPasses(int $userId): array
    {
        try {
            $s = $this->db->prepare('SELECT code, label, expires_at, created_at FROM member_passes WHERE user_id = ? ORDER BY id DESC');
            $s->execute([$userId]);
            return $s->fetchAll() ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** Resolve a member by email (for admin grant-by-email). */
    public function userByEmail(string $email): ?array
    {
        $s = $this->db->prepare('SELECT id, name, email FROM lms_users WHERE email = ?');
        $s->execute([strtolower(trim($email))]);
        return $s->fetch() ?: null;
    }

    /** Decode a lesson's quiz, or null. Shape: {pass:int, questions:[{q,options[],answer}]} */
    /**
     * The quiz on a lesson, normalised, or null.
     *
     *   pass      1-100, the score that passes (default 70)
     *   graded    true = counts toward the grade and must be passed; false = practice
     *   attempts  0 = unlimited, else the most a learner may submit
     *   questions [{type: single|multi|truefalse, q, options[], answer: int|int[], explain}]
     *
     * Older quizzes carry none of the new keys: they read as graded, unlimited,
     * single-answer — exactly what they were.
     */
    public function quiz(array $lesson): ?array
    {
        if (empty($lesson['quiz_json'])) return null;
        $q = json_decode((string) $lesson['quiz_json'], true);
        if (!is_array($q) || empty($q['questions']) || !is_array($q['questions'])) return null;
        $qs = [];
        foreach ($q['questions'] as $item) {
            $n = self::normaliseQuestion(is_array($item) ? $item : []);
            if ($n) $qs[] = $n;
        }
        if (!$qs) return null;
        return [
            'pass'      => max(1, min(100, (int) ($q['pass'] ?? 70))),
            'graded'    => !array_key_exists('graded', $q) || (bool) $q['graded'],
            'attempts'  => max(0, min(99, (int) ($q['attempts'] ?? 0))),
            'questions' => $qs,
        ];
    }

    /** One question, made valid, or null when it cannot be. Shared with the Studio's save. */
    public static function normaliseQuestion(array $q): ?array
    {
        $type = in_array($q['type'] ?? '', ['single', 'multi', 'truefalse'], true) ? $q['type'] : 'single';
        $prompt = trim((string) ($q['q'] ?? ''));
        if ($prompt === '') return null;
        $opts = $type === 'truefalse' ? ['True', 'False']
              : array_values(array_filter(array_map(static fn($o) => trim((string) $o), (array) ($q['options'] ?? [])), static fn($o) => $o !== ''));
        if (count($opts) < 2) return null;
        $max = count($opts) - 1;
        if ($type === 'multi') {
            $ans = array_values(array_unique(array_filter(array_map('intval', (array) ($q['answer'] ?? [])), static fn($i) => $i >= 0 && $i <= $max)));
            sort($ans);
            if (!$ans) return null;
        } else {
            $ans = max(0, min($max, (int) (is_array($q['answer'] ?? null) ? ($q['answer'][0] ?? 0) : ($q['answer'] ?? 0))));
        }
        return ['type' => $type, 'q' => $prompt, 'options' => $opts, 'answer' => $ans, 'explain' => trim((string) ($q['explain'] ?? ''))];
    }

    /** How many times this learner has submitted this quiz, and their best score. */
    public function quizStats(int $userId, int $lessonId): array
    {
        $s = $this->db->prepare('SELECT COUNT(*) AS n, MAX(score) AS best, MAX(passed) AS passed FROM quiz_attempts WHERE user_id = ? AND lesson_id = ?');
        $s->execute([$userId, $lessonId]);
        $r = $s->fetch() ?: [];
        return ['used' => (int) ($r['n'] ?? 0), 'best' => $r['best'] !== null && ($r['n'] ?? 0) > 0 ? (int) $r['best'] : null, 'passed' => (int) ($r['passed'] ?? 0) === 1];
    }

    /**
     * Grade a submission. $answers[i] is an option index, or a list of them for
     * a multi-select question. Records the attempt.
     *
     * Feedback is per question (right or wrong, with the explanation); the right
     * ANSWER is only revealed on a practice quiz, once passed, or once the
     * attempts are spent — otherwise a retake is just copying the key out.
     * A practice quiz completes the item on any score; a graded one on a pass.
     */
    public function gradeQuiz(int $userId, array $lesson, array $answers): array
    {
        $quiz = $this->quiz($lesson);
        if (!$quiz) return ['ok' => false, 'error' => 'This lesson has no quiz.'];
        $stats = $this->quizStats($userId, (int) $lesson['id']);
        if ($quiz['attempts'] > 0 && $stats['used'] >= $quiz['attempts'])
            return ['ok' => false, 'code' => 'no_attempts', 'error' => 'You have used all ' . $quiz['attempts'] . ' attempts on this quiz.'];
        $total = count($quiz['questions']); $correct = 0; $marks = [];
        foreach ($quiz['questions'] as $i => $q) {
            $given = $answers[$i] ?? null;
            if ($q['type'] === 'multi') {
                $g = array_values(array_unique(array_map('intval', (array) ($given ?? []))));
                sort($g);
                $ok = $g === $q['answer'];
            } else {
                $ok = $given !== null && !is_array($given) && (int) $given === (int) $q['answer'];
            }
            if ($ok) $correct++;
            $marks[] = ['correct' => $ok, 'explain' => $q['explain']];
        }
        $score = $total ? (int) round($correct / $total * 100) : 0;
        $passed = $score >= (int) $quiz['pass'];
        $this->db->prepare('INSERT INTO quiz_attempts (user_id, lesson_id, score, passed) VALUES (?,?,?,?)')
            ->execute([$userId, (int) $lesson['id'], $score, $passed ? 1 : 0]);
        if ($passed || !$quiz['graded']) $this->markComplete($userId, $lesson);
        $used = $stats['used'] + 1;
        $left = $quiz['attempts'] > 0 ? max(0, $quiz['attempts'] - $used) : null;
        $reveal = !$quiz['graded'] || $passed || $stats['passed'] || $left === 0;
        foreach ($quiz['questions'] as $i => $q) $marks[$i]['answer'] = $reveal ? $q['answer'] : null;
        return ['ok' => true, 'score' => $score, 'passed' => $passed, 'correct' => $correct, 'total' => $total,
                'pass' => (int) $quiz['pass'], 'graded' => $quiz['graded'], 'attempts_used' => $used,
                'attempts_left' => $left, 'best' => max($score, (int) ($stats['best'] ?? 0)), 'marks' => $marks];
    }

    public function markComplete(int $userId, array $lesson): void
    {
        $this->db->prepare(Database::insertIgnore('lesson_progress', ['user_id', 'lesson_id', 'course_id']))
            ->execute([$userId, (int) $lesson['id'], (int) $lesson['course_id']]);
    }
    public function unmark(int $userId, int $lessonId): void
    {
        $this->db->prepare('DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?')->execute([$userId, $lessonId]);
    }

    public function progress(int $userId, int $courseId): array
    {
        $total = $this->lessonCount($courseId);    // free once the outline is in hand
        $s = $this->db->prepare('SELECT lesson_id FROM lesson_progress WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        $done = array_map('intval', array_column($s->fetchAll(), 'lesson_id'));
        $pct = $total ? (int) round(count($done) / $total * 100) : 0;
        return ['total' => $total, 'completed' => count($done), 'pct' => $pct, 'ids' => $done, 'complete' => $total > 0 && count($done) >= $total];
    }

    /* ── Lesson notes (cross-device for signed-in learners) ── */
    private static bool $notesEnsured = false;
    private function ensureNotes(): void
    {
        if (self::$notesEnsured) return;
        self::$notesEnsured = true;
        // Provisioned from the schema files on MySQL/Postgres; this is only a
        // safety net for older SQLite databases created before this table existed.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS lesson_notes (
                   user_id INTEGER NOT NULL, lesson_id INTEGER NOT NULL, course_id INTEGER NOT NULL,
                   body TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                   PRIMARY KEY (user_id, lesson_id)
                 );"
            );
        }
    }

    public function getNote(int $userId, int $lessonId): string
    {
        $this->ensureNotes();
        $s = $this->db->prepare('SELECT body FROM lesson_notes WHERE user_id = ? AND lesson_id = ?');
        $s->execute([$userId, $lessonId]);
        $v = $s->fetchColumn();
        return $v === false ? '' : (string) $v;
    }

    /** Upsert a note; an empty body removes it. Portable (no ON CONFLICT). */
    public function saveNote(int $userId, int $lessonId, int $courseId, string $body): void
    {
        $this->ensureNotes();
        $body = mb_substr($body, 0, 20000);
        $now = gmdate('Y-m-d H:i:s');
        $ex = $this->db->prepare('SELECT 1 FROM lesson_notes WHERE user_id = ? AND lesson_id = ?');
        $ex->execute([$userId, $lessonId]);
        $exists = (bool) $ex->fetchColumn();
        if (trim($body) === '') {
            if ($exists) $this->db->prepare('DELETE FROM lesson_notes WHERE user_id = ? AND lesson_id = ?')->execute([$userId, $lessonId]);
            return;
        }
        if ($exists) {
            $this->db->prepare('UPDATE lesson_notes SET body = ?, course_id = ?, updated_at = ? WHERE user_id = ? AND lesson_id = ?')
                ->execute([$body, $courseId, $now, $userId, $lessonId]);
        } else {
            $this->db->prepare('INSERT INTO lesson_notes (user_id, lesson_id, course_id, body, updated_at) VALUES (?,?,?,?,?)')
                ->execute([$userId, $lessonId, $courseId, $body, $now]);
        }
    }

    /** Every note a learner has for a course, joined to lesson titles, in curriculum order. */
    public function notesForCourse(int $userId, int $courseId): array
    {
        $this->ensureNotes();
        $s = $this->db->prepare(
            "SELECT n.body, l.title, l.slug
             FROM lesson_notes n JOIN lessons l ON l.id = n.lesson_id JOIN modules m ON m.id = l.module_id
             WHERE n.user_id = ? AND n.course_id = ? AND n.body <> ''
             ORDER BY m.position, m.id, l.position, l.id"
        );
        $s->execute([$userId, $courseId]);
        return $s->fetchAll();
    }

    /* ── Authoring: modules ── */
    public function addModule(int $courseId, string $title): int
    {
        $this->outline = [];
        $pos = (int) $this->db->query('SELECT COALESCE(MAX(position),-1)+1 FROM modules WHERE course_id = ' . (int) $courseId)->fetchColumn();
        $st = $this->db->prepare('INSERT INTO modules (course_id, title, position) VALUES (?,?,?)');
        $st->execute([$courseId, $title, $pos]);
        return (int) $this->db->lastInsertId();
    }
    public function renameModule(int $id, string $title): void { $this->outline = []; $this->db->prepare('UPDATE modules SET title = ? WHERE id = ?')->execute([$title, $id]); }
    /** A module's overview: what it covers and its learning objectives (newline-separated). */
    public function describeModule(int $id, string $summary, string $objectives): void
    {
        $this->outline = [];
        $obj = implode("\n", array_slice(array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $objectives) ?: []))), 0, 12));
        $this->db->prepare('UPDATE modules SET summary = ?, objectives = ? WHERE id = ?')->execute([mb_substr(trim($summary), 0, 1000), $obj, $id]);
    }
    public function deleteModule(int $id): void
    {
        $this->outline = [];
        $this->db->prepare('DELETE FROM lessons WHERE module_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM modules WHERE id = ?')->execute([$id]);
    }
    public function moduleCourse(int $id): ?int
    {
        $s = $this->db->prepare('SELECT course_id FROM modules WHERE id = ?'); $s->execute([$id]);
        $v = $s->fetchColumn(); return $v === false ? null : (int) $v;
    }

    /* ── Authoring: lessons ── */
    public function lessonById(int $id): ?array { $s = $this->db->prepare('SELECT * FROM lessons WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

    public function saveLesson(array $d): int
    {
        $this->outline = [];
        $module = $this->lessonModule((int) $d['module_id']);
        if (!$module) throw new RuntimeException('Module not found');
        $courseId = (int) $module['course_id'];
        $slug = slugify($d['slug'] ?: $d['title']);
        // ensure unique slug within the course
        $chk = $this->db->prepare('SELECT id FROM lessons WHERE course_id = ? AND slug = ? AND id <> ?');
        $base = $slug; $i = 2;
        while (true) { $chk->execute([$courseId, $slug, (int) ($d['id'] ?? 0)]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $i++; }
        $fields = [
            'module_id' => (int) $d['module_id'], 'course_id' => $courseId, 'slug' => $slug,
            'title' => $d['title'], 'body_html' => $d['body_html'] ?? '', 'video_url' => (($v = trim((string) ($d['video_url'] ?? ''))) !== '' ? (Embeds::lessonVideo($v)['src'] ?? null) : null),
            'transcript' => mb_substr(trim((string) ($d['transcript'] ?? '')), 0, 60000),
            'captions_url' => ($cap = trim((string) ($d['captions_url'] ?? ''))) !== '' ? Embeds::captionsFile($cap) : null,
            'resources_json' => self::normaliseResources((array) ($d['resources'] ?? [])),
            'duration_min' => (int) ($d['duration_min'] ?? 0), 'is_preview' => !empty($d['is_preview']) ? 1 : 0,
            'quiz_json' => isset($d['quiz_json']) && $d['quiz_json'] !== '' ? $d['quiz_json'] : null,
            'kind' => in_array($d['kind'] ?? '', ['assignment', 'discussion'], true) ? $d['kind'] : '',
            'weight' => max(1, min(100, (int) ($d['weight'] ?? 1))),
            'assignment_json' => ($d['kind'] ?? '') === 'assignment'
                ? json_encode(['pass' => max(1, min(100, (int) ($d['assignment_pass'] ?? 70)))]) : null,
            'position' => (int) ($d['position'] ?? 0), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($d['id'])) {
            $set = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($fields)));
            $this->db->prepare("UPDATE lessons SET $set WHERE id = :id")->execute($fields + ['id' => (int) $d['id']]);
            return (int) $d['id'];
        }
        $fields['position'] = (int) $this->db->query('SELECT COALESCE(MAX(position),-1)+1 FROM lessons WHERE module_id = ' . (int) $d['module_id'])->fetchColumn();
        $cols = implode(',', array_keys($fields)); $ph = implode(',', array_map(fn($k) => ":$k", array_keys($fields)));
        $this->db->prepare("INSERT INTO lessons ($cols) VALUES ($ph)")->execute($fields);
        return (int) $this->db->lastInsertId();
    }
    /** Up to 20 resources, each a title and a link we allow; JSON, or null for none. */
    public static function normaliseResources(array $list): ?string
    {
        $out = [];
        foreach ($list as $r) {
            if (!is_array($r)) continue;
            $url = Embeds::resourceUrl((string) ($r['url'] ?? ''));
            if ($url === null) continue;
            $title = mb_substr(trim((string) ($r['title'] ?? '')), 0, 160);
            $out[] = ['title' => $title !== '' ? $title : (basename((string) parse_url($url, PHP_URL_PATH)) ?: 'Resource'), 'url' => $url];
            if (count($out) >= 20) break;
        }
        return $out ? json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    }

    /** A lesson's resources, decoded and re-checked on the way out. */
    public static function resources(array $lesson): array
    {
        $r = json_decode((string) ($lesson['resources_json'] ?? ''), true);
        if (!is_array($r)) return [];
        return array_values(array_filter(array_map(static fn($x) => is_array($x) && ($u = Embeds::resourceUrl((string) ($x['url'] ?? ''))) !== null
            ? ['title' => (string) ($x['title'] ?? 'Resource'), 'url' => $u] : null, $r)));
    }

    private function lessonModule(int $moduleId): ?array { $s = $this->db->prepare('SELECT * FROM modules WHERE id = ?'); $s->execute([$moduleId]); return $s->fetch() ?: null; }
    public function deleteLesson(int $id): void { $this->outline = []; $this->db->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]); }

    /* ── Management: reordering (positions 0..n in the given order) ── */
    public function reorderModules(int $courseId, array $orderedIds): void
    {
        $this->outline = [];
        $u = $this->db->prepare('UPDATE modules SET position = ? WHERE id = ? AND course_id = ?');
        foreach (array_values($orderedIds) as $pos => $id) { $u->execute([$pos, (int) $id, $courseId]); }
    }
    public function reorderLessons(int $moduleId, array $orderedIds): void
    {
        $this->outline = [];
        $u = $this->db->prepare('UPDATE lessons SET position = ? WHERE id = ? AND module_id = ?');
        foreach (array_values($orderedIds) as $pos => $id) { $u->execute([$pos, (int) $id, $moduleId]); }
    }

    /* ── Management: per-learner interventions ── */
    public function unenrol(int $userId, int $courseId): void
    {
        $this->db->prepare('DELETE FROM course_enrolment WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
        $this->db->prepare('DELETE FROM lesson_progress WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
    }
    public function resetProgress(int $userId, int $courseId): void
    {
        $this->db->prepare('DELETE FROM lesson_progress WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
        $this->db->prepare('DELETE FROM quiz_attempts WHERE user_id = ? AND lesson_id IN (SELECT id FROM lessons WHERE course_id = ?)')->execute([$userId, $courseId]);
        $this->db->prepare('DELETE FROM assignment_submissions WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
    }
    public function revokeCertificate(int $userId, int $courseId): void
    {
        $this->db->prepare('DELETE FROM certificates WHERE user_id = ? AND course_id = ?')->execute([$userId, $courseId]);
    }
    /** Admin manual issue (e.g. offline cohort) — bypasses the completion check. */
    public function adminIssueCertificate(int $userId, int $courseId): ?array
    {
        if ($cur = $this->getCertificate($userId, $courseId)) return $cur;
        $serial = 'AV-' . strtoupper(bin2hex(random_bytes(6))) . '-' . date('Y');
        $this->db->prepare(Database::insertIgnore('certificates', ['user_id', 'course_id', 'serial']))->execute([$userId, $courseId, $serial]);
        return $this->getCertificate($userId, $courseId);
    }
    /** Counts used to decide whether a course can be safely hard-deleted. */
    public function courseUsage(int $courseId): array
    {
        $c = fn($sql) => (int) ($this->db->query($sql)->fetchColumn() ?: 0);
        return [
            'enrolments'   => $c('SELECT COUNT(*) FROM course_enrolment WHERE course_id = ' . $courseId),
            'certificates' => $c('SELECT COUNT(*) FROM certificates WHERE course_id = ' . $courseId),
        ];
    }

    /* ── Certificates ── */
    public function issueCertificate(int $userId, int $courseId): ?array
    {
        if (!$this->requirementsMet($userId, $courseId)) return null;
        $cur = $this->getCertificate($userId, $courseId);
        if ($cur) return $cur;
        // Unguessable serial (48 random bits) — public verify.php must not be brute-forceable.
        $serial = 'AV-' . strtoupper(bin2hex(random_bytes(6))) . '-' . date('Y');
        $this->db->prepare(Database::insertIgnore('certificates', ['user_id', 'course_id', 'serial']))->execute([$userId, $courseId, $serial]);
        return $this->getCertificate($userId, $courseId);
    }
    public function getCertificate(int $userId, int $courseId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM certificates WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]); return $s->fetch() ?: null;
    }
    /* ── Payments ── */
    /** Idempotently ensure the payments.months column exists (added post-release). */
    private static bool $monthsEnsured = false;
    private function ensurePaymentsMonths(): void
    {
        if (self::$monthsEnsured) return;
        self::$monthsEnsured = true;
        try { $this->db->exec("ALTER TABLE payments ADD COLUMN months INTEGER NOT NULL DEFAULT 12"); }
        catch (\Throwable $e) { /* column already present */ }
    }

    public function createPayment(int $userId, string $kind, ?int $courseId, int $amountKobo, string $reference, string $provider = 'paystack', int $months = 12): void
    {
        $this->ensurePaymentsMonths();
        $this->db->prepare('INSERT INTO payments (reference, user_id, provider, kind, course_id, amount_kobo, months) VALUES (?,?,?,?,?,?,?)')
            ->execute([$reference, $userId, $provider, $kind, $courseId, $amountKobo, max(1, $months)]);
    }
    public function paymentByRef(string $reference): ?array
    {
        $s = $this->db->prepare('SELECT * FROM payments WHERE reference = ?'); $s->execute([$reference]);
        return $s->fetch() ?: null;
    }
    /**
     * Mark a verified payment paid (idempotent) and grant the access it bought.
     * $paidKobo MUST be the amount Paystack confirmed was actually paid: access is
     * refused unless it covers the amount owed. Enforcing this here (not just in
     * the caller) closes the underpayment bypass where the webhook granted access
     * without checking the amount.
     */
    public function finalizePayment(string $reference, ?int $paidKobo = null, ?string $currency = null): bool
    {
        $p = $this->paymentByRef($reference);
        if (!$p) return false;
        if ($p['status'] === 'paid') return true;       // already granted (idempotent)
        /* An amount is only an amount in its currency. Every price here is in
           kobo; 5,000,000 of some other currency's minor units is not ₦50,000.
           The NGV fee paths always refused non-NGN; the course and membership
           grant did not. An empty currency is a gateway that did not say (the
           CACENTRE checkout reports none on some routes), which is not a
           different currency. */
        if ($currency !== null && !in_array(strtoupper($currency), ['NGN', ''], true)) {
            error_log('[lms] finalizePayment refused for ' . $reference . ': paid in ' . $currency . ', priced in NGN');
            return false;
        }
        /* ONLY WHAT THIS METHOD CAN ACTUALLY GRANT.
           Without the allow-list, a kind with no branch below — an NGV fee,
           say — was marked paid and granted nothing: the row is consumed, so
           whatever really owns that kind never posts it, and the money
           vanishes between two handlers that each think the other had it. */
        if (!in_array((string) $p['kind'], ['course', 'membership'], true)) {
            error_log('[lms] finalizePayment declined ' . $reference . ': "' . (string) $p['kind']
                . '" is not a kind this grants. It belongs to whatever owns that kind.');
            return false;
        }
        if ($paidKobo === null || (int) $paidKobo < (int) $p['amount_kobo']) {
            error_log('[lms] finalizePayment refused for ' . $reference . ': paid '
                . var_export($paidKobo, true) . ' kobo < owed ' . (int) $p['amount_kobo']);
            return false;
        }
        /* ONE CLAIM, ONE GRANT. The webhook, the browser's return and the
           cron sweep can all arrive for the same reference at once, and each
           read 'pending' above. The update used to be unconditional, so every
           one of them went on to grantMembership — and a renewal extends from
           the later of now or the paid-through date, so one payment bought two
           years. The status test is in the UPDATE now, and only the caller
           whose UPDATE changed the row grants; the others see it already paid.
           The claim and the grant commit together, so a grant that throws does
           not leave a row marked paid with nothing behind it. */
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("UPDATE payments SET status='paid', paid_at=? WHERE reference=? AND status <> 'paid'");
            $st->execute([gmdate('Y-m-d H:i:s'), $reference]);
            if ($st->rowCount() !== 1) {
                if ($own) $this->db->commit();
                return true;                            // somebody else claimed it: paid, and granted once, by them
            }
            if ($p['kind'] === 'course' && $p['course_id']) {
                $this->enrol((int) $p['user_id'], (int) $p['course_id']);
            } elseif ($p['kind'] === 'membership') {
                $this->grantMembership((int) $p['user_id'], (int) ($p['months'] ?? 12) ?: 12);
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        /* Mail after the commit: a slow mailer must not hold the write lock. */
        $user = $this->userRow((int) $p['user_id']);
        if ($user && class_exists('Notify')) {
            if ($p['kind'] === 'course' && $p['course_id']) {
                $c = $this->courseRow((int) $p['course_id']);
                if ($c) Notify::enrolled($user, $c);
            } elseif ($p['kind'] === 'membership') {
                Notify::membership($user);
            }
        }
        return true;
    }
    public function grantMembership(int $userId, int $months = 12): void
    {
        // Renewals extend from the LATER of now or the member's current paid-through
        // date, so paying dues early (or twice) never forfeits time already paid for.
        /* UTC, as isMember() compares and SQLite's datetime('now') writes. This
           used date() — local time — so on a server set to Lagos every paid
           membership ended an hour after the date it showed.
           And from Membership::nextBase, the one answer to "where do these
           months begin": a member behind on dues pays the OLDEST month owed
           first. Extending from today forgave every month between their
           lapse and the day they paid. */
        $base = Membership::nextBase($userId);
        $exp = gmdate('Y-m-d H:i:s', (int) strtotime("+$months months", $base));
        $this->db->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at) VALUES (?, 'member', 'active', ?, ?)")
            ->execute([$userId, gmdate('Y-m-d H:i:s', min($base, time())), $exp]);
    }

    /* grantMembershipByEmail() was here: "extend a membership by N months,
       found by email". The dues webhook was its only caller, and it is what
       made a renewal untraceable — a month granted with no reference to be
       idempotent on, no amount checked, no payments row and no audit. The
       renewal goes through lib/PaymentWebhook now, which writes the row and
       finalises it like every other dues charge. The method is gone rather
       than left unused, because an email and a number is all it takes to call
       it and it would grant the month again. */

    /** The member's most recent ACTIVE membership row (lifetime first, then latest expiry).
     *  Active only: a cancelled lifetime row used to win this ordering, so a
     *  member whose membership the office had ended read as "lifetime" here. */
    public function latestMembership(int $userId): ?array
    {
        $s = $this->db->prepare(
            "SELECT * FROM memberships WHERE user_id = ? AND status = 'active'
             ORDER BY (expires_at IS NULL) DESC, expires_at DESC, id DESC LIMIT 1"
        );
        $s->execute([$userId]);
        return $s->fetch() ?: null;
    }

    /**
     * Membership-dues status for the member dashboard. "Dues" are the annual
     * membership fee (AV_MEMBERSHIP_NGN). Returns a render-ready shape:
     *   state: active | due_soon | overdue | none
     */
    public function duesStatus(int $userId): array
    {
        $annualNgn  = Membership::annual();
        $monthlyNgn = Membership::monthly();
        $amountNgn = $annualNgn;
        $m = $this->latestMembership($userId);
        $paidThrough = $m['expires_at'] ?? null;
        $lifetime = $m && empty($paidThrough);

        $daysLeft = null;
        if ($paidThrough) {
            $ts = strtotime((string) $paidThrough . ' UTC');   // stored in UTC
            if ($ts) $daysLeft = (int) floor(($ts - time()) / 86400);
        }

        if ($lifetime)               $state = 'active';
        elseif ($daysLeft === null)  $state = 'none';     // never paid dues
        elseif ($daysLeft < 0)       $state = 'overdue';  // lapsed
        elseif ($daysLeft <= 30)     $state = 'due_soon'; // within renewal window
        else                         $state = 'active';

        $lastPaid = null;
        $lp = $this->db->prepare(
            "SELECT paid_at FROM payments
             WHERE user_id = ? AND kind = 'membership' AND status = 'paid'
             ORDER BY paid_at DESC, id DESC LIMIT 1"
        );
        $lp->execute([$userId]);
        if ($row = $lp->fetch()) $lastPaid = $row['paid_at'] ?? null;

        // Total dues ever paid (sum of confirmed membership payments), plus a count.
        $tp = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_kobo), 0) AS kobo, COUNT(*) AS n
             FROM payments WHERE user_id = ? AND kind = 'membership' AND status = 'paid'"
        );
        $tp->execute([$userId]);
        $totRow = $tp->fetch() ?: ['kobo' => 0, 'n' => 0];
        $totalPaidNgn = (int) round(((int) $totRow['kobo']) / 100);

        return [
            'amount_ngn'    => $amountNgn,        // annual (kept for back-compat)
            'annual_ngn'    => $annualNgn,
            'monthly_ngn'   => $monthlyNgn,
            'currency'      => 'NGN',
            'period'        => 'year',
            'state'         => $state,
            'lifetime'      => $lifetime,
            'paid_through'  => $paidThrough ? gmdate('c', (int) strtotime((string) $paidThrough . ' UTC')) : null,
            'days_left'     => $daysLeft,
            'last_paid_at'  => $lastPaid ? gmdate('c', (int) strtotime((string) $lastPaid)) : null,
            'total_paid_ngn' => $totalPaidNgn,   // cumulative dues contributed
            'payments_count' => (int) $totRow['n'],
            /* What they are behind by, and why they owe at all — the same
               figure the office sees (Membership::arrears). */
            'arrears'       => Membership::arrears($userId),
            /* Can money be taken AT ALL — by us or on our behalf — not "do
               we hold keys". The narrow question hid the Pay button on a
               site whose dues CACENTRE can collect perfectly well. */
            'payable'       => class_exists('Payments') && Payments::canCollect(),
        ];
    }
    /** Mark a payment settled without granting anything — the owner of the kind already did. */
    public function markPaymentPaid(string $reference): void
    {
        $this->db->prepare("UPDATE payments SET status='paid', paid_at=? WHERE reference=? AND status<>'paid'")
            ->execute([gmdate('Y-m-d H:i:s'), $reference]);
    }

    /**
     * Finish the payments CACENTRE took that nobody came back to tell us about.
     *
     * ── WHY THIS IS NEEDED ON THE HOSTED ROUTE AND NOT THE DIRECT ONE ──────
     *
     * Our own Paystack has TWO confirmations: the payer returning, and a
     * webhook that is retried until acknowledged. CACENTRE's checkout has
     * only the first — it is a pull API, so nothing is pushed to us. A payer
     * who pays and then closes the tab is left with their money taken and
     * their account saying they still owe it, which is the single worst
     * state this system can be in.
     *
     * So: ask about the ones still open. It READS — a status call cannot
     * take money or grant anything by itself — and every finaliser it calls
     * is idempotent on the reference, so a sweep that overlaps with the
     * payer's return does what one of them alone would do.
     *
     * Bounded to a week because a hosted charge expires: asking forever
     * about one that will never be paid turns a tidy sweep into a slow one.
     *
     * @return array{asked: int, settled: int, pending: int}
     */
    public function sweepHostedPayments(int $max = 50): array
    {
        $out = ['asked' => 0, 'settled' => 0, 'pending' => 0];
        if (!class_exists('Payments') || !class_exists('CfisCheckout') || !CfisCheckout::configured()) {
            return $out;
        }

        $since = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $st = $this->db->prepare(
            "SELECT reference, kind, user_id, amount_kobo FROM payments
              WHERE provider = 'cfis' AND status <> 'paid' AND created_at >= ?
              ORDER BY id LIMIT " . max(1, min(200, $max))
        );
        $st->execute([$since]);

        foreach ($st->fetchAll() as $row) {
            $ref = (string) $row['reference'];
            $out['asked']++;

            $v = Payments::verifyBy('cfis', $ref);
            if (empty($v['paid'])) { $out['pending']++; continue; }

            $minor = (int) ($v['amount_minor'] ?? 0);

            /* Dispatched by kind, because only the owner of a kind knows what
               paying for it means. An NGV fee posts to the fee ledger; a
               course grants access. One handler doing both would be one
               handler getting one of them wrong. */
            if ((string) $row['kind'] === 'ngv') {
                if (!class_exists('NgvLedger')) { $out['pending']++; continue; }
                $r = NgvLedger::payOnline(
                    (int) $row['user_id'], $ref, (int) round($minor / 100),
                    ['method' => 'card', 'note' => 'Paid online by card (CACENTRE checkout)']
                );
                if (empty($r['ok'])) { $out['pending']++; continue; }
                $this->markPaymentPaid($ref);
                $out['settled']++;
                continue;
            }

            if ($this->finalizePayment($ref, $minor, (string) ($v['currency'] ?? ''))) {
                $out['settled']++;
            } else {
                $out['pending']++;
            }
        }

        return $out;
    }

    private function userRow(int $id): ?array { $s = $this->db->prepare('SELECT * FROM lms_users WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
    private function courseRow(int $id): ?array { $s = $this->db->prepare('SELECT * FROM courses WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

    /**
     * Called after a lesson is completed. If the whole course is now done,
     * issues the certificate (once) and reports whether this was the first
     * time — so the caller can send a single completion email.
     * Returns ['complete'=>bool, 'newly'=>bool, 'cert'=>?array].
     */
    public function completeIfDone(int $userId, int $courseId): array
    {
        /* Every lesson ticked AND every quiz passed: a certificate says the
           learner was assessed, so a progress row written for a quiz lesson
           without a pass (an old tick, a direct request) does not earn one. */
        if (!$this->requirementsMet($userId, $courseId))
            return ['complete' => false, 'newly' => false, 'cert' => null];
        $newly = !$this->getCertificate($userId, $courseId);
        $cert = $this->issueCertificate($userId, $courseId);
        return ['complete' => true, 'newly' => (bool) ($newly && $cert), 'cert' => $cert];
    }

    /**
     * What a certificate certifies: every item done, and a passing grade —
     * every graded quiz and assignment passed and the weighted grade at or
     * above the course's pass mark. One answer, used by the issue path, the
     * completion check and the grades page alike.
     */
    public function requirementsMet(int $userId, int $courseId): bool
    {
        if (!$this->progress($userId, $courseId)['complete']) return false;
        $course = $this->courseRow($courseId);
        return $course !== null && (new LmsCourseware($this->db, $this))->grades($userId, $course)['passed'];
    }

    /* ── Instructors ── */
    public function findUserByEmail(string $email): ?array
    {
        $s = $this->db->prepare('SELECT * FROM lms_users WHERE email = ?'); $s->execute([strtolower(trim($email))]);
        return $s->fetch() ?: null;
    }
    public function promoteToInstructor(int $userId): void
    {
        $this->db->prepare("UPDATE lms_users SET role='instructor' WHERE id = ? AND role='learner'")->execute([$userId]);
    }
    public function instructorName(?int $userId): ?string
    {
        if (!$userId) return null;
        $s = $this->db->prepare('SELECT name FROM lms_users WHERE id = ?'); $s->execute([$userId]);
        $v = $s->fetchColumn(); return $v === false ? null : (string) $v;
    }

    /** Courses an instructor owns (any status), with light stats. */
    public function coursesForInstructor(int $userId): array
    {
        $s = $this->db->prepare("SELECT id, slug, title, status, access_type, price_ngn FROM courses WHERE instructor_id = ? ORDER BY sort, title");
        $s->execute([$userId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) { $r['stats'] = $this->courseStats((int) $r['id']); }
        return $rows;
    }

    public function courseStats(int $courseId): array
    {
        $total = $this->lessonCount($courseId);
        $enrolled = (int) $this->db->query('SELECT COUNT(*) FROM course_enrolment WHERE course_id = ' . (int) $courseId)->fetchColumn();
        $completed = (int) $this->db->query('SELECT COUNT(*) FROM certificates WHERE course_id = ' . (int) $courseId)->fetchColumn();
        // average completion across enrolled learners
        $avg = 0;
        if ($enrolled && $total) {
            $s = $this->db->prepare('SELECT COUNT(*) FROM lesson_progress WHERE course_id = ?'); $s->execute([$courseId]);
            $doneRows = (int) $s->fetchColumn();
            $avg = (int) round(min(100, $doneRows / ($enrolled * $total) * 100));
        }
        return ['lessons' => $total, 'enrolled' => $enrolled, 'completed' => $completed, 'avg_pct' => $avg];
    }

    /** Per-learner roster for one course (enrolled learners + their progress). */
    public function roster(int $courseId, int $limit = 300): array
    {
        $total = $this->lessonCount($courseId);
        $s = $this->db->prepare(
            "SELECT u.id, u.name, u.email, e.created_at AS enrolled_at,
                    (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = u.id AND lp.course_id = e.course_id) AS done,
                    (SELECT MAX(completed_at) FROM lesson_progress lp WHERE lp.user_id = u.id AND lp.course_id = e.course_id) AS last_active,
                    (SELECT 1 FROM certificates c WHERE c.user_id = u.id AND c.course_id = e.course_id) AS certified
             FROM course_enrolment e JOIN lms_users u ON u.id = e.user_id
             WHERE e.course_id = ? ORDER BY done DESC, u.name LIMIT ?"
        );
        $s->bindValue(1, $courseId, PDO::PARAM_INT); $s->bindValue(2, $limit, PDO::PARAM_INT); $s->execute();
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            $r['done'] = (int) $r['done'];
            $r['pct'] = $total ? (int) round(min(100, $r['done'] / $total * 100)) : 0;
            $r['certified'] = (bool) $r['certified'];
        }
        return $rows;
    }

    /**
     * Per-lesson completion funnel for a course: how many enrolled learners
     * have completed each lesson, in curriculum order. Reveals where learners
     * drop off. Returns [{title, module, done, pct}] (pct of enrolled).
     */
    public function lessonFunnel(int $courseId): array
    {
        $enrolled = (int) $this->db->query('SELECT COUNT(*) FROM course_enrolment WHERE course_id = ' . (int) $courseId)->fetchColumn();
        $s = $this->db->prepare(
            "SELECT l.id, l.title, m.title AS module,
                    (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.lesson_id = l.id) AS done
             FROM lessons l JOIN modules m ON m.id = l.module_id
             WHERE l.course_id = ? ORDER BY m.position, m.id, l.position, l.id"
        );
        $s->execute([$courseId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            $r['done'] = (int) $r['done'];
            $r['pct'] = $enrolled ? (int) round(min(100, $r['done'] / $enrolled * 100)) : 0;
        }
        return $rows;
    }

    public function ownsCourse(int $userId, string $slug): ?array
    {
        $s = $this->db->prepare('SELECT * FROM courses WHERE slug = ? AND instructor_id = ?');
        $s->execute([$slug, $userId]);
        return $s->fetch() ?: null;
    }

    /* ── Member administration (Studio) ── */

    /** Lazily ensure the lightweight admin audit trail exists. SQLite auto-creates
     *  it; on MySQL/Postgres the table is provisioned from the schema files, so the
     *  SQLite-only DDL is skipped there. */
    public function ensureAudit(): void
    {
        static $done = false; if ($done) return;
        $done = true;
        // lms_audit is in db/schema.sql, so MySQL/Postgres get it from the applied
        // schema file. This lazy create is only a safety net for older SQLite DBs.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS lms_audit (
                   id INTEGER PRIMARY KEY AUTOINCREMENT,
                   actor TEXT NOT NULL DEFAULT 'admin',
                   action TEXT NOT NULL,
                   target TEXT NOT NULL DEFAULT '',
                   detail TEXT NOT NULL DEFAULT '',
                   ip TEXT NOT NULL DEFAULT '',
                   created_at TEXT NOT NULL DEFAULT (datetime('now'))
                 );"
            );
        }
        // Additive: older audit tables (any driver) gain the ip column.
        try { if (class_exists('Database') && Database::tableExists('lms_audit') && !Database::columnExists('lms_audit', 'ip')) {
            $this->db->exec("ALTER TABLE lms_audit ADD COLUMN ip TEXT NOT NULL DEFAULT ''");
        } } catch (Throwable $e) { /* best-effort */ }
    }
    /**
     * Append an immutable audit record for a core admin action. Captures the
     * actor (admin identity) and the proxy-validated client IP automatically, so
     * every state-changing action is attributable. Best-effort: never throws.
     */
    /**
     * Who did it. See AdminAudit::actor() — the same reasoning, and the
     * same resolution, because these two write to the same table and a
     * trail that names the person through one door and "admin" through
     * the other is no better than one that names nobody.
     */
    public function audit(string $action, string $target = '', string $detail = '', ?string $actor = null): void
    {
        $actor = trim((string) $actor);
        if ($actor === '' || $actor === 'admin') {
            $actor = function_exists('av_admin_actor') ? av_admin_actor() : 'admin';
        }
        $this->ensureAudit();
        $ip = function_exists('av_client_ip') ? av_client_ip() : '';
        try {
            $this->db->prepare("INSERT INTO lms_audit (actor, action, target, detail, ip) VALUES (?,?,?,?,?)")
                ->execute([$actor ?: 'admin', $action, mb_substr($target, 0, 300), mb_substr($detail, 0, 600), $ip]);
        } catch (Throwable $e) {
            error_log('[lms] audit write skipped: ' . $e->getMessage());
        }
    }
    public function recentAudit(int $limit = 40): array
    {
        $this->ensureAudit();
        $s = $this->db->prepare("SELECT * FROM lms_audit ORDER BY id DESC LIMIT ?");
        $s->bindValue(1, $limit, PDO::PARAM_INT); $s->execute();
        return $s->fetchAll();
    }

    /** Counts by role, for the console summary. */
    public function memberCounts(): array
    {
        $out = ['total' => 0];
        foreach ($this->db->query("SELECT role, COUNT(*) c FROM lms_users GROUP BY role")->fetchAll() as $r) {
            $out[(string) $r['role']] = (int) $r['c'];
            $out['total'] += (int) $r['c'];
        }
        return $out;
    }

    /** Filtered member list for the admin console. */
    public function membersForAdmin(string $q = '', string $role = '', string $status = '', int $limit = 200): array
    {
        if (class_exists('Levels')) Levels::ensure(); // guarantees the level column
        if (class_exists('Birthdays')) Birthdays::ensure(); // and the birthday ones
        $w = []; $p = [];
        if ($q !== '')      { $w[] = '(name LIKE ? OR email LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($role !== '')   { $w[] = 'role = ?';   $p[] = $role; }
        if ($status !== '') { $w[] = 'status = ?'; $p[] = $status; }
        $sql = "SELECT id, name, email, role, status, created_at, last_login, level, birthday, birth_year FROM lms_users";
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        $s = $this->db->prepare($sql); $s->execute($p);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) { $r['org'] = LmsAuth::isOrgMember($r); $r['rank'] = LmsAuth::rank((string) $r['role']); $r['level'] = $r['level'] ?? 'O'; }
        return $rows;
    }

    public function memberById(int $id): ?array
    {
        $s = $this->db->prepare("SELECT id, name, email, role, status, created_at, last_login FROM lms_users WHERE id = ?");
        $s->execute([$id]); return $s->fetch() ?: null;
    }

    public function setMemberRole(int $id, string $role): bool
    {
        if (!isset(LmsAuth::ROLE_RANK[$role])) return false;
        $this->db->prepare("UPDATE lms_users SET role = ? WHERE id = ?")->execute([$role, $id]);
        return true;
    }

    public function setMemberStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'suspended'], true)) return false;
        $this->db->prepare("UPDATE lms_users SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($status === 'suspended') $this->db->prepare("DELETE FROM lms_sessions WHERE user_id = ?")->execute([$id]); // revoke sessions
        /* And their CACENTRE gate passes, which the gate checks without asking
           here: a pass already on a phone would otherwise open the door until
           it expired. Best effort — a gate that cannot be reached is logged,
           and still refuses their ID card, which it does ask about. */
        if ($status === 'suspended' && class_exists('GatePass') && GatePass::ready()) {
            try { $r = GatePass::revoke($id); if (empty($r['ok'])) error_log('[gate] revoke on suspend #' . $id . ': ' . ($r['error'] ?? '')); }
            catch (Throwable $e) { error_log('[gate] revoke on suspend: ' . $e->getMessage()); }
        }
        return true;
    }

    /** Pre-create a member (passwordless; they sign in via Google/reset). */
    public function createMember(string $name, string $email, string $role): array
    {
        $email = strtolower(trim($email)); $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        if (!isset(LmsAuth::ROLE_RANK[$role])) $role = 'member';
        $ex = $this->db->prepare("SELECT id FROM lms_users WHERE email = ?"); $ex->execute([$email]);
        if ($ex->fetchColumn()) return ['ok' => false, 'error' => 'An account with that email already exists.'];
        $this->db->prepare("INSERT INTO lms_users (name, email, password_hash, role) VALUES (?,?,?,?)")
            ->execute([$name !== '' ? $name : ucfirst(explode('@', $email)[0]), $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), $role]);
        $newId = (int) $this->db->lastInsertId();
        if (class_exists('Events')) Events::emit('member.created', ['email' => $email, 'name' => $name, 'role' => $role, 'via' => 'admin']);
        return ['ok' => true, 'id' => $newId];
    }

    public function certificateBySerial(string $serial): ?array
    {
        $s = $this->db->prepare(
            'SELECT c.*, u.name AS learner, co.title AS course FROM certificates c
             JOIN lms_users u ON u.id = c.user_id JOIN courses co ON co.id = c.course_id WHERE c.serial = ?'
        );
        $s->execute([$serial]); return $s->fetch() ?: null;
    }
}
