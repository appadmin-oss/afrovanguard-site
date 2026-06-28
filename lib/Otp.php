<?php
/**
 * lib/Otp.php — one-time email sign-in codes (the passwordless path).
 *
 * request() emails a short numeric code; check() redeems it. Codes are stored
 * only as a SHA-256 hash bound to the email + purpose, are single-use, expire
 * fast (policy-driven TTL), and burn after a few wrong tries — so a leaked DB
 * or a guessing attacker gets nothing. Driver-aware DDL (SQLite output is
 * byte-identical; MySQL/Postgres translated) like the rest of the app.
 *
 * Enumeration-safe by construction: request() returns the same shape whether
 * or not an account exists (a code is sent to any valid address — redeeming it
 * is what creates/【signs in the account, in LmsAuth::loginWithOtp()).
 */
declare(strict_types=1);

final class Otp
{
    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $db = Database::pdo();
        $ddl = "CREATE TABLE IF NOT EXISTS lms_otps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(190) NOT NULL,
            purpose VARCHAR(20) NOT NULL DEFAULT 'login',
            code_hash VARCHAR(64) NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            consumed INTEGER NOT NULL DEFAULT 0,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            expires_at VARCHAR(32) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_otps_lookup ON lms_otps(email, purpose, consumed);";
        $drv = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $db->exec($drv === 'sqlite' ? $ddl : Database::translateDDL($ddl, $drv));
        $done = true;
    }

    /** Bind a code to its email + purpose so it can't be replayed elsewhere. */
    private static function hash(string $email, string $purpose, string $code): string
    {
        return hash('sha256', strtolower(trim($email)) . '|' . $purpose . '|' . $code);
    }

    /**
     * Email a fresh code. Always returns ['ok'=>true] for a syntactically valid
     * address (enumeration-safe). Invalidates any prior unconsumed codes for the
     * same (email, purpose) so only the newest works.
     */
    public static function request(string $email, string $purpose = 'login'): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        self::ensure();
        $db = Database::pdo();
        $db->prepare('UPDATE lms_otps SET consumed = 1 WHERE email = ? AND purpose = ? AND consumed = 0')->execute([$email, $purpose]);

        $len  = AuthPolicy::otpLength();
        $code = str_pad((string) random_int(0, (10 ** $len) - 1), $len, '0', STR_PAD_LEFT);
        $now  = time();
        $db->prepare('INSERT INTO lms_otps (email, purpose, code_hash, ip, expires_at, created_at) VALUES (?,?,?,?,?,?)')
           ->execute([$email, $purpose, self::hash($email, $purpose, $code), av_client_ip(),
                      gmdate('Y-m-d H:i:s', $now + AuthPolicy::otpTtl()), gmdate('Y-m-d H:i:s', $now)]);
        self::email($email, $code, AuthPolicy::otpTtl());
        if (class_exists('Events')) { try { Events::emit('auth.otp.requested', ['email' => $email, 'purpose' => $purpose]); } catch (Throwable $e) {} }
        return ['ok' => true];
    }

    /**
     * Redeem a code. Returns true on a correct, live, unburnt code (and consumes
     * it). Increments attempts on a miss; burns the code once attempts hit the
     * policy max so guessing is bounded.
     */
    public static function check(string $email, string $code, string $purpose = 'login'): bool
    {
        $email = strtolower(trim($email));
        $code  = preg_replace('/\D/', '', $code);
        if ($code === '') return false;
        self::ensure();
        $db = Database::pdo();
        $st = $db->prepare('SELECT * FROM lms_otps WHERE email = ? AND purpose = ? AND consumed = 0 AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $st->execute([$email, $purpose, gmdate('Y-m-d H:i:s')]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $db->prepare('UPDATE lms_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        if ((int) $row['attempts'] + 1 >= AuthPolicy::otpMaxAttempts() + 1) {
            // too many tries on this code — burn it.
            $db->prepare('UPDATE lms_otps SET consumed = 1 WHERE id = ?')->execute([$row['id']]);
        }
        if (!hash_equals((string) $row['code_hash'], self::hash($email, $purpose, $code))) return false;

        $db->prepare('UPDATE lms_otps SET consumed = 1 WHERE id = ?')->execute([$row['id']]);
        return true;
    }

    private static function email(string $to, string $code, int $ttl): void
    {
        if (!class_exists('Mailer')) { error_log("[otp] (mailer off) code for {$to}: {$code}"); return; }
        $mins  = max(1, (int) round($ttl / 60));
        $spaced = trim(chunk_split($code, 3, ' '));   // "123 456" — easier to read
        $html = Mailer::shell(
            'Your sign-in code',
            [
                'Use this code to sign in to Afrovanguard:',
                '<div style="font:700 34px/1 \'Montserrat\',Arial,sans-serif;letter-spacing:8px;color:#111827;background:#f6f4ee;border-radius:12px;padding:18px 0;text-align:center;margin:6px 0">' . htmlspecialchars($spaced, ENT_QUOTES) . '</div>',
                'It expires in ' . $mins . ' minutes and can be used once. If you didn’t request this, you can safely ignore this email — no changes were made.',
            ],
            null,
            'Your Afrovanguard sign-in code: ' . $code
        );
        try { Mailer::send($to, 'Your sign-in code — Afrovanguard', $html); }
        catch (Throwable $e) { error_log('[otp] send failed: ' . $e->getMessage()); }
    }
}
