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
        $ls = $this->db->prepare('SELECT id, slug, title, duration_min, is_preview, position FROM lessons WHERE module_id = ? ORDER BY position, id');
        foreach ($modules as &$m) { $ls->execute([$m['id']]); $m['lessons'] = $ls->fetchAll(); }
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

    /** Ordered lesson list (for prev/next). */
    public function orderedLessons(int $courseId): array
    {
        $s = $this->db->prepare(
            'SELECT l.id, l.slug, l.title, l.is_preview FROM lessons l JOIN modules m ON m.id = l.module_id
             WHERE l.course_id = ? ORDER BY m.position, m.id, l.position, l.id'
        );
        $s->execute([$courseId]);
        return $s->fetchAll();
    }

    public function isMember(int $userId): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM memberships WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > datetime('now')) LIMIT 1");
        $s->execute([$userId]);
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
        $this->db->prepare('INSERT OR IGNORE INTO course_enrolment (user_id, course_id) VALUES (?,?)')->execute([$userId, $courseId]);
    }

    /** Can this (maybe-null) user open this lesson? */
    public function canAccess(?array $user, array $course, array $lesson): bool
    {
        if (!empty($lesson['is_preview'])) return true;             // previews always open
        $access = $course['access_type'] ?? 'open';
        if ($access === 'open') return true;                        // open courses: all lessons free
        if (!$user) return false;                                   // tracked/membership/paid need an account
        if ($user['role'] === 'admin' || $user['role'] === 'instructor') return true;
        if ($access === 'tracked') return true;                     // free but account-gated for tracking
        if ($access === 'membership') return $this->isMember((int) $user['id']);
        if ($access === 'paid') return $this->isEnrolled((int) $user['id'], (int) $course['id']) || $this->isMember((int) $user['id']);
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
        $this->db->prepare('INSERT OR IGNORE INTO lesson_progress (user_id, lesson_id, course_id) VALUES (?,?,?)')
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

    /* ── Certificates ── */
    public function issueCertificate(int $userId, int $courseId): ?array
    {
        if (!$this->progress($userId, $courseId)['complete']) return null;
        $cur = $this->getCertificate($userId, $courseId);
        if ($cur) return $cur;
        $serial = 'AV-' . strtoupper(substr(md5($userId . ':' . $courseId . ':' . microtime()), 0, 4)) . '-' . date('Y') . '-' . str_pad((string) $courseId, 3, '0', STR_PAD_LEFT) . str_pad((string) $userId, 4, '0', STR_PAD_LEFT);
        $this->db->prepare('INSERT OR IGNORE INTO certificates (user_id, course_id, serial) VALUES (?,?,?)')->execute([$userId, $courseId, $serial]);
        return $this->getCertificate($userId, $courseId);
    }
    public function getCertificate(int $userId, int $courseId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM certificates WHERE user_id = ? AND course_id = ?');
        $s->execute([$userId, $courseId]); return $s->fetch() ?: null;
    }
    /* ── Payments ── */
    public function createPayment(int $userId, string $kind, ?int $courseId, int $amountKobo, string $reference, string $provider = 'paystack'): void
    {
        $this->db->prepare('INSERT INTO payments (reference, user_id, provider, kind, course_id, amount_kobo) VALUES (?,?,?,?,?,?)')
            ->execute([$reference, $userId, $provider, $kind, $courseId, $amountKobo]);
    }
    public function paymentByRef(string $reference): ?array
    {
        $s = $this->db->prepare('SELECT * FROM payments WHERE reference = ?'); $s->execute([$reference]);
        return $s->fetch() ?: null;
    }
    /** Mark a verified payment paid (idempotent) and grant the access it bought. */
    public function finalizePayment(string $reference): bool
    {
        $p = $this->paymentByRef($reference);
        if (!$p) return false;
        if ($p['status'] === 'paid') return true;       // already granted
        $this->db->prepare("UPDATE payments SET status='paid', paid_at=datetime('now') WHERE reference=?")->execute([$reference]);
        if ($p['kind'] === 'course' && $p['course_id']) {
            $this->enrol((int) $p['user_id'], (int) $p['course_id']);
        } elseif ($p['kind'] === 'membership') {
            $this->grantMembership((int) $p['user_id']);
        }
        return true;
    }
    public function grantMembership(int $userId, int $months = 12): void
    {
        $exp = date('Y-m-d H:i:s', strtotime("+$months months"));
        $this->db->prepare("INSERT INTO memberships (user_id, tier, status, expires_at) VALUES (?, 'member', 'active', ?)")
            ->execute([$userId, $exp]);
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
