<?php
/**
 * lib/AdminMfa.php — a second factor on the Studio, ported from NGG.
 *
 * A Studio sign-in stood on one secret. The admin roles reach every member
 * record, every export, the gate passes and the money screens, and the sister
 * site has required a second factor on exactly those roles for a year
 * (NGG: api/_lib/domain/auth.php). This is that scheme, in this site's shapes.
 *
 * WHAT IS THE SAME AS NGG, deliberately, because each part was a fix there:
 *
 *   ENROLMENT IS ONCE. Re-enrolment is a recovery, and recovery is not
 *   self-service. Writing a fresh secret over an existing one — from a session
 *   that has not yet passed the factor, because a first-time enroller has none
 *   to pass — makes the second factor optional: sign in with the password,
 *   enrol afresh, satisfy the gate with the secret you just chose. A lost
 *   authenticator goes through reset(), which another Super Admin runs.
 *
 *   BACKUP CODES, eight of them, shown once and stored hashed, consumed on
 *   use. Without them every lost phone is a support ticket, and a scheme
 *   whose recovery hurts gets turned off.
 *
 *   ATTEMPTS ARE COUNTED AGAINST THE ADMIN, not the address they came from.
 *   Six digits with a ±1 window is a small enough space that the only thing
 *   between it and a script is a ceiling, and a per-IP ceiling buys another
 *   handful of guesses per address.
 *
 * WHAT IS THIS SITE'S:
 *
 *   The factor belongs to the PERSON, not to the admin grant, so it is keyed
 *   on the email and survives being removed from admin_users and added back.
 *
 *   The break-glass ADMIN_TOKEN sign-in (`src` 't') is exempt, and that is a
 *   decision rather than an oversight: it is a credential held outside any
 *   account, for when the normal road is broken. Requiring an enrolled
 *   authenticator to use the door that exists to route around a broken door
 *   would make it no door at all. It is already bound to the token's
 *   fingerprint, so rotating ADMIN_TOKEN ends every such session.
 */
declare(strict_types=1);

final class AdminMfa
{
    /** Wrong codes before a pause, and how long the pause is. NGG's numbers. */
    public const LOCK_AFTER = 8;
    public const LOCK_MINUTES = 15;
    public const BACKUP_CODES = 8;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        try {
            Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS admin_mfa (
                email       VARCHAR(191) PRIMARY KEY,
                secret      VARCHAR(64) NOT NULL DEFAULT '',
                backup      TEXT,
                enrolled_at VARCHAR(32) NOT NULL DEFAULT '',
                last_used   VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE TABLE IF NOT EXISTS lms_login_throttle (
                ident VARCHAR(64) PRIMARY KEY,
                fails INTEGER NOT NULL DEFAULT 0,
                locked_until VARCHAR(32) NOT NULL DEFAULT '',
                updated_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) { error_log('[adminmfa] ensure: ' . $e->getMessage()); }
    }

    private static function key(string $email): string { return strtolower(trim($email)); }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }

    /* ══ Who needs one ══════════════════════════════════════════════════════ */

    /**
     * Every Studio role. The audit's line is "admin and finance roles and
     * anyone who can see minors' data", and an editor reaches learner records
     * and exports — so the honest answer is all three rather than a list that
     * drifts out of step with what each role can actually see.
     */
    public static function required(string $role): bool
    {
        return $role !== '' && isset(AdminRoles::RANK[$role]);
    }

    public static function row(string $email): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM admin_mfa WHERE email = ?');
            $st->execute([self::key($email)]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[adminmfa] row: ' . $e->getMessage()); return []; }
    }

    public static function isEnrolled(string $email): bool
    {
        return trim((string) (self::row($email)['secret'] ?? '')) !== '';
    }

    /** What the Studio needs to know to draw the right panel. */
    public static function status(string $email, string $role, bool $passed): array
    {
        $enrolled = self::isEnrolled($email);
        return [
            'required' => self::required($role),
            'enrolled' => $enrolled,
            'passed'   => $passed,
            'need'     => !self::required($role) || $passed ? '' : ($enrolled ? 'code' : 'enrol'),
            'backup_remaining' => $enrolled ? count(self::backupHashes($email)) : 0,
        ];
    }

    /* ══ Enrolling ══════════════════════════════════════════════════════════ */

