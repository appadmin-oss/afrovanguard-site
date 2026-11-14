<?php
/**
 * lib/LearnerFeedback.php — learners give written feedback on a COURSE.
 * Feedback, not ratings: there are no stars and no scores of instructors or
 * courses anywhere in the Academy, and no feedback names, targets or is
 * attributed to an instructor. It belongs to the course, and the whole course
 * team reads it.
 *
 * ── THE RULES, STATED ONCE ──────────────────────────────────────────────────
 *  • WHO MAY GIVE FEEDBACK: an enrolled learner (not on the course's team)
 *    who has completed at least half the course's lessons, or has had at
 *    least one grade released on it. eligibility() is the only answer.
 *  • WHAT IT IS: written answers to a few optional prompts about the course
 *    (PROMPTS). Every answer is optional; saving needs at least one.
 *  • ONE PER LEARNER PER COURSE, held by the database too: UNIQUE
 *    (course_id, user_id) on course_feedback. EDITABLE FOR 30 DAYS from when
 *    it was first given, then fixed.
 *  • WHO READS IT: every member of the course's team (lead, instructor,
 *    assistant), under the rules below. ANONYMITY: nothing a team member or
 *    the instructor list reads carries a learner's id, name, email or date —
 *    forTeam() and counts() never select them, and the moderation queue does
 *    not show them either.
 *  • APPROVAL. Every piece of feedback waits for Academy staff (CourseTeam
 *    right `moderate_feedback`) to approve it before any instructor sees it.
 *    Hiding takes a reason: abusive, identifying or off-topic. Nobody
 *    moderates feedback on a course they teach. Changing the words sends it
 *    back to the queue.
 *  • THE THRESHOLD. Approved feedback reaches the team only once at least
 *    MIN_LEARNERS learners on that course have given feedback, so a single
 *    comment cannot be traced to the one learner who wrote it.
 *  • TESTIMONIALS. The course page may quote approved feedback only where
 *    the learner ticked "you may quote me" (default off) — their answer to
 *    the first prompt, as "Shared with permission", with their first name and
 *    last initial. Unticking takes it down at once. No stars, no averages.
 *  • ONE REMINDER. The web cron emails a learner who qualifies and has not
 *    given feedback, once per learner per course, ever — claimed in
 *    feedback_prompts before it sends.
 *
 * Who may do what is CourseTeam::can() — never re-derived here.
 */
declare(strict_types=1);

final class LearnerFeedback
{
    public const MIN_LEARNERS = 3;
    public const EDIT_DAYS = 30;
    public const MIN_LESSON_SHARE = 0.5;
    /** The guiding prompts: column => the question the learner reads. */
    public const PROMPTS = ['helped' => 'What helped you learn on this course?', 'clearer' => 'What could be clearer?', 'anything_else' => 'Anything else the course team should know?'];
    public const HIDE_REASONS = ['abusive' => 'Abusive', 'identifying' => 'Identifies someone', 'off-topic' => 'Off-topic'];
    public const MAX_TEXT = 2000;
    public const REMIND_BATCH = 200;

