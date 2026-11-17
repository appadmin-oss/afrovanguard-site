<?php
/**
 * lib/Scorecard.php — an instructor's scorecard, per course and overall.
 *
 * EVERY FIGURE IS DERIVED, NEVER STORED. Each is worked out on the request
 * from the rows that record what happened (course feedback, final observations,
 * grade_marks, grade_submissions, enrolments), and each carries `n`, the
 * number of things it was worked out from. A figure from fewer than SMALL_N
 * things is FLAGGED (`small`), not hidden.
 *
 * There are NO learner ratings of instructors. Learners give written feedback
 * on a COURSE (lib/LearnerFeedback); the scorecard shows, for each course the
 * instructor teaches, how much feedback the course has received, how much
 * staff approved and how much the team can read — labelled as the course's,
 * shared by its whole team, never attributed to the instructor.
 *
 * The figures (docs/academy-instructors.md explains each for the people who
 * read them):
 *   feedback       per course: received / approved / shared (LearnerFeedback::
 *                  counts), and the approved feedback itself once the course
 *                  has feedback from MIN_LEARNERS learners (forTeam)
 *   observations   final observations only: the latest, and the average per
 *                  criterion; plus the average of every score
 *   turnaround     days from a submission to THIS instructor's first mark on
 *                  it (grade_marks.created_at − grade_submissions.submitted_at),
 *                  over their marks on assignments and peer items (a live
 *                  panel scores in the room, so it has no turnaround):
 *                  the median, the 90th percentile (nearest rank), and the
 *                  share marked within 7 days
 *   moderation     the share of their marks on work that was flagged for
 *                  moderation or settled by an agreed score
 *   release lag    days from a submission's final score to its release, for
 *                  the releases this instructor pressed (median)
 *   outcomes       COURSE outcomes, not the instructor's: completion rate,
 *                  pass rate and average course grade of the course's
 *                  learners — teams share courses, so these are labelled as
 *                  the course's, never as personal blame
 *
 * WHO SEES WHOSE (visibleCourses()): your own, on every course you teach;
 * Academy staff, everybody's (right `view_all_scorecards`); a lead, a member
 * of their team, on the courses they lead only (right `view_team_scorecard`).
 * An instructor never sees a colleague's. All through CourseTeam.
 */
declare(strict_types=1);

final class Scorecard
{
    public const SMALL_N = 5;
    public const WITHIN_DAYS = 7;
    public const PERIODS = ['12m' => 'Last 12 months', '6m' => 'Last 6 months', '3m' => 'Last 3 months', 'all' => 'All time'];

    /* ══ Arithmetic ═══════════════════════════════════════════════════════ */

    public static function median(array $v): ?float
    {
        return $v === [] ? null : GradingWork::median(array_values($v));
    }

    /** The p-th percentile by nearest rank: the ceil(p/100 × n)-th smallest value. */
    public static function percentile(array $v, float $p): ?float
    {
        if ($v === []) return null;
        sort($v);
        $rank = (int) ceil($p / 100 * count($v));
        return (float) $v[max(1, min(count($v), $rank)) - 1];
    }

    /** A figure: its value, how many things it came from, and whether that is few. */
    public static function fig(?float $value, int $n): array
    {
        return ['value' => $value === null ? null : round($value, 2), 'n' => $n, 'small' => $n > 0 && $n < self::SMALL_N];
    }

    private static function days(string $from, string $to): ?float
    {
        $a = strtotime($from . ' UTC'); $b = strtotime($to . ' UTC');
        if ($a === false || $b === false || $b < $a) return null;
        return ($b - $a) / 86400;
    }

    /** A period preset → its UTC lower bound ('' for all time) and the matching local day. */
    public static function period(string $key): array
    {
        if (!isset(self::PERIODS[$key])) $key = '12m';
        $months = ['12m' => 12, '6m' => 6, '3m' => 3, 'all' => 0][$key];
        $from = $months ? Grading::stamp((int) strtotime('-' . $months . ' months', Grading::now())) : '';
        return ['key' => $key, 'label' => self::PERIODS[$key], 'from' => $from, 'from_day' => $from === '' ? '' : substr($from, 0, 10)];
    }

