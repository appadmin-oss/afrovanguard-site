<?php
/**
 * lib/SuperAdmin.php — the default, always-present Super Admin.
 *
 * A fresh Afrovanguard deploy needs exactly one account that can sign in and
 * reach EVERYTHING: the Studio (superadmin ⇒ roles, security policy, DB tools,
 * destructive actions) and, through it, every other admin and manager/member.
 * That account is provisioned here, idempotently, so it exists the first time
 * anyone opens the sign-in or Studio pages — no SSH, no manual SQL.
 *
 * What "Super Admin" means, concretely, is already wired across the app:
 *   • admin_users(email → 'superadmin')   → full Studio (AdminRoles::current)
 *   • lms_users(role='admin')             → top of the member RBAC ladder
 * so this account can list/add/remove admins (admin_add/remove) AND manage
 * every manager/member (mem_*, mentorship_*, roles). We set BOTH.
 *
 * Configuration (all optional — safe defaults ship):
 *   AV_SUPERADMIN_EMAIL     default: mamcareer@afrovanguard.org.ng
 *   AV_SUPERADMIN_NAME      default: "Super Admin"
 *   AV_SUPERADMIN_PASSWORD  if set → used verbatim (recommended, keep it in .env);
 *                           if empty → a strong random password is generated once
 *                           and stashed in app_meta so `php db/seed_admin.php`
 *                           (or Studio → Admins) can reveal it exactly once.
 *
 * Everything is fingerprint-guarded: after the first apply we store a hash of
 * (email|password) in app_meta and short-circuit, so the hot path costs a single
 * indexed lookup and a changed env password re-applies automatically.
 */
declare(strict_types=1);

final class SuperAdmin
{
    private const FP_KEY   = 'superadmin_seed_fp';   // fingerprint of the last-applied (email|password)
    private const PW_KEY   = 'superadmin_initial_password'; // generated password, revealed once then cleared
    private static bool $done = false;

    public static function defaultEmail(): string
    {
        $e = strtolower(trim((string) (getenv('AV_SUPERADMIN_EMAIL') ?: '')));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) $e = 'mamcareer@afrovanguard.org.ng';
        return $e;
    }

    public static function defaultName(): string
    {
        $n = trim((string) (getenv('AV_SUPERADMIN_NAME') ?: ''));
        return $n !== '' ? $n : 'Super Admin';
    }

    /**
     * Ensure the default Super Admin exists and is a loginable superadmin.
     * Idempotent + cheap after the first apply. Never throws — a provisioning
     * hiccup must never take a page down; it just logs and moves on.
     *
     * @return array{created:bool,password:?string,email:string} password is
     *         non-null only the first time a generated password is minted.
     */
    public static function ensure(): array
    {
        $email = self::defaultEmail();
        if (self::$done) return ['created' => false, 'password' => null, 'email' => $email];

        try {
            $envPw = (string) (getenv('AV_SUPERADMIN_PASSWORD') ?: '');
            // Fingerprint: only re-apply when the target email or the *chosen*
            // password actually changes. (An empty env password fingerprints as
            // "auto" so we generate exactly once and never churn afterwards.)
            $fp = hash('sha256', $email . '|' . ($envPw !== '' ? 'env:' . $envPw : 'auto'));
            $seen = Database::metaGet(self::FP_KEY);
            if ($seen === $fp) { self::$done = true; return ['created' => false, 'password' => null, 'email' => $email]; }

            // 1) Studio role — always superadmin.
            AdminRoles::add($email, 'superadmin', 'system:default');

            // 2) Loginable member account at the top of the RBAC ladder.
            $db = Database::pdo();
            $created = false; $revealPw = null;

            // Choose the password: env verbatim, else a freshly generated strong one.
            $password = $envPw;
            if ($password === '') {
                $password = self::generatePassword();
                $revealPw = $password;
            }

            $st = $db->prepare('SELECT id, role, status FROM lms_users WHERE email = ?');
            $st->execute([$email]);
            $row = $st->fetch();

            if (!$row) {
                $db->prepare(
                    'INSERT INTO lms_users (name, email, password_hash, role, status, email_verified, has_password)
                     VALUES (?,?,?,?,?,1,1)'
                )->execute([self::defaultName(), $email, password_hash($password, PASSWORD_BCRYPT), 'admin', 'active']);
                $created = true;
            } else {
                // Promote + (re)set the password so this account is always usable.
                $db->prepare(
                    "UPDATE lms_users
                        SET role = 'admin', status = 'active', email_verified = 1,
                            has_password = 1, password_hash = ?
                      WHERE id = ?"
                )->execute([password_hash($password, PASSWORD_BCRYPT), (int) $row['id']]);
            }

            // Stash a generated password for one-time reveal (env passwords are
            // never stored — the operator already knows them).
            if ($revealPw !== null) Database::metaSet(self::PW_KEY, $revealPw);
            else Database::metaSet(self::PW_KEY, '');

            Database::metaSet(self::FP_KEY, $fp);
            self::$done = true;

            if (class_exists('Events')) { try { Events::emit('superadmin.ensured', ['email' => $email, 'created' => $created]); } catch (Throwable $e) {} }
            return ['created' => $created, 'password' => $revealPw, 'email' => $email];
        } catch (Throwable $e) {
            error_log('[superadmin] ensure: ' . $e->getMessage());
            self::$done = true; // don't retry-storm within a request
            return ['created' => false, 'password' => null, 'email' => $email];
        }
    }

    /** The one-time generated password, if one was minted and not yet cleared. */
    public static function pendingPassword(): string
    {
        try { return (string) (Database::metaGet(self::PW_KEY) ?? ''); }
        catch (Throwable $e) { return ''; }
    }

    /** Forget the stashed generated password (call after showing it once). */
    public static function forgetPassword(): void
    {
        try { Database::metaSet(self::PW_KEY, ''); } catch (Throwable $e) {}
    }

    /** A human-typable but strong password: 4 words-ish blocks + digits + symbol. */
    private static function generatePassword(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digit = '23456789';
        $sym   = '!@#$%&*?';
        $pick = static function (string $set, int $n): string {
            $out = ''; $max = strlen($set) - 1;
            for ($i = 0; $i < $n; $i++) $out .= $set[random_int(0, $max)];
            return $out;
        };
        // e.g. "Kqmr-7f3d-Xtvn!"  — 15 chars, mixed, no ambiguous glyphs.
        return $pick($alpha, 4) . '-' . $pick($digit, 1) . $pick($alpha, 1) . $pick($digit, 1) . $pick($alpha, 1)
             . '-' . $pick($alpha, 4) . $sym[random_int(0, strlen($sym) - 1)];
    }
}
