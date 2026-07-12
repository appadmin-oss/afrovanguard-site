<?php
/**
 * lib/Levels.php — the membership progression engine ("How Afrovanguard Works").
 *
 * Tracks a member's level (O → A → B → C …) and the signals that drive
 * advancement — principally referrals (personally introducing committed
 * members). Level O is the default. O → A becomes eligible once a member has
 * introduced REFERRALS_FOR_A committed members; higher levels are granted by
 * leadership (Studio → People). Self-contained: it provisions its own storage
 * and never throws into the request.
 */
declare(strict_types=1);

final class Levels
{
    public const ORDER = ['O', 'A', 'B', 'C'];
    public const REFERRALS_FOR_A = 2;

    public const LADDER = [
        'O' => ['label' => 'Foundation Member',                'blurb' => 'Learning the culture, serving weekly, guided by a mentor.'],
        'A' => ['label' => 'Level A — Growing Leader',          'blurb' => 'Has introduced and mentored new members; earns an official Afrovanguard email and an accountability mentor.'],
        'B' => ['label' => 'Level B — Established Leader',       'blurb' => 'Deepening service and responsibility across the movement.'],
        'C' => ['label' => 'Level C — Organisational Leadership','blurb' => 'Carries organisational leadership; membership dues are mandatory.'],
    ];

    private static bool $ensured = false;

    private static function db(): PDO { return Database::pdo(); }

    /** Idempotently provision the level column + referrals table. */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            $pdo = self::db();
            try { $pdo->exec("ALTER TABLE lms_users ADD COLUMN level TEXT NOT NULL DEFAULT 'O'"); }
            catch (\Throwable $e) { /* column exists */ }
            $pdo->exec("CREATE TABLE IF NOT EXISTS member_referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id INTEGER NOT NULL,
                referred_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )");
            // one referrer per referred member (a member is "introduced" once)
            try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_ref_referred ON member_referrals(referred_id)"); }
            catch (\Throwable $e) {}
        } catch (\Throwable $e) { error_log('[levels] ensure: ' . $e->getMessage()); }
    }

    public static function of(int $userId): string
    {
        self::ensure();
        try {
            $s = self::db()->prepare('SELECT level FROM lms_users WHERE id = ?');
            $s->execute([$userId]);
            $v = (string) ($s->fetchColumn() ?: 'O');
            return in_array($v, self::ORDER, true) ? $v : 'O';
        } catch (\Throwable $e) { return 'O'; }
    }

    /** Set a member's level (leadership action). Returns true on change. */
    public static function set(int $userId, string $level): bool
    {
        if (!in_array($level, self::ORDER, true)) return false;
        self::ensure();
        try {
            self::db()->prepare('UPDATE lms_users SET level = ? WHERE id = ?')->execute([$level, $userId]);
            if (class_exists('Events')) Events::emit('member.level.changed', ['user_id' => $userId, 'level' => $level]);
            return true;
        } catch (\Throwable $e) { error_log('[levels] set: ' . $e->getMessage()); return false; }
    }

    /** Record that $referrerId personally introduced $referredId. Idempotent. */
    public static function recordReferral(int $referrerId, int $referredId): bool
    {
        if ($referrerId <= 0 || $referredId <= 0 || $referrerId === $referredId) return false;
        self::ensure();
        try {
            $sql = Database::insertIgnore('member_referrals', ['referrer_id', 'referred_id']);
            self::db()->prepare($sql)->execute([$referrerId, $referredId]);
            if (class_exists('Events')) Events::emit('member.referred', ['referrer_id' => $referrerId, 'referred_id' => $referredId]);
            return true;
        } catch (\Throwable $e) { error_log('[levels] recordReferral: ' . $e->getMessage()); return false; }
    }

    public static function referralCount(int $userId): int
    {
        self::ensure();
        try {
            $s = self::db()->prepare('SELECT COUNT(*) FROM member_referrals WHERE referrer_id = ?');
            $s->execute([$userId]);
            return (int) $s->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    public static function nextOf(string $level): ?string
    {
        $i = array_search($level, self::ORDER, true);
        return ($i !== false && $i + 1 < count(self::ORDER)) ? self::ORDER[$i + 1] : null;
    }

    /** Is the member eligible to advance now? O→A is referral-driven; the rest
     *  are leadership decisions, so they report false here (granted in Studio). */
    public static function eligibleForNext(int $userId): bool
    {
        return self::of($userId) === 'O' && self::referralCount($userId) >= self::REFERRALS_FOR_A;
    }

    public static function inviteUrl(int $userId): string
    {
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        return $base . '/login?next=' . rawurlencode('/portal/') . '&ref=' . $userId;
    }

    /** A render-ready progression summary for the portal. */
    public static function progress(int $userId): array
    {
        $level = self::of($userId);
        $next  = self::nextOf($level);
        $refs  = self::referralCount($userId);
        return [
            'level'            => $level,
            'label'            => self::LADDER[$level]['label'] ?? 'Member',
            'blurb'            => self::LADDER[$level]['blurb'] ?? '',
            'next'             => $next,
            'next_label'       => $next ? (self::LADDER[$next]['label'] ?? '') : null,
            'referrals'        => $refs,
            'referrals_needed' => self::REFERRALS_FOR_A,
            'eligible_next'    => self::eligibleForNext($userId),
            'invite_url'       => self::inviteUrl($userId),
            'order'            => self::ORDER,
        ];
    }

    /** Register listeners: capture a pending referral when a member is created. */
    public static function boot(): void
    {
        if (!class_exists('Events')) return;
        Events::on('member.created', static function (array $p): void {
            try {
                $ref = isset($_COOKIE['av_ref']) && ctype_digit((string) $_COOKIE['av_ref']) ? (int) $_COOKIE['av_ref'] : 0;
                if ($ref <= 0) return;
                $email = trim((string) ($p['email'] ?? ''));
                if ($email === '') return;
                $s = Database::pdo()->prepare('SELECT id FROM lms_users WHERE email = ? LIMIT 1');
                $s->execute([$email]);
                $newId = (int) ($s->fetchColumn() ?: 0);
                if ($newId > 0 && $newId !== $ref) self::recordReferral($ref, $newId);
                if (!headers_sent()) setcookie('av_ref', '', ['expires' => time() - 3600, 'path' => '/']);
            } catch (\Throwable $e) { error_log('[levels] capture: ' . $e->getMessage()); }
        });
    }
}