    /**
     * Mint a secret and eight backup codes. ONCE: an enrolled admin who has
     * lost their authenticator goes through reset(), which has a person on
     * the other side of it.
     */
    public static function enrol(string $email, string $role): array
    {
        self::ensure();
        $email = self::key($email);
        if ($email === '') return ['ok' => false, 'error' => 'Not signed in.'];
        if (!self::required($role)) return ['ok' => false, 'code' => 'not_admin', 'error' => 'Only a Studio role enrols a second factor.'];
        if (self::isEnrolled($email)) {
            self::audit('mfa_enrol_refused', $email, 'Already enrolled — a lost authenticator is reset by another Super Admin.');
            return ['ok' => false, 'code' => 'already_enrolled',
                    'error' => 'This account already has a second factor. If you have lost it, another Super Admin resets it for you.'];
        }

        $secret = self::randomSecret(20);
        $codes = []; $hashes = [];
        for ($i = 0; $i < self::BACKUP_CODES; $i++) {
            $c = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $codes[] = $c;
            $hashes[] = password_hash(self::normalise($c), PASSWORD_DEFAULT);
        }
        try {
            Database::pdo()->prepare('INSERT INTO admin_mfa (email, secret, backup, enrolled_at) VALUES (?,?,?,?)')
                ->execute([$email, $secret, json_encode($hashes, JSON_UNESCAPED_SLASHES), self::now()]);
        } catch (Throwable $e) {
            /* Two tabs pressing "set up" at once: the PRIMARY KEY decides, and
               the loser is told what the winner's tab is showing. */
            error_log('[adminmfa] enrol ' . $email . ': ' . $e->getMessage());
            return ['ok' => false, 'code' => 'already_enrolled', 'error' => 'This account already has a second factor.'];
        }
        self::audit('mfa_enrolled', $email, 'A second factor was set up for the Studio.');
        $issuer = rawurlencode(self::issuer());
        $otpauth = 'otpauth://totp/' . $issuer . ':' . rawurlencode($email)
            . '?secret=' . $secret . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
        return ['ok' => true, 'secret' => $secret, 'backup_codes' => $codes,
                'otpauth' => $otpauth, 'qr_svg' => self::qr($otpauth)];
    }

