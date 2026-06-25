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
        if (strlen($password) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        $db = Database::pdo();
        $ex = $db->prepare('SELECT id FROM lms_users WHERE email = ?'); $ex->execute([$email]);
        if ($ex->fetchColumn()) return ['ok' => false, 'error' => 'An account with that email already exists. Try signing in.'];
        $role = in_array($role, ['learner', 'instructor'], true) ? $role : 'learner';
        // New password accounts start UNVERIFIED — no session until the emailed
        // link is clicked. (Google/OAuth accounts are created already-verified.)
        $db->prepare('INSERT INTO lms_users (name, email, password_hash, role, email_verified) VALUES (?,?,?,?,0)')
           ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
        $id = (int) $db->lastInsertId();
        $full = self::byId($id);
        if ($full) self::sendVerification($full);
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
        $st = $db->prepare("SELECT * FROM lms_users WHERE verify_hash = ? AND verify_expires > datetime('now')");
        $st->execute([hash('sha256', $token)]);
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
        $u = self::byEmail($email);
        if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
            return ['ok' => false, 'error' => 'Incorrect email or password.'];
        }
        if ((int) ($u['email_verified'] ?? 1) === 0) {
            return ['ok' => false, 'verify_required' => true, 'email' => $u['email'],
                    'error' => 'Please verify your email first — we sent you a link when you signed up. Check your inbox, or request a new one.'];
        }
        Database::pdo()->prepare('UPDATE lms_users SET last_login = datetime(\'now\') WHERE id = ?')->execute([$u['id']]);
        self::startSession((int) $u['id']);
        return ['ok' => true, 'user' => self::publicUser($u)];
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
        $db->prepare('UPDATE lms_users SET last_login = datetime(\'now\') WHERE id = ?')->execute([$u['id']]);
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
             WHERE s.token_hash = ? AND s.expires_at > datetime(\'now\') AND u.status = \'active\''
        );
        $st->execute([hash('sha256', $tok)]);
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

    private static function startSession(int $userId): void
    {
        $token = bin2hex(random_bytes(20));
        Database::pdo()->prepare('INSERT INTO lms_sessions (token_hash, user_id, ip, ua, expires_at) VALUES (?,?,?,?,?)')
            ->execute([hash('sha256', $token), $userId, av_client_ip(), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240), date('Y-m-d H:i:s', time() + self::TTL)]);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $token, ['expires' => time() + self::TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $token; self::$cache = null; self::$checked = false;
    }
}
