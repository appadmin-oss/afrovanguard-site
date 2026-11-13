<?php
/**
 * lib/AcademyNudges.php — the Academy, in the portal's bell and to-do list.
 *
 * The Academy already mails people when time passes (lib/LmsDrip.php: a
 * lesson unlocks, a course sits idle, a live class is tomorrow) and when a
 * course is finished (Notify::completed). None of it reached the portal:
 * a member who lives in the portal never saw that their assignment came back,
 * that someone answered them in a discussion, or that they had fallen behind
 * the course's pace (owner, 2026-11-13: "the Afrovanguard site needs
 * reminders and updates from the academy").
 *
 * Two kinds of message, one rule each:
 *
 *   Updates   something happened TO you: graded, returned, replied to, a
 *             submission waiting for you to mark, a certificate. Sent at the
 *             moment it happens, from the one method that makes it happen.
 *   Reminders time passed: a lesson opened, you are behind, you have not
 *             touched a course for a week, a class is tomorrow. Sent from the
 *             cron tick, once (Notifications dedupe keys), never per tick.
 *
 * Email is not doubled: where LmsDrip or Notify already mails, this only
 * adds the bell. The new events mail only where acting on them matters
 * (returned work, a failed grade, being behind).
 *
 * Nothing here throws into the caller: a learner's submission must not fail
 * because the bell could not ring.
 */
declare(strict_types=1);

final class AcademyNudges
{
    /** How many days before an item's suggested date counts as "due soon". */
    public const DUE_SOON_DAYS = 2;
    /** At most this many learners are looked at in one daily pace sweep. */
    public const SWEEP_MAX = 300;
    /** Reply bells: at most one per thread per person per day. */
    private const KIND = 'academy';

    private static function path(string $p): string { return '/academy/' . ltrim($p, '/'); }

    private static function ring(int $uid, string $title, string $body, string $url, string $key): int
    {
        try { return Notifications::push($uid, self::KIND, $title, $body, $url, $key); }
        catch (Throwable $e) { error_log('[academy-nudge] ' . $e->getMessage()); return 0; }
    }

