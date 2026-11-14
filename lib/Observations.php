<?php
/**
 * lib/Observations.php — Academy staff observe teaching.
 *
 * A coordinator or admin (CourseTeam right `observe`) records an observation
 * of one team member on one course: the date, what was observed (a live
 * assessment session, a lesson, or something else described in words), a
 * score from 1 to 4 against each teaching criterion, strengths, areas to
 * develop, and an agreed action with a follow-up date.
 *
 * ── THE RULES, STATED ONCE ──────────────────────────────────────────────────
 *  • The criteria are one list, kept in one place: criteria() — the defaults
 *    below until staff edit them (setCriteria(), stored in app_meta). A final
 *    observation keeps the names it was scored against, so editing the list
 *    never rewrites one; a draft is scored against the list as it is now.
 *  • A DRAFT is the observer's notes: the instructor cannot see it, and it is
 *    not counted on any scorecard. Marking it FINAL shares it with the
 *    instructor, counts it, and fixes it — a final observation is never edited.
 *  • The instructor may add their own reflection to a final observation, and
 *    edit it; nobody else writes it.
 *  • Nobody observes themselves.
 *  • The day before the follow-up date, the web cron emails the observer and
 *    the instructor — once, claimed in followup_reminded_at before it sends.
 *
 * Who may do what is CourseTeam::can() — never re-derived here.
 */
declare(strict_types=1);

final class Observations
{
    public const DEFAULT_CRITERIA = ['Preparation', 'Clarity', 'Engagement', 'Assessment and feedback', 'Inclusion'];
    public const SCALE = [1 => 'Not yet', 2 => 'Developing', 3 => 'Good', 4 => 'Excellent'];
    public const TARGETS = ['live' => 'Live session', 'lesson' => 'Lesson', 'other' => 'Other'];
    public const META_KEY = 'academy.observation_criteria';
    public const MAX_CRITERIA = 12;

    private static bool $ready = false;

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS teaching_observations (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id            INTEGER NOT NULL,
            instructor_id        INTEGER NOT NULL,
            observer_id          INTEGER NOT NULL,
            observed_on          VARCHAR(10) NOT NULL DEFAULT '',
            target_kind          VARCHAR(16) NOT NULL DEFAULT 'other',
            target_id            INTEGER NOT NULL DEFAULT 0,
            target_note          VARCHAR(300) NOT NULL DEFAULT '',
            scores_json          TEXT,
            strengths            TEXT,
            develop              TEXT,
            action_text          TEXT,
            follow_up_on         VARCHAR(10) NOT NULL DEFAULT '',
            status               VARCHAR(16) NOT NULL DEFAULT 'draft',
            finalised_at         VARCHAR(32) NOT NULL DEFAULT '',
            reflection           TEXT,
            reflection_at        VARCHAR(32) NOT NULL DEFAULT '',
            followup_reminded_at VARCHAR(32) NOT NULL DEFAULT '',
            created_at           VARCHAR(32) NOT NULL DEFAULT '',
            updated_at           VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_observations_instructor ON teaching_observations (instructor_id, status);
        CREATE INDEX IF NOT EXISTS idx_observations_followup ON teaching_observations (status, follow_up_on)";
    }

