<?php
/**
 * lib/Grading.php — graded items, weights, the pass mark, and what a learner's
 * grade is. Coursera's model, on this Academy.
 *
 * A course may carry GRADED ITEMS. Each has a kind, a weight, a passing score,
 * an optional due date with a late window and a late penalty, and a number of
 * attempts:
 *
 *   quiz        an existing lesson quiz counted towards the grade. Machine
 *               marked; the best counted attempt is the score, and it counts as
 *               soon as it is taken (the learner already saw it on the lesson).
 *   assignment  instructor-marked against a rubric. The learner submits text, a
 *               link and/or one file (lib/GradingWork.php).
 *   peer        learners review each other's submissions against the same
 *               rubric; the median of the peer scores is the score
 *               (lib/PeerReview.php).
 *   live        a live assessment: learners book a slot in a scheduled
 *               session and a panel of the team scores them there against the
 *               rubric (lib/LiveAssess.php). Each panelist's score is a mark
 *               like any other, so moderation, release, weights, the gradebook
 *               and the certificate treat it exactly as an assignment — there
 *               is no second scoring path.
 *
 * ── THE RULES, STATED ONCE ──────────────────────────────────────────────────
 *  • Weights of a course's items add to 100. Publishing is refused otherwise,
 *    and so is any weight change on a course that has published items.
 *  • A draft is invisible to learners. A published item that has been used
 *    (submissions, or quiz attempts on its lesson) keeps its rubric and weight
 *    unless the change carries a reason, which goes on the audit trail like a
 *    regrade. Once used, an item is retired, never deleted.
 *  • A learner sees an assignment or peer score only once a member of the team
 *    RELEASES it (GradingWork::release). Never as soon as someone types a mark.
 *  • Where a learner has several attempts, the LATEST released attempt counts —
 *    not the best. Quizzes are the exception: the best counted attempt.
 *  • A late submission (after the due date, inside the late window) keeps its
 *    mark; the late penalty comes off the final and is shown separately.
 *  • The course grade is the weighted average of the item scores; an item whose
 *    window closed with nothing submitted counts as 0. A learner passes when
 *    every item is passed and the grade meets the course pass mark (default 70,
 *    set by the lead).
 *
 * Who may do what is CourseTeam::can() — never re-derived here.
 *
 * Times are stored as UTC 'Y-m-d H:i:s'. Forms speak the organisation's time
 * zone (AV_TZ, Lagos), and fromLocal()/toLocal() are the only conversions.
 */
declare(strict_types=1);

final class Grading
{
    public const KINDS = ['quiz', 'assignment', 'peer', 'live'];
    public const STATUSES = ['draft', 'published', 'retired'];
    public const DEFAULT_PASS_MARK = 70;
    public const DEFAULT_MODERATION = 15;
    public const ACCEPTS = ['text', 'link', 'file'];
    public const MAX_CRITERIA = 12;

    private const KIND_LABELS = ['quiz' => 'Quiz', 'assignment' => 'Assignment', 'peer' => 'Peer-reviewed assignment', 'live' => 'Live assessment'];

    private static bool $ready = false;
    private static ?int $clock = null;

    /* ══ Schema ═══════════════════════════════════════════════════════════ */

