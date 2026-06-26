<?php
/**
 * lib/LmsAuth.php — learner / instructor accounts for the Academy.
 * DB-backed sessions (httpOnly cookie holding a random token; only its
 * SHA-256 hash is stored, so a DB leak ≠ session takeover).
 */
declare(strict_types=1);

final class LmsAuth
{
    const COOKIE = 'av_lms';
    const TTL = 2592000; // 30 days

    /**
     * Access ladder. `role` is the single RBAC field; higher rank ⊇ lower.
     *   learner   — public account (email or generic Google)
     *   member    — @afrovanguard.org.ng (verified) → mentorship access
     *   mentor    — member + can manage mentees
     *   instructor— legacy/teaching peer of mentor (kept for course authoring)
     *   coordinator — manages members + programmes
     *   admin     — full control
     */
    const ROLE_RANK = ['learner' => 0, 'member' => 10, 'mentor' => 20, 'instructor' => 20, 'coordinator' => 30, 'admin' => 40];

    private static ?array $cache = null;
    private static bool $checked = false;

    /* ── Role / access helpers ─────────────────────────────────── */
    public static function rank(?string $role): int { return self::ROLE_RANK[$role ?? 'learner'] ?? 0; }
    public static function atLeast(?array $u, string $role): bool { return $u !== null && self::rank((string) ($u['role'] ?? 'learner')) >= (self::ROLE_RANK[$role] ?? 99); }
    public static function isOrgEmail(string $email): bool {
        $at = strrchr(strtolower(trim($email)), '@');
        return $at !== false && substr($at, 1) === strtolower((string) (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng'));
    }
    /** Org member = elevated role OR an org-domain email. Gates mentorship. */
    public static function isOrgMember(?array $u): bool {
        return $u !== null && (self::rank((string) ($u['role'] ?? '')) >= self::ROLE_RANK['member'] || self::isOrgEmail((string) ($u['email'] ?? '')));
    }
    public static function canMentor(?array $u): bool { return self::atLeast($u, 'mentor'); }
    public static function canManageMembers(?array $u): bool { return self::atLeast($u, 'coordinator'); }
    public static function canTeach(?array $u): bool { return $u !== null && in_array((string) ($u['role'] ?? ''), ['instructor', 'coordinator', 'admin'], true); }

    public static function register(string $name, string $email, string $password, string $role = 'learner'): array
    {
        $name = trim($name); $email = strtolower(trim($email));
        if ($name === '') return ['ok' => false, 'error' => 'Please enter your name.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        if ($e = AuthPolicy::passwordError($password)) return ['ok' => false, 'error' => $e];
        self::ensureSecurity();
        $db = Database::pdo();
        $ex = $db->prepare('SELECT id FROM lms_users WHERE email = ?'); $ex->execute([$email]);
        if ($ex->fetchColumn()) return ['ok' => false, 'error' => 'An account with that email already exists. Try signing in.'];
        $role = in_array($role, ['learner', 'instructor'], true) ? $role : 'learner';
        // New password accounts start UNVERIFIED — no session until the emailed
        // link is clicked. (Google/OAuth accounts are created already-verified.)
        $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified, has_password) VALUES (?,?,?,?,0,1)')
           ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
        $id = (int) $db->lastInsertId();
        $full = self::byId($id);
        if ($full) self::sendVerification($full);
        if (class_exists('Events')) Events::emit('member.created', ['email' => $email, 'name' => $name, 'role' => $role, 'via' => 'register']);
        return ['ok' => true, 'verify_required' => true, 'email' => $email,
                'message' => 'Account created. Check your inbox for a link to verify your email and finish signing in.'];
    }

    /** Issue a fresh single-use verification token and email the link (24h TTL). */
    private static function sendVerification(array $u): void
    {
        $token = bin2hex(random_bytes(32));
        Database::pdo()->prepare('UPDATE lms_users SET verify_hash = ?, verify_expires = ? WHERE id = ?')
            ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400), (int) $u['id']]);
        if (!class_exists('Mailer')) return;
        $site = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://afrovanguard.org.ng';
        $link = $site . '/academy/api.php?action=verify-email&token=' . $token;
        $html = Mailer::shell(
            'Confirm your email',
            [
                'Hi ' . htmlspecialchars((string) $u['name'], ENT_QUOTES) . ',',
                'Welcome to Afrovanguard. Confirm this email address to activate your account and sign in.',
                'This link expires in 24 hours. If you didn’t create an account, you can safely ignore this message.',
            ],
            ['url' => $link, 'text' => 'Verify my email'],
            'Confirm your email to activate your Afrovanguard account.'
        );
        Mailer::send((string) $u['email'], 'Verify your email — Afrovanguard', $html);
    }

    /**
     * Redeem a verification token (single-use). On success marks the account
     * verified, clears the token, starts a session, and returns the user.
     */
    public static function verifyEmailToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $db = Database::pdo();
        // Bind UTC now (== SQLite datetime('now')) so the TEXT comparison is
        // identical on SQLite and portable to MySQL/Postgres (text vs text).
        $st = $db->prepare('SELECT * FROM lms_users WHERE verify_hash = ? AND verify_expires > ?');
        $st->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
        $u = $st->fetch();
        if (!$u) return null;
        $db->prepare('UPDATE lms_users SET email_verified = 1, verify_hash = NULL, verify_expires = NULL WHERE id = ?')->execute([$u['id']]);
        self::startSession((int) $u['id']);
        if (class_exists('Notify')) { try { Notify::welcome($u); } catch (Throwable $e) {} }
        return self::byId((int) $u['id']);
    }

    /** Re-send a verification link. Enumeration-safe (caller rate-limits). */
    public static function resendVerification(string $email): void
    {
        $email = strtolower(trim($email));
        $u = self::byEmail($email);
        if ($u && (int) ($u['email_verified'] ?? 1) === 0) self::sendVerification($u);
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        if (!AuthPolicy::allows('password')) {
            return ['ok' => false, 'error' => 'Password sign-in is turned off — use a sign-in code instead.', 'use_otp' => true];
        }
        if (AuthPolicy::passwordBlockedFor($email)) {
            return ['ok' => false, 'error' => 'For your security, this account signs in with a code. We can email you one.', 'use_otp' => true];
        }
        if ($lock = self::lockoutRemaining($email)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in about ' . ceil($lock / 60) . ' min, or use a sign-in code.', 'use_otp' => true];
        }
        $u = self::byEmail($email);
        if (!$u || $u['status'] !== 'active' || !password_verify($password, (string) $u['password_hash'])) {
            self::recordFailure($email);
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }
        if ((int) ($u['email_verified'] ?? 1) === 0) {
            return ['ok' => false, 'verify_required' => true, 'email' => $u['email'],
                    'error' => 'Please verify your email first — we sent you a link when you signed up. Check your inbox, or request a new one.'];
        }
        self::clearFailures($email);
        Database::pdo()->prepare('UPDATE lms_users SET last_login = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $u['id']]);
        self::startSession((int) $u['id']);
        return ['ok' => true, 'user' => self::publicUser($u)];
    }

    /**
     * Passwordless sign-in (or sign-up) with an emailed one-time code.
     * A correct code proves the address — so an unknown email creates a fresh,
     * already-verified account (org-domain emails get `member`). $name is only
     * used when the account is being created.
     */
    public static function loginWithOtp(string $email, string $code, string $name = ''): array
    {
        $email = strtolower(trim($email));
        if (!AuthPolicy::allows('otp')) return ['ok' => false, 'error' => 'Code sign-in is turned off.'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter a valid email address.'];
        if (!Otp::check($email, $code, 'login')) {
            return ['ok' => false, 'error' => 'That code is wrong or has expired. Request a new one.'];
        }
        $db = Database::pdo();
        $u = self::byEmail($email);
        if (!$u) {
            $display = trim($name) !== '' ? trim($name) : ucfirst(explode('@', $email)[0]);
            $role = self::isOrgEmail($email) ? 'member' : 'learner';
            $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified) VALUES (?,?,?,?,1)')
               ->execute([$display, $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), $role]);
            $u = self::byEmail($email);
            if ($u && class_exists('Notify')) { try { Notify::welcome($u); } catch (Throwable $e) {} }
            if ($u && class_exists('Events')) Events::emit('member.created', ['email' => $email, 'name' => (string) $u['name'], 'role' => (string) $u['role'], 'via' => 'otp']);
        } else {
            if (($u['status'] ?? 'active') !== 'active') return ['ok' => false, 'error' => 'This account is not active. Please contact us.'];
            if ((int) ($u['email_verified'] ?? 0) === 0) {
                $db->prepare('UPDATE lms_users SET email_verified = 1, verify_hash = NULL, verify_expires = NULL WHERE id = ?')->execute([$u['id']]);
            }
            if (self::isOrgEmail($email) && self::rank((string) $u['role']) < self::ROLE_RANK['member']) {
                $db->prepare("UPDATE lms_users SET role = 'member' WHERE id = ?")->execute([$u['id']]);
                $u['role'] = 'member';
            }
        }
        if (!$u) return ['ok' => false, 'error' => 'Could not complete sign-in. Please try again.'];
        self::clearFailures($email);
        $db->prepare('UPDATE lms_users SET last_login = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $u['id']]);
        self::startSession((int) $u['id']);
        return ['ok' => true, 'user' => self::publicUser($u), 'has_password' => self::hasPassword((int) $u['id'])];
    }

    /** Set (or change) the signed-in member's password — the "add a password
     *  after verifying" path. Validated against the live security policy. */
    public static function setPassword(int $userId, string $new): array
    {
        if ($userId <= 0) return ['ok' => false, 'error' => 'Please sign in first.'];
        if ($e = AuthPolicy::passwordError($new)) return ['ok' => false, 'error' => $e];
        self::ensureSecurity();
        Database::pdo()->prepare('UPDATE lms_users SET password_hash = ?, has_password = 1 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $userId]);
        if (class_exists('Events')) { try { Events::emit('member.password.set', ['user_id' => $userId]); } catch (Throwable $e) {} }
        return ['ok' => true, 'message' => 'Password saved. You can now sign in with it.'];
    }

    /**
     * Sign in (or provision) an account from a verified OAuth profile (Google).
     * Funnels every OAuth path through one place: find-or-create by email,
     * grant `member` on a VERIFIED org-domain email (never downgrade an existing
     * higher role), then start the session. Created accounts are passwordless
     * (a random unusable hash) so password login can't be used against them.
     */
    public static function oauthSignIn(string $email, string $name, bool $emailVerified, string $provider = 'google'): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Your provider did not return a usable email.'];
        $orgVerified = $emailVerified && self::isOrgEmail($email);
        $db = Database::pdo();
        $u = self::byEmail($email);
        if (!$u) {
            $display = $name !== '' ? $name : ucfirst(explode('@', $email)[0]);
            $role = $orgVerified ? 'member' : 'learner';
            $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified) VALUES (?,?,?,?,?)')
               ->execute([$display, $email, password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT), $role, $emailVerified ? 1 : 0]);
            $u = self::byEmail($email);
            if ($u && class_exists('Notify')) Notify::welcome($u);
            if ($u && class_exists('Events')) Events::emit('member.created', ['email' => $email, 'name' => (string) $u['name'], 'role' => (string) $u['role'], 'via' => $provider]);
        } else {
            if (($u['status'] ?? 'active') !== 'active') return ['ok' => false, 'error' => 'This account is not active. Please contact us.'];
            // A successful OAuth sign-in proves email ownership — clear any pending verification.
            if ($emailVerified && (int) ($u['email_verified'] ?? 0) === 0) {
                $db->prepare('UPDATE lms_users SET email_verified = 1, verify_hash = NULL, verify_expires = NULL WHERE id = ?')->execute([$u['id']]);
            }
            if ($orgVerified && self::rank((string) $u['role']) < self::ROLE_RANK['member']) {
                $db->prepare("UPDATE lms_users SET role = 'member' WHERE id = ?")->execute([$u['id']]);
                $u['role'] = 'member';
            }
        }
        if (!$u) return ['ok' => false, 'error' => 'Could not complete sign-in. Please try again.'];
        $db->prepare('UPDATE lms_users SET last_login = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $u['id']]);
        self::startSession((int) $u['id']);
        return ['ok' => true, 'user' => self::publicUser($u)];
    }

    public static function logout(): void
    {
        $tok = $_COOKIE[self::COOKIE] ?? '';
        if ($tok && preg_match('/^[a-f0-9]{40}$/', $tok)) {
            Database::pdo()->prepare('DELETE FROM lms_sessions WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[self::COOKIE]); self::$cache = null; self::$checked = false;
    }

    public static function user(): ?array
    {
        if (self::$checked) return self::$cache;
        self::$checked = true;
        $tok = $_COOKIE[self::COOKIE] ?? '';
        if (!$tok || !preg_match('/^[a-f0-9]{40}$/', $tok)) return self::$cache = null;
        $st = Database::pdo()->prepare(
            'SELECT u.* FROM lms_sessions s JOIN lms_users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = \'active\''
        );
        $st->execute([hash('sha256', $tok), gmdate('Y-m-d H:i:s')]);
        $u = $st->fetch();
        return self::$cache = ($u ?: null);
    }

    public static function require(): array { $u = self::user(); if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401); return $u; }
    public static function publicUser(array $u): array {
        return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'],
                'org' => self::isOrgMember($u), 'can_mentor' => self::canMentor($u)];
    }

    private static function byId(int $id): ?array { $s = Database::pdo()->prepare('SELECT * FROM lms_users WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
    private static function byEmail(string $e): ?array { $s = Database::pdo()->prepare('SELECT * FROM lms_users WHERE email = ?'); $s->execute([$e]); return $s->fetch() ?: null; }

    /** Does this account carry a usable (member-set) password? OAuth/OTP-created
     *  accounts get a random unusable hash and read as false. */
    public static function hasPassword(int $userId): bool
    {
        self::ensureSecurity();
        $s = Database::pdo()->prepare('SELECT has_password FROM lms_users WHERE id = ?'); $s->execute([$userId]);
        return (int) $s->fetchColumn() === 1;
    }

    private static function startSession(int $userId): void
    {
        $ttl = class_exists('AuthPolicy') ? AuthPolicy::sessionTtlSeconds() : self::TTL;
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
            ->execute([hash('sha256', $token), $userId, av_client_ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240), date('Y-m-d H:i:s', time() + $ttl)]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $token, ['expires' => time() + $ttl, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $token; self::$cache = null; self::$checked = false;
    }

    /* ── security tables / brute-force lockout ──────────────────────
       Additive, lazily ensured so existing databases pick them up with no
       manual migration: a has_password flag on lms_users + a small per
       (email+IP) throttle table. Driver-aware. */
    private static function ensureSecurity(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        try {
            if (class_exists('Database') && method_exists('Database', 'columnExists')
                && !Database::columnExists('lms_users', 'has_password')) {
                $db->exec('ALTER TABLE lms_users ADD COLUMN has_password INTEGER NOT NULL DEFAULT 0');
            }
        } catch (Throwable $e) { /* column already there / race — fine */ }
        $ddl = "CREATE TABLE IF NOT EXISTS lms_login_throttle (
            ident VARCHAR(64) PRIMARY KEY,
            fails INTEGER NOT NULL DEFAULT 0,
            locked_until VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try { $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv)); } catch (Throwable $e) {}
        $done = true;
    }

    private static function throttleIdent(string $email): string
    {
        return hash('sha1', strtolower(trim($email)) . '|' . av_client_ip());
    }

    /** Seconds remaining on a lockout for this email+IP, or 0 if clear/off. */
    private static function lockoutRemaining(string $email): int
    {
        $threshold = (int) AuthPolicy::get()['lockout_threshold'];
        if ($threshold <= 0) return 0;
        self::ensureSecurity();
        $s = Database::pdo()->prepare('SELECT locked_until FROM lms_login_throttle WHERE ident = ?');
        $s->execute([self::throttleIdent($email)]);
        $until = (string) ($s->fetchColumn() ?: '');
        if ($until === '') return 0;
        $left = strtotime($until . ' UTC') - time();
        return $left > 0 ? $left : 0;
    }

    private static function recordFailure(string $email): void
    {
        $threshold = (int) AuthPolicy::get()['lockout_threshold'];
        if ($threshold <= 0) return;
        self::ensureSecurity();
        $db = Database::pdo();
        $ident = self::throttleIdent($email);
        $now = gmdate('Y-m-d H:i:s');
        $s = $db->prepare('SELECT fails FROM lms_login_throttle WHERE ident = ?'); $s->execute([$ident]);
        $col = $s->fetchColumn();
        $fails = ($col === false ? 0 : (int) $col) + 1;
        $until = '';
        if ($fails >= $threshold) { $until = gmdate('Y-m-d H:i:s', time() + (int) AuthPolicy::get()['lockout_minutes'] * 60); $fails = 0; }
        $sql = Database::driver() === 'mysql'
            ? 'INSERT INTO lms_login_throttle (ident, fails, locked_until, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)'
            : 'INSERT INTO lms_login_throttle (ident, fails, locked_until, updated_at) VALUES (?,?,?,?) ON CONFLICT(ident) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until, updated_at = excluded.updated_at';
        $db->prepare($sql)->execute([$ident, $fails, $until, $now]);
    }

    private static function clearFailures(string $email): void
    {
        if ((int) AuthPolicy::get()['lockout_threshold'] <= 0) return;
        self::ensureSecurity();
        Database::pdo()->prepare('DELETE FROM lms_login_throttle WHERE ident = ?')->execute([self::throttleIdent($email)]);
    }
}