    /* ══ Who sees whose ═══════════════════════════════════════════════════ */

    /**
     * The courses on which $viewer may see $instructor's scorecard — the
     * courses the instructor teaches, narrowed by CourseTeam's rights.
     * @return array<int, array{id:int, title:string, slug:string, team_role:string}>
     */
    public static function visibleCourses(int $viewer, int $instructor): array
    {
        if ($viewer <= 0 || $instructor <= 0) return [];
        $all = $viewer === $instructor || CourseTeam::canAnywhere($viewer, 'view_all_scorecards');
        $out = [];
        foreach (CourseTeam::coursesFor($instructor) as $c) {
            if ($all || CourseTeam::can((int) $c['id'], $viewer, 'view_team_scorecard')) {
                $out[(int) $c['id']] = ['id' => (int) $c['id'], 'title' => (string) $c['title'], 'slug' => (string) $c['slug'], 'team_role' => (string) $c['team_role']];
            }
        }
        return $out;
    }

    public static function canSee(int $viewer, int $instructor, int $courseId = 0): bool
    {
        $v = self::visibleCourses($viewer, $instructor);
        return $courseId > 0 ? isset($v[$courseId]) : $v !== [];
    }

    /* ══ The scorecard ════════════════════════════════════════════════════ */

    /**
     * $instructor's scorecard as $viewer may see it: one course ($courseId),
     * or every course the viewer may see for them (0). $full = false skips
     * the comments and course outcomes (the instructor list's headline).
     */
    public static function build(int $instructor, int $viewer, int $courseId = 0, string $periodKey = '12m', bool $full = true): array
    {
        LearnerFeedback::ensure(); Observations::ensure();
        $vis = self::visibleCourses($viewer, $instructor);
        if ($courseId > 0) {
            if (!isset($vis[$courseId])) return ['ok' => false, 'error' => 'You cannot see this scorecard.'];
            $courses = [$courseId => $vis[$courseId]];
        } else {
            if (!$vis) return ['ok' => false, 'error' => 'You cannot see this scorecard.'];
            $courses = $vis;
        }
        $ids = array_keys($courses);
        $p = self::period($periodKey);
        $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE id = ?'); $st->execute([$instructor]);
        $who = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $instructor, 'name' => 'Instructor #' . $instructor, 'email' => ''];

