<?php
/**
 * lib/LiveAssess.php — live assessment sessions: the fourth kind of graded item.
 *
 * A `live` item has one or more scheduled SESSIONS (a start and end in Lagos
 * time, a place and/or a meeting link, a capacity). A learner books a slot,
 * or the team seats them; on the day a panel from the course team scores each
 * learner from teach.php's session screen.
 *
 * ── NO SECOND SCORING PATH ─────────────────────────────────────────────────
 * Scoring a learner who is present creates (once) a grade_submissions row for
 * that booking's attempt, and each panelist's rubric scores are a mark through
 * GradingWork::mark(). So the moderation threshold, agreeing a final, release
 * gating, the late-free "latest attempt counts" rule, the gradebook and the
 * certificate are exactly the assignment's — this class only decides who is
 * sitting in front of the panel.
 *
 * The rules:
 *   • One live booking per learner per item. It is held by the database, not
 *     just here: live_bookings is UNIQUE (item_id, user_id, live_key) and
 *     live_key is 'live' only while the booking is active, so two requests
 *     racing to book cannot both win. Booking another session MOVES the
 *     booking.
 *   • A full session refuses the booking — for the team too.
 *   • A session that has started cannot be booked.
 *   • Present + scored uses an attempt; ABSENT uses an attempt and scores
 *     nothing, unless a lead or instructor EXCUSES it with a reason, which
 *     gives the attempt back so the learner can rebook. A learner absent for
 *     every attempt has missed the item (0).
 *   • Saving a panel mark is idempotent: per learner, keyed on (submission,
 *     panelist), and an identical resend changes nothing (GradingWork::mark).
 *   • Learners are reminded the day before, once (remind(), from the web cron).
 */
declare(strict_types=1);

final class LiveAssess
{
    /** Statuses that hold a seat in a session. */
    private const SEATED = ['booked', 'present', 'absent'];

    /* ══ Sessions ═════════════════════════════════════════════════════════ */

