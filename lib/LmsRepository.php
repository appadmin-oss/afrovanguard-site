<?php
/**
 * lib/LmsRepository.php — modules, lessons, progress, access & membership.
 */
declare(strict_types=1);

final class LmsRepository
{
    private PDO $db;
    public function __construct(?PDO $pdo = null) { $this->db = $pdo ?? Database::pdo(); }

    /** Modules (with their lessons) for a course. */
    public function curriculum(int $courseId): array
    {
        $mods = $this->db->prepare('SELECT * FROM modules WHERE course_id = ? ORDER BY position, id');
        $mods->execute([$courseId]);
        $modules = $mods->fetchAll();
        // Also fetch video_url / quiz_json so the UI can label each lesson's type
        // (video · reading · quiz) with an icon — a cheap read; both are small.
        $ls = $this->db->prepare('SELECT id, slug, title, duration_min, is_preview, position, video_url, quiz_json FROM lessons WHERE module_id = ? ORDER BY position, id');
        foreach ($modules as &$m) {
            $ls->execute([$m['id']]);
            $lessons = $ls->fetchAll();
            foreach ($lessons as &$l) { $l['type'] = self::lessonType($l); }
            $m['lessons'] = $lessons;
        }
        return $modules;
    }

    public function lessonCount(int $courseId): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?'); $s->execute([$courseId]);
        return (int) $s->fetchColumn();
    }

    public function lesson(int $courseId, string $slug): ?array
    {
        $s = $this->db->prepare('SELECT * FROM lessons WHERE course_id = ? AND slug = ?');
        $s->execute([$courseId, $slug]);
        return $s->fetch() ?: null;
    }

    /** Ordered lesson list (for prev/next + the "up next" card). */
    public function orderedLessons(int $courseId): array
    {
        $s = $this->db->prepare(
            'SELECT l.id, l.slug, l.title, l.is_preview, l.duration_min, l.video_url, l.quiz_json
             FROM lessons l JOIN modules m ON m.id = l.module_id
             WHERE l.course_id = ? ORDER BY m.position, m.id, l.position, l.id'
        );
        $s->execute([$courseId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) { $r['type'] = self::lessonType($r); }
        return $rows;
    }

    /** Classify a lesson row as 'video' | 'quiz' | 'reading' from its content. */
    public static function lessonType(array $l): string
    {
        if (!empty($l['video_url'])) return 'video';
        if (!empty($l['quiz_json']) && trim((string) $l['quiz_json']) !== '') return 'quiz';
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
    public function enrol(int $userId, int $courseId): void
    {
        $st = $this->db->prepare(Database::insertIgnore('course_enrolment', ['user_id', 'course_id']));
        $st->execute([$userId, $courseId]);
        if ($st->rowCount() > 0 && class_exists('Events')) Events::emit('enrollment.created', ['user_id' => $userId, 'course_id' => $courseId]);
    }

    /** A learner's enrolled courses with progress + certificate state (for the portal). */
    public function enrolledCourses(int $userId): array
    {
        $s = $this->db->prepare(
            "SELECT c.id, c.slug, c.title, c.cover_url, c.gradient
             FROM course_enrolment e JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = ? ORDER BY e.created_at DESC"
        );
        $s->execute([$userId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) {
            $pr = $this->progress($userId, (int) $r['id']);
            $r['pct'] = $pr['pct']; $r['complete'] = $pr['complete'];
            $r['certified'] = (bool) $this->getCertificate($userId, (int) $r['id']);
        }
        return $rows;
    }

    /** Can this (maybe-null) user open this lesson? */
    public function canAccess(?array $user, array $course, array $lesson): bool
    {
        if (!empty($lesson['is_preview'])) return true;             // previews always open
        $access = $course['access_type'] ?? 'open';
        if ($access === 'open') return true;                        // open courses: all lessons free
        if (!$user) return false;                                   // tracked/membership/paid need an account
        if (LmsAuth::canTeach($user)) return true;                  // instructor / coordinator / admin: full access
        if ($access === 'tracked') return true;                     // free but account-gated for tracking
        // A "member" is a paid Academy member OR an Afrovanguard org account (@afrovanguard.org.ng).
        $member = $this->isMember((int) $user['id']) || LmsAuth::isOrgMember($user);
        if ($access === 'membership') return $member;
        if ($access === 'paid') return $this->isEnrolled((int) $user['id'], (int) $course['id']) || $member;
        return false;
    }

    /** Decode a lesson's quiz, or null. Shape: {pass:int, questions:[{q,options[],answer}]} */
    public function quiz(array $lesson): ?array
    {
        if (empty($lesson['quiz_json'])) return null;
        $q = json_decode((string) $lesson['quiz_json'], true);
        if (!is_array($q) || empty($q['questions'])) return null;
        $q['pass'] = max(1, min(100, (int) ($q['pass'] ?? 70)));
        return $q;
    }

    /** Grade submitted answers (array of chosen option indexes). Records the attempt. */
    public function gradeQuiz(int $userId, array $lesson, array $answers): array
    {
        $quiz = $this->quiz($lesson);
        if (!$quiz) return ['ok' => false];
        $total = count($quiz['questions']); $correct = 0;
        foreach ($quiz['questions'] as $i => $q) {
            if (isset($answers[$i]) && (int) $answers[$i] === (int) $q['answer']) $correct++;
        }
        $score = $total ? (int) round($correct / $total * 100) : 0;
        $passed = $score >= (int) $quiz['pass'];
        $this->db->prepare('INSERT INTO quiz_attempts (user_id, lesson_id, score, passed) VALUES (?,?,?,?)')
            ->execute([$userId, (int) $lesson['id'], $score, $passed ? 1 : 0]);
        if ($passed) $this->markComplete($userId, $lesson);
        return ['ok' => true, 'score' => $score, 'passed' => $passed, 'correct' => $correct, 'total' => $total, 'pass' => (int) $quiz['pass']];
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
        $total = $this->lessonCount($courseId);
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
        $pos = (int) $this->db->query('SELECT COALESCE(MAX(position),-1)+1 FROM modules WHERE course_id = ' . (int) $courseId)->fetchColumn();
        $st = $this->db->prepare('INSERT INTO modules (course_id, title, position) VALUES (?,?,?)');
        $st->execute([$courseId, $title, $pos]);
        return (int) $this->db->lastInsertId();
    }
    public function renameModule(int $id, string $title): void { $this->db->prepare('UPDATE modules SET title = ? WHERE id = ?')->execute([$title, $id]); }
    public function deleteModule(int $id): void
    {
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
            'title' => $d['title'], 'body_html' => $d['body_html'] ?? '', 'video_url' => $d['video_url'] ?: null,
            'duration_min' => (int) ($d['duration_min'] ?? 0), 'is_preview' => !empty($d['is_preview']) ? 1 : 0,
            'quiz_json' => isset($d['quiz_json']) && $d['quiz_json'] !== '' ? $d['quiz_json'] : null,
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
    private function lessonModule(int $moduleId): ?array { $s = $this->db->prepare('SELECT * FROM modules WHERE id = ?'); $s->execute([$moduleId]); return $s->fetch() ?: null; }
    public function deleteLesson(int $id): void { $this->db->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]); }

    /* ── Management: reordering (positions 0..n in the given order) ── */
    public function reorderModules(int $courseId, array $orderedIds): void
    {
        $u = $this->db->prepare('UPDATE modules SET position = ? WHERE id = ? AND course_id = ?');
        foreach (array_values($orderedIds) as $pos => $id) { $u->execute([$pos, (int) $id, $courseId]); }
    }
    public function reorderLessons(int $moduleId, array $orderedIds): void
    {
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
        if (!$this->progress($userId, $courseId)['complete']) return null;
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
    public function finalizePayment(string $reference, ?int $paidKobo = null): bool
    {
        $p = $this->paymentByRef($reference);
        if (!$p) return false;
        if ($p['status'] === 'paid') return true;       // already granted (idempotent)
        if ($paidKobo === null || (int) $paidKobo < (int) $p['amount_kobo']) {
            error_log('[lms] finalizePayment refused for ' . $reference . ': paid '
                . var_export($paidKobo, true) . ' kobo < owed ' . (int) $p['amount_kobo']);
            return false;
        }
        $this->db->prepare("UPDATE payments SET status='paid', paid_at=? WHERE reference=?")->execute([gmdate('Y-m-d H:i:s'), $reference]);
        $user = $this->userRow((int) $p['user_id']);
        if ($p['kind'] === 'course' && $p['course_id']) {
            $this->enrol((int) $p['user_id'], (int) $p['course_id']);
            if ($user && class_exists('Notify')) {
                $c = $this->courseRow((int) $p['course_id']);
                if ($c) Notify::enrolled($user, $c);
            }
        } elseif ($p['kind'] === 'membership') {
            $this->grantMembership((int) $p['user_id'], (int) ($p['months'] ?? 12) ?: 12);
            if ($user && class_exists('Notify')) Notify::membership($user);
        }
        return true;
    }
    public function grantMembership(int $userId, int $months = 12): void
    {
        // Renewals extend from the LATER of now or the member's current paid-through
        // date, so paying dues early (or twice) never forfeits time already paid for.
        $base = time();
        $cur  = $this->latestMembership($userId);
        if ($cur && !empty($cur['expires_at'])) {
            $curTs = strtotime((string) $cur['expires_at']);
            if ($curTs && $curTs > $base) $base = $curTs;
        }
        $exp = date('Y-m-d H:i:s', strtotime("+$months months", $base));
        $this->db->prepare("INSERT INTO memberships (user_id, tier, status, expires_at) VALUES (?, 'member', 'active', ?)")
            ->execute([$userId, $exp]);
    }

    /** Extend a member's membership by N months, found by email (recurring dues). */
    public function grantMembershipByEmail(string $email, int $months = 1): bool
    {
        $s = $this->db->prepare('SELECT id FROM lms_users WHERE email = ? LIMIT 1');
        $s->execute([$email]);
        $id = (int) ($s->fetchColumn() ?: 0);
        if ($id <= 0) return false;
        $this->grantMembership($id, max(1, $months));
        return true;
    }

    /** The member's most recent membership row (lifetime rows first, then latest expiry). */
    public function latestMembership(int $userId): ?array
    {
        $s = $this->db->prepare(
            "SELECT * FROM memberships WHERE user_id = ?
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
        $annualNgn  = defined('AV_DUES_ANNUAL_NGN')  ? (int) AV_DUES_ANNUAL_NGN  : 12000;
        $monthlyNgn = defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000;
        $amountNgn = $annualNgn;
        $m = $this->latestMembership($userId);
        $paidThrough = $m['expires_at'] ?? null;
        $lifetime = $m && empty($paidThrough);

        $daysLeft = null;
        if ($paidThrough) {
            $ts = strtotime((string) $paidThrough);
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

        return [
            'amount_ngn'   => $amountNgn,        // annual (kept for back-compat)
            'annual_ngn'   => $annualNgn,
            'monthly_ngn'  => $monthlyNgn,
            'currency'     => 'NGN',
            'period'       => 'year',
            'state'        => $state,
            'lifetime'     => $lifetime,
            'paid_through' => $paidThrough ? gmdate('c', (int) strtotime((string) $paidThrough)) : null,
            'days_left'    => $daysLeft,
            'last_paid_at' => $lastPaid ? gmdate('c', (int) strtotime((string) $lastPaid)) : null,
            'payable'      => class_exists('Payments') && Payments::configured('paystack'),
        ];
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
        if (!$this->progress($userId, $courseId)['complete']) return ['complete' => false, 'newly' => false, 'cert' => null];
        $newly = !$this->getCertificate($userId, $courseId);
        $cert = $this->issueCertificate($userId, $courseId);
        return ['complete' => true, 'newly' => (bool) ($newly && $cert), 'cert' => $cert];
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
    public function audit(string $action, string $target = '', string $detail = '', string $actor = 'admin'): void
    {
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
        $w = []; $p = [];
        if ($q !== '')      { $w[] = '(name LIKE ? OR email LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($role !== '')   { $w[] = 'role = ?';   $p[] = $role; }
        if ($status !== '') { $w[] = 'status = ?'; $p[] = $status; }
        $sql = "SELECT id, name, email, role, status, created_at, last_login, level FROM lms_users";
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
