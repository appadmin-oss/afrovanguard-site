<?php
/**
 * lib/LmsCourseware.php — the parts of the Academy that make a course a course
 * rather than a reading list: grades, assignments, discussions, reviews,
 * programmes (series of courses), the catalogue's figures, and a learner's
 * schedule.
 *
 * LmsRepository still owns lessons, progress, quizzes and certificates; this
 * class reads through it so there is one definition of each. Two rules run
 * through everything here:
 *
 *   - A certificate certifies a PASS. `grades()` is the one answer to "has this
 *     learner passed", and LmsRepository::requirementsMet() asks it.
 *   - Lists are built from grouped queries, never a query per row — a catalogue
 *     of forty courses is four queries, not a hundred and sixty.
 */
declare(strict_types=1);

final class LmsCourseware
{
    public const RESERVED_SLUGS = ['my', 'programmes', 'teach', 'ngv', 'studio', 'og', 'sitemap', 'dns', 'pay', 'verify', 'api'];

    private PDO $db;
    private LmsRepository $lms;

    public function __construct(?PDO $pdo = null, ?LmsRepository $lms = null)
    {
        $this->db = $pdo ?? Database::pdo();
        $this->lms = $lms ?? new LmsRepository($this->db);
    }

    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function lines(string $s, int $max = 20): array
    {
        return array_slice(array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $s) ?: []), static fn($x) => $x !== '')), 0, $max);
    }
    public static function skills(array $course): array { return self::lines((string) ($course['skills'] ?? ''), 24); }
    public static function prerequisites(array $course): array { return self::lines((string) ($course['prerequisites'] ?? ''), 12); }
    public static function objectives(array $module): array { return self::lines((string) ($module['objectives'] ?? ''), 12); }

    /* ── Assignments ─────────────────────────────────────────────────── */

    /** The pass mark for an assignment item (default 70). */
    public static function assignmentPass(array $lesson): int
    {
        $a = json_decode((string) ($lesson['assignment_json'] ?? ''), true);
        return max(1, min(100, (int) (is_array($a) ? ($a['pass'] ?? 70) : 70)));
    }

    public function submission(int $userId, int $lessonId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM assignment_submissions WHERE user_id = ? AND lesson_id = ?');
        $s->execute([$userId, $lessonId]);
        return $s->fetch() ?: null;
    }

    /**
     * Submit (or resubmit) an assignment. A resubmission replaces the current
     * one and goes back in the grading queue; a submission that has already
     * PASSED is final, so a learner cannot turn a grade back into "pending".
     */
    public function submit(int $userId, array $lesson, string $body, string $url): array
    {
        if (LmsRepository::lessonType($lesson) !== 'assignment') return ['ok' => false, 'error' => 'This item is not an assignment.'];
        $body = trim($body); $url = trim($url);
        if ($url !== '' && (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL))) return ['ok' => false, 'error' => 'Add a full link starting with https://'];
        if ($body === '' && $url === '') return ['ok' => false, 'error' => 'Write your answer or add a link to your work.'];
        if (mb_strlen($body) > 20000) return ['ok' => false, 'error' => 'Keep your answer under 20,000 characters.'];
        $cur = $this->submission($userId, (int) $lesson['id']);
        if ($cur && $cur['status'] === 'graded' && (int) $cur['score'] >= self::assignmentPass($lesson))
            return ['ok' => false, 'error' => 'This assignment is already passed.'];
        $now = self::now();
        if ($cur) {
            $this->db->prepare("UPDATE assignment_submissions SET body = ?, link_url = ?, status = 'submitted', score = NULL, updated_at = ? WHERE id = ?")
                ->execute([$body, $url, $now, (int) $cur['id']]);
        } else {
            $this->db->prepare('INSERT INTO assignment_submissions (user_id, lesson_id, course_id, body, link_url, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$userId, (int) $lesson['id'], (int) $lesson['course_id'], $body, $url, 'submitted', $now, $now]);
        }
        return ['ok' => true, 'submission' => $this->submission($userId, (int) $lesson['id'])];
    }

    public function submissionById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM assignment_submissions WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /**
     * Grade a submission (score 0-100), or return it for revision ($return).
     * A pass completes the item; a fail or a return leaves it open to resubmit.
     */
    public function grade(int $subId, ?int $score, string $feedback, int $graderId, bool $return = false): array
    {
        $sub = $this->submissionById($subId);
        if (!$sub) return ['ok' => false, 'error' => 'Submission not found.'];
        $lesson = $this->lms->lessonById((int) $sub['lesson_id']);
        if (!$lesson) return ['ok' => false, 'error' => 'Assignment not found.'];
        $feedback = mb_substr(trim($feedback), 0, 5000);
        if ($return) {
            if ($feedback === '') return ['ok' => false, 'error' => 'Say what to change.'];
            $now = self::now();
            $this->db->prepare("UPDATE assignment_submissions SET status = 'returned', score = NULL, feedback = ?, graded_by = ?, graded_at = ?, updated_at = ? WHERE id = ?")
                ->execute([$feedback, $graderId, $now, $now, $subId]);
            $this->lms->unmark((int) $sub['user_id'], (int) $lesson['id']);
            return ['ok' => true, 'passed' => false, 'returned' => true];
        }
        if ($score === null || $score < 0 || $score > 100) return ['ok' => false, 'error' => 'Give a score from 0 to 100.'];
        /* updated_at moves with the grade too: it is the one sortable "last
           touched" column on every driver (graded_at stays text on Postgres,
           where updated_at is a timestamp, and the two cannot be COALESCEd). */
        $now = self::now();
        $this->db->prepare("UPDATE assignment_submissions SET status = 'graded', score = ?, feedback = ?, graded_by = ?, graded_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$score, $feedback, $graderId, $now, $now, $subId]);
        $passed = $score >= self::assignmentPass($lesson);
        if ($passed) $this->lms->markComplete((int) $sub['user_id'], $lesson);
        else $this->lms->unmark((int) $sub['user_id'], (int) $lesson['id']);
        $done = $this->lms->completeIfDone((int) $sub['user_id'], (int) $lesson['course_id']);
        return ['ok' => true, 'passed' => $passed, 'course_complete' => $done['complete'], 'newly' => $done['newly']];
    }

    /**
     * Submissions waiting for a grader, for the given courses (null = all).
     * Oldest first: whoever has waited longest is marked next.
     */
    public function queue(?array $courseIds, string $status = 'submitted', int $limit = 200): array
    {
        if ($courseIds !== null && !$courseIds) return [];
        $st = in_array($status, ['submitted', 'graded', 'returned'], true) ? $status : 'submitted';
        $where = 's.status = ?'; $args = [$st];
        if ($courseIds !== null) {
            $where .= ' AND s.course_id IN (' . implode(',', array_fill(0, count($courseIds), '?')) . ')';
            $args = array_merge($args, array_map('intval', $courseIds));
        }
        $s = $this->db->prepare(
            "SELECT s.*, u.name AS learner, u.email AS learner_email, l.title AS item_title, l.slug AS item_slug,
                    l.assignment_json, c.title AS course_title, c.slug AS course_slug
             FROM assignment_submissions s
             JOIN lms_users u ON u.id = s.user_id
             JOIN lessons l ON l.id = s.lesson_id
             JOIN courses c ON c.id = s.course_id
             WHERE $where ORDER BY s.updated_at " . ($st === 'submitted' ? 'ASC' : 'DESC') . ', s.id LIMIT ' . max(1, min(500, $limit))
        );
        $s->execute($args);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) $r['pass'] = self::assignmentPass($r);
        return $rows;
    }

    /* ── Grades ──────────────────────────────────────────────────────── */

    /**
     * The learner's grades for a course. Graded items are graded quizzes and
     * assignments; practice quizzes, readings, videos and discussions are not.
     *
     *   items[]  title, slug, type, weight, pass, score|null, status
     *            (passed | failed | pending | returned | not_started)
     *   overall  weighted average, an item not yet scored counting as 0
     *   passed   every item passed AND overall >= pass_mark (true when the
     *            course has no graded items: then completion alone certifies)
     */
    public function grades(int $userId, array $course): array
    {
        $courseId = (int) $course['id'];
        $passMark = max(0, min(100, (int) ($course['pass_mark'] ?? 70)));
        $best = [];
        $s = $this->db->prepare('SELECT q.lesson_id, MAX(q.score) AS best, COUNT(*) AS n FROM quiz_attempts q JOIN lessons l ON l.id = q.lesson_id WHERE q.user_id = ? AND l.course_id = ? GROUP BY q.lesson_id');
        $s->execute([$userId, $courseId]);
        foreach ($s->fetchAll() as $r) $best[(int) $r['lesson_id']] = ['best' => (int) $r['best'], 'n' => (int) $r['n']];
        $subs = [];
        $s = $this->db->prepare('SELECT * FROM assignment_submissions WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        foreach ($s->fetchAll() as $r) $subs[(int) $r['lesson_id']] = $r;

        $items = []; $wSum = 0; $wScore = 0; $allPassed = true;
        foreach ($this->lms->orderedLessons($courseId) as $l) {
            $type = LmsRepository::lessonType($l);
            $lid = (int) $l['id'];
            $weight = max(1, (int) ($l['weight'] ?? 1));
            if ($type === 'assignment') {
                $pass = self::assignmentPass($l);
                $sub = $subs[$lid] ?? null;
                $score = ($sub && $sub['status'] === 'graded') ? (int) $sub['score'] : null;
                $status = !$sub ? 'not_started' : ($sub['status'] === 'returned' ? 'returned'
                        : ($score === null ? 'pending' : ($score >= $pass ? 'passed' : 'failed')));
            } else {
                $q = $this->lms->quiz($l);
                if (!$q || !$q['graded']) continue;
                $pass = (int) $q['pass'];
                $score = isset($best[$lid]) ? $best[$lid]['best'] : null;
                $status = $score === null ? 'not_started' : ($score >= $pass ? 'passed' : 'failed');
                if ($type === 'video') $type = 'quiz';
            }
            $items[] = ['id' => $lid, 'title' => (string) $l['title'], 'slug' => (string) $l['slug'], 'type' => $type,
                        'weight' => $weight, 'pass' => $pass, 'score' => $score, 'status' => $status];
            $wSum += $weight; $wScore += $weight * (int) ($score ?? 0);
            if ($status !== 'passed') $allPassed = false;
        }
        $overall = $wSum ? (int) round($wScore / $wSum) : 0;
        return ['items' => $items, 'overall' => $overall, 'pass_mark' => $passMark,
                'passed' => !$items || ($allPassed && $overall >= $passMark), 'graded_count' => count($items)];
    }

    /* ── Discussions ─────────────────────────────────────────────────── */

    private static function clean(string $s, int $max): string { return mb_substr(trim(str_replace("\r\n", "\n", $s)), 0, $max); }

    /** Threads in a course's forum, pinned first then most recently active. */
    public function threads(int $courseId, bool $withHidden = false, int $limit = 100): array
    {
        $s = $this->db->prepare(
            "SELECT t.*, u.name AS author, l.title AS lesson_title, l.slug AS lesson_slug
             FROM forum_threads t LEFT JOIN lms_users u ON u.id = t.user_id LEFT JOIN lessons l ON l.id = t.lesson_id
             WHERE t.course_id = ?" . ($withHidden ? '' : ' AND t.hidden = 0') . "
             ORDER BY t.pinned DESC, t.last_post_at DESC, t.id DESC LIMIT " . max(1, min(300, $limit))
        );
        $s->execute([$courseId]);
        return $s->fetchAll();
    }

    public function thread(int $id): ?array
    {
        $s = $this->db->prepare('SELECT t.*, u.name AS author FROM forum_threads t LEFT JOIN lms_users u ON u.id = t.user_id WHERE t.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function posts(int $threadId, bool $withHidden = false): array
    {
        $s = $this->db->prepare(
            'SELECT p.*, u.name AS author, u.role AS author_role FROM forum_posts p LEFT JOIN lms_users u ON u.id = p.user_id
             WHERE p.thread_id = ?' . ($withHidden ? '' : ' AND p.hidden = 0') . ' ORDER BY p.id'
        );
        $s->execute([$threadId]);
        return $s->fetchAll();
    }

    public function createThread(int $userId, int $courseId, string $title, string $body, ?int $lessonId = null): array
    {
        $title = self::clean($title, 160); $body = self::clean($body, 10000);
        if (mb_strlen($title) < 3) return ['ok' => false, 'error' => 'Give your post a title.'];
        if ($body === '') return ['ok' => false, 'error' => 'Write your question or idea.'];
        $now = self::now();
        $this->db->prepare('INSERT INTO forum_threads (course_id, lesson_id, user_id, title, body, last_post_at, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$courseId, $lessonId, $userId, $title, $body, $now, $now]);
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
    }

    /** The course's own thread for a discussion item, created on first use. */
    public function promptThread(array $lesson): array
    {
        $s = $this->db->prepare('SELECT * FROM forum_threads WHERE lesson_id = ? AND user_id = 0 ORDER BY id LIMIT 1');
        $s->execute([(int) $lesson['id']]);
        if ($t = $s->fetch()) return $t;
        $now = self::now();
        $this->db->prepare('INSERT INTO forum_threads (course_id, lesson_id, user_id, title, body, pinned, last_post_at, created_at) VALUES (?,?,0,?,?,1,?,?)')
            ->execute([(int) $lesson['course_id'], (int) $lesson['id'], (string) $lesson['title'], '', $now, $now]);
        return $this->thread((int) $this->db->lastInsertId()) ?? [];
    }

    /**
     * Reply to a thread. Replying to a discussion item's prompt completes that
     * item for the learner — taking part IS the work.
     */
    public function reply(int $userId, int $threadId, string $body): array
    {
        $t = $this->thread($threadId);
        if (!$t || (int) $t['hidden'] === 1) return ['ok' => false, 'error' => 'Discussion not found.'];
        if ((int) $t['locked'] === 1) return ['ok' => false, 'error' => 'This discussion is closed.'];
        $body = self::clean($body, 10000);
        if ($body === '') return ['ok' => false, 'error' => 'Write a reply first.'];
        $now = self::now();
        $this->db->prepare('INSERT INTO forum_posts (thread_id, course_id, user_id, body, created_at) VALUES (?,?,?,?,?)')
            ->execute([$threadId, (int) $t['course_id'], $userId, $body, $now]);
        $this->db->prepare('UPDATE forum_threads SET reply_count = reply_count + 1, last_post_at = ? WHERE id = ?')->execute([$now, $threadId]);
        $completed = false;
        if ((int) $t['user_id'] === 0 && !empty($t['lesson_id'])) {
            $l = $this->lms->lessonById((int) $t['lesson_id']);
            if ($l && LmsRepository::lessonType($l) === 'discussion') { $this->lms->markComplete($userId, $l); $completed = true; }
        }
        return ['ok' => true, 'id' => (int) $this->db->lastInsertId(), 'completed_item' => $completed];
    }

    /** Instructor moderation: hide | show | pin | unpin | lock | unlock (thread), hide | show (post). */
    public function moderate(string $what, int $id, string $op): bool
    {
        $map = ['hide' => ['hidden', 1], 'show' => ['hidden', 0], 'pin' => ['pinned', 1], 'unpin' => ['pinned', 0], 'lock' => ['locked', 1], 'unlock' => ['locked', 0]];
        if (!isset($map[$op])) return false;
        [$col, $val] = $map[$op];
        if ($what === 'post') {
            if ($col !== 'hidden') return false;
            $st = $this->db->prepare('UPDATE forum_posts SET hidden = ? WHERE id = ?');
        } else {
            $st = $this->db->prepare("UPDATE forum_threads SET $col = ? WHERE id = ?");
        }
        $st->execute([$val, $id]);
        return $st->rowCount() > 0;
    }

    public function postById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM forum_posts WHERE id = ?'); $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /* ── Ratings and reviews ─────────────────────────────────────────── */

    /** Only somebody who has taken the course may review it. */
    public function canReview(int $userId, int $courseId): bool
    {
        if ($this->lms->isEnrolled($userId, $courseId)) return true;
        return (int) ($this->lms->progress($userId, $courseId)['completed'] ?? 0) > 0;
    }

    public function saveReview(int $userId, int $courseId, int $rating, string $body): array
    {
        if ($rating < 1 || $rating > 5) return ['ok' => false, 'error' => 'Choose 1 to 5 stars.'];
        if (!$this->canReview($userId, $courseId)) return ['ok' => false, 'error' => 'Enrol in this course to review it.'];
        $body = self::clean($body, 2000); $now = self::now();
        $cur = $this->myReview($userId, $courseId);
        if ($cur) {
            $this->db->prepare('UPDATE course_reviews SET rating = ?, body = ?, updated_at = ? WHERE id = ?')->execute([$rating, $body, $now, (int) $cur['id']]);
        } else {
            $this->db->prepare('INSERT INTO course_reviews (user_id, course_id, rating, body, created_at, updated_at) VALUES (?,?,?,?,?,?)')
                ->execute([$userId, $courseId, $rating, $body, $now, $now]);
        }
        return ['ok' => true, 'summary' => $this->ratingSummary($courseId)];
    }

    public function myReview(int $userId, int $courseId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM course_reviews WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        return $s->fetch() ?: null;
    }

    /** avg (one decimal), count, and the 5→1 star distribution. */
    public function ratingSummary(int $courseId): array
    {
        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $s = $this->db->prepare('SELECT rating, COUNT(*) AS n FROM course_reviews WHERE course_id = ? AND hidden = 0 GROUP BY rating');
        $s->execute([$courseId]);
        $n = 0; $sum = 0;
        foreach ($s->fetchAll() as $r) { $k = (int) $r['rating']; if (isset($dist[$k])) { $dist[$k] = (int) $r['n']; $n += (int) $r['n']; $sum += $k * (int) $r['n']; } }
        return ['avg' => $n ? round($sum / $n, 1) : 0.0, 'count' => $n, 'dist' => $dist];
    }

    public function reviews(int $courseId, int $limit = 20): array
    {
        $s = $this->db->prepare(
            "SELECT r.id, r.rating, r.body, r.updated_at, u.name AS author FROM course_reviews r JOIN lms_users u ON u.id = r.user_id
             WHERE r.course_id = ? AND r.hidden = 0 AND r.body <> '' ORDER BY r.updated_at DESC, r.id DESC LIMIT " . max(1, min(100, $limit))
        );
        $s->execute([$courseId]);
        return $s->fetchAll();
    }

    public function hideReview(int $id, bool $hidden = true): bool
    {
        $st = $this->db->prepare('UPDATE course_reviews SET hidden = ? WHERE id = ?');
        $st->execute([$hidden ? 1 : 0, $id]);
        return $st->rowCount() > 0;
    }

    /**
     * Is this course ready for learners? A checklist an author works through,
     * not a gate: [['level' => 'fix'|'tip', 'text' => …, 'lesson' => id|null, 'module' => id|null], …].
     * "fix" is something a learner would trip over (an empty item, a starter
     * question nobody replaced); "tip" makes the course better.
     */
    public function readiness(array $course): array
    {
        $out = [];
        $add = static function (string $level, string $t, ?int $l = null, ?int $m = null) use (&$out): void { $out[] = ['level' => $level, 'text' => $t, 'lesson' => $l, 'module' => $m]; };
        if (trim((string) ($course['summary'] ?? '')) === '') $add('fix', 'Add a one-line summary — it is what the catalogue card shows.');
        if (trim((string) ($course['cover_url'] ?? '')) === '') $add('tip', 'Add a cover image.');
        if (!self::skills($course)) $add('tip', 'List the skills learners will gain — the course page and search use them.');
        $mods = $this->lms->curriculum((int) $course['id']);
        if (!$mods) { $add('fix', 'Add your first module.'); return $out; }
        $s = $this->db->prepare('SELECT id, body_html FROM lessons WHERE course_id = ?');
        $s->execute([(int) $course['id']]);
        $bodies = [];
        foreach ($s->fetchAll() as $r) $bodies[(int) $r['id']] = trim(strip_tags((string) $r['body_html']));
        $graded = 0;
        foreach ($mods as $i => $m) {
            $mid = (int) $m['id']; $name = 'Module ' . ($i + 1);
            if (!$m['lessons']) { $add('fix', $name . ' has no items.', null, $mid); continue; }
            if (trim((string) ($m['summary'] ?? '')) === '') $add('tip', $name . ' has no introduction — learners see it when the module starts.', null, $mid);
            foreach ($m['lessons'] as $l) {
                $lid = (int) $l['id']; $type = (string) ($l['type'] ?? 'reading'); $title = '“' . $l['title'] . '”';
                $body = $bodies[$lid] ?? '';
                $starter = in_array($body, ['Write your first lesson here.', 'Write your second lesson here.'], true);
                if ($type === 'reading' && ($body === '' || $starter)) $add('fix', $title . ' has no text yet.', $lid, $mid);
                if (in_array($type, ['assignment', 'discussion'], true) && $body === '') $add('fix', $title . ' needs ' . ($type === 'assignment' ? 'instructions.' : 'a prompt.'), $lid, $mid);
                if (in_array($type, ['quiz', 'practice'], true) && str_contains((string) ($l['quiz_json'] ?? ''), 'Replace this with your question')) $add('fix', $title . ' still has the starter question.', $lid, $mid);
                if ($type === 'video' && str_starts_with((string) $l['video_url'], '/uploads/') && empty($l['captions_url'] ?? null)) $add('tip', $title . ' has no captions.', $lid, $mid);
                if ($type === 'quiz' || $type === 'assignment') $graded++;
            }
        }
        if (!$graded) $add('tip', 'Nothing is graded, so the certificate is given for finishing. Add a graded quiz or an assignment to certify a pass.');
        if (($course['status'] ?? '') !== 'published') $add('tip', 'The course is a draft. Publish it from Courses when the list above is clear.');
        return $out;
    }

    /**
     * The Studio's search: courses, items and (when $people) learners whose
     * name, email, title or summary contains every word typed. Eight of each.
     * LIKE wildcards are escaped with `!`, which both SQLite and MySQL read
     * the same way (a backslash is two characters to one and one to the other).
     */
    public function search(string $q, bool $people = false, int $per = 8): array
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim(mb_substr($q, 0, 80)))) ?: [], static fn($w) => $w !== '')), 0, 5);
        $out = ['courses' => [], 'items' => [], 'learners' => []];
        if (!$words) return $out;
        $esc = static fn(string $w): string => '%' . strtr($w, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $cond = static function (array $cols) use ($words, $esc): array {
            $sql = []; $args = [];
            foreach ($words as $w) {
                $sql[] = '(' . implode(' OR ', array_map(static fn($c) => "LOWER($c) LIKE ? ESCAPE '!'", $cols)) . ')';
                foreach ($cols as $_) $args[] = $esc($w);
            }
            return [implode(' AND ', $sql), $args];
        };
        $per = max(1, min(20, $per));
        [$w, $a] = $cond(['c.title', 'c.summary', 'c.slug', 'c.category']);
        $s = $this->db->prepare("SELECT c.id, c.slug, c.title, c.status, c.category FROM courses c WHERE $w ORDER BY c.status DESC, c.title LIMIT $per");
        $s->execute($a); $out['courses'] = $s->fetchAll();
        [$w, $a] = $cond(['l.title']);
        $s = $this->db->prepare("SELECT l.id, l.title, l.kind, l.video_url, l.quiz_json, c.slug AS course_slug, c.title AS course_title, m.title AS module_title
                                 FROM lessons l JOIN courses c ON c.id = l.course_id JOIN modules m ON m.id = l.module_id WHERE $w ORDER BY c.title, l.title LIMIT $per");
        $s->execute($a);
        foreach ($s->fetchAll() as $r) {
            $r['type'] = LmsRepository::lessonType($r);
            unset($r['video_url'], $r['quiz_json']);
            $out['items'][] = $r;
        }
        if ($people) {
            [$w, $a] = $cond(['u.name', 'u.email']);
            $s = $this->db->prepare("SELECT u.id, u.name, u.email, (SELECT COUNT(*) FROM course_enrolment e WHERE e.user_id = u.id) AS courses,
                                            (SELECT c.slug FROM course_enrolment e JOIN courses c ON c.id = e.course_id WHERE e.user_id = u.id ORDER BY e.created_at DESC LIMIT 1) AS last_course
                                     FROM lms_users u WHERE $w ORDER BY u.name LIMIT $per");
            $s->execute($a); $out['learners'] = $s->fetchAll();
        }
        return $out;
    }

    /* ── Catalogue figures (grouped, never per row) ──────────────────── */

    /**
     * For a set of course ids: lessons, minutes, enrolled, rating avg + count.
     * @return array<int, array{lessons:int, minutes:int, enrolled:int, rating:float, ratings:int}>
     */
    public function stats(array $courseIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));
        $out = [];
        foreach ($ids as $id) $out[$id] = ['lessons' => 0, 'minutes' => 0, 'enrolled' => 0, 'rating' => 0.0, 'ratings' => 0];
        if (!$ids) return $out;
        $in = implode(',', $ids);
        foreach ($this->db->query("SELECT course_id, COUNT(*) AS n, COALESCE(SUM(duration_min),0) AS m FROM lessons WHERE course_id IN ($in) GROUP BY course_id") as $r) {
            $out[(int) $r['course_id']]['lessons'] = (int) $r['n']; $out[(int) $r['course_id']]['minutes'] = (int) $r['m'];
        }
        foreach ($this->db->query("SELECT course_id, COUNT(*) AS n FROM course_enrolment WHERE course_id IN ($in) GROUP BY course_id") as $r) {
            $out[(int) $r['course_id']]['enrolled'] = (int) $r['n'];
        }
        foreach ($this->db->query("SELECT course_id, COUNT(*) AS n, SUM(rating) AS s FROM course_reviews WHERE hidden = 0 AND course_id IN ($in) GROUP BY course_id") as $r) {
            $n = (int) $r['n'];
            $out[(int) $r['course_id']]['ratings'] = $n;
            $out[(int) $r['course_id']]['rating'] = $n ? round((int) $r['s'] / $n, 1) : 0.0;
        }
        return $out;
    }

    /** course_id => true for every course this learner is enrolled in. */
    public function enrolledIds(int $userId): array
    {
        $s = $this->db->prepare('SELECT course_id FROM course_enrolment WHERE user_id = ?');
        $s->execute([$userId]);
        return array_fill_keys(array_map('intval', array_column($s->fetchAll(), 'course_id')), true);
    }

    /** course_id => completed lesson count, for one learner. */
    public function doneCounts(int $userId): array
    {
        $s = $this->db->prepare('SELECT course_id, COUNT(*) AS n FROM lesson_progress WHERE user_id = ? GROUP BY course_id');
        $s->execute([$userId]);
        $out = [];
        foreach ($s->fetchAll() as $r) $out[(int) $r['course_id']] = (int) $r['n'];
        return $out;
    }

    /**
     * Filter + sort catalogue rows (avac_decorate() shape) by the request.
     *   q      words, all must match title/summary/category/level/skills
     *   track  category slug · level · price: free | members | paid
     *   sort   recommended | popular | rating | newest | title | shortest
     */
    public static function filter(array $rows, array $p): array
    {
        $q = mb_strtolower(trim((string) ($p['q'] ?? '')));
        $words = $q === '' ? [] : preg_split('/\s+/', $q);
        $track = (string) ($p['track'] ?? ''); $level = (string) ($p['level'] ?? ''); $price = (string) ($p['price'] ?? '');
        $rows = array_values(array_filter($rows, static function ($r) use ($words, $track, $level, $price): bool {
            $c = $r['course'];
            if ($track !== '' && slugify((string) $c['category']) !== $track) return false;
            if ($level !== '' && slugify((string) $c['level']) !== $level) return false;
            if ($price !== '') {
                if (self::priceTone((string) ($c['access_type'] ?? 'open')) !== $price) return false;
            }
            $hay = mb_strtolower(implode(' ', [$c['title'] ?? '', $c['summary'] ?? '', $c['category'] ?? '', $c['level'] ?? '', $c['skills'] ?? '']));
            foreach ($words as $w) { if ($w !== '' && !str_contains($hay, $w)) return false; }
            return true;
        }));
        $sort = (string) ($p['sort'] ?? 'recommended');
        $cmp = match ($sort) {
            'popular'  => static fn($a, $b) => $b['enrolled'] <=> $a['enrolled'],
            'rating'   => static fn($a, $b) => [$b['rating'], $b['ratings']] <=> [$a['rating'], $a['ratings']],
            'newest'   => static fn($a, $b) => strcmp((string) ($b['course']['created_at'] ?? ''), (string) ($a['course']['created_at'] ?? '')),
            'title'    => static fn($a, $b) => strcasecmp((string) $a['course']['title'], (string) $b['course']['title']),
            'shortest' => static fn($a, $b) => [$a['minutes'] ?: PHP_INT_MAX, $a['course']['title']] <=> [$b['minutes'] ?: PHP_INT_MAX, $b['course']['title']],
            default    => null,
        };
        if ($cmp) usort($rows, $cmp);
        return $rows;
    }

    /** free (open, tracked) · members · paid (paid, restricted) — the catalogue's price filter. */
    public static function priceTone(string $access): string
    {
        return ['membership' => 'members', 'paid' => 'paid', 'restricted' => 'paid'][$access] ?? 'free';
    }

    /* ── Schedule (suggested deadlines) ──────────────────────────────── */

    /** When this learner started the course: their enrolment, else their first completion. */
    public function startedAt(int $userId, int $courseId): ?string
    {
        $s = $this->db->prepare('SELECT created_at FROM course_enrolment WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        $v = $s->fetchColumn();
        if ($v) return (string) $v;
        $s = $this->db->prepare('SELECT MIN(completed_at) FROM lesson_progress WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]);
        $v = $s->fetchColumn();
        return $v ? (string) $v : null;
    }

    /**
     * Suggested due date per module: the course's pace spread evenly from the
     * start date (one week a module when no pace is set). Suggested, not
     * enforced — nothing locks when a date passes; the learner is told they are
     * behind and can reset the schedule by enrolling again.
     * @return array<int, string> module_id => Y-m-d
     */
    public static function schedule(?string $startedAt, array $course, array $modules): array
    {
        if (!$startedAt || !$modules) return [];
        $n = count($modules);
        $weeks = (int) ($course['pace_weeks'] ?? 0) > 0 ? (int) $course['pace_weeks'] : $n;
        $start = strtotime(substr($startedAt, 0, 10) . ' 00:00:00 UTC') ?: time();
        $out = [];
        foreach (array_values($modules) as $i => $m) {
            $out[(int) $m['id']] = gmdate('Y-m-d', $start + (int) round(($i + 1) * $weeks * 7 / $n) * 86400);
        }
        return $out;
    }

    /**
     * Everything the "My learning" page shows, in a handful of queries:
     * courses in progress (with the next item and its suggested date),
     * completed ones, recent feedback on assignments, and programmes.
     */
    public function dashboard(int $userId): array
    {
        $s = $this->db->prepare(
            "SELECT c.*, e.created_at AS enrolled_at FROM course_enrolment e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ?
             UNION SELECT c.*, NULL AS enrolled_at FROM courses c WHERE c.id IN (SELECT DISTINCT course_id FROM lesson_progress WHERE user_id = ?)
               AND c.id NOT IN (SELECT course_id FROM course_enrolment WHERE user_id = ?)"
        );
        $s->execute([$userId, $userId, $userId]);
        $courses = $s->fetchAll();
        $certs = [];
        $cs = $this->db->prepare('SELECT course_id, serial, issued_at FROM certificates WHERE user_id = ?');
        $cs->execute([$userId]);
        foreach ($cs->fetchAll() as $r) $certs[(int) $r['course_id']] = $r;
        $last = [];
        $ls = $this->db->prepare('SELECT course_id, MAX(completed_at) AS t FROM lesson_progress WHERE user_id = ? GROUP BY course_id');
        $ls->execute([$userId]);
        foreach ($ls->fetchAll() as $r) $last[(int) $r['course_id']] = (string) $r['t'];

        $active = []; $done = []; $today = gmdate('Y-m-d');
        foreach ($courses as $c) {
            $id = (int) $c['id'];
            $pr = $this->lms->progress($userId, $id);
            $row = ['course' => $c, 'pct' => $pr['pct'], 'done' => $pr['completed'], 'total' => $pr['total'],
                    'cert' => $certs[$id] ?? null, 'last' => $last[$id] ?? ($c['enrolled_at'] ?? null)];
            if ($row['cert']) { $done[] = $row; continue; }
            $mods = $this->lms->curriculum($id);
            $due = self::schedule($c['enrolled_at'] ?: $this->startedAt($userId, $id), $c, $mods);
            $next = null; $nextDue = null;
            foreach ($mods as $m) {
                foreach ($m['lessons'] as $l) {
                    if (!in_array((int) $l['id'], $pr['ids'], true)) { $next = $l + ['module' => (string) $m['title']]; $nextDue = $due[(int) $m['id']] ?? null; break 2; }
                }
            }
            $row['next'] = $next; $row['due'] = $nextDue; $row['behind'] = $nextDue !== null && $nextDue < $today;
            $row['grades'] = $pr['complete'] ? $this->grades($userId, $c) : null;
            $active[] = $row;
        }
        usort($active, static fn($a, $b) => strcmp((string) $b['last'], (string) $a['last']));

        $fb = $this->db->prepare(
            "SELECT s.status, s.score, s.feedback, s.graded_at, l.title AS item_title, l.slug AS item_slug, l.assignment_json, c.title AS course_title, c.slug AS course_slug
             FROM assignment_submissions s JOIN lessons l ON l.id = s.lesson_id JOIN courses c ON c.id = s.course_id
             WHERE s.user_id = ? ORDER BY s.updated_at DESC, s.id DESC LIMIT 6"
        );
        $fb->execute([$userId]);
        $feedback = $fb->fetchAll();
        foreach ($feedback as &$f) $f['pass'] = self::assignmentPass($f);
        unset($f);

        $progs = [];
        foreach ($this->programmes(true) as $p) {
            $pp = $this->programmeProgress($userId, $p);
            if ($pp['started'] || $pp['cert']) $progs[] = ['programme' => $p] + $pp;
        }
        return ['active' => $active, 'done' => $done, 'feedback' => $feedback, 'programmes' => $progs];
    }

    /* ── Programmes (series of courses) ──────────────────────────────── */

    public function programmes(bool $publishedOnly = true): array
    {
        $rows = $this->db->query('SELECT * FROM programmes' . ($publishedOnly ? " WHERE status = 'published'" : '') . ' ORDER BY sort, title')->fetchAll();
        if (!$rows) return [];
        $n = [];
        foreach ($this->db->query('SELECT programme_id, COUNT(*) AS n FROM programme_courses GROUP BY programme_id') as $r) $n[(int) $r['programme_id']] = (int) $r['n'];
        foreach ($rows as &$r) $r['course_count'] = $n[(int) $r['id']] ?? 0;
        return $rows;
    }

    public function programme(string $slug, bool $drafts = false): ?array
    {
        $s = $this->db->prepare('SELECT * FROM programmes WHERE slug = ?' . ($drafts ? '' : " AND status = 'published'"));
        $s->execute([$slug]);
        return $s->fetch() ?: null;
    }

    public function programmeById(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM programmes WHERE id = ?'); $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** The programme's courses in order (published only unless $drafts). */
    public function programmeCourses(int $programmeId, bool $drafts = false): array
    {
        $s = $this->db->prepare(
            'SELECT c.*, pc.position FROM programme_courses pc JOIN courses c ON c.id = pc.course_id
             WHERE pc.programme_id = ?' . ($drafts ? '' : " AND c.status = 'published'") . ' ORDER BY pc.position, c.title'
        );
        $s->execute([$programmeId]);
        return $s->fetchAll();
    }

    /** Published programmes a course belongs to (for the course page's "part of"). */
    public function programmesFor(int $courseId): array
    {
        $s = $this->db->prepare("SELECT p.* FROM programmes p JOIN programme_courses pc ON pc.programme_id = p.id WHERE pc.course_id = ? AND p.status = 'published' ORDER BY p.sort, p.title");
        $s->execute([$courseId]);
        return $s->fetchAll();
    }

    /**
     * A learner's standing in a programme: per course certified or not, and
     * the programme certificate once every course is certified.
     */
    public function programmeProgress(int $userId, array $programme): array
    {
        $courses = $this->programmeCourses((int) $programme['id']);
        $certified = [];
        $s = $this->db->prepare('SELECT course_id FROM certificates WHERE user_id = ?');
        $s->execute([$userId]);
        foreach ($s->fetchAll() as $r) $certified[(int) $r['course_id']] = true;
        $dc = $this->doneCounts($userId);
        $mine = $this->enrolledIds($userId);
        $done = 0; $started = false; $rows = [];
        foreach ($courses as $c) {
            $ok = isset($certified[(int) $c['id']]);
            if ($ok) $done++;
            if ($ok || ($dc[(int) $c['id']] ?? 0) > 0 || isset($mine[(int) $c['id']])) $started = true;
            $rows[] = ['course' => $c, 'certified' => $ok];
        }
        $total = count($courses);
        return ['courses' => $rows, 'done' => $done, 'total' => $total, 'pct' => $total ? (int) round($done / $total * 100) : 0,
                'complete' => $total > 0 && $done >= $total, 'started' => $started,
                'cert' => $this->programmeCertificate($userId, (int) $programme['id'])];
    }

    public function programmeCertificate(int $userId, int $programmeId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM programme_certificates WHERE user_id = ? AND programme_id = ?');
        $s->execute([$userId, $programmeId]);
        return $s->fetch() ?: null;
    }

    /** Issue the programme certificate if, and only if, every course is certified. */
    public function issueProgrammeCertificate(int $userId, array $programme): ?array
    {
        if ($cur = $this->programmeCertificate($userId, (int) $programme['id'])) return $cur;
        if (!$this->programmeProgress($userId, $programme)['complete']) return null;
        $serial = 'AVP-' . strtoupper(bin2hex(random_bytes(6))) . '-' . gmdate('Y');
        $this->db->prepare(Database::insertIgnore('programme_certificates', ['user_id', 'programme_id', 'serial', 'issued_at']))
            ->execute([$userId, (int) $programme['id'], $serial, self::now()]);
        return $this->programmeCertificate($userId, (int) $programme['id']);
    }

    public function programmeCertBySerial(string $serial): ?array
    {
        $s = $this->db->prepare(
            'SELECT pc.serial, pc.issued_at, u.name AS learner, p.title AS programme_title, p.slug AS programme_slug
             FROM programme_certificates pc JOIN lms_users u ON u.id = pc.user_id JOIN programmes p ON p.id = pc.programme_id WHERE pc.serial = ?'
        );
        $s->execute([$serial]);
        return $s->fetch() ?: null;
    }

    /** Create or update a programme; returns its slug. $courseSlugs sets the series in order. */
    public function saveProgramme(array $d, ?array $courseSlugs = null): string
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('A title is required.');
        $slug = slugify((string) (($d['slug'] ?? '') ?: $title)) ?: 'programme';
        $editing = (string) ($d['_editing'] ?? '');
        $ex = $editing !== '' ? $this->programme($editing, true) : null;
        $base = $slug; $i = 2;
        $chk = $this->db->prepare('SELECT id FROM programmes WHERE slug = ? AND id <> ?');
        while (true) { $chk->execute([$slug, (int) ($ex['id'] ?? 0)]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $i++; }
        $f = ['slug' => $slug, 'title' => mb_substr($title, 0, 200), 'summary' => mb_substr(trim((string) ($d['summary'] ?? '')), 0, 1000),
              'body_html' => (string) ($d['body_html'] ?? ''), 'cover_url' => ($d['cover_url'] ?? '') ?: null,
              'status' => ($d['status'] ?? 'draft') === 'published' ? 'published' : 'draft', 'sort' => (int) ($d['sort'] ?? 0), 'updated_at' => self::now()];
        if ($ex) {
            $set = implode(', ', array_map(static fn($k) => "$k = :$k", array_keys($f)));
            $this->db->prepare("UPDATE programmes SET $set WHERE id = :id")->execute($f + ['id' => (int) $ex['id']]);
            $pid = (int) $ex['id'];
        } else {
            $f['created_at'] = $f['updated_at'];
            $this->db->prepare('INSERT INTO programmes (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_map(static fn($k) => ":$k", array_keys($f))) . ')')->execute($f);
            $pid = (int) $this->db->lastInsertId();
        }
        if ($courseSlugs !== null) {
            $this->db->prepare('DELETE FROM programme_courses WHERE programme_id = ?')->execute([$pid]);
            $find = $this->db->prepare('SELECT id FROM courses WHERE slug = ?');
            $ins = $this->db->prepare(Database::insertIgnore('programme_courses', ['programme_id', 'course_id', 'position']));
            $pos = 0;
            foreach ($courseSlugs as $cs) {
                $find->execute([(string) $cs]);
                if ($cid = $find->fetchColumn()) $ins->execute([$pid, (int) $cid, $pos++]);
            }
        }
        return $slug;
    }

    /** Delete a programme. Refused when certificates have been issued for it, unless forced. */
    public function deleteProgramme(string $slug, bool $force = false): array
    {
        $p = $this->programme($slug, true);
        if (!$p) return ['ok' => false, 'error' => 'Programme not found.'];
        $s = $this->db->prepare('SELECT COUNT(*) FROM programme_certificates WHERE programme_id = ?');
        $s->execute([(int) $p['id']]);
        $n = (int) $s->fetchColumn();
        if ($n > 0 && !$force) return ['ok' => false, 'needs_confirm' => true, 'error' => $n . ' programme certificate(s) were issued. Unpublish it instead, or confirm to delete them too.'];
        $this->db->prepare('DELETE FROM programme_certificates WHERE programme_id = ?')->execute([(int) $p['id']]);
        $this->db->prepare('DELETE FROM programme_courses WHERE programme_id = ?')->execute([(int) $p['id']]);
        $this->db->prepare('DELETE FROM programmes WHERE id = ?')->execute([(int) $p['id']]);
        return ['ok' => true];
    }
}