    private static bool $ready = false;

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS course_feedback (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id     INTEGER NOT NULL,
            user_id       INTEGER NOT NULL,
            helped        TEXT,
            clearer       TEXT,
            anything_else TEXT,
            share_public  INTEGER NOT NULL DEFAULT 0,
            state         VARCHAR(16) NOT NULL DEFAULT '',
            hide_reason   VARCHAR(32) NOT NULL DEFAULT '',
            moderated_by  INTEGER NOT NULL DEFAULT 0,
            moderated_at  VARCHAR(32) NOT NULL DEFAULT '',
            created_at    VARCHAR(32) NOT NULL DEFAULT '',
            updated_at    VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE (course_id, user_id)
        );
        CREATE INDEX IF NOT EXISTS idx_course_feedback_state ON course_feedback (state, course_id);
        CREATE TABLE IF NOT EXISTS feedback_prompts (
            course_id  INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            sent_at    VARCHAR(32) NOT NULL DEFAULT '',
            PRIMARY KEY (course_id, user_id)
        )";
    }

    public const TABLES = ['course_feedback', 'feedback_prompts'];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Grading::ensure();
        Database::execSchema(Database::pdo(), self::ddl());
    }

    public static function reset(): void { self::$ready = false; }

    /* ══ Who may give feedback ═══════════════════════════════════════════ */

    /** @return array{ok:bool, error?:string, code?:string, lessons_done:int, lessons_total:int, released:bool} */
    public static function eligibility(int $courseId, int $userId): array
    {
        self::ensure();
        $lms = new LmsRepository();
        $out = ['ok' => false, 'lessons_done' => 0, 'lessons_total' => 0, 'released' => false];
        if ($courseId <= 0 || $userId <= 0) return $out + ['code' => 'signin', 'error' => 'Sign in to give feedback on this course.'];
        if (CourseTeam::roleOf($courseId, $userId) !== null) return $out + ['code' => 'team', 'error' => 'You teach this course, so you cannot give learner feedback on it.'];
        if (!$lms->isEnrolled($userId, $courseId)) return $out + ['code' => 'enrol', 'error' => 'Only learners on this course can give feedback on it.'];
        $db = Database::pdo();
        $total = $lms->lessonCount($courseId);
        $st = $db->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND course_id = ?');
        $st->execute([$userId, $courseId]);
        $done = (int) $st->fetchColumn();
        $st = $db->prepare("SELECT 1 FROM grade_submissions WHERE course_id = ? AND user_id = ? AND released_at <> '' LIMIT 1");
        $st->execute([$courseId, $userId]);
        $released = (bool) $st->fetchColumn();
        $out['lessons_done'] = $done; $out['lessons_total'] = $total; $out['released'] = $released;
        if (!($total > 0 && $done >= $total * self::MIN_LESSON_SHARE) && !$released) {
            return $out + ['code' => 'progress', 'error' => 'You can give feedback on this course once you have completed half its lessons or had a grade released.'];
        }
        return ['ok' => true] + $out;
    }


    /* ══ A learner's own feedback ═════════════════════════════════════════ */

    /** This learner's feedback on this course; null if none. */
    public static function mine(int $courseId, int $userId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM course_feedback WHERE course_id = ? AND user_id = ?');
        $st->execute([$courseId, $userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $t = strtotime((string) $r['created_at'] . ' UTC');
        $r['editable_until'] = ($t === false ? 0 : $t) + self::EDIT_DAYS * 86400;
        $r['editable'] = Grading::now() <= $r['editable_until'];
        return $r;
    }

    /** The prompt answers out of a form array, trimmed and capped. */
    private static function answers(array $x): array
    {
        $a = [];
        foreach (array_keys(self::PROMPTS) as $k) $a[$k] = mb_substr(trim((string) ($x[$k] ?? '')), 0, self::MAX_TEXT);
        return $a;
    }

    private static function blank(array $a): bool { return implode('', $a) === ''; }

    /**
     * Give or edit this learner's feedback.
     * $d = ['helped' => …, 'clearer' => …, 'anything_else' => …, 'share' => bool]
     * Changed words go back to the moderation queue; unchanged words keep
     * their decision. Changing only the sharing choice needs no new approval.
     */
    public static function save(int $courseId, int $userId, array $d): array
    {
        self::ensure();
        $el = self::eligibility($courseId, $userId);
        if (!$el['ok']) return ['ok' => false, 'code' => $el['code'] ?? 'ineligible', 'error' => $el['error'] ?? 'You cannot give feedback on this course.'];
        $mine = self::mine($courseId, $userId);
        if ($mine && !$mine['editable']) return ['ok' => false, 'code' => 'locked', 'error' => 'Feedback can be changed for ' . self::EDIT_DAYS . ' days after it is given. Yours is now fixed.'];
        $a = self::answers($d);
        if (self::blank($a)) return ['ok' => false, 'error' => 'Write something in at least one box.'];
        $share = !empty($d['share']) ? 1 : 0;
        $db = Database::pdo();
        $now = Grading::stamp();
        if ($mine) {
            $same = self::answers($mine) === $a;
            $db->prepare('UPDATE course_feedback SET helped = ?, clearer = ?, anything_else = ?, share_public = ?, state = ?, hide_reason = ?, moderated_by = ?, moderated_at = ?, updated_at = ? WHERE id = ?')
               ->execute([$a['helped'], $a['clearer'], $a['anything_else'], $share, $same ? $mine['state'] : 'pending', $same ? $mine['hide_reason'] : '',
                          $same ? (int) $mine['moderated_by'] : 0, $same ? $mine['moderated_at'] : '', $now, (int) $mine['id']]);
            return ['ok' => true, 'id' => (int) $mine['id'], 'edited' => true];
        }
        try {
            $db->prepare("INSERT INTO course_feedback (course_id, user_id, helped, clearer, anything_else, share_public, state, created_at, updated_at) VALUES (?,?,?,?,?,?,'pending',?,?)")
               ->execute([$courseId, $userId, $a['helped'], $a['clearer'], $a['anything_else'], $share, $now, $now]);
        } catch (PDOException $e) {
            // The UNIQUE key: another request made this learner's feedback first.
            return ['ok' => false, 'code' => 'duplicate', 'error' => 'You have already given feedback on this course — edit it instead.'];
        }
        return ['ok' => true, 'id' => (int) $db->lastInsertId(), 'edited' => false];
    }

    /* ══ Moderation ═══════════════════════════════════════════════════════ */

    /**
     * Approve or hide a learner's feedback (a course_feedback id). Hiding
     * needs a reason from HIDE_REASONS. Nobody moderates feedback on a course
     * they teach.
     */
    public static function moderate(int $id, int $by, string $decision, string $reason = ''): array
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM course_feedback WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || self::blank(self::answers($row))) return ['ok' => false, 'error' => 'Nothing to moderate.'];
        $courseId = (int) $row['course_id'];
        if (!CourseTeam::can($courseId, $by, 'moderate_feedback')) return ['ok' => false, 'error' => 'Only Academy staff moderate feedback.'];
        if (CourseTeam::roleOf($courseId, $by) !== null) return ['ok' => false, 'error' => 'Feedback on a course you teach is moderated by someone else.'];
        if ($decision === 'approve') { $state = 'approved'; $reason = ''; }
        elseif ($decision === 'hide') {
            if (!isset(self::HIDE_REASONS[$reason])) return ['ok' => false, 'error' => 'Say why it is hidden: abusive, identifying or off-topic.'];
            $state = 'hidden';
        } else return ['ok' => false, 'error' => 'Approve or hide.'];
        $db->prepare('UPDATE course_feedback SET state = ?, hide_reason = ?, moderated_by = ?, moderated_at = ? WHERE id = ?')->execute([$state, $reason, $by, Grading::stamp(), $id]);
        Grading::audit('feedback.' . $state, $courseId, 'feedback#' . $id, $reason, $by);
        return ['ok' => true, 'state' => $state];
    }

    /**
     * The moderation queue for this person: pending feedback on courses they
     * may moderate, never about themselves. No learner identity — a moderator
     * judges the words, not who wrote them.
     */
    public static function pending(int $by): array
    {
        self::ensure();
        if (!CourseTeam::canAnywhere($by, 'moderate_feedback')) return [];
        $db = Database::pdo();
        $out = [];
        foreach ($db->query("SELECT f.*, c.title AS course_title FROM course_feedback f JOIN courses c ON c.id = f.course_id WHERE f.state = 'pending' ORDER BY f.updated_at, f.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!CourseTeam::can((int) $r['course_id'], $by, 'moderate_feedback') || CourseTeam::roleOf((int) $r['course_id'], $by) !== null) continue;
            $out[] = ['id' => (int) $r['id'], 'course_title' => (string) $r['course_title'], 'answers' => self::answers($r),
                      'share' => (bool) $r['share_public'], 'month' => substr((string) $r['updated_at'], 0, 7)];
        }
        return $out;
    }

    /* ══ What the course team sees ════════════════════════════════════════ */

    /** How many learners on this course have given feedback (any state). The threshold reads this. */
    public static function giversOn(int $courseId): int
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM course_feedback WHERE course_id = ?');
        $st->execute([$courseId]);
        return (int) $st->fetchColumn();
    }

    /** Whether approved feedback on this course may be shown to its team yet. */
    public static function thresholdMet(int $courseId): bool { return self::giversOn($courseId) >= self::MIN_LEARNERS; }

    /**
     * Per course, the feedback learners gave on it: received (every piece),
     * approved (by staff), shared (approved AND the course is past the
     * threshold — what the team can actually read). Feedback on the COURSE,
     * shared by its whole team: never attributed to one instructor.
     * @return array<int, array{received:int, approved:int, shared:int, threshold_met:bool, givers:int}>
     */
    public static function counts(array $courseIds, string $from = ''): array
    {
        self::ensure();
        $out = [];
        foreach (array_values(array_filter(array_map('intval', $courseIds))) as $c) {
            $sql = 'SELECT state FROM course_feedback WHERE course_id = ?'; $args = [$c];
            if ($from !== '') { $sql .= ' AND created_at >= ?'; $args[] = $from; }
            $st = Database::pdo()->prepare($sql); $st->execute($args);
            $states = $st->fetchAll(PDO::FETCH_COLUMN);
            $met = self::thresholdMet($c);
            $approved = count(array_filter($states, static fn($x) => $x === 'approved'));
            $out[$c] = ['received' => count($states), 'approved' => $approved, 'shared' => $met ? $approved : 0,
                        'threshold_met' => $met, 'givers' => self::giversOn($c)];
        }
        return $out;
    }

    /**
     * The approved feedback a member of this course's team may read: only once
     * MIN_LEARNERS learners on the course have given feedback, and only for a
     * team member or Academy staff. The words; never who, and never when
     * (a date narrows a small class to a person).
     * @return array{ok:bool, shown:bool, givers:int, items:array<int, array<string,string>>}
     */
    public static function forTeam(int $courseId, int $viewer, string $from = ''): array
    {
        self::ensure();
        if (!CourseTeam::can($courseId, $viewer, 'view')) return ['ok' => false, 'shown' => false, 'givers' => 0, 'items' => []];
        $givers = self::giversOn($courseId);
        if ($givers < self::MIN_LEARNERS) return ['ok' => true, 'shown' => false, 'givers' => $givers, 'items' => []];
        $sql = "SELECT helped, clearer, anything_else FROM course_feedback WHERE course_id = ? AND state = 'approved'"; $args = [$courseId];
        if ($from !== '') { $sql .= ' AND created_at >= ?'; $args[] = $from; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY helped, clearer, anything_else');
        $st->execute($args);
        return ['ok' => true, 'shown' => true, 'givers' => $givers, 'items' => array_map([self::class, 'answers'], $st->fetchAll(PDO::FETCH_ASSOC))];
    }

    /* ══ The public course page ═══════════════════════════════════════════ */

    /** "Ada Obi" → "Ada O."; a single name stays as it is. */
    public static function publicName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (!$parts || $parts[0] === '') return 'A learner';
        if (count($parts) < 2) return $parts[0];
        return $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
    }

    /**
     * Testimonials: approved feedback whose learner ticked "you may quote me"
     * (default off). The quote is their answer to the first prompt, "What
     * helped you learn on this course?" — never what they wrote for the team
     * about what could be clearer. Unticking takes it down at once.
     */
    public static function testimonials(int $courseId, int $limit = 6): array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT f.helped, f.updated_at, u.name FROM course_feedback f JOIN lms_users u ON u.id = f.user_id
                                         WHERE f.course_id = ? AND f.state = 'approved' AND f.share_public = 1 AND f.helped <> '' ORDER BY f.updated_at DESC, f.id DESC LIMIT " . max(1, $limit));
        $st->execute([$courseId]);
        return array_map(static fn($r) => ['who' => self::publicName((string) $r['name']), 'text' => (string) $r['helped'],
            'month' => Grading::toLocal((string) $r['updated_at'], 'F Y')], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ══ The prompt and the one reminder ══════════════════════════════════ */

    public static function shouldInvite(int $courseId, int $userId): bool
    {
        if ($userId <= 0) return false;
        return self::mine($courseId, $userId) === null && self::eligibility($courseId, $userId)['ok'];
    }

    /**
     * Email learners who qualify and have not given feedback — once per
     * learner per course, ever. Each is claimed in feedback_prompts (PRIMARY
     * KEY course + learner) before the email goes.
     */
    public static function remind(): int
    {
        self::ensure();
        $db = Database::pdo();
        $st = $db->query("SELECT e.course_id, e.user_id FROM course_enrolment e JOIN courses c ON c.id = e.course_id
                           WHERE NOT EXISTS (SELECT 1 FROM course_feedback f WHERE f.course_id = e.course_id AND f.user_id = e.user_id)
                             AND NOT EXISTS (SELECT 1 FROM feedback_prompts p WHERE p.course_id = e.course_id AND p.user_id = e.user_id)
                           ORDER BY e.course_id, e.user_id");
        $claim = $db->prepare(Database::insertIgnore('feedback_prompts', ['course_id', 'user_id', 'sent_at']));
        $sent = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($sent >= self::REMIND_BATCH) break;
            $cid = (int) $r['course_id']; $uid = (int) $r['user_id'];
            if (!self::eligibility($cid, $uid)['ok']) continue;
            $claim->execute([$cid, $uid, Grading::stamp()]);
            if ($claim->rowCount() !== 1) continue;   // another tick got there first
            $sent++;
            self::mailInvite($cid, $uid);
        }
        return $sent;
    }

    private static function mailInvite(int $courseId, int $userId): void
    {
        try {
            if (!class_exists('Mailer')) return;
            $u = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?'); $u->execute([$userId]); $u = $u->fetch(PDO::FETCH_ASSOC);
            $c = Database::pdo()->prepare('SELECT slug, title FROM courses WHERE id = ?'); $c->execute([$courseId]); $c = $c->fetch(PDO::FETCH_ASSOC);
            if (!$u || !$c) return;
            $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $first = explode(' ', trim((string) $u['name']))[0] ?: 'there';
            $subject = 'Your feedback on ' . $c['title'];
            Mailer::send((string) $u['email'], $subject, Mailer::shell($subject, [
                'Hi ' . $h($first) . ',',
                'You are far enough into <strong>' . $h((string) $c['title']) . '</strong> to tell us, and the people who teach it, what is working and what could be clearer.',
                'Instructors never see who wrote it, and Academy staff read it before anyone else does. This is the only reminder we will send.',
            ], ['url' => academy_url($c['slug'] . '/grades/?feedback=1'), 'text' => 'Give feedback'], 'Your feedback', ['eyebrow' => 'Afrovanguard Academy']));
        } catch (Throwable $e) { error_log('[feedback] reminder: ' . $e->getMessage()); }
    }
}