    /**
     * The grading tables, as canonical SQLite DDL that Database::execSchema()
     * translates per engine. The same tables are in db/schema.sql and its
     * MySQL / Postgres siblings; tests/grading.test.php holds the two in step.
     * No ';' may appear inside this string except between statements.
     */
    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS course_grading (
            course_id  INTEGER PRIMARY KEY,
            pass_mark  INTEGER NOT NULL DEFAULT 70,
            updated_by INTEGER NOT NULL DEFAULT 0,
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS graded_items (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id        INTEGER NOT NULL,
            kind             VARCHAR(16) NOT NULL DEFAULT 'assignment',
            title            VARCHAR(191) NOT NULL DEFAULT '',
            instructions     TEXT,
            lesson_id        INTEGER NOT NULL DEFAULT 0,
            weight           INTEGER NOT NULL DEFAULT 0,
            pass_score       INTEGER NOT NULL DEFAULT 50,
            due_at           VARCHAR(32) NOT NULL DEFAULT '',
            late_hours       INTEGER NOT NULL DEFAULT 0,
            late_penalty     INTEGER NOT NULL DEFAULT 0,
            max_attempts     INTEGER NOT NULL DEFAULT 1,
            accepts          VARCHAR(32) NOT NULL DEFAULT 'text,link,file',
            rubric_json      TEXT,
            peer_count       INTEGER NOT NULL DEFAULT 3,
            peer_grace_hours INTEGER NOT NULL DEFAULT 72,
            moderation_pct   INTEGER NOT NULL DEFAULT 15,
            status           VARCHAR(16) NOT NULL DEFAULT 'draft',
            position         INTEGER NOT NULL DEFAULT 0,
            created_by       INTEGER NOT NULL DEFAULT 0,
            created_at       VARCHAR(32) NOT NULL DEFAULT '',
            updated_at       VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_graded_items_course ON graded_items (course_id, status);
        CREATE TABLE IF NOT EXISTS grade_submissions (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id           INTEGER NOT NULL,
            course_id         INTEGER NOT NULL,
            user_id           INTEGER NOT NULL,
            attempt           INTEGER NOT NULL DEFAULT 1,
            body              TEXT,
            link              VARCHAR(500) NOT NULL DEFAULT '',
            file_name         VARCHAR(191) NOT NULL DEFAULT '',
            file_store        VARCHAR(120) NOT NULL DEFAULT '',
            file_mime         VARCHAR(100) NOT NULL DEFAULT '',
            file_size         INTEGER NOT NULL DEFAULT 0,
            submitted_at      VARCHAR(32) NOT NULL DEFAULT '',
            is_late           INTEGER NOT NULL DEFAULT 0,
            final_pct         REAL,
            final_source      VARCHAR(16) NOT NULL DEFAULT '',
            final_reason      TEXT,
            final_by          INTEGER NOT NULL DEFAULT 0,
            final_at          VARCHAR(32) NOT NULL DEFAULT '',
            flagged           INTEGER NOT NULL DEFAULT 0,
            released_raw      REAL,
            released_pct      REAL,
            released_penalty  INTEGER NOT NULL DEFAULT 0,
            released_feedback TEXT,
            released_at       VARCHAR(32) NOT NULL DEFAULT '',
            released_by       INTEGER NOT NULL DEFAULT 0,
            UNIQUE (item_id, user_id, attempt)
        );
        CREATE INDEX IF NOT EXISTS idx_grade_sub_course ON grade_submissions (course_id, submitted_at);
        CREATE INDEX IF NOT EXISTS idx_grade_sub_user ON grade_submissions (user_id, item_id);
        CREATE TABLE IF NOT EXISTS grade_marks (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            marker_id     INTEGER NOT NULL,
            scores_json   TEXT,
            points        REAL NOT NULL DEFAULT 0,
            max_points    REAL NOT NULL DEFAULT 0,
            feedback      TEXT,
            created_at    VARCHAR(32) NOT NULL DEFAULT '',
            updated_at    VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE (submission_id, marker_id)
        );
        CREATE TABLE IF NOT EXISTS peer_reviews (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id       INTEGER NOT NULL,
            submission_id INTEGER NOT NULL,
            reviewer_id   INTEGER NOT NULL,
            assigned_at   VARCHAR(32) NOT NULL DEFAULT '',
            scores_json   TEXT,
            points        REAL,
            max_points    REAL,
            feedback      TEXT,
            done_at       VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE (submission_id, reviewer_id)
        );
        CREATE INDEX IF NOT EXISTS idx_peer_reviews_reviewer ON peer_reviews (reviewer_id, item_id);
        CREATE TABLE IF NOT EXISTS live_sessions (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id     INTEGER NOT NULL,
            course_id   INTEGER NOT NULL,
            starts_at   VARCHAR(32) NOT NULL DEFAULT '',
            ends_at     VARCHAR(32) NOT NULL DEFAULT '',
            place       VARCHAR(300) NOT NULL DEFAULT '',
            link        VARCHAR(500) NOT NULL DEFAULT '',
            capacity    INTEGER NOT NULL DEFAULT 10,
            status      VARCHAR(16) NOT NULL DEFAULT 'open',
            created_by  INTEGER NOT NULL DEFAULT 0,
            created_at  VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_live_sessions_item ON live_sessions (item_id, starts_at);
        CREATE TABLE IF NOT EXISTS live_bookings (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id    INTEGER NOT NULL,
            item_id       INTEGER NOT NULL,
            user_id       INTEGER NOT NULL,
            live_key      VARCHAR(32) NOT NULL DEFAULT 'live',
            status        VARCHAR(16) NOT NULL DEFAULT 'booked',
            attempt       INTEGER NOT NULL DEFAULT 1,
            submission_id INTEGER NOT NULL DEFAULT 0,
            booked_by     INTEGER NOT NULL DEFAULT 0,
            booked_at     VARCHAR(32) NOT NULL DEFAULT '',
            excuse_reason VARCHAR(500) NOT NULL DEFAULT '',
            excused_by    INTEGER NOT NULL DEFAULT 0,
            reminded_at   VARCHAR(32) NOT NULL DEFAULT '',
            UNIQUE (item_id, user_id, live_key)
        );
        CREATE INDEX IF NOT EXISTS idx_live_bookings_session ON live_bookings (session_id, status);
        CREATE TABLE IF NOT EXISTS grading_digests (
            course_id   INTEGER NOT NULL,
            digest_day  VARCHAR(10) NOT NULL,
            sent_at     VARCHAR(32) NOT NULL DEFAULT '',
            recipients  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (course_id, digest_day)
        )";
    }

    /** The table names ddl() creates, for the schema-parity test and the MySQL proof. */
    public const TABLES = ['course_grading', 'graded_items', 'grade_submissions', 'grade_marks', 'peer_reviews', 'live_sessions', 'live_bookings', 'grading_digests'];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        CourseTeam::ensure();
        $db = Database::pdo();
        Database::execSchema($db, self::ddl());
        // A certificate records the grade it was issued on (graded courses only).
        try {
            if (Database::tableExists('certificates') && !Database::columnExists('certificates', 'grade_pct')) {
                Database::execSchema($db, 'ALTER TABLE certificates ADD COLUMN grade_pct REAL');
            }
        } catch (Throwable $e) { error_log('[grading] certificates.grade_pct: ' . $e->getMessage()); }
    }

    public static function reset(): void { self::$ready = false; }

    /* ══ Time ═════════════════════════════════════════════════════════════ */

    /** Pin "now" (a Unix time) for tests; null returns to the real clock. */
    public static function setClock(?int $ts): void { self::$clock = $ts; }
    public static function now(): int { return self::$clock ?? time(); }
    public static function stamp(?int $ts = null): string { return gmdate('Y-m-d H:i:s', $ts ?? self::now()); }

    private static function tz(): DateTimeZone
    {
        try { return new DateTimeZone(defined('AV_TZ') ? (string) AV_TZ : 'UTC'); } catch (Throwable $e) { return new DateTimeZone('UTC'); }
    }

    /** A form's local 'Y-m-d\TH:i' (or 'Y-m-d H:i') → stored UTC, '' if empty, null if not a date. */
    public static function fromLocal(string $v): ?string
    {
        $v = trim(str_replace('T', ' ', $v));
        if ($v === '') return '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v)) return null;
        $d = DateTime::createFromFormat(strlen($v) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s', $v, self::tz());
        if (!$d) return null;
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    }

    /** Stored UTC → the organisation's local time in $fmt ('' stays ''). */
    public static function toLocal(string $utc, string $fmt = 'j M Y, g:i a'): string
    {
        if ($utc === '') return '';
        try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(self::tz())->format($fmt); }
        catch (Throwable $e) { return $utc; }
    }

    private static function ts(string $utc): ?int
    {
        if ($utc === '') return null;
        $t = strtotime($utc . ' UTC');
        return $t === false ? null : $t;
    }

    /** When an item is due and when its window finally closes (null = never). */
    public static function window(array $item): array
    {
        $due = self::ts((string) $item['due_at']);
        $closes = $due === null ? null : $due + max(0, (int) $item['late_hours']) * 3600;
        $graceEnds = $due === null ? null : $due + max(0, (int) $item['peer_grace_hours']) * 3600;
        return ['due' => $due, 'closes' => $closes, 'grace_ends' => $graceEnds];
    }

    /* ══ Rubrics ══════════════════════════════════════════════════════════ */

    /**
     * Parse the rubric editor's text — one criterion per line:
     *   Name | max points | level: descriptor; level: descriptor
     * e.g.  Argument | 10 | 0: missing; 5: present but thin; 10: clear and evidenced
     * A plain form textarea, so writing a rubric needs no JavaScript.
     */
    public static function parseRubric(string $text): array
    {
        $criteria = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $name = mb_substr($parts[0] ?? '', 0, 120);
            $max = $parts[1] ?? '';
            if ($name === '' || !preg_match('/^\d+(\.\d+)?$/', $max) || (float) $max <= 0 || (float) $max > 100) {
                return ['ok' => false, 'error' => 'Rubric line ' . ($n + 1) . ' needs a name and a maximum between 1 and 100, like "Argument | 10 | 0: missing; 10: clear".'];
            }
            $levels = [];
            foreach (array_filter(array_map('trim', explode(';', (string) ($parts[2] ?? '')))) as $lv) {
                if (preg_match('/^(\d+(?:\.\d+)?)\s*:\s*(.+)$/', $lv, $m)) {
                    if ((float) $m[1] > (float) $max) return ['ok' => false, 'error' => 'Rubric line ' . ($n + 1) . ' has a level above its maximum.'];
                    $levels[] = ['points' => (float) $m[1], 'text' => mb_substr($m[2], 0, 300)];
                } else {
                    $levels[] = ['points' => null, 'text' => mb_substr($lv, 0, 300)];
                }
            }
            $criteria[] = ['name' => $name, 'max' => (float) $max, 'levels' => $levels];
        }
        if (!$criteria) return ['ok' => false, 'error' => 'A rubric needs at least one criterion.'];
        if (count($criteria) > self::MAX_CRITERIA) return ['ok' => false, 'error' => 'A rubric has at most ' . self::MAX_CRITERIA . ' criteria.'];
        return ['ok' => true, 'criteria' => $criteria];
    }

