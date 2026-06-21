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
}