    public const TABLES = ['teaching_observations'];

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Grading::ensure();
        Database::execSchema(Database::pdo(), self::ddl());
    }

    public static function reset(): void { self::$ready = false; }

    /* ══ The criteria ═════════════════════════════════════════════════════ */

    /** The teaching criteria an observation is scored against now. */
    public static function criteria(): array
    {
        try {
            $raw = Database::metaGet(self::META_KEY);
            $list = $raw ? json_decode($raw, true) : null;
            if (is_array($list) && $list) return array_values(array_map('strval', $list));
        } catch (Throwable $e) { error_log('[observations] criteria: ' . $e->getMessage()); }
        return self::DEFAULT_CRITERIA;
    }

    /** Replace the list (one criterion per line). Staff only. */
    public static function setCriteria(string $text, int $by): array
    {
        if (!CourseTeam::canAnywhere($by, 'observe')) return ['ok' => false, 'error' => 'Only Academy staff change the observation criteria.'];
        $list = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = mb_substr(trim($line), 0, 80);
            if ($line !== '' && !in_array($line, $list, true)) $list[] = $line;
        }
        if (!$list) return ['ok' => false, 'error' => 'Keep at least one criterion.'];
        if (count($list) > self::MAX_CRITERIA) return ['ok' => false, 'error' => 'At most ' . self::MAX_CRITERIA . ' criteria.'];
        Database::metaSet(self::META_KEY, (string) json_encode($list));
        (new LmsRepository())->audit('observations.criteria', 'academy', implode(' · ', $list), CourseTeam::actor($by));
        return ['ok' => true, 'criteria' => $list];
    }

    /* ══ Reading ══════════════════════════════════════════════════════════ */

    private static function hydrate(array $r): array
    {
        $r['id'] = (int) $r['id']; $r['course_id'] = (int) $r['course_id'];
        $r['instructor_id'] = (int) $r['instructor_id']; $r['observer_id'] = (int) $r['observer_id'];
        $r['scores'] = json_decode((string) ($r['scores_json'] ?? ''), true) ?: [];
        $vals = array_map(static fn($s) => (int) $s['score'], $r['scores']);
        $r['average'] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
        return $r;
    }

    public static function get(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT o.*, c.title AS course_title, c.slug AS course_slug, ui.name AS instructor_name, uo.name AS observer_name
                                          FROM teaching_observations o JOIN courses c ON c.id = o.course_id
                                          LEFT JOIN lms_users ui ON ui.id = o.instructor_id LEFT JOIN lms_users uo ON uo.id = o.observer_id WHERE o.id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::hydrate($r) : null;
    }

    /**
     * May this person read this observation? The observed instructor once it
     * is final; whoever may observe on the course (staff) always.
     */
    public static function canSee(array $o, int $viewer): bool
    {
        if ($viewer <= 0) return false;
        if (CourseTeam::can((int) $o['course_id'], $viewer, 'observe')) return true;
        return (int) $o['instructor_id'] === $viewer && $o['status'] === 'final';
    }

    /** Observations this person may read, newest first; optionally only those of one instructor. */
    public static function listFor(int $viewer, int $instructorId = 0): array
    {
        self::ensure();
        $sql = 'SELECT o.*, c.title AS course_title, c.slug AS course_slug, ui.name AS instructor_name, uo.name AS observer_name
                  FROM teaching_observations o JOIN courses c ON c.id = o.course_id
                  LEFT JOIN lms_users ui ON ui.id = o.instructor_id LEFT JOIN lms_users uo ON uo.id = o.observer_id';
        $args = [];
        if ($instructorId > 0) { $sql .= ' WHERE o.instructor_id = ?'; $args[] = $instructorId; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY o.observed_on DESC, o.id DESC');
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r = self::hydrate($r); if (self::canSee($r, $viewer)) $out[] = $r; }
        return $out;
    }

    /** Final observations of an instructor on some courses, inside a period (UTC), for the scorecard. */
    public static function finals(int $instructorId, array $courseIds, string $fromDay = '', string $toDay = ''): array
    {
        self::ensure();
        $courseIds = array_values(array_filter(array_map('intval', $courseIds)));
        if (!$courseIds) return [];
        $sql = "SELECT * FROM teaching_observations WHERE status = 'final' AND instructor_id = ? AND course_id IN (" . implode(',', array_fill(0, count($courseIds), '?')) . ')';
        $args = array_merge([$instructorId], $courseIds);
        if ($fromDay !== '') { $sql .= ' AND observed_on >= ?'; $args[] = $fromDay; }
        if ($toDay !== '') { $sql .= ' AND observed_on < ?'; $args[] = $toDay; }
        $st = Database::pdo()->prepare($sql . ' ORDER BY observed_on, id');
        $st->execute($args);
        return array_map([self::class, 'hydrate'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ══ Writing ══════════════════════════════════════════════════════════ */

    private static function day(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    /**
     * Record or revise an observation. A draft may be revised by anyone who
     * may observe on the course; a final one by nobody.
     * $d: course_id, instructor_id, observed_on (Y-m-d), target_kind, target_id,
     *     target_note, scores (criterion name => 1..4), strengths, develop,
     *     action, follow_up_on (Y-m-d), id (to revise), final (bool).
     */
    public static function save(array $d, int $by): array
    {
        self::ensure();
        $id = (int) ($d['id'] ?? 0);
        $cur = $id > 0 ? self::get($id) : null;
        if ($id > 0 && !$cur) return ['ok' => false, 'error' => 'No such observation.'];
        $courseId = $cur ? $cur['course_id'] : (int) ($d['course_id'] ?? 0);
        $insId = $cur ? $cur['instructor_id'] : (int) ($d['instructor_id'] ?? 0);
        if (!CourseTeam::can($courseId, $by, 'observe')) return ['ok' => false, 'error' => 'Only Academy staff record observations.'];
        if ($cur && $cur['status'] === 'final') return ['ok' => false, 'code' => 'final', 'error' => 'This observation is final and shared, so it cannot be changed.'];
        if (CourseTeam::roleOf($courseId, $insId) === null) return ['ok' => false, 'error' => 'Choose someone on this course\'s teaching team.'];
        if ($insId === $by) return ['ok' => false, 'error' => 'Nobody observes themselves — ask a colleague.'];

        $on = self::day((string) ($d['observed_on'] ?? ''));
        if ($on === null || $on === '') return ['ok' => false, 'error' => 'Give the date of the observation.'];
        $kind = (string) ($d['target_kind'] ?? 'other');
        if (!isset(self::TARGETS[$kind])) return ['ok' => false, 'error' => 'Choose what was observed.'];
        $tid = (int) ($d['target_id'] ?? 0);
        $note = mb_substr(trim((string) ($d['target_note'] ?? '')), 0, 300);
        $db = Database::pdo();
        if ($kind === 'live') {
            $st = $db->prepare('SELECT 1 FROM live_sessions WHERE id = ? AND course_id = ?'); $st->execute([$tid, $courseId]);
            if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'Choose one of this course\'s live sessions.'];
        } elseif ($kind === 'lesson') {
            $st = $db->prepare('SELECT 1 FROM lessons WHERE id = ? AND course_id = ?'); $st->execute([$tid, $courseId]);
            if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'Choose one of this course\'s lessons.'];
        } else {
            $tid = 0;
            if ($note === '') return ['ok' => false, 'error' => 'Say what was observed.'];
        }

        // Scored against the current list. Once final, the names are stored with the scores and never change.
        $names = self::criteria();
        $in = (array) ($d['scores'] ?? []);
        $scores = [];
        foreach ($names as $n => $name) {
            $v = trim((string) ($in[$n] ?? ($in[$name] ?? '')));
            if ($v === '') continue;
            if (!preg_match('/^[1-4]$/', $v)) return ['ok' => false, 'error' => 'Each criterion is scored from 1 to 4.'];
            $scores[] = ['name' => $name, 'score' => (int) $v];
        }
        $final = !empty($d['final']);
        $action = mb_substr(trim((string) ($d['action'] ?? '')), 0, 4000);
        $fu = self::day((string) ($d['follow_up_on'] ?? ''));
        if ($fu === null) return ['ok' => false, 'error' => 'The follow-up date is a date.'];
        if ($fu !== '' && $fu < $on) return ['ok' => false, 'error' => 'The follow-up comes after the observation.'];
        if ($final) {
            if (count($scores) !== count($names)) return ['ok' => false, 'code' => 'incomplete', 'error' => 'Score every criterion before marking this final.'];
            if ($action !== '' && $fu === '') return ['ok' => false, 'code' => 'incomplete', 'error' => 'An agreed action needs a follow-up date.'];
        }
        $now = Grading::stamp();
        $fields = [
            'observed_on' => $on, 'target_kind' => $kind, 'target_id' => $tid, 'target_note' => $note,
            'scores_json' => (string) json_encode($scores),
            'strengths' => mb_substr(trim((string) ($d['strengths'] ?? '')), 0, 4000),
            'develop' => mb_substr(trim((string) ($d['develop'] ?? '')), 0, 4000),
            'action_text' => $action, 'follow_up_on' => $fu,
            'status' => $final ? 'final' : 'draft', 'finalised_at' => $final ? $now : '', 'updated_at' => $now,
        ];
        if ($cur) {
            $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
            $db->prepare("UPDATE teaching_observations SET $set WHERE id = ? AND status = 'draft'")->execute([...array_values($fields), $id]);
        } else {
            $fields += ['course_id' => $courseId, 'instructor_id' => $insId, 'observer_id' => $by, 'created_at' => $now];
            $db->prepare('INSERT INTO teaching_observations (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')')
               ->execute(array_values($fields));
            $id = (int) $db->lastInsertId();
        }
        Grading::audit($final ? 'observation.final' : 'observation.save', $courseId, 'observation#' . $id, 'of user#' . $insId, $by);
        if ($final) self::notifyShared($id);
        return ['ok' => true, 'id' => $id, 'final' => $final];
    }

    /** The instructor's own reflection on a final observation of them. */
    public static function reflect(int $id, int $by, string $text): array
    {
        $o = self::get($id);
        if (!$o || $o['instructor_id'] !== $by || $o['status'] !== 'final') return ['ok' => false, 'error' => 'You can add a reflection to a shared observation of your own teaching.'];
        Database::pdo()->prepare('UPDATE teaching_observations SET reflection = ?, reflection_at = ? WHERE id = ?')
            ->execute([mb_substr(trim($text), 0, 4000), Grading::stamp(), $id]);
        Grading::audit('observation.reflect', $o['course_id'], 'observation#' . $id, '', $by);
        return ['ok' => true];
    }

    /** Delete a draft (a final observation is part of the record). */
    public static function deleteDraft(int $id, int $by): array
    {
        $o = self::get($id);
        if (!$o) return ['ok' => false, 'error' => 'No such observation.'];
        if (!CourseTeam::can($o['course_id'], $by, 'observe')) return ['ok' => false, 'error' => 'Only Academy staff manage observations.'];
        if ($o['status'] !== 'draft') return ['ok' => false, 'error' => 'A final observation stays on the record.'];
        Database::pdo()->prepare("DELETE FROM teaching_observations WHERE id = ? AND status = 'draft'")->execute([$id]);
        Grading::audit('observation.delete', $o['course_id'], 'observation#' . $id, '', $by);
        return ['ok' => true];
    }

    /* ══ Mail ═════════════════════════════════════════════════════════════ */

    private static function localDay(int $offsetDays = 0): string
    {
        try { $tz = new DateTimeZone(defined('AV_TZ') ? (string) AV_TZ : 'UTC'); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        return (new DateTime('@' . (Grading::now() + $offsetDays * 86400)))->setTimezone($tz)->format('Y-m-d');
    }

    private static function person(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT id, name, email FROM lms_users WHERE id = ?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function send(array $to, string $subject, array $rows, string $url, string $label): bool
    {
        try {
            if (!class_exists('Mailer')) return false;
            return (bool) Mailer::send((string) $to['email'], $subject, Mailer::shell($subject, $rows, ['url' => $url, 'text' => $label], $subject, ['eyebrow' => 'Afrovanguard Academy']));
        } catch (Throwable $e) { error_log('[observations] mail: ' . $e->getMessage()); return false; }
    }

    private static function notifyShared(int $id): void
    {
        $o = self::get($id);
        $p = $o ? self::person($o['instructor_id']) : null;
        if (!$o || !$p) return;
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        self::send($p, 'An observation of your teaching has been shared', [
            'Hi ' . $h(explode(' ', trim((string) $p['name']))[0] ?: 'there') . ',',
            'The observation of your teaching on <strong>' . $h((string) $o['course_title']) . '</strong> (' . $h($o['observed_on']) . ') is now shared with you. You can add your own reflection.',
        ], academy_url('teach/?view=observation&id=' . $id), 'Read the observation');
    }

    /**
     * The day before a final observation's follow-up date (Lagos), email the
     * observer and the instructor. Claimed per observation before sending, so
     * it goes once however often the cron runs. A follow-up date already past
     * when the cron first sees it is not chased.
     */
    public static function remindFollowUps(): int
    {
        self::ensure();
        $db = Database::pdo();
        $today = self::localDay(0); $tomorrow = self::localDay(1);
        $st = $db->prepare("SELECT id FROM teaching_observations WHERE status = 'final' AND followup_reminded_at = '' AND follow_up_on <> '' AND follow_up_on >= ? AND follow_up_on <= ?");
        $st->execute([$today, $tomorrow]);
        $claim = $db->prepare("UPDATE teaching_observations SET followup_reminded_at = ? WHERE id = ? AND followup_reminded_at = ''");
        $n = 0;
        foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
            $claim->execute([Grading::stamp(), $id]);
            if ($claim->rowCount() !== 1) continue;
            $n++;
            $o = self::get($id);
            if (!$o) continue;
            $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            foreach ([$o['instructor_id'], $o['observer_id']] as $who) {
                $p = self::person($who);
                if (!$p) continue;
                self::send($p, 'Follow-up due ' . $o['follow_up_on'] . ': ' . $o['course_title'], [
                    'Hi ' . $h(explode(' ', trim((string) $p['name']))[0] ?: 'there') . ',',
                    'The follow-up agreed at the teaching observation on ' . $h($o['observed_on']) . ' is due on <strong>' . $h($o['follow_up_on']) . '</strong>.',
                    'The agreed action: ' . nl2br($h((string) $o['action_text'])),
                ], academy_url('teach/?view=observation&id=' . $id), 'Open the observation');
            }
        }
        return $n;
    }
}
