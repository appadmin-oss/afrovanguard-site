<?php
/**
 * lib/AdminRoles.php — structured admin levels for the Studio.
 *
 *   superadmin       full control (roles, DB tools, destructive actions, undo)
 *   admin            day-to-day management (content, people, mentorship, webhooks, undo)
 *   editor           content only (Diary, Academy, Moderation)
 *   academy_admin    SCOPED — the Academy only (its dedicated /academy/studio/ portal)
 *   mentorship_admin SCOPED — Mentorship only (its dedicated /mentorship/admin/ portal)
 *
 * The general ladder (editor < admin < superadmin) is rank-based. The two scoped
 * roles sit BELOW superadmin but are not a point on that ladder — they are
 * confined to a single domain by an explicit capability allow-list in
 * admin/api.php (see av_admin_scope_allows()), and routed to their own portal
 * rather than the full Studio. Use isScoped()/scopeHome() to detect/route them.
 *
 * The break-glass ADMIN_TOKEN (bearer or token login) is always SUPERADMIN.
 * Additional admins are member accounts listed in admin_users(email→role); when
 * such a member signs in (password or Google) and opens an admin surface, they're
 * bridged to an admin session cookie carrying their role (so CSRF/session work).
 */
declare(strict_types=1);

final class AdminRoles
{
    // Rank orders the GENERAL ladder. Scoped roles get a nominal rank below admin
    // so any accidental rank comparison can never grant management/superadmin
    // powers; real authorisation for scoped roles is the allow-list, not rank.
    const RANK = ['editor' => 1, 'academy_admin' => 1, 'mentorship_admin' => 1, 'admin' => 2, 'superadmin' => 3];

    /** Scoped roles → the portal they belong in (used to route them on sign-in). */
    const SCOPE_HOME = ['academy_admin' => '/academy/studio/', 'mentorship_admin' => '/mentorship/admin/'];

    /** Human labels for the Team & roles dropdown (key → display name). */
    const LABELS = [
        'editor'           => 'Editor — content only',
        'academy_admin'    => 'Academy Admin — Academy portal only',
        'mentorship_admin' => 'Mentorship Admin — Mentorship portal only',
        'admin'            => 'Admin — management + content',
        'superadmin'       => 'Super Admin — everything',
    ];

    public static function isScoped(string $role): bool { return isset(self::SCOPE_HOME[$role]); }
    public static function scopeHome(string $role): string { return self::SCOPE_HOME[$role] ?? ''; }
    /** [['value'=>role,'label'=>…], …] in ascending authority — for role pickers. */
    public static function options(): array
    {
        $out = [];
        foreach (['editor', 'academy_admin', 'mentorship_admin', 'admin', 'superadmin'] as $r) {
            $out[] = ['value' => $r, 'label' => self::LABELS[$r] ?? $r];
        }
        return $out;
    }

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        try {
            Database::pdo()->exec("CREATE TABLE IF NOT EXISTS admin_users (
                email VARCHAR(191) PRIMARY KEY,
                role VARCHAR(16) NOT NULL DEFAULT 'editor',
                added_by VARCHAR(191) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) { error_log('[adminroles] ensure: ' . $e->getMessage()); }
        self::$ready = true;
    }

    public static function roleForEmail(string $email): string
    {
        $email = strtolower(trim($email)); if ($email === '') return '';
        self::ensure();
        try {
            $s = Database::pdo()->prepare('SELECT role FROM admin_users WHERE email = ?'); $s->execute([$email]);
            $r = (string) $s->fetchColumn();
            return isset(self::RANK[$r]) ? $r : '';
        } catch (Throwable $e) { return ''; }
    }

    /** Resolve the CURRENT request's admin role ('' if not an admin). Bridges a
     *  signed-in member-admin to a role cookie so the rest of the session works. */
    public static function current(): string
    {
        if (function_exists('av_admin_bearer_ok') && av_admin_bearer_ok()) return 'superadmin';
        if (function_exists('av_admin_cookie_role')) {
            $r = av_admin_cookie_role();
            if ($r !== '') return $r;
        }
        if (class_exists('LmsAuth')) {
            $u = LmsAuth::user();
            if ($u && !empty($u['email'])) {
                $role = self::roleForEmail((string) $u['email']);
                if ($role !== '') { if (function_exists('av_admin_cookie_issue')) av_admin_cookie_issue(43200, $role); return $role; }
            }
        }
        return '';
    }

    public static function can(string $min): bool
    {
        $cur = self::current();
        return $cur !== '' && (self::RANK[$cur] ?? 0) >= (self::RANK[$min] ?? 99);
    }

    public static function list(): array
    {
        self::ensure();
        try { return Database::pdo()->query('SELECT email, role, added_by, created_at FROM admin_users ORDER BY role DESC, email ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e) { return []; }
    }

    public static function add(string $email, string $role, string $by = ''): array
    {
        self::ensure();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email.'];
        if (!isset(self::RANK[$role])) return ['ok' => false, 'error' => 'Unknown role.'];
        try {
            $db = Database::pdo();
            $exists = $db->prepare('SELECT 1 FROM admin_users WHERE email=?'); $exists->execute([$email]);
            if ($exists->fetchColumn()) $db->prepare('UPDATE admin_users SET role=? WHERE email=?')->execute([$role, $email]);
            else $db->prepare('INSERT INTO admin_users (email, role, added_by, created_at) VALUES (?,?,?,?)')->execute([$email, $role, $by, gmdate('Y-m-d H:i:s')]);
            return ['ok' => true];
        } catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not save.']; }
    }

    public static function remove(string $email): array
    {
        self::ensure();
        try { Database::pdo()->prepare('DELETE FROM admin_users WHERE email=?')->execute([strtolower(trim($email))]); return ['ok' => true]; }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not remove.']; }
    }
}

/** Global helper used by require_admin() and the API. */
function av_admin_role(): string { return class_exists('AdminRoles') ? AdminRoles::current() : (av_admin_cookie_valid() || av_admin_bearer_ok() ? 'superadmin' : ''); }

/**
 * Capability gate for the SCOPED admin roles (academy_admin, mentorship_admin).
 *
 * Returns true only for actions inside the role's own domain. This is an
 * allow-list, not a rank check: a scoped role can reach ONLY what is listed
 * here, so a new action is denied by default until explicitly granted. The
 * `mod_` action family is deliberately split — mod_save/mod_delete/mod_reorder
 * are Academy *curriculum modules*; mod_queue/mod_approve/mod_reject are Diary
 * moderation and are NOT granted to the Academy admin.
 *
 * General roles (editor/admin/superadmin) are governed by the rank-based gates
 * in admin/api.php and never reach this function.
 */
function av_admin_scope_allows(string $role, string $action): bool
{
    static $map = [
        'academy_admin' => [
            'prefix' => ['ac_', 'roster_', 'cert_', 'lesson_'],
            'exact'  => ['ping', 'enrollments', 'upload', 'activity',
                         'mod_save', 'mod_delete', 'mod_reorder'],
        ],
        'mentorship_admin' => [
            'prefix' => ['mentorship_'],
            'exact'  => ['ping', 'activity'],
        ],
    ];
    if (!isset($map[$role])) return false;
    foreach ($map[$role]['prefix'] as $p) {
        if (strncmp($action, $p, strlen($p)) === 0) return true;
    }
    return in_array($action, $map[$role]['exact'], true);
}