    private static function course(int $courseId): ?array
    {
        try {
            $s = Database::pdo()->prepare('SELECT id, slug, title, instructor_id FROM courses WHERE id = ?');
            $s->execute([$courseId]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }

    /* ── Updates: at the moment they happen ─────────────────────────────── */

    /** A learner handed in an assignment: the instructor has something to mark. */
    public static function submitted(int $learnerId, array $lesson): void
    {
        $c = self::course((int) ($lesson['course_id'] ?? 0));
        $to = (int) ($c['instructor_id'] ?? 0);
        if (!$c || $to <= 0 || $to === $learnerId) return;
        $who = '';
        try { $s = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $s->execute([$learnerId]); $who = (string) ($s->fetchColumn() ?: ''); } catch (Throwable $e) {}
        /* One bell per submission round: a resubmission after a return rings again. */
        self::ring($to, 'To mark: ' . (string) ($lesson['title'] ?? 'an assignment'),
            ($who !== '' ? $who : 'A learner') . ' handed it in · ' . (string) $c['title'],
            self::path('teach/grading'), 'acsub:' . (int) $lesson['id'] . ':' . $learnerId . ':' . gmdate('YmdHi'));
    }

    /**
     * A submission came back. $how: passed | failed | returned.
     * Passing is good news and the bell says so; failing or being asked to
     * change something needs action, so it mails as well.
     */
    public static function graded(array $sub, array $lesson, string $how, ?int $score, string $feedback, bool $courseDone): void
    {
        $uid = (int) ($sub['user_id'] ?? 0);
        $c = self::course((int) ($lesson['course_id'] ?? 0));
        if ($uid <= 0 || !$c) return;
        $item = (string) ($lesson['title'] ?? 'Your assignment');
        $url = self::path($c['slug'] . '/learn/' . (string) ($lesson['slug'] ?? ''));
        $fb = trim(mb_substr(preg_replace('/\s+/', ' ', $feedback) ?? '', 0, 160));
        [$title, $body] = match ($how) {
            'passed'   => ['Passed: ' . $item, ($score !== null ? $score . '/100' : 'Passed') . ($courseDone ? ' · course complete' : '') . ($fb !== '' ? ' · “' . $fb . '”' : '')],
            'failed'   => ['Try again: ' . $item, ($score !== null ? $score . '/100, not yet a pass. ' : '') . ($fb !== '' ? '“' . $fb . '”' : 'Read the feedback and resubmit.')],
            default    => ['Changes asked: ' . $item, $fb !== '' ? '“' . $fb . '”' : 'Your instructor returned it for changes.'],
        };
        $n = self::ring($uid, $title, $body, $url, 'acgrade:' . (int) ($sub['id'] ?? 0) . ':' . substr(md5($how . '|' . $score . '|' . $feedback), 0, 12));   // a repeat of the same grade rings once
        /* The certificate mail already covers a pass that finished the course. */
        if ($n && $how !== 'passed') {
            try { Notifications::email($uid, $title, $how === 'failed' ? 'Not yet a pass. Read the feedback and resubmit when ready.' : 'Your instructor returned this for changes. Read the feedback and resubmit.', $url); } catch (Throwable $e) {}
        }
    }

    /**
     * Someone replied in a discussion: the thread's starter and everyone who
     * has posted in it, except the person replying. A busy thread is one bell
     * per person per day, not one per post.
     */
    public static function replied(int $replierId, array $thread): void
    {
        try {
            $tid = (int) ($thread['id'] ?? 0);
            $c = self::course((int) ($thread['course_id'] ?? 0));
            if ($tid <= 0 || !$c) return;
            $people = [];
            if ((int) ($thread['user_id'] ?? 0) > 0) $people[(int) $thread['user_id']] = true;
            $s = Database::pdo()->prepare('SELECT DISTINCT user_id FROM forum_posts WHERE thread_id = ? AND hidden = 0');
            $s->execute([$tid]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) ?: [] as $p) $people[(int) $p] = true;
            unset($people[$replierId], $people[0]);
            if (!$people) return;
            $who = '';
            $q = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?'); $q->execute([$replierId]); $who = (string) ($q->fetchColumn() ?: 'Someone');
            $title = 'New reply: ' . ((string) ($thread['title'] ?? '') ?: 'a discussion');
            $url = self::path($c['slug'] . '/discussions/' . $tid);
            foreach (array_slice(array_keys($people), 0, 200) as $p) {
                self::ring($p, $title, $who . ' replied · ' . (string) $c['title'], $url, 'acreply:' . $tid . ':' . $p . ':' . gmdate('Ymd'));
            }
        } catch (Throwable $e) { error_log('[academy-nudge] reply: ' . $e->getMessage()); }
    }

    /** A course finished: the certificate is in the bell as well as the mail. */
    public static function certified(int $uid, array $course): void
    {
        if ($uid <= 0 || empty($course['id'])) return;
        self::ring($uid, 'Certificate earned: ' . (string) ($course['title'] ?? 'your course'),
            'Well done. Download it or share the verify link.', self::path((string) $course['slug'] . '/certificate'), 'accert:' . (int) $course['id']);
    }

    /* ── Reminders: from the cron tick ──────────────────────────────────── */

    /** The bell that goes with LmsDrip's "a lesson has opened" mail. */
    public static function lessonOpened(int $uid, array $r): void
    {
        self::ring($uid, 'New lesson open: ' . (string) ($r['title'] ?? ''), (string) ($r['course_title'] ?? ''),
            self::path((string) $r['course_slug'] . '/learn/' . (string) $r['slug']), 'acopen:' . (int) $r['id']);
    }

    /** The bell that goes with LmsDrip's weekly "pick it back up" mail. */
    public static function idle(int $uid, array $r, string $nextTitle, string $nextSlug, int $pct): void
    {
        self::ring($uid, 'Pick up ' . (string) $r['course_title'], $pct . '% done · next: ' . $nextTitle,
            self::path((string) $r['course_slug'] . '/learn/' . $nextSlug), 'acidle:' . (int) $r['course_id'] . ':' . gmdate('oW'));
    }

    /** The bell that goes with LmsDrip's "class tomorrow" mail. */
    public static function classSoon(int $uid, array $r, string $when): void
    {
        self::ring($uid, 'Live class: ' . (string) $r['title'], $when . ((string) ($r['host_name'] ?? '') !== '' ? ' · with ' . $r['host_name'] : ''),
            (string) ($r['join_url'] ?? '') ?: '/academy/my', 'acclass:' . (int) $r['id']);
    }

    /**
     * Once a day: where each learner stands against each course's pace
     * (LmsCourseware::schedule, the same dates "My learning" shows).
     *   behind     the next item's suggested date has passed: bell + mail, weekly
     *   due soon   it falls within DUE_SOON_DAYS: bell only, once per item
     * Runs at most once a day however often the cron ticks.
     */
    public static function sweepPace(bool $force = false): array
    {
        $made = ['behind' => 0, 'due_soon' => 0, 'learners' => 0];
        try {
            LmsDrip::ensure();
            $db = Database::pdo();
            $day = (int) gmdate('Ymd');
            if (!$force) {
                $st = $db->prepare("SELECT 1 FROM lms_drip_sent WHERE kind = 'pace-sweep' AND user_id = 0 AND subject_id = ? LIMIT 1");
                $st->execute([$day]);
                if ($st->fetchColumn()) return $made + ['skipped' => true];
            }
            $db->prepare("INSERT INTO lms_drip_sent (kind, user_id, subject_id, sent_at) VALUES ('pace-sweep', 0, ?, ?)")->execute([$day, gmdate('Y-m-d H:i:s')]);

            $ids = $db->query("SELECT DISTINCT e.user_id FROM course_enrolment e JOIN lms_users u ON u.id = e.user_id
                               WHERE u.status = 'active' ORDER BY e.user_id LIMIT " . self::SWEEP_MAX)->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if (!$ids) return $made;
            $cw = new LmsCourseware();
            $today = gmdate('Y-m-d');
            $soon = gmdate('Y-m-d', time() + self::DUE_SOON_DAYS * 86400);
            $week = gmdate('oW');
            foreach ($ids as $uid) {
                $uid = (int) $uid;
                $made['learners']++;
                foreach (self::pace($cw, $uid) as $p) {
                    if ($p['behind']) {
                        $title = 'Behind on ' . $p['course'];
                        $n = self::ring($uid, $title, 'Next: ' . $p['item'] . ' · suggested by ' . self::day($p['due']), $p['url'], 'acbehind:' . $p['course_id'] . ':' . $week);
                        if ($n) { $made['behind']++; try { Notifications::email($uid, $title, 'Your next item, ' . $p['item'] . ', was suggested for ' . self::day($p['due']) . '. A short session today puts you back on pace.', $p['url']); } catch (Throwable $e) {} }
                    } elseif ($p['due'] !== null && $p['due'] >= $today && $p['due'] <= $soon) {
                        if (self::ring($uid, 'Coming up: ' . $p['item'], $p['course'] . ' · suggested by ' . self::day($p['due']), $p['url'], 'acsoon:' . $p['item_id'])) $made['due_soon']++;
                    }
                }
            }
        } catch (Throwable $e) { error_log('[academy-nudge] pace: ' . $e->getMessage()); }
        return $made;
    }

    private static function day(?string $ymd): string
    {
        $t = $ymd ? strtotime($ymd . ' 12:00 UTC') : false;
        return $t ? gmdate('D j M', $t) : 'now';
    }

    /**
     * One learner's courses in progress, as the bell and the to-do list need
     * them: the next item, its suggested date, and whether that has passed.
     */
    public static function pace(LmsCourseware $cw, int $uid): array
    {
        $out = [];
        try {
            $d = $cw->dashboard($uid);
            foreach ((array) ($d['active'] ?? []) as $row) {
                $c = (array) ($row['course'] ?? []);
                $n = $row['next'] ?? null;
                if (!$n || empty($c['slug'])) continue;
                $out[] = [
                    'course_id' => (int) $c['id'], 'course' => (string) $c['title'],
                    'item_id' => (int) $n['id'], 'item' => (string) $n['title'],
                    'due' => $row['due'] ?? null, 'behind' => !empty($row['behind']),
                    'pct' => (int) ($row['pct'] ?? 0), 'last' => (string) ($row['last'] ?? ''),
                    'url' => self::path($c['slug'] . '/learn/' . (string) $n['slug']),
                ];
            }
        } catch (Throwable $e) { error_log('[academy-nudge] pace(' . $uid . '): ' . $e->getMessage()); }
        return $out;
    }

    /**
     * The to-do list's "Learning" section: work handed back to redo first,
     * then submissions waiting for this person to mark (instructors), then the
     * next item in each course, behind first.
     */
    public static function todo(int $uid, int $limit = 6): array
    {
        $items = []; $redo = [];
        try {
            $db = Database::pdo();
            $s = $db->prepare("SELECT s.id, s.status, s.score, l.id AS lid, l.title, l.slug, c.title AS course_title, c.slug AS course_slug
                               FROM assignment_submissions s JOIN lessons l ON l.id = s.lesson_id JOIN courses c ON c.id = s.course_id
                               WHERE s.user_id = ? AND (s.status = 'returned' OR (s.status = 'graded' AND s.score IS NOT NULL))
                               ORDER BY s.updated_at DESC LIMIT 20");
            $s->execute([$uid]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if ($r['status'] === 'graded') {
                    $l = (new LmsRepository())->lessonById((int) $r['lid']);
                    if (!$l || (int) $r['score'] >= LmsCourseware::assignmentPass($l)) continue;
                }
                $redo[(int) $r['lid']] = true;
                $items[] = ['id' => 'acredo-' . (int) $r['id'], 'title' => 'Resubmit: ' . (string) $r['title'],
                    'meta' => (string) $r['course_title'] . ' · ' . ($r['status'] === 'returned' ? 'changes asked' : 'not yet a pass'),
                    'urgent' => true, 'href' => self::path($r['course_slug'] . '/learn/' . $r['slug']), 'kind' => 'learn'];
            }
            $m = $db->prepare("SELECT COUNT(*) FROM assignment_submissions s JOIN courses c ON c.id = s.course_id WHERE c.instructor_id = ? AND s.status = 'submitted'");
            $m->execute([$uid]);
            if (($k = (int) $m->fetchColumn()) > 0) {
                $items[] = ['id' => 'acmark', 'title' => $k . ' submission' . ($k === 1 ? '' : 's') . ' to mark', 'meta' => 'Academy · grading',
                    'urgent' => false, 'href' => self::path('teach/grading'), 'kind' => 'learn'];
            }
            $pace = self::pace(new LmsCourseware(), $uid);
            usort($pace, static fn($a, $b) => [$b['behind'], (string) $a['due']] <=> [$a['behind'], (string) $b['due']]);
            foreach ($pace as $p) {
                if (isset($redo[$p['item_id']])) continue;   // already on the list as a redo
                $items[] = ['id' => 'aclearn-' . $p['course_id'], 'title' => $p['item'],
                    'meta' => $p['course'] . ' · ' . $p['pct'] . '%' . ($p['due'] ? ($p['behind'] ? ' · behind, was ' . self::day($p['due']) : ' · by ' . self::day($p['due'])) : ''),
                    'urgent' => $p['behind'], 'href' => $p['url'], 'kind' => 'learn'];
            }
        } catch (Throwable $e) { error_log('[academy-nudge] todo: ' . $e->getMessage()); }
        return array_slice($items, 0, $limit);
    }
}