        $out = ['ok' => true, 'instructor' => $who, 'courses' => $courses, 'period' => $p,
                'limited' => $courseId === 0 && $viewer !== $instructor && !CourseTeam::canAnywhere($viewer, 'view_all_scorecards')];
        // Feedback on the COURSES, shared by each course's team — not the instructor's.
        $fb = LearnerFeedback::counts($ids, $p['from']);
        $tot = ['received' => 0, 'approved' => 0, 'shared' => 0];
        foreach ($fb as $cid => $f) { foreach ($tot as $k => $_) $tot[$k] += $f[$k]; $fb[$cid]['title'] = $courses[$cid]['title']; }
        $out['feedback'] = ['courses' => $fb, 'total' => $tot];
        $out['feedback_items'] = [];
        if ($full) foreach ($ids as $cid) $out['feedback_items'][$cid] = LearnerFeedback::forTeam($cid, $viewer, $p['from']);
        $out['observations'] = self::observationFigures(Observations::finals($instructor, $ids, $p['from_day']));
        $out += self::markingFigures($instructor, $ids, $p['from']);
        $out['outcomes'] = $full ? self::outcomes($ids, $p['from']) : null;
        return $out;
    }

    private static function observationFigures(array $obs): array
    {
        $per = []; $all = [];
        foreach ($obs as $o) {
            foreach ($o['scores'] as $s) { $per[(string) $s['name']][] = (int) $s['score']; $all[] = (int) $s['score']; }
        }
        $criteria = [];
        foreach ($per as $name => $v) $criteria[$name] = self::fig(array_sum($v) / count($v), count($v));
        $latest = $obs ? end($obs) : null;
        return ['n' => count($obs), 'small' => $obs !== [] && count($obs) < self::SMALL_N,
                'average' => self::fig($all ? array_sum($all) / count($all) : null, count($obs)),
                'criteria' => $criteria, 'latest' => $latest];
    }

    /** Turnaround, moderation rate and release lag for one instructor's own actions. */
    public static function markingFigures(int $instructor, array $courseIds, string $from = ''): array
    {
        $db = Database::pdo();
        $in = implode(',', array_fill(0, count($courseIds), '?'));
        $args = array_merge([$instructor], $courseIds);
        $since = $from !== '' ? ' AND m.created_at >= ?' : '';
        if ($from !== '') $args[] = $from;
        $st = $db->prepare("SELECT m.created_at AS marked_at, s.submitted_at, s.flagged, s.final_source, i.kind
                              FROM grade_marks m JOIN grade_submissions s ON s.id = m.submission_id JOIN graded_items i ON i.id = s.item_id
                             WHERE m.marker_id = ? AND s.course_id IN ($in)$since");
        $st->execute($args);
        $turn = []; $marks = 0; $moderated = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $marks++;
            if ((int) $r['flagged'] || (string) $r['final_source'] === 'moderated') $moderated++;
            if ((string) $r['kind'] === 'live' || (string) $r['submitted_at'] === '') continue;
            $d = self::days((string) $r['submitted_at'], (string) $r['marked_at']);
            if ($d !== null) $turn[] = $d;
        }
        $within = count(array_filter($turn, static fn($d) => $d <= self::WITHIN_DAYS));

        $args = array_merge([$instructor], $courseIds);
        $since = $from !== '' ? ' AND released_at >= ?' : '';
        if ($from !== '') $args[] = $from;
        $st = $db->prepare("SELECT final_at, released_at FROM grade_submissions
                             WHERE released_by = ? AND course_id IN ($in) AND released_at <> '' AND final_at <> ''$since");
        $st->execute($args);
        $lag = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = self::days((string) $r['final_at'], (string) $r['released_at']);
            if ($d !== null) $lag[] = $d;
        }
        return [
            'turnaround' => [
                'median' => self::fig(self::median($turn), count($turn)),
                'p90' => self::fig(self::percentile($turn, 90), count($turn)),
                'within' => self::fig($turn ? $within / count($turn) * 100 : null, count($turn)),
            ],
            'moderation' => self::fig($marks ? $moderated / $marks * 100 : null, $marks),
            'release_lag' => self::fig(self::median($lag), count($lag)),
        ];
    }

    /**
     * The courses' outcomes, pooled: learners (enrolled in the period, not on
     * the team) who completed every lesson; of those whose grade is complete,
     * the share who passed and their average grade (graded courses only).
     */
    public static function outcomes(array $courseIds, string $from = ''): array
    {
        $db = Database::pdo();
        $lms = new LmsRepository();
        $learners = 0; $completed = 0; $decided = 0; $passed = 0; $grades = []; $graded = false;
        foreach ($courseIds as $cid) {
            $total = $lms->lessonCount((int) $cid);
            $isGraded = Grading::hasGradedItems((int) $cid);
            $graded = $graded || $isGraded;
            $team = array_map(static fn($m) => (int) $m['user_id'], CourseTeam::members((int) $cid));
            $sql = 'SELECT e.user_id, (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = e.user_id AND lp.course_id = e.course_id) AS done
                      FROM course_enrolment e WHERE e.course_id = ?';
            $args = [(int) $cid];
            if ($from !== '') { $sql .= ' AND e.created_at >= ?'; $args[] = $from; }
            $st = $db->prepare($sql); $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array((int) $r['user_id'], $team, true)) continue;
                $learners++;
                if ($total > 0 && (int) $r['done'] >= $total) $completed++;
                if ($isGraded) {
                    $s = Grading::standing((int) $r['user_id'], (int) $cid);
                    if ($s['complete']) { $decided++; $grades[] = (float) $s['grade']; if ($s['passed']) $passed++; }
                }
            }
        }
        return [
            'learners' => $learners, 'graded' => $graded,
            'completion' => self::fig($learners ? $completed / $learners * 100 : null, $learners),
            'pass_rate' => self::fig($decided ? $passed / $decided * 100 : null, $decided),
            'avg_grade' => self::fig($grades ? array_sum($grades) / count($grades) : null, count($grades)),
        ];
    }

    /* ══ The instructor list ══════════════════════════════════════════════ */

    /**
     * Every instructor with their headline figures, for Academy staff.
     * Filters: q (name or email contains), course (on that course's team),
     * role (holds that role somewhere), attention (median turnaround above
     * 7 days, or observation average below 2.5).
     */
    public static function directory(int $viewer, string $periodKey = '12m', array $f = []): array
    {
        if (!CourseTeam::canAnywhere($viewer, 'view_all_scorecards')) return ['ok' => false, 'error' => 'Only Academy staff see every instructor.'];
        CourseTeam::ensure();
        $sql = 'SELECT DISTINCT u.id, u.name, u.email FROM course_instructors t JOIN lms_users u ON u.id = t.user_id WHERE 1 = 1';
        $args = [];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') { $sql .= ' AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)'; $like = '%' . strtolower($q) . '%'; $args[] = $like; $args[] = $like; }
        if (($c = (int) ($f['course'] ?? 0)) > 0) { $sql .= ' AND t.course_id = ?'; $args[] = $c; }
        if (in_array($f['role'] ?? '', CourseTeam::ROLES, true)) { $sql .= ' AND t.role = ?'; $args[] = $f['role']; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY u.name, u.id');
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $sc = self::build((int) $u['id'], $viewer, 0, $periodKey, false);
            if (!$sc['ok']) continue;
            $attention = ($sc['turnaround']['median']['value'] !== null && $sc['turnaround']['median']['value'] > self::WITHIN_DAYS)
                || ($sc['observations']['average']['value'] !== null && $sc['observations']['average']['value'] < 2.5);
            if (!empty($f['attention']) && !$attention) continue;
            $rows[] = ['user' => $u, 'card' => $sc, 'attention' => $attention];
        }
        return ['ok' => true, 'rows' => $rows, 'period' => self::period($periodKey)];
    }

    /** The instructor list as CSV, formula-safe through Csv. Counts only — no feedback text, no learner named. */
    public static function directoryCsv(array $dir, $out): void
    {
        $cell = static fn(array $fig, string $unit = ''): string => $fig['value'] === null ? '' : Grading::num((float) $fig['value']) . $unit;
        Csv::put($out, ['Name', 'Email', 'Courses', 'Course feedback received', 'Course feedback approved', 'Course feedback shared with the team',
                        'Observations', 'Observation average (1-4)', 'Marks', 'Median days to mark', '90th percentile days', 'Marked within 7 days %',
                        'Moderation rate %', 'Releases', 'Median release lag days', 'Small samples', 'Needs attention']);
        foreach ($dir['rows'] as $row) {
            $c = $row['card']; $small = [];
            foreach (['Observations' => $c['observations']['average'], 'Turnaround' => $c['turnaround']['median'], 'Moderation' => $c['moderation'], 'Release lag' => $c['release_lag']] as $k => $fig) if ($fig['small']) $small[] = $k;
            Csv::put($out, [
                $row['user']['name'], $row['user']['email'], implode('; ', array_map(static fn($x) => $x['title'], $c['courses'])),
                $c['feedback']['total']['received'], $c['feedback']['total']['approved'], $c['feedback']['total']['shared'],
                $c['observations']['n'], $cell($c['observations']['average']), $c['moderation']['n'], $cell($c['turnaround']['median']),
                $cell($c['turnaround']['p90']), $cell($c['turnaround']['within']), $cell($c['moderation']), $c['release_lag']['n'],
                $cell($c['release_lag']), implode('; ', $small), $row['attention'] ? 'Yes' : '',
            ]);
        }
    }
}