    /** The rubric back as editor text (the inverse of parseRubric). */
    public static function rubricText(array $criteria): string
    {
        $lines = [];
        foreach ($criteria as $c) {
            $lv = array_map(static fn($l) => ($l['points'] === null ? '' : self::num((float) $l['points']) . ': ') . $l['text'], $c['levels'] ?? []);
            $lines[] = $c['name'] . ' | ' . self::num((float) $c['max']) . ($lv ? ' | ' . implode('; ', $lv) : '');
        }
        return implode("\n", $lines);
    }

    public static function rubricMax(array $criteria): float
    {
        return (float) array_sum(array_map(static fn($c) => (float) $c['max'], $criteria));
    }

    /**
     * Check a set of criterion scores against a rubric. Every criterion must be
     * scored, each between 0 and its maximum.
     * @return array{ok:bool, error?:string, scores?:array<int,float>, points?:float, max?:float}
     */
    public static function scoreRubric(array $criteria, array $scores): array
    {
        $out = [];
        foreach ($criteria as $i => $c) {
            $v = $scores[$i] ?? null;
            if ($v === null || $v === '' || !is_numeric($v)) return ['ok' => false, 'error' => 'Score every criterion ("' . $c['name'] . '" is missing).'];
            $v = (float) $v;
            if ($v < 0 || $v > (float) $c['max']) return ['ok' => false, 'error' => '"' . $c['name'] . '" is scored out of ' . self::num((float) $c['max']) . '.'];
            $out[$i] = $v;
        }
        return ['ok' => true, 'scores' => $out, 'points' => (float) array_sum($out), 'max' => self::rubricMax($criteria)];
    }

