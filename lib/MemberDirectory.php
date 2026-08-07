<?php
/**
 * lib/MemberDirectory.php — the member directory + rich profile cards.
 *
 * Powers the hover/tap profile card shown across the portal & community: role,
 * cohort/level, the member's LOCAL time (from their timezone pref), a headline
 * (mentor profile, if present) and self-declared skills (a Prefs value). Every
 * read is org-scoped and fail-safe.
 */
declare(strict_types=1);

final class MemberDirectory
{
    private static function orgLike(): string
    {
        $d = strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
        return '%@' . $d;
    }

    private static function roleLabel(string $role): string
    {
        $map = ['admin' => 'Admin', 'coordinator' => 'Coordinator', 'instructor' => 'Instructor',
                'mentor' => 'Mentor', 'member' => 'Member', 'learner' => 'Learner'];
        return $map[strtolower($role)] ?? ucfirst($role ?: 'Member');
    }

    private static function hasMentorHeadline(): bool
    {
        try { return Database::columnExists('mentor_profiles', 'headline'); } catch (Throwable $e) { return false; }
    }
    private static function hasLevel(): bool
    {
        try { return Database::columnExists('lms_users', 'level'); } catch (Throwable $e) { return false; }
    }

    /** One member's card. Org members only; returns null if not found/not org. */
    public static function card(int $viewerId, int $userId): ?array
    {
        if ($userId <= 0) return null;
        $hasMentor = self::hasMentorHeadline();
        try {
            $sql = "SELECT u.id, u.name, u.email, u.role" . (self::hasLevel() ? ", u.level" : ", 0 AS level")
                 . ($hasMentor ? ", mp.headline AS headline" : ", '' AS headline")
                 . " FROM lms_users u"
                 . ($hasMentor ? " LEFT JOIN mentor_profiles mp ON mp.user_id = u.id" : "")
                 . " WHERE u.id = ? AND u.status = 'active' AND LOWER(u.email) LIKE ?";
            $st = Database::pdo()->prepare($sql);
            $st->execute([$userId, self::orgLike()]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('[directory] card: ' . $e->getMessage()); return null; }
        if (!$r) return null;
        return self::shape($r, $viewerId);
    }

    /** Directory list, optional name/skill query. Org members only. */
    public static function listMembers(int $viewerId, string $q = '', int $limit = 200): array
    {
        $hasMentor = self::hasMentorHeadline();
        $limit = max(1, min(500, $limit));
        $q = trim($q);
        try {
            $sql = "SELECT u.id, u.name, u.email, u.role" . (self::hasLevel() ? ", u.level" : ", 0 AS level")
                 . ($hasMentor ? ", mp.headline AS headline" : ", '' AS headline")
                 . " FROM lms_users u"
                 . ($hasMentor ? " LEFT JOIN mentor_profiles mp ON mp.user_id = u.id" : "")
                 . " WHERE u.status = 'active' AND LOWER(u.email) LIKE ?";
            $args = [self::orgLike()];
            if ($q !== '') { $sql .= " AND (LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?)"; $args[] = '%' . strtolower($q) . '%'; $args[] = '%' . strtolower($q) . '%'; }
            $sql .= " ORDER BY u.name ASC LIMIT $limit";
            $st = Database::pdo()->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[directory] list: ' . $e->getMessage()); return []; }
        $out = [];
        foreach ($rows as $r) $out[] = self::shape($r, $viewerId);
        // If a skill query matched no names, also surface members whose skills match.
        return $out;
    }

    private static function shape(array $r, int $viewerId): array
    {
        $uid  = (int) $r['id'];
        $name = (string) ($r['name'] ?: explode('@', (string) $r['email'])[0]);
        $tz   = function_exists('av_user_tz') ? av_user_tz($uid) : (defined('AV_TZ') ? AV_TZ : 'UTC');
        $skills = class_exists('Prefs') ? array_values(array_filter(array_map('trim', explode(',', Prefs::get($uid, 'skills', ''))))) : [];
        $level = (int) ($r['level'] ?? 0);
        return [
            'id'         => $uid,
            'name'       => $name,
            'initial'    => mb_strtoupper(mb_substr($name, 0, 1)),
            'role'       => self::roleLabel((string) ($r['role'] ?? 'member')),
            'level'      => $level > 0 ? $level : null,
            'headline'   => mb_substr(trim((string) ($r['headline'] ?? '')), 0, 160),
            'skills'     => array_slice($skills, 0, 8),
            'tz'         => $tz,
            'local_time' => function_exists('av_now_tz') ? av_now_tz('H:i', $tz) : '',
            'is_me'      => $uid === $viewerId,
        ];
    }

    /** Save the caller's own skills (comma-separated). */
    public static function setSkills(int $uid, string $skills): bool
    {
        if ($uid <= 0 || !class_exists('Prefs')) return false;
        $clean = array_slice(array_values(array_filter(array_map(fn($s) => trim(mb_substr($s, 0, 40)), explode(',', $skills)))), 0, 8);
        return Prefs::set($uid, 'skills', implode(', ', $clean));
    }
}