    /**
     * The otpauth URI as an inline SVG, drawn HERE.
     *
     * A QR for a shared secret must not be fetched from a chart service: the
     * URL would carry the secret to somebody else's logs. The encoder is
     * already in the tree for the gate passes and the appeals.
     */
    public static function qr(string $uri): string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class)) return '';
        try {
            $o = new \chillerlan\QRCode\QROptions([
                'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
                'addQuietzone' => true, 'quietzoneSize' => 4,
                'outputBase64' => false, 'svgAddXmlHeader' => false,
                'drawLightModules' => false, 'connectPaths' => true,
            ]);
            return (new \chillerlan\QRCode\QRCode($o))->render($uri);
        } catch (Throwable $e) { error_log('[adminmfa] qr: ' . $e->getMessage()); return ''; }
    }

    /* ══ Passing it ═════════════════════════════════════════════════════════ */

    /**
     * A six-digit code, or one of the eight backup codes (consumed).
     * Returns ['ok' => true] on success; the caller re-issues the cookie.
     */
    public static function verify(string $email, string $code): array
    {
        self::ensure();
        $email = self::key($email);
        $row = self::row($email);
        $secret = trim((string) ($row['secret'] ?? ''));
        if ($secret === '') return ['ok' => false, 'code' => 'not_enrolled', 'error' => 'Set up a second factor first.'];

        if ($left = self::lockedFor($email)) {
            self::audit('mfa_locked', $email, 'Too many wrong codes; paused for ' . ceil($left / 60) . ' more minutes.');
            return ['ok' => false, 'code' => 'too_many_attempts',
                    'error' => 'Too many wrong codes. Try again in about ' . ceil($left / 60) . ' minutes.'];
        }

        if (self::totpValid($secret, $code)) {
            self::clearFails($email);
            self::touch($email);
            self::audit('mfa_passed', $email, 'Second factor accepted.');
            return ['ok' => true];
        }

        $norm = self::normalise($code);
        if (strlen($norm) >= 6) {
            $hashes = self::backupHashes($email);
            foreach ($hashes as $i => $h) {
                if (is_string($h) && password_verify($norm, $h)) {
                    unset($hashes[$i]);
                    try {
                        Database::pdo()->prepare('UPDATE admin_mfa SET backup = ?, last_used = ? WHERE email = ?')
                            ->execute([json_encode(array_values($hashes), JSON_UNESCAPED_SLASHES), self::now(), $email]);
                    } catch (Throwable $e) { error_log('[adminmfa] backup spend: ' . $e->getMessage()); }
                    self::clearFails($email);
                    self::audit('mfa_backup_used', $email, 'A backup code was used — ' . count($hashes) . ' left.');
                    return ['ok' => true, 'backup_used' => true, 'backup_remaining' => count($hashes)];
                }
            }
        }

        $locked = self::recordFail($email);
        self::audit($locked ? 'mfa_lockout' : 'mfa_failed', $email, $locked
            ? 'Wrong code ' . self::LOCK_AFTER . ' times; paused for ' . self::LOCK_MINUTES . ' minutes.'
            : 'A wrong code was entered.');
        return ['ok' => false, 'code' => $locked ? 'too_many_attempts' : 'invalid_code',
                'error' => $locked
                    ? 'Too many wrong codes. Try again in about ' . self::LOCK_MINUTES . ' minutes.'
                    : 'That code is not right. Check your authenticator, or use a backup code.'];
    }

    /** A lost authenticator: cleared by somebody else, re-enrolled next sign-in. */
    public static function reset(string $email, string $by): array
    {
        self::ensure();
        $email = self::key($email);
        if ($email === '') return ['ok' => false, 'error' => 'Which account?'];
        if ($email === self::key($by)) {
            /* Resetting your own is resetting the factor with the factor you
               have lost, from a session that has already passed it — which is
               the enrolment hole again, wearing a different hat. */
            return ['ok' => false, 'code' => 'own_account',
                    'error' => 'Another Super Admin resets your second factor. Resetting your own would make it optional.'];
        }
        try { Database::pdo()->prepare('DELETE FROM admin_mfa WHERE email = ?')->execute([$email]); }
        catch (Throwable $e) { return ['ok' => false, 'error' => 'Could not reset it.']; }
        self::clearFails($email);
        self::audit('mfa_reset', $email, 'Second factor cleared — they set one up at their next Studio sign-in.', $by);
        return ['ok' => true];
    }

    /* ══ TOTP (RFC 6238, HMAC-SHA1) ═════════════════════════════════════════ */

    public static function randomSecret(int $bytes = 20): string
    {
        $s = '';
        $n = (int) ceil($bytes * 8 / 5);
        for ($i = 0; $i < $n; $i++) $s .= self::B32[random_int(0, 31)];
        return $s;
    }

    public static function base32Decode(string $s): string
    {
        $s = (string) preg_replace('/[^A-Z2-7]/', '', strtoupper($s));
        $bits = ''; $out = '';
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $v = strpos(self::B32, $s[$i]);
            if ($v === false) continue;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $out .= chr((int) bindec(substr($bits, $i, 8)));
        return $out;
    }

    public static function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), self::base32Decode($secret), true);
        $o = ord($hash[strlen($hash) - 1]) & 0xf;
        $v = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16)
           | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
        return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** ±1 step of drift, as every authenticator expects. */
    public static function totpValid(string $secret, string $code, int $window = 1): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $now = intdiv(time(), 30);
        for ($d = -$window; $d <= $window; $d++) if (hash_equals(self::code($secret, $now + $d), $code)) return true;
        return false;
    }

    /* ══ Inside ═════════════════════════════════════════════════════════════ */

    public static function issuer(): string
    {
        return 'Afrovanguard Studio';
    }

    private static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^0-9a-z]/i', '', $code));
    }

    private static function backupHashes(string $email): array
    {
        $j = json_decode((string) (self::row($email)['backup'] ?? ''), true);
        return is_array($j) ? $j : [];
    }

    private static function touch(string $email): void
    {
        try { Database::pdo()->prepare('UPDATE admin_mfa SET last_used = ? WHERE email = ?')->execute([self::now(), $email]); }
        catch (Throwable $e) { /* the sign-in is what matters */ }
    }

    /* The throttle is keyed on the ADMIN, not on email+IP as the password
       lockout is: rotating addresses must not buy another eight guesses at a
       six-digit space. Same table, so there is one lockout store. */
    private static function ident(string $email): string { return 'mfa:' . hash('sha1', self::key($email)); }

    public static function lockedFor(string $email): int
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT locked_until FROM lms_login_throttle WHERE ident = ?');
            $st->execute([self::ident($email)]);
            $until = (string) ($st->fetchColumn() ?: '');
            if ($until === '') return 0;
            $left = strtotime($until . ' UTC') - time();
            return $left > 0 ? $left : 0;
        } catch (Throwable $e) { return 0; }
    }

    private static function recordFail(string $email): bool
    {
        self::ensure();
        try {
            $db = Database::pdo();
            $ident = self::ident($email);
            $st = $db->prepare('SELECT fails FROM lms_login_throttle WHERE ident = ?'); $st->execute([$ident]);
            $col = $st->fetchColumn();
            $fails = ($col === false ? 0 : (int) $col) + 1;
            $until = '';
            if ($fails >= self::LOCK_AFTER) { $until = gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60); $fails = 0; }
            $sql = Database::driver() === 'mysql'
                ? 'INSERT INTO lms_login_throttle (ident, fails, locked_until, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until), updated_at = VALUES(updated_at)'
                : 'INSERT INTO lms_login_throttle (ident, fails, locked_until, updated_at) VALUES (?,?,?,?) ON CONFLICT(ident) DO UPDATE SET fails = excluded.fails, locked_until = excluded.locked_until, updated_at = excluded.updated_at';
            $db->prepare($sql)->execute([$ident, $fails, $until, self::now()]);
            return $until !== '';
        } catch (Throwable $e) { error_log('[adminmfa] fail count: ' . $e->getMessage()); return false; }
    }

    private static function clearFails(string $email): void
    {
        try { Database::pdo()->prepare('DELETE FROM lms_login_throttle WHERE ident = ?')->execute([self::ident($email)]); }
        catch (Throwable $e) { /* nothing worth failing a sign-in over */ }
    }

    private static function audit(string $action, string $target, string $detail, string $by = ''): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('access', $action, $target, $detail, null, $by !== '' ? $by : null); }
        catch (Throwable $e) { /* best effort */ }
    }
}
