<?php
/**
 * lib/AdminRoles.php — structured admin levels for the Studio.
 *
 *   superadmin  full control (roles, DB tools, destructive actions, undo)
 *   admin       day-to-day management (content, people, mentorship, webhooks, undo)
 *   editor      content only (Diary, Academy, Moderation)
 *
 * The break-glass ADMIN_TOKEN (bearer or token login) is always SUPERADMIN.
 * Additional admins are member accounts listed in admin_users(email→role); when
 * such a member is signed in (LmsAuth) and opens the Studio, they're bridged to
 * an admin session cookie carrying their role (so CSRF/session work as usual).
 */
declare(strict_types=1);

final class AdminRoles
{
    const RANK = ['editor' => 1, 'admin' => 2, 'superadmin' => 3];

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
