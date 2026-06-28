<?php
/**
 * lib/AuthPolicy.php — the superadmin-configurable sign-in security policy.
 *
 * Sign-in on Afrovanguard supports three methods — a passwordless email code
 * (OTP), an optional password, and Google — and a handful of security layers
 * around them. WHICH methods are allowed and HOW strict the layers are is not
 * hard-coded: the superadmin tunes it in the Studio (Sign-in → Security), and
 * it persists in the portable app_meta key/value store so the same knobs work
 * on SQLite / MySQL / Postgres.
 *
 * Everything here is read through merge-over-defaults, so a missing/[]/partial
 * stored policy always yields a complete, safe policy. Callers ask questions
 * ("is OTP allowed?", "is this password strong enough?") rather than poking at
 * raw fields.
 */
declare(strict_types=1);

final class AuthPolicy
{
    private const KEY = 'auth_policy';
    private static ?array $cache = null;

    /** Safe, sensible defaults — a fresh install signs in out of the box. */
    public static function defaults(): array
    {
        return [
            // allowed methods
            'allow_otp'             => true,   // passwordless email code (primary)
            'allow_password'        => true,   // email + password
            'allow_google'          => true,   // Google OAuth (only if also configured)
            // one-time code
            'otp_length'            => 6,      // 4–8 digits
            'otp_ttl'               => 600,    // seconds a code stays valid (1–30 min)
            'otp_max_attempts'      => 5,      // wrong tries before a code is burned
            // step-up: org accounts must use a code (or Google), never a password
            'require_otp_for_org'   => false,
            // passwords
            'password_min_len'      => 8,      // 6–64
            'password_require_mixed'=> false,  // require letters AND numbers
            // sessions
            'session_ttl_days'      => 30,     // 1–90
            // brute-force lockout (per email+IP). threshold 0 = off.
            'lockout_threshold'     => 8,
            'lockout_minutes'       => 15,
        ];
    }

    /** The full, merged policy (defaults ← stored overrides). */
    public static function get(): array
    {
        if (self::$cache !== null) return self::$cache;
        $stored = [];
        try {
            $raw = Database::metaGet(self::KEY);
            if ($raw) { $d = json_decode($raw, true); if (is_array($d)) $stored = $d; }
        } catch (Throwable $e) { /* DB not ready → defaults */ }
        return self::$cache = self::sanitize(array_merge(self::defaults(), $stored));
    }

    /** Validate + clamp a (partial) patch, persist the full policy, return it. */
    public static function save(array $patch): array
    {
        $merged = self::sanitize(array_merge(self::get(), $patch));
        // never let the admin lock everyone out: at least one method must remain on.
        if (!$merged['allow_otp'] && !$merged['allow_password'] && !$merged['allow_google']) {
            $merged['allow_otp'] = true;
        }
        Database::metaSet(self::KEY, json_encode($merged, JSON_UNESCAPED_SLASHES));
        self::$cache = $merged;
        if (class_exists('Events')) { try { Events::emit('security.policy.updated', ['by' => 'admin']); } catch (Throwable $e) {} }
        return $merged;
    }

    /** Coerce types + clamp every numeric field into a sane range. */
    private static function sanitize(array $p): array
    {
        $b = fn($k) => filter_var($p[$k] ?? false, FILTER_VALIDATE_BOOLEAN);
        $clamp = fn($v, $lo, $hi, $d) => max($lo, min($hi, (int) ($v === '' || $v === null ? $d : $v)));
        $d = self::defaults();
        return [
            'allow_otp'              => $b('allow_otp'),
            'allow_password'         => $b('allow_password'),
            'allow_google'           => $b('allow_google'),
            'otp_length'             => $clamp($p['otp_length'] ?? null, 4, 8, $d['otp_length']),
            'otp_ttl'                => $clamp($p['otp_ttl'] ?? null, 60, 1800, $d['otp_ttl']),
            'otp_max_attempts'       => $clamp($p['otp_max_attempts'] ?? null, 3, 10, $d['otp_max_attempts']),
            'require_otp_for_org'    => $b('require_otp_for_org'),
            'password_min_len'       => $clamp($p['password_min_len'] ?? null, 6, 64, $d['password_min_len']),
            'password_require_mixed' => $b('password_require_mixed'),
            'session_ttl_days'       => $clamp($p['session_ttl_days'] ?? null, 1, 90, $d['session_ttl_days']),
            'lockout_threshold'      => $clamp($p['lockout_threshold'] ?? null, 0, 100, $d['lockout_threshold']),
            'lockout_minutes'        => $clamp($p['lockout_minutes'] ?? null, 1, 1440, $d['lockout_minutes']),
        ];
    }

    /* ── questions callers actually ask ─────────────────────────── */

    /** Is a method offered? Google additionally needs to be configured. */
    public static function allows(string $method): bool
    {
        $p = self::get();
        switch ($method) {
            case 'otp':      return (bool) $p['allow_otp'];
            case 'password': return (bool) $p['allow_password'];
            case 'google':   return (bool) $p['allow_google'] && class_exists('GoogleAuth') && GoogleAuth::configured();
            default:         return false;
        }
    }

    /** The method flags the login page renders (Google folds in "configured"). */
    public static function publicMethods(): array
    {
        return ['otp' => self::allows('otp'), 'password' => self::allows('password'), 'google' => self::allows('google')];
    }

    public static function otpLength(): int { return (int) self::get()['otp_length']; }
    public static function otpTtl(): int { return (int) self::get()['otp_ttl']; }
    public static function otpMaxAttempts(): int { return (int) self::get()['otp_max_attempts']; }
    public static function sessionTtlSeconds(): int { return (int) self::get()['session_ttl_days'] * 86400; }

    /** Org accounts may be required to step up to a code (no password path). */
    public static function passwordBlockedFor(string $email): bool
    {
        return self::get()['require_otp_for_org'] && LmsAuth::isOrgEmail($email);
    }

    /** Null if the password satisfies the policy, else a human error string. */
    public static function passwordError(string $pw): ?string
    {
        $p = self::get();
        if (strlen($pw) < (int) $p['password_min_len']) {
            return 'Password must be at least ' . (int) $p['password_min_len'] . ' characters.';
        }
        if ($p['password_require_mixed'] && !(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw))) {
            return 'Password must include both letters and numbers.';
        }
        return null;
    }
}