    public static function session(int $id): ?array
    {
        Grading::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM live_sessions WHERE id = ?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** An item's sessions, earliest first (cancelled ones too, unless $openOnly). */
    public static function sessions(int $itemId, bool $openOnly = false): array
    {
        Grading::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM live_sessions WHERE item_id = ?' . ($openOnly ? " AND status = 'open'" : '') . ' ORDER BY starts_at, id');
        $st->execute([$itemId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['taken'] = self::taken((int) $r['id']);
        return $rows;
    }

    /** Seats taken in a session. */
    public static function taken(int $sessionId): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM live_bookings WHERE session_id = ? AND status IN ('booked','present','absent')");
        $st->execute([$sessionId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Schedule (or edit, with $d['id']) a session. Times arrive as the form's
     * local 'Y-m-d\TH:i'; ends_at may instead be given as end_time 'H:i' on
     * the start's day.
     */
    public static function saveSession(int $itemId, array $d, int $by): array
    {
        $item = Grading::item($itemId);
        if (!$item || $item['kind'] !== 'live') return ['ok' => false, 'error' => 'That is not a live assessment.'];
        $courseId = (int) $item['course_id'];
        if (!CourseTeam::can($courseId, $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can schedule sessions.'];
        $start = Grading::fromLocal((string) ($d['starts_at'] ?? ''));
        $endRaw = (string) ($d['ends_at'] ?? '');
        if ($endRaw === '' && !empty($d['end_time']) && $start) $endRaw = substr(Grading::toLocal($start, 'Y-m-d'), 0, 10) . ' ' . $d['end_time'];
        $end = Grading::fromLocal($endRaw);
        if (!$start || !$end) return ['ok' => false, 'error' => 'Give the session a date, a start and an end time.'];
        if (strcmp($end, $start) <= 0) return ['ok' => false, 'error' => 'A session ends after it starts.'];
        $place = mb_substr(trim((string) ($d['place'] ?? '')), 0, 300);
        $link = trim((string) ($d['link'] ?? ''));
        if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $link) || strlen($link) > 500)) return ['ok' => false, 'error' => 'The meeting link must be a full https:// address.'];
        if ($place === '' && $link === '') return ['ok' => false, 'error' => 'Say where the session is: a place, a meeting link, or both.'];
        $cap = (string) ($d['capacity'] ?? '');
        if (!preg_match('/^\d+$/', $cap) || (int) $cap < 1 || (int) $cap > 500) return ['ok' => false, 'error' => 'Capacity is a number of learners from 1 to 500.'];
        $db = Database::pdo();
        if (!empty($d['id'])) {
            $cur = self::session((int) $d['id']);
            if (!$cur || (int) $cur['item_id'] !== $itemId) return ['ok' => false, 'error' => 'No such session.'];
            if ((int) $cap < self::taken((int) $cur['id'])) return ['ok' => false, 'error' => 'More learners are already booked than that capacity.'];
            $db->prepare('UPDATE live_sessions SET starts_at = ?, ends_at = ?, place = ?, link = ?, capacity = ? WHERE id = ?')
               ->execute([$start, $end, $place, $link, (int) $cap, (int) $cur['id']]);
            $id = (int) $cur['id'];
        } else {
            $db->prepare('INSERT INTO live_sessions (item_id, course_id, starts_at, ends_at, place, link, capacity, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
               ->execute([$itemId, $courseId, $start, $end, $place, $link, (int) $cap, 'open', max(0, $by), Grading::stamp()]);
            $id = (int) $db->lastInsertId();
        }
        Grading::audit('live.session.save', $courseId, 'session#' . $id, Grading::toLocal($start) . ' · ' . ($place ?: $link) . ' · ' . $cap . ' seats', $by);
        return ['ok' => true, 'id' => $id];
    }

    /** Cancel a session; its bookings are released so learners can book another. */
    public static function cancelSession(int $sessionId, int $by): array
    {
        $s = self::session($sessionId);
        if (!$s) return ['ok' => false, 'error' => 'No such session.'];
        if (!CourseTeam::can((int) $s['course_id'], $by, 'manage_items')) return ['ok' => false, 'error' => 'Only a lead or instructor can cancel a session.'];
        $db = Database::pdo();
        $db->prepare("UPDATE live_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);
        $db->prepare("UPDATE live_bookings SET status = 'cancelled', live_key = " . self::keyExpr() . " WHERE session_id = ? AND status = 'booked'")->execute([$sessionId]);
        Grading::audit('live.session.cancel', (int) $s['course_id'], 'session#' . $sessionId, '', $by);
        return ['ok' => true];
    }

    /* ══ Bookings ═════════════════════════════════════════════════════════ */

    public static function booking(int $id): ?array
    {
        Grading::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM live_bookings WHERE id = ?'); $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The learner's active booking on an item (with its session), or null. */
    public static function bookingFor(int $itemId, int $userId): ?array
    {
        Grading::ensure();
        $st = Database::pdo()->prepare("SELECT b.*, s.starts_at, s.ends_at, s.place, s.link FROM live_bookings b JOIN live_sessions s ON s.id = b.session_id
                                         WHERE b.item_id = ? AND b.user_id = ? AND b.live_key = 'live'");
        $st->execute([$itemId, $userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Unexcused absences — attempts used with no score. */
    public static function absences(int $itemId, int $userId): int
    {
        Grading::ensure();
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM live_bookings WHERE item_id = ? AND user_id = ? AND status = 'absent'");
        $st->execute([$itemId, $userId]);
        return (int) $st->fetchColumn();
    }

    /** Attempts used: sessions sat and scored, plus unexcused absences. */
    public static function attemptsUsed(int $itemId, int $userId): int
    {
        return GradingWork::attemptsUsed($itemId, $userId) + self::absences($itemId, $userId);
    }

    /** The SQL that frees a booking's live_key: its own id, so it never collides. */
    private static function keyExpr(): string
    {
        return Database::driver() === 'mysql' ? "CONCAT('b', id)" : "('b' || id)";
    }

    /**
     * Book $userId into a session. $by is the learner themself, or a member of
     * the team seating them. Booking another session of the same item moves
     * the booking; booking the same one again is a no-op.
     */
    public static function book(int $sessionId, int $userId, int $by): array
    {
        $s = self::session($sessionId);
        if (!$s || $s['status'] !== 'open') return ['ok' => false, 'error' => 'That session is not open for booking.'];
        $item = Grading::item((int) $s['item_id']);
        if (!$item || $item['status'] !== 'published' || $item['kind'] !== 'live') return ['ok' => false, 'error' => 'This assessment is not open for booking.'];
        $courseId = (int) $item['course_id'];
        if ($by !== $userId && !CourseTeam::can($courseId, $by, 'mark')) return ['ok' => false, 'error' => 'Only the course team can book somebody else in.'];
        if (!(new LmsRepository())->isEnrolled($userId, $courseId)) return ['ok' => false, 'error' => 'Only learners on the course can be booked.'];
        if (Grading::now() >= (int) strtotime($s['starts_at'] . ' UTC')) return ['ok' => false, 'code' => 'started', 'error' => 'That session has already started.'];
        $w = Grading::window($item);
        if ($w['closes'] !== null && strtotime($s['starts_at'] . ' UTC') > $w['closes']) return ['ok' => false, 'error' => 'That session is after the assessment closes.'];

        $cur = self::bookingFor((int) $item['id'], $userId);
        if ($cur && (int) $cur['session_id'] === $sessionId) return ['ok' => true, 'id' => (int) $cur['id'], 'unchanged' => true];
        if ($cur && $cur['status'] !== 'booked') return ['ok' => false, 'error' => 'That booking has already been sat.'];
        if (!$cur && self::attemptsUsed((int) $item['id'], $userId) >= max(1, (int) $item['max_attempts'])) {
            return ['ok' => false, 'code' => 'attempts', 'error' => 'No attempts are left on this assessment.'];
        }
        if (self::taken($sessionId) >= (int) $s['capacity']) return ['ok' => false, 'code' => 'full', 'error' => 'That session is full. Choose another.'];

        $db = Database::pdo();
        $attempt = $cur ? (int) $cur['attempt'] : self::attemptsUsed((int) $item['id'], $userId) + 1;
        try {
            $db->beginTransaction();
            if ($cur) {
                $db->prepare("UPDATE live_bookings SET status = 'cancelled', live_key = " . self::keyExpr() . ' WHERE id = ?')->execute([(int) $cur['id']]);
            }
            $db->prepare("INSERT INTO live_bookings (session_id, item_id, user_id, live_key, status, attempt, booked_by, booked_at) VALUES (?,?,?,'live','booked',?,?,?)")
               ->execute([$sessionId, (int) $item['id'], $userId, $attempt, max(0, $by), Grading::stamp()]);
            $id = (int) $db->lastInsertId();
            // Re-check the seat inside the transaction: two last-seat bookings must not both stand.
            if (self::taken($sessionId) > (int) $s['capacity']) { $db->rollBack(); return ['ok' => false, 'code' => 'full', 'error' => 'That session is full. Choose another.']; }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'error' => 'You already have a booking on this assessment.'];
        }
        if ($by !== $userId) Grading::audit('live.book', $courseId, 'session#' . $sessionId . ' user#' . $userId, 'seated by the team', $by);
        return ['ok' => true, 'id' => $id, 'moved' => (bool) $cur];
    }

    /** A learner cancels their own (unsat) booking. */
    public static function cancelBooking(int $bookingId, int $by): array
    {
        $b = self::booking($bookingId);
        if (!$b || $b['status'] !== 'booked') return ['ok' => false, 'error' => 'There is no booking to cancel.'];
        $item = Grading::item((int) $b['item_id']);
        if ((int) $b['user_id'] !== $by && !CourseTeam::can((int) $item['course_id'], $by, 'mark')) return ['ok' => false, 'error' => 'That booking is not yours.'];
        Database::pdo()->prepare("UPDATE live_bookings SET status = 'cancelled', live_key = " . self::keyExpr() . ' WHERE id = ?')->execute([$bookingId]);
        return ['ok' => true];
    }

    /** A session's roster, in booking order. */
    public static function roster(int $sessionId): array
    {
        $st = Database::pdo()->prepare("SELECT b.*, u.name, u.email FROM live_bookings b JOIN lms_users u ON u.id = b.user_id
                                         WHERE b.session_id = ? AND b.status IN ('booked','present','absent','excused') ORDER BY b.booked_at, b.id");
        $st->execute([$sessionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ══ On the day ═══════════════════════════════════════════════════════ */

    /** Record present or absent. Any panelist (anyone who may mark). */
    public static function presence(int $bookingId, string $state, int $by): array
    {
        $b = self::booking($bookingId);
        if (!$b) return ['ok' => false, 'error' => 'No such booking.'];
        $item = Grading::item((int) $b['item_id']);
        if (!CourseTeam::can((int) $item['course_id'], $by, 'mark')) return ['ok' => false, 'error' => 'Only the course team runs a session.'];
        if (!in_array($state, ['present', 'absent'], true)) return ['ok' => false, 'error' => 'Present or absent.'];
        if (!in_array($b['status'], ['booked', 'present', 'absent'], true)) return ['ok' => false, 'error' => 'That booking is ' . $b['status'] . '.'];
        if ($b['status'] === $state) return ['ok' => true, 'unchanged' => true];
        if ($state === 'absent' && (int) $b['submission_id'] > 0 && GradingWork::marks((int) $b['submission_id'])) {
            return ['ok' => false, 'error' => 'This learner has already been scored, so they were here.'];
        }
        // Sat or missed, the booking is no longer the learner's active one.
        Database::pdo()->prepare('UPDATE live_bookings SET status = ?, live_key = ' . self::keyExpr() . ' WHERE id = ?')->execute([$state, $bookingId]);
        if ($state === 'absent') Grading::audit('live.absent', (int) $item['course_id'], 'booking#' . $bookingId . ' user#' . $b['user_id'], '', $by);
        return ['ok' => true];
    }

    /** A lead or instructor excuses an absence with a reason: the attempt is given back. */
    public static function excuse(int $bookingId, int $by, string $reason): array
    {
        $b = self::booking($bookingId);
        if (!$b) return ['ok' => false, 'error' => 'No such booking.'];
        $item = Grading::item((int) $b['item_id']);
        if (!CourseTeam::can((int) $item['course_id'], $by, 'moderate')) return ['ok' => false, 'error' => 'Only a lead or instructor can excuse an absence.'];
        if ($b['status'] !== 'absent') return ['ok' => false, 'error' => 'Only an absence can be excused.'];
        $reason = trim($reason);
        if ($reason === '') return ['ok' => false, 'error' => 'Say why the absence is excused — it is recorded.'];
        Database::pdo()->prepare("UPDATE live_bookings SET status = 'excused', excuse_reason = ?, excused_by = ? WHERE id = ?")
            ->execute([mb_substr($reason, 0, 500), max(0, $by), $bookingId]);
        Grading::audit('live.excuse', (int) $item['course_id'], 'booking#' . $bookingId . ' user#' . $b['user_id'], $reason, $by);
        return ['ok' => true];
    }

    /**
     * A panelist scores the learner in front of them. Marks the learner
     * present, creates their submission for this attempt once (whoever on the
     * panel saves first), and records this panelist's mark through
     * GradingWork::mark — idempotent per (learner, panelist).
     */
    public static function score(int $bookingId, int $panelistId, array $scores, string $note): array
    {
        $b = self::booking($bookingId);
        if (!$b) return ['ok' => false, 'error' => 'No such booking.'];
        $item = Grading::item((int) $b['item_id']);
        if (!$item || !CourseTeam::can((int) $item['course_id'], $panelistId, 'mark')) return ['ok' => false, 'error' => 'Only the course team can score.'];
        if ($b['status'] === 'absent' || $b['status'] === 'excused') return ['ok' => false, 'error' => 'This learner is marked ' . $b['status'] . '. Mark them present first.'];
        if (!in_array($b['status'], ['booked', 'present'], true)) return ['ok' => false, 'error' => 'That booking is ' . $b['status'] . '.'];
        $chk = Grading::scoreRubric($item['rubric'], array_values($scores));
        if (!$chk['ok']) return $chk;
        if ($b['status'] === 'booked') self::presence($bookingId, 'present', $panelistId);
        $subId = self::submissionFor($b, $item);
        if ($subId <= 0) return ['ok' => false, 'error' => 'Could not open the score sheet. Try again.'];
        return GradingWork::mark($subId, $panelistId, $scores, $note);
    }

    /** The booking's submission, created once even when several panelists save at the same moment. */
    private static function submissionFor(array $b, array $item): int
    {
        $db = Database::pdo();
        if ((int) $b['submission_id'] > 0) return (int) $b['submission_id'];
        $s = self::session((int) $b['session_id']);
        $db->prepare(Database::insertIgnore('grade_submissions', ['item_id', 'course_id', 'user_id', 'attempt', 'body', 'submitted_at', 'is_late']))
           ->execute([(int) $item['id'], (int) $item['course_id'], (int) $b['user_id'], (int) $b['attempt'], '', (string) ($s['starts_at'] ?? Grading::stamp()), 0]);
        $st = $db->prepare('SELECT id FROM grade_submissions WHERE item_id = ? AND user_id = ? AND attempt = ?');
        $st->execute([(int) $item['id'], (int) $b['user_id'], (int) $b['attempt']]);
        $id = (int) $st->fetchColumn();
        $db->prepare('UPDATE live_bookings SET submission_id = ? WHERE id = ? AND submission_id = 0')->execute([$id, (int) $b['id']]);
        return $id;
    }

    /* ══ The reminder ═════════════════════════════════════════════════════ */

    /**
     * Remind every learner booked into a session starting within the next 24
     * hours. Each booking is CLAIMED (reminded_at set where it was empty)
     * before its email goes, so a cron every five minutes still sends one.
     */
    public static function remind(): int
    {
        Grading::ensure();
        $db = Database::pdo();
        $now = Grading::stamp(); $soon = Grading::stamp(Grading::now() + 86400);
        $st = $db->prepare("SELECT b.id, b.user_id, b.item_id, s.starts_at, s.ends_at, s.place, s.link, u.name, u.email, i.title, c.slug, c.title AS course_title
                              FROM live_bookings b JOIN live_sessions s ON s.id = b.session_id JOIN lms_users u ON u.id = b.user_id
                              JOIN graded_items i ON i.id = b.item_id JOIN courses c ON c.id = i.course_id
                             WHERE b.status = 'booked' AND b.reminded_at = '' AND s.status = 'open' AND s.starts_at > ? AND s.starts_at <= ?");
        $st->execute([$now, $soon]);
        $claim = $db->prepare("UPDATE live_bookings SET reminded_at = ? WHERE id = ? AND reminded_at = ''");
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $claim->execute([$now, (int) $r['id']]);
            if ($claim->rowCount() !== 1) continue;
            $n++;
            try {
                $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $when = Grading::toLocal((string) $r['starts_at'], 'l j F, g:i a') . ' – ' . Grading::toLocal((string) $r['ends_at'], 'g:i a');
                $where = trim($r['place'] . ($r['link'] !== '' ? ' · ' . $r['link'] : ''));
                $subject = 'Tomorrow: ' . $r['title'] . ' (live assessment)';
                Mailer::send((string) $r['email'], $subject, Mailer::shell($subject, [
                    'Hi ' . $h(explode(' ', trim((string) $r['name']))[0] ?: 'there') . ',',
                    'Your live assessment <strong>' . $h((string) $r['title']) . '</strong> for ' . $h((string) $r['course_title']) . ' is tomorrow.',
                    '<strong>When:</strong> ' . $h($when) . ' (Lagos time)<br><strong>Where:</strong> ' . $h($where),
                ], ['url' => academy_url($r['slug'] . '/grades/?item=' . (int) $r['item_id']), 'text' => 'See your booking'], 'Live assessment tomorrow', ['eyebrow' => 'Afrovanguard Academy']));
            } catch (Throwable $e) { error_log('[live] reminder: ' . $e->getMessage()); }
        }
        return $n;
    }
}
