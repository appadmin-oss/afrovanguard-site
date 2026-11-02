<?php
/**
 * lib/AdminRoles.php — structured admin levels for the Studio.
 *
 *   superadmin  full control (roles, DB tools, destructive actions, undo)
 *   admin       day-to-day management (content, people, mentorship, webhooks, undo)
 *   editor      content only (Diary, Academy, Moderation)
 *
 * The break-glass ADMIN_TOKEN (bearer or token login) is always SUPERADMIN.
 *
 * Only an organisation address (@afrovanguard.org.ng, AV_ORG_DOMAIN) can be an
 * admin. Refused when added, and — because a row written before this rule, or
 * straight into the table, would otherwise still open the Studio — ignored
 * when read: an off-domain entry grants nothing. The address is the identity
 * the organisation controls; a personal mailbox is one it cannot suspend.
 * Additional admins are member accounts listed in admin_users(email→role); when
 * such a member is signed in (LmsAuth) and opens the Studio, they're bridged to
 * an admin session cookie carrying their role (so CSRF/session work as usual).
 */
declare(strict_types=1);

final class AdminRoles
{
    const RANK = ['editor' => 1, 'admin' => 2, 'superadmin' => 3];

    private static bool $ready = false;
    /** Carried between current() and a re-issue within one request. */
    private static bool $mfa = false;

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
        if (!self::orgEmail($email)) return '';
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
        $c = function_exists('av_admin_cookie_parse') ? av_admin_cookie_parse() : null;
        self::$mfa = $c !== null && !empty($c['mfa']);
        /* A token sign-in: av_admin_cookie_parse() has already checked the role
           against RANK and the MAC against the ADMIN_TOKEN it was issued under,
           so rotating the token ends it. */
        if ($c !== null && $c['src'] !== 'm') return (string) $c['role'];
        /* A bridged session is the member's: it holds only while they are
           signed in and still on the admin team, at the level they hold NOW. */
        $live = '';
        if (class_exists('LmsAuth')) {
            $u = LmsAuth::user();
            if ($u && !empty($u['email'])) $live = self::roleForEmail((string) $u['email']);
        }
        if ($live === '') {
            if ($c !== null && function_exists('av_admin_cookie_clear')) av_admin_cookie_clear();
            return '';
        }
        /* Re-issuing keeps whatever the factor said: a bridge refreshed because
           the role changed must not quietly drop a second factor already
           passed, and must not invent one that was not. */
        if (($c === null || $c['role'] !== $live) && function_exists('av_admin_cookie_issue')) av_admin_cookie_issue(43200, $live, 'm', self::$mfa);
        return $live;
    }

    /**
     * Has this session passed its second factor (lib/AdminMfa)?
     *
     * The break-glass ADMIN_TOKEN stands on its own credential and is exempt
     * — see the note in AdminMfa — so a bearer or token session answers true.
     */
    public static function mfaPassed(): bool
    {
        if (function_exists('av_admin_bearer_ok') && av_admin_bearer_ok()) return true;
        $c = function_exists('av_admin_cookie_parse') ? av_admin_cookie_parse() : null;
        if ($c === null) return false;
        if (($c['src'] ?? '') === 't') return true;
        return !empty($c['mfa']);
    }

    /** Who the Studio is for, as an address — '' for a token session. */
    public static function email(): string
    {
        if (!class_exists('LmsAuth')) return '';
        try { $u = LmsAuth::user(); return $u && !empty($u['email']) ? strtolower((string) $u['email']) : ''; }
        catch (Throwable $e) { return ''; }
    }

    /**
     * Mark this session as having passed the factor. Re-issues the bridged
     * cookie with the flag inside the signature, so nothing the browser holds
     * can set it.
     */
    public static function markMfaPassed(): void
    {
        $role = self::current();
        if ($role === '' || !function_exists('av_admin_cookie_issue')) return;
        self::$mfa = true;
        av_admin_cookie_issue(43200, $role, 'm', true);
    }

    /**
     * The one question every guarded surface asks: is this request allowed to
     * act at $min? A Studio role that has not passed its second factor is NOT
     * — the gate is here rather than at a hundred call sites, which is the
     * same reason the role check is.
     */

    public static function orgEmail(string $email): bool
    {
        return class_exists('LmsAuth') && LmsAuth::isOrgEmail($email);
    }

    public static function domain(): string
    {
        return strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
    }

    public static function can(string $min): bool
    {
        $cur = self::effective();
        return $cur !== '' && (self::RANK[$cur] ?? 0) >= (self::RANK[$min] ?? 99);
    }

    /**
     * The role this request may ACT with: the role it holds, or '' when a
     * second factor is required and has not been passed.
     *
     * This, not current(), is what av_admin_role() answers — so require_admin()
     * and the forty pages and API actions that ask for a role are gated by
     * the same sentence, with no edit each. current() stays the raw answer,
     * for the two endpoints that must run BEFORE the factor is passed (setting
     * one up, and entering a code) and for the Studio page, which has to tell
     * "not an admin" from "an admin who needs to finish signing in".
     */
    public static function effective(): string
    {
        $role = self::current();
        if ($role === '') return '';
        return (AdminMfa::required($role) && !self::mfaPassed()) ? '' : $role;
    }

    public static function list(): array
    {
        self::ensure();
        /* `valid` says whether the row still grants anything: an off-domain entry is listed so it can be removed, never honoured. */
        try { return array_map(static fn($r) => $r + ['valid' => self::orgEmail((string) $r['email'])],
                  Database::pdo()->query('SELECT email, role, added_by, created_at FROM admin_users ORDER BY role DESC, email ASC')->fetchAll(PDO::FETCH_ASSOC) ?: []); }
        catch (Throwable $e) { return []; }
    }

    public static function add(string $email, string $role, string $by = ''): array
    {
        self::ensure();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email.'];
        if (!self::orgEmail($email)) return ['ok' => false, 'code' => 'org_email', 'error' => 'Only @' . self::domain() . ' addresses can be admins.'];
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
function av_admin_role(): string { return AdminRoles::effective(); }

/**
 * Who is acting, for provenance on records that outlive the session — "who set
 * this rule" is the first question anyone asks of a policy change.
 *
 * A bridged member-admin is identified by email. A break-glass token sign-in has
 * no identity beyond its role, so it reports that honestly rather than borrowing
 * a name it cannot prove.
 */
function av_admin_actor(): string
{
    try {
        if (class_exists('LmsAuth')) {
            $u = LmsAuth::user();
            if ($u && !empty($u['email'])) return (string) $u['email'];
        }
    } catch (Throwable $e) { /* fall through to the role */ }
    $role = av_admin_role();
    return $role !== '' ? $role . ' (token)' : 'admin';
}