    /** 12.0 → "12", 12.5 → "12.5". */
    public static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /* ══ Items ════════════════════════════════════════════════════════════ */

    public static function kindLabel(string $kind): string { return self::KIND_LABELS[$kind] ?? ucfirst($kind); }

    private static function hydrate(array $r): array
    {
        foreach (['id', 'course_id', 'lesson_id', 'weight', 'pass_score', 'late_hours', 'late_penalty', 'max_attempts',
                  'peer_count', 'peer_grace_hours', 'moderation_pct', 'position', 'created_by'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $rub = json_decode((string) ($r['rubric_json'] ?? ''), true);
        $r['rubric'] = is_array($rub) ? $rub : [];
        $r['rubric_max'] = self::rubricMax($r['rubric']);
        $r['accepts_list'] = array_values(array_intersect(self::ACCEPTS, explode(',', (string) $r['accepts'])));
        return $r;
    }

    public static function item(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM graded_items WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::hydrate($r) : null;
    }

    /**
     * A course's items in order. $which: 'all' (drafts, published, retired),
     * 'team' (drafts and published — what the team works with), 'published'
     * (what learners see and what the grade is made of).
     */
    public static function items(int $courseId, string $which = 'published'): array
    {
        self::ensure();
        $where = ['all' => '', 'team' => " AND status IN ('draft','published')", 'published' => " AND status = 'published'"][$which] ?? " AND status = 'published'";
        $st = Database::pdo()->prepare('SELECT * FROM graded_items WHERE course_id = ?' . $where . ' ORDER BY position, id');
        $st->execute([$courseId]);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Whether the course is graded — i.e. has at least one published item. */
    public static function hasGradedItems(int $courseId): bool
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT 1 FROM graded_items WHERE course_id = ? AND status = 'published' LIMIT 1");
        $st->execute([$courseId]);
        return (bool) $st->fetchColumn();
    }

    /** How much an item has been used: submissions, or attempts on its quiz lesson. */
    public static function usage(array $item): int
    {
        $db = Database::pdo();
        if ($item['kind'] === 'quiz') {
            if ((int) $item['lesson_id'] <= 0) return 0;
            $st = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?'); $st->execute([(int) $item['lesson_id']]);
            return (int) $st->fetchColumn();
        }
        $st = $db->prepare('SELECT COUNT(*) FROM grade_submissions WHERE item_id = ?'); $st->execute([(int) $item['id']]);
        $n = (int) $st->fetchColumn();
        if ($item['kind'] === 'live') {
            $st = $db->prepare('SELECT COUNT(*) FROM live_bookings WHERE item_id = ?'); $st->execute([(int) $item['id']]);
            $n += (int) $st->fetchColumn();
        }
        return $n;
    }

    /** Sum of the weights of the items that make up the grade once published (drafts + published). */
    public static function weightTotal(int $courseId): int
    {
        return (int) array_sum(array_map(static fn($i) => (int) $i['weight'], self::items($courseId, 'team')));
    }

    /**
     * Create or edit an item. $d is the editor form; $d['id'] edits.
     * @return array{ok:bool, id?:int, error?:string}
     */
    public static function saveItem(int $courseId, array $d, int $by): array
    {
        self::ensure();
        if (!CourseTeam::can($courseId, $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can set up graded items.'];
        $cur = !empty($d['id']) ? self::item((int) $d['id']) : null;
        if (!empty($d['id']) && (!$cur || (int) $cur['course_id'] !== $courseId)) return ['ok' => false, 'error' => 'That item is not on this course.'];
        if ($cur && $cur['status'] === 'retired') return ['ok' => false, 'error' => 'A retired item cannot be edited.'];
        $published = $cur && $cur['status'] === 'published';

        $kind = $cur ? (string) $cur['kind'] : (string) ($d['kind'] ?? '');
        if ($cur && isset($d['kind']) && $d['kind'] !== $kind) return ['ok' => false, 'error' => 'An item\'s kind cannot be changed once created.'];
        if (!in_array($kind, self::KINDS, true)) return ['ok' => false, 'error' => 'Choose a quiz, an assignment or a peer-reviewed assignment.'];

        $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 191);
        if ($title === '') return ['ok' => false, 'error' => 'Give the item a title.'];
        $int = static function ($v, int $min, int $max, int $def): ?int {
            if ($v === null || $v === '') return $def;
            if (!preg_match('/^-?\d+$/', trim((string) $v))) return null;
            $n = (int) $v; return ($n < $min || $n > $max) ? null : $n;
        };
        $weight = $int($d['weight'] ?? null, 0, 100, $cur ? (int) $cur['weight'] : 0);
        if ($weight === null) return ['ok' => false, 'error' => 'A weight is a whole number from 0 to 100.'];
        $passScore = $int($d['pass_score'] ?? null, 0, 100, $cur ? (int) $cur['pass_score'] : 50);
        if ($passScore === null) return ['ok' => false, 'error' => 'A passing score is a whole number from 0 to 100.'];
        $lateHours = $int($d['late_hours'] ?? null, 0, 720, $cur ? (int) $cur['late_hours'] : 0);
        $latePen = $int($d['late_penalty'] ?? null, 0, 100, $cur ? (int) $cur['late_penalty'] : 0);
        if ($lateHours === null || $latePen === null) return ['ok' => false, 'error' => 'The late window is 0–720 hours and the penalty 0–100%.'];
        $maxAtt = $int($d['max_attempts'] ?? null, $kind === 'quiz' ? 0 : 1, 20, $cur ? (int) $cur['max_attempts'] : 1);
        if ($maxAtt === null) return ['ok' => false, 'error' => $kind === 'quiz' ? 'Attempts are 0 (unlimited) to 20.' : 'Attempts are 1 to 20.'];
        if ($kind === 'peer') $maxAtt = 1;   // reviews attach to one submission; see docs/academy-grading.md
        $modPct = $int($d['moderation_pct'] ?? null, 0, 100, $cur ? (int) $cur['moderation_pct'] : self::DEFAULT_MODERATION);
        $peerCount = $int($d['peer_count'] ?? null, 1, 10, $cur ? (int) $cur['peer_count'] : 3);
        $peerGrace = $int($d['peer_grace_hours'] ?? null, 0, 720, $cur ? (int) $cur['peer_grace_hours'] : 72);
        if ($modPct === null || $peerCount === null || $peerGrace === null) return ['ok' => false, 'error' => 'Check the moderation threshold (0–100%), reviews per submission (1–10) and grace (0–720 hours).'];

        $due = array_key_exists('due_at', $d) ? self::fromLocal((string) $d['due_at']) : ($cur ? (string) $cur['due_at'] : '');
        if ($due === null) return ['ok' => false, 'error' => 'The due date is not a date.'];
        if ($kind === 'peer' && $due === '') return ['ok' => false, 'error' => 'A peer-reviewed item needs a due date: reviews and the fall-back to instructor marking run from it.'];

        $lessonId = 0; $rubricJson = null; $accepts = '';
        if ($kind === 'quiz') {
            $lessonId = (int) ($d['lesson_id'] ?? ($cur['lesson_id'] ?? 0));
            $st = Database::pdo()->prepare('SELECT quiz_json FROM lessons WHERE id = ? AND course_id = ?');
            $st->execute([$lessonId, $courseId]);
            $qj = $st->fetchColumn();
            if ($qj === false || !(new LmsRepository())->quiz(['quiz_json' => $qj])) return ['ok' => false, 'error' => 'Choose a lesson of this course that has a quiz.'];
        } else {
            $rub = self::parseRubric((string) ($d['rubric'] ?? ($cur ? self::rubricText($cur['rubric']) : '')));
            if (!$rub['ok']) return $rub;
            $rubricJson = json_encode($rub['criteria'], JSON_UNESCAPED_UNICODE);
            if ($kind !== 'live') {   // a live item takes no submissions: the panel scores it in the room
                $acc = isset($d['accepts']) ? (array) $d['accepts'] : ($cur ? $cur['accepts_list'] : self::ACCEPTS);
                $acc = array_values(array_intersect(self::ACCEPTS, array_map('strval', $acc)));
                if (!$acc) return ['ok' => false, 'error' => 'Say what learners may submit: text, a link and/or a file.'];
                $accepts = implode(',', $acc);
            }
        }
        $instructions = mb_substr(trim((string) ($d['instructions'] ?? ($cur['instructions'] ?? ''))), 0, 20000);
        $reason = trim((string) ($d['reason'] ?? ''));

        if ($published) {
            /* A published item's weight moves only through setWeights(), which
               can see all the weights at once and keep them at 100. */
            if ($weight !== (int) $cur['weight']) return ['ok' => false, 'error' => 'Change a published item\'s weight with "Weights", so the course still adds up to 100.'];
            $rubricChanged = $kind !== 'quiz' && $rubricJson !== json_encode($cur['rubric'], JSON_UNESCAPED_UNICODE);
            $lessonChanged = $kind === 'quiz' && $lessonId !== (int) $cur['lesson_id'];
            if (($rubricChanged || $lessonChanged) && self::usage($cur) > 0 && $reason === '') {
                return ['ok' => false, 'code' => 'reason_required', 'error' => 'Learners have already submitted this item. Changing how it is marked needs a reason, which is recorded.'];
            }
        }

        $now = self::stamp();
        $fields = [
            'title' => $title, 'instructions' => $instructions, 'lesson_id' => $lessonId, 'weight' => $weight,
            'pass_score' => $passScore, 'due_at' => $due, 'late_hours' => $lateHours, 'late_penalty' => $latePen,
            'max_attempts' => $maxAtt, 'accepts' => $accepts, 'rubric_json' => $rubricJson, 'peer_count' => $peerCount,
            'peer_grace_hours' => $peerGrace, 'moderation_pct' => $modPct, 'updated_at' => $now,
        ];
        $db = Database::pdo();
        if ($cur) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
            $db->prepare("UPDATE graded_items SET $set WHERE id = ?")->execute([...array_values($fields), (int) $cur['id']]);
            $id = (int) $cur['id'];
            // A new moderation threshold re-judges every submission not yet agreed by hand.
            if ($modPct !== (int) $cur['moderation_pct']) {
                $ids = $db->prepare("SELECT id FROM grade_submissions WHERE item_id = ? AND final_source <> 'moderated'");
                $ids->execute([$id]);
                foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $sid) GradingWork::recompute((int) $sid);
            }
            if ($published && isset($rubricChanged) && ($rubricChanged || $lessonChanged) && self::usage($cur) > 0) {
                self::audit('grading.item.regrade', $courseId, 'item#' . $id, 'marking changed: ' . $reason, $by);
            } else {
                self::audit('grading.item.save', $courseId, 'item#' . $id, $title, $by);
            }
        } else {
            $pos = (int) $db->query('SELECT COALESCE(MAX(position), 0) + 1 FROM graded_items WHERE course_id = ' . $courseId)->fetchColumn();
            $fields += ['course_id' => $courseId, 'kind' => $kind, 'status' => 'draft', 'position' => $pos, 'created_by' => max(0, $by), 'created_at' => $now];
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO graded_items ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int) $db->lastInsertId();
            self::audit('grading.item.create', $courseId, 'item#' . $id, $kind . ': ' . $title, $by);
        }
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Publish every draft item. Refused unless the weights of all the course's
     * items (published and drafts) add to exactly 100.
     */
    public static function publish(int $courseId, int $by): array
    {
        self::ensure();
        if (!CourseTeam::can($courseId, $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can publish graded items.'];
        $items = self::items($courseId, 'team');
        $drafts = array_filter($items, static fn($i) => $i['status'] === 'draft');
        if (!$drafts) return ['ok' => false, 'error' => 'There is nothing in draft to publish.'];
        $total = (int) array_sum(array_map(static fn($i) => (int) $i['weight'], $items));
        if ($total !== 100) return ['ok' => false, 'code' => 'weights', 'error' => 'The weights add to ' . $total . ', not 100. Adjust them before publishing.'];
        $st = Database::pdo()->prepare("UPDATE graded_items SET status = 'published', updated_at = ? WHERE id = ? AND status = 'draft'");
        foreach ($drafts as $i) $st->execute([self::stamp(), (int) $i['id']]);
        self::audit('grading.publish', $courseId, 'course#' . $courseId, count($drafts) . ' item(s) published', $by);
        return ['ok' => true, 'published' => count($drafts)];
    }

    /**
     * Set several weights at once (the only way to move a published item's
     * weight). Once a course has published items its weights must still add to
     * 100 afterwards; changing the weight of an item learners have used needs a
     * reason.
     * @param array<int,int|string> $weights itemId => weight
     */
    public static function setWeights(int $courseId, array $weights, string $reason, int $by): array
    {
        self::ensure();
        if (!CourseTeam::can($courseId, $by, 'change_weights')) return ['ok' => false, 'error' => 'Only a lead or instructor can change weights.'];
        $items = [];
        foreach (self::items($courseId, 'team') as $i) $items[(int) $i['id']] = $i;
        $new = [];
        foreach ($items as $id => $i) $new[$id] = (int) $i['weight'];
        foreach ($weights as $id => $w) {
            if (!isset($items[(int) $id])) return ['ok' => false, 'error' => 'An item in that list is not on this course.'];
            if (!preg_match('/^\d+$/', trim((string) $w)) || (int) $w > 100) return ['ok' => false, 'error' => 'A weight is a whole number from 0 to 100.'];
            $new[(int) $id] = (int) $w;
        }
        $anyPublished = (bool) array_filter($items, static fn($i) => $i['status'] === 'published');
        $total = (int) array_sum($new);
        if ($anyPublished && $total !== 100) return ['ok' => false, 'code' => 'weights', 'error' => 'The weights add to ' . $total . ', not 100.'];
        $reason = trim($reason);
        $changed = [];
        foreach ($new as $id => $w) {
            if ($w === (int) $items[$id]['weight']) continue;
            if ($items[$id]['status'] === 'published' && self::usage($items[$id]) > 0 && $reason === '') {
                return ['ok' => false, 'code' => 'reason_required', 'error' => 'Learners have already been graded on "' . $items[$id]['title'] . '". Changing its weight needs a reason, which is recorded.'];
            }
            $changed[$id] = $w;
        }
        $st = Database::pdo()->prepare('UPDATE graded_items SET weight = ?, updated_at = ? WHERE id = ?');
        foreach ($changed as $id => $w) $st->execute([$w, self::stamp(), $id]);
        if ($changed) {
            $desc = implode(', ', array_map(static fn($id) => 'item#' . $id . ' ' . $items[$id]['weight'] . '→' . $changed[$id], array_keys($changed)));
            self::audit('grading.weights', $courseId, 'course#' . $courseId, $desc . ($reason !== '' ? ' — ' . $reason : ''), $by);
        }
        return ['ok' => true, 'changed' => count($changed)];
    }

    /** Take an item out of the grade. Its submissions and marks are kept. */
    public static function retire(int $itemId, int $by, string $reason = ''): array
    {
        $item = self::item($itemId);
        if (!$item) return ['ok' => false, 'error' => 'No such item.'];
        if (!CourseTeam::can((int) $item['course_id'], $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can retire an item.'];
        if ($item['status'] === 'retired') return ['ok' => true, 'changed' => false];
        Database::pdo()->prepare("UPDATE graded_items SET status = 'retired', updated_at = ? WHERE id = ?")->execute([self::stamp(), $itemId]);
        self::audit('grading.item.retire', (int) $item['course_id'], 'item#' . $itemId, trim($item['title'] . ' ' . $reason), $by);
        return ['ok' => true, 'changed' => true];
    }

    /** Delete a draft nobody has used. Anything published or used is retired instead. */
    public static function deleteItem(int $itemId, int $by): array
    {
        $item = self::item($itemId);
        if (!$item) return ['ok' => false, 'error' => 'No such item.'];
        if (!CourseTeam::can((int) $item['course_id'], $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can delete an item.'];
        if ($item['status'] !== 'draft' || self::usage($item) > 0) {
            return ['ok' => false, 'code' => 'retire', 'error' => 'This item has been published or used, so it is retired rather than deleted — its marks stay on the record.'];
        }
        Database::pdo()->prepare('DELETE FROM graded_items WHERE id = ?')->execute([$itemId]);
        self::audit('grading.item.delete', (int) $item['course_id'], 'item#' . $itemId, $item['title'], $by);
        return ['ok' => true];
    }

    /* ══ Pass mark ════════════════════════════════════════════════════════ */

    public static function passMark(int $courseId): int
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT pass_mark FROM course_grading WHERE course_id = ?');
        $st->execute([$courseId]);
        $v = $st->fetchColumn();
        return $v === false ? self::DEFAULT_PASS_MARK : (int) $v;
    }

    public static function setPassMark(int $courseId, $mark, int $by): array
    {
        self::ensure();
        if (!CourseTeam::can($courseId, $by, 'set_pass_mark')) return ['ok' => false, 'error' => 'Only the course lead can set the pass mark.'];
        if (!preg_match('/^\d+$/', trim((string) $mark)) || (int) $mark < 1 || (int) $mark > 100) return ['ok' => false, 'error' => 'The pass mark is a whole number from 1 to 100.'];
        $mark = (int) $mark; $old = self::passMark($courseId);
        $db = Database::pdo();
        $up = $db->prepare('UPDATE course_grading SET pass_mark = ?, updated_by = ?, updated_at = ? WHERE course_id = ?');
        $up->execute([$mark, max(0, $by), self::stamp(), $courseId]);
        if ($up->rowCount() === 0) {
            $db->prepare(Database::insertIgnore('course_grading', ['course_id', 'pass_mark', 'updated_by', 'updated_at']))
               ->execute([$courseId, $mark, max(0, $by), self::stamp()]);
        }
        self::audit('grading.passmark', $courseId, 'course#' . $courseId, $old . ' → ' . $mark, $by);
        return ['ok' => true];
    }

    /* ══ A learner's results ══════════════════════════════════════════════ */

    /**
     * One item, one learner. What the learner may see — released scores only.
     *   status   not_started | submitted | marked | released | missed
     *            (quiz: not_started | released | missed)
     *   late     the latest attempt came in after the due date
     *   score    the score that counts, after any late penalty (null = not yet)
     *   raw / penalty   the score before the penalty, and the penalty taken
     *   passed   score >= the item's passing score
     *   counts_attempt  which attempt the score is from (assignments: the latest
     *            released one; quizzes: the best counted one)
     * $forTeam adds `pending` — a final score not yet released — for the gradebook.
     */
    public static function result(array $item, int $userId, bool $forTeam = false): array
    {
        $w = self::window($item);
        $closed = $w['closes'] !== null && self::now() > $w['closes'];
        $out = ['status' => 'not_started', 'late' => false, 'score' => null, 'raw' => null, 'penalty' => 0, 'passed' => false,
                'counts_attempt' => 0, 'attempts' => 0, 'feedback' => '', 'released_at' => '', 'latest_newer' => false, 'pending' => null,
                'closed' => $closed];
        $db = Database::pdo();

        if ($item['kind'] === 'quiz') {
            $st = $db->prepare('SELECT id, score, created_at FROM quiz_attempts WHERE user_id = ? AND lesson_id = ? ORDER BY id');
            $st->execute([$userId, (int) $item['lesson_id']]);
            $best = null; $n = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if ((int) $item['max_attempts'] > 0 && $n >= (int) $item['max_attempts']) break;
                $at = self::ts((string) $a['created_at']);
                if ($w['closes'] !== null && $at !== null && $at > $w['closes']) continue;   // after the window: not counted
                $n++;
                $late = $w['due'] !== null && $at !== null && $at > $w['due'];
                $pen = $late ? (int) $item['late_penalty'] : 0;
                $score = round((float) $a['score'] * (100 - $pen) / 100, 2);
                if ($best === null || $score > $best['score']) $best = ['score' => $score, 'raw' => (float) $a['score'], 'pen' => $pen, 'late' => $late, 'n' => $n];
            }
            $out['attempts'] = $n;
            if ($best) {
                $out = array_merge($out, ['status' => 'released', 'score' => $best['score'], 'raw' => $best['raw'], 'penalty' => $best['pen'],
                    'late' => $best['late'], 'counts_attempt' => $best['n']]);
            } elseif ($closed) {
                $out['status'] = 'missed'; $out['score'] = 0.0;
            }
            $out['passed'] = $out['score'] !== null && $out['score'] >= (int) $item['pass_score'];
            return $out;
        }

        $st = $db->prepare('SELECT * FROM grade_submissions WHERE item_id = ? AND user_id = ? ORDER BY attempt');
        $st->execute([(int) $item['id'], $userId]);
        $subs = $st->fetchAll(PDO::FETCH_ASSOC);
        $out['attempts'] = count($subs);
        if ($item['kind'] === 'live') {
            // An unexcused absence is an attempt used, with no score.
            $absent = LiveAssess::absences((int) $item['id'], $userId);
            $out['attempts'] += $absent;
            $out['booking'] = LiveAssess::bookingFor((int) $item['id'], $userId);
            if (!$subs && $out['booking'] === null && $absent >= max(1, (int) $item['max_attempts'])) $closed = true;
        }
        if (!$subs) {
            if ($closed) { $out['status'] = 'missed'; $out['score'] = 0.0; }
            return $out;
        }
        $latest = end($subs);
        $out['late'] = (bool) $latest['is_late'];
        $out['latest_id'] = (int) $latest['id'];
        // The LATEST released attempt counts — not the best.
        $counted = null;
        foreach (array_reverse($subs) as $s) { if ((string) $s['released_at'] !== '') { $counted = $s; break; } }
        if ($counted) {
            $out['score'] = round((float) $counted['released_pct'], 2);
            $out['raw'] = round((float) $counted['released_raw'], 2);
            $out['penalty'] = (int) $counted['released_penalty'];
            $out['feedback'] = (string) ($counted['released_feedback'] ?? '');
            $out['counts_attempt'] = (int) $counted['attempt'];
            $out['released_at'] = (string) $counted['released_at'];
            $out['latest_newer'] = (int) $counted['id'] !== (int) $latest['id'];
            $out['passed'] = $out['score'] >= (int) $item['pass_score'];
        }
        if ((string) $latest['released_at'] !== '') $out['status'] = 'released';
        elseif ($latest['final_pct'] !== null && !(int) $latest['flagged']) $out['status'] = 'marked';
        else $out['status'] = 'submitted';
        if ($forTeam && $latest['final_pct'] !== null && (string) $latest['released_at'] === '') {
            $pen = (int) $latest['is_late'] ? (int) $item['late_penalty'] : 0;
            $out['pending'] = round((float) $latest['final_pct'] * (100 - $pen) / 100, 2);
        }
        return $out;
    }

    /**
     * A learner's standing on a course:
     *   grade        weighted grade so far (0–100): released scores, and 0 for
     *                anything missed; items still open count as nothing yet
     *   remaining    the weight (as a share of 100) still to be decided
     *   need_avg     the average needed on what remains to reach the pass mark
     *                (null when nothing remains; > 100 means out of reach)
     *   complete     every item has a score
     *   passed       complete, every item passed, and grade ≥ the pass mark
     */
    public static function standing(int $userId, int $courseId, bool $forTeam = false): array
    {
        $items = self::items($courseId, 'published');
        $pass = self::passMark($courseId);
        $total = (int) array_sum(array_map(static fn($i) => (int) $i['weight'], $items));
        $rows = []; $earned = 0.0; $remaining = 0.0; $allPassed = true; $complete = true;
        foreach ($items as $i) {
            $r = self::result($i, $userId, $forTeam);
            $share = $total > 0 ? (int) $i['weight'] * 100 / $total : 0;
            if ($r['score'] === null) { $remaining += $share; $complete = false; $allPassed = false; }
            else { $earned += $share * $r['score'] / 100; if (!$r['passed']) $allPassed = false; }
            $rows[] = ['item' => $i, 'result' => $r, 'share' => $share];
        }
        $grade = round($earned, 2);
        $need = null;
        if ($remaining > 0.0001) $need = max(0.0, round(($pass - $earned) * 100 / $remaining, 1));
        return [
            'items' => $rows, 'grade' => $grade, 'remaining' => round($remaining, 2), 'pass_mark' => $pass,
            'weights_total' => $total, 'need_avg' => $need, 'complete' => $complete && $items !== [],
            'all_items_passed' => $allPassed && $items !== [],
            'passed' => $items !== [] && $complete && $allPassed && $grade >= $pass,
        ];
    }

    /** Everybody in the course (enrolled, or who has submitted) with their standing, for the gradebook. */
    public static function gradebook(int $courseId): array
    {
        $db = Database::pdo();
        $st = $db->prepare(
            'SELECT u.id, u.name, u.email FROM lms_users u
              WHERE u.id IN (SELECT user_id FROM course_enrolment WHERE course_id = ?)
                 OR u.id IN (SELECT user_id FROM grade_submissions WHERE course_id = ?)
              ORDER BY u.name, u.id'
        );
        $st->execute([$courseId, $courseId]);
        $team = array_map(static fn($m) => (int) $m['user_id'], CourseTeam::members($courseId));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if (in_array((int) $u['id'], $team, true)) continue;
            $out[] = ['user' => $u, 'standing' => self::standing((int) $u['id'], $courseId, true)];
        }
        return $out;
    }

    /* ══ Audit ════════════════════════════════════════════════════════════ */

    public static function audit(string $action, int $courseId, string $target, string $detail, int $by): void
    {
        try { (new LmsRepository())->audit($action, 'course#' . $courseId . ' ' . $target, $detail, CourseTeam::actor($by)); }
        catch (Throwable $e) { error_log('[grading] audit: ' . $e->getMessage()); }
    }
}
