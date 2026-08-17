<?php
/**
 * lib/Levels.php — the membership progression engine ("How Afrovanguard Works").
 *
 * The ladder is O → A → … → G, where each level represents a doubling of a
 * member's mentorship tree. Its shape and its criteria are NOT constants here:
 * the order, the window that defines an "active" mentee, how many active
 * mentees Level A needs, whether multiplication is required above A, and the
 * minimum tenure all come from AvRules, so leadership can change them from
 * Studio without a deploy.
 *
 * Two design decisions are deliberate and worth keeping.
 *
 * First, advancement is driven by ACTIVE MENTEES, not referrals. Referrals are
 * still recorded — they are useful, and the invite link depends on them — but a
 * member who has introduced ten people and mentors none of them has not
 * demonstrated leadership. Rewarding headcount is the anti-pattern the concept
 * report names explicitly (§17).
 *
 * Second, nothing here promotes anyone. recommendation() returns evidence and a
 * verdict for a human to act on; set() is a leadership action. The auto-promote
 * rule exists, defaults to off, and is flagged as a conflict when switched on
 * (§20: "automated as a recommendation, not blindly automated").
 *
 * Self-contained and fail-safe: it provisions its own storage through the
 * portable schema helpers and never throws into a request.
 */
declare(strict_types=1);

final class Levels
{
    /** The shipped ladder. Leadership can reorder or extend it via levels.order. */
    public const ORDER = ['O', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /** Legacy alias — the referral bar that used to drive O→A. Retained so older
     *  callers keep resolving; the live criterion is levels.mentees_for_a. */
    public const REFERRALS_FOR_A = 2;

    /** Descriptions for the shipped levels. A level added via levels.order that
     *  has no entry here still works — it is described generically. */
    public const LADDER = [
        'O' => ['label' => 'Foundation Member',                 'blurb' => 'Learning the culture, serving weekly, guided by a mentor.'],
        'A' => ['label' => 'Level A — Growing Leader',           'blurb' => 'Actively mentoring committed members; earns an official Afrovanguard email and an accountability mentor.'],
        'B' => ['label' => 'Level B — Established Leader',       'blurb' => 'Mentees have begun mentoring others — multiplication has started.'],
        'C' => ['label' => 'Level C — Organisational Leadership','blurb' => 'Carries organisational leadership; membership dues are mandatory.'],
        'D' => ['label' => 'Level D — Multiplying Leader',       'blurb' => 'A mentorship tree several generations deep and still growing.'],
        'E' => ['label' => 'Level E — Senior Leader',            'blurb' => 'Sustains leaders who sustain leaders across the movement.'],
        'F' => ['label' => 'Level F — Movement Leader',          'blurb' => 'Responsible for whole branches of the multiplication network.'],
        'G' => ['label' => 'Level G — Grand Leader',             'blurb' => 'A mature multiplication network that no longer depends on them.'],
    ];

    private static bool $ensured = false;

    private static function db(): PDO { return Database::pdo(); }

    /* ── the ladder, as configured ─────────────────────────────────────── */

    /** The live ladder order. Falls back to the shipped ORDER if the rule is unusable. */
    public static function order(): array
    {
        if (!class_exists('AvRules')) return self::ORDER;
        $o = AvRules::arr('levels.order');
        return count($o) >= 2 ? $o : self::ORDER;
    }

    /** The live ladder as code => ['label','blurb'], covering configured levels. */
    public static function ladder(): array
    {
        $out = [];
        foreach (self::order() as $code) {
            $out[$code] = self::LADDER[$code] ?? [
                'label' => 'Level ' . $code,
                'blurb' => 'A further stage of leadership multiplication.',
            ];
        }
        return $out;
    }

    public static function labelOf(string $level): string
    {
        return (string) (self::ladder()[$level]['label'] ?? ('Level ' . $level));
    }

    /* ── storage ───────────────────────────────────────────────────────── */

    /**
     * Idempotently provision the level column, its audit stamps and the
     * referrals table — through the portable schema helpers, so this works on
     * SQLite, MySQL and Postgres alike (it previously emitted SQLite-only DDL).
     */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            $pdo = self::db();
            Database::execSchema($pdo, "
                CREATE TABLE IF NOT EXISTS member_referrals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    referrer_id INTEGER NOT NULL,
                    referred_id INTEGER NOT NULL,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE UNIQUE INDEX IF NOT EXISTS ux_ref_referred ON member_referrals(referred_id);
            ");
            // A member is "introduced" once, so referred_id is unique above.
            self::addCol('lms_users', 'level', "VARCHAR(4) NOT NULL DEFAULT 'O'");
            self::addCol('lms_users', 'level_at', "VARCHAR(32) NOT NULL DEFAULT ''");
            self::addCol('lms_users', 'level_by', "VARCHAR(120) NOT NULL DEFAULT ''");
        } catch (\Throwable $e) { error_log('[levels] ensure: ' . $e->getMessage()); }
    }

    /** Add a column if it is missing; a duplicate-column error is the success case. */
    private static function addCol(string $table, string $col, string $type): void
    {
        try {
            $ddl = "ALTER TABLE {$table} ADD COLUMN {$col} {$type};";
            Database::execSchema(self::db(), $ddl);
        } catch (\Throwable $e) { /* column exists */ }
    }

    /* ── reading and setting a level ───────────────────────────────────── */

    public static function of(int $userId): string
    {
        self::ensure();
        $order = self::order();
        try {
            $s = self::db()->prepare('SELECT level FROM lms_users WHERE id = ?');
            $s->execute([$userId]);
            $v = (string) ($s->fetchColumn() ?: '');
            return in_array($v, $order, true) ? $v : (string) $order[0];
        } catch (\Throwable $e) { return (string) $order[0]; }
    }

    /** Set a member's level (a leadership action). Returns true on change. */
    public static function set(int $userId, string $level, string $by = 'admin'): bool
    {
        if (!in_array($level, self::order(), true)) return false;
        self::ensure();
        try {
            self::db()->prepare('UPDATE lms_users SET level = ?, level_at = ?, level_by = ? WHERE id = ?')
                      ->execute([$level, gmdate('Y-m-d H:i:s'), mb_substr($by, 0, 120), $userId]);
            if (class_exists('Events')) Events::emit('member.level.changed', ['user_id' => $userId, 'level' => $level, 'by' => $by]);
            return true;
        } catch (\Throwable $e) { error_log('[levels] set: ' . $e->getMessage()); return false; }
    }

    /** When the member last changed level ('' if never recorded). */
    public static function levelSince(int $userId): string
    {
        self::ensure();
        try {
            $s = self::db()->prepare('SELECT level_at FROM lms_users WHERE id = ?');
            $s->execute([$userId]);
            return (string) ($s->fetchColumn() ?: '');
        } catch (\Throwable $e) { return ''; }
    }

    /** Days held at the current level. 0 when unknown (never blocks on missing data). */
    public static function tenureDays(int $userId): int
    {
        $at = self::levelSince($userId);
        if ($at === '') return 0;
        $t = strtotime($at);
        return $t ? max(0, (int) floor((time() - $t) / 86400)) : 0;
    }

    /* ── referrals (recorded, but no longer the advancement signal) ─────── */

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
        $order = self::order();
        $i = array_search($level, $order, true);
        return ($i !== false && $i + 1 < count($order)) ? (string) $order[$i + 1] : null;
    }

    /* ── the criteria ──────────────────────────────────────────────────── */

    /**
     * What the next level requires of this member, and how far along they are.
     *
     * O → A needs active mentees. Above A, multiplication is additionally
     * required when levels.require_multiplication is on: mentees who are
     * themselves mentoring, doubling with each rung. Tenure applies throughout.
     */
    public static function criteria(int $userId): array
    {
        $level = self::of($userId);
        $next  = self::nextOf($level);
        $order = self::order();

        $needMentees   = class_exists('AvRules') ? AvRules::int('levels.mentees_for_a', 2) : 2;
        $needMultiply  = class_exists('AvRules') ? AvRules::bool('levels.require_multiplication', true) : true;
        $minTenure     = class_exists('AvRules') ? AvRules::int('levels.min_tenure_days', 90) : 90;

        $m = class_exists('Mentorship') ? Mentorship::multiplication($userId)
           : ['pairings' => 0, 'active' => 0, 'multiplying' => 0, 'window_days' => 60, 'min_sessions' => 2];
        $tenure = self::tenureDays($userId);

        $reqs = [];
        if ($next !== null) {
            $idx = (int) array_search($next, $order, true);   // 1 for A, 2 for B, …

            $reqs[] = [
                'key'   => 'active_mentees',
                'label' => $needMentees . ' active mentee' . ($needMentees === 1 ? '' : 's'),
                'help'  => 'Mentees with at least ' . $m['min_sessions'] . ' sessions held in the last ' . $m['window_days'] . ' days.',
                'have'  => $m['active'], 'need' => $needMentees,
                'met'   => $m['active'] >= $needMentees,
            ];

            // Above A each rung doubles the expected multiplication.
            if ($needMultiply && $idx >= 2) {
                $needMult = (int) min($m['active'] > 0 ? $m['active'] : $needMentees, max(1, $idx - 1));
                $reqs[] = [
                    'key'   => 'multiplying_mentees',
                    'label' => $needMult . ' mentee' . ($needMult === 1 ? '' : 's') . ' who are themselves mentoring',
                    'help'  => 'Multiplication, not recruitment — this is what separates the levels above A.',
                    'have'  => $m['multiplying'], 'need' => $needMult,
                    'met'   => $m['multiplying'] >= $needMult,
                ];
            }

            if ($minTenure > 0) {
                $reqs[] = [
                    'key'   => 'tenure',
                    'label' => $minTenure . ' days at ' . $level,
                    'help'  => $tenure > 0 ? 'Held for ' . $tenure . ' days.' : 'No level date on record, so tenure is not counted against this member.',
                    'have'  => $tenure, 'need' => $minTenure,
                    // An unrecorded level date must not permanently block a member.
                    'met'   => $tenure === 0 || $tenure >= $minTenure,
                ];
            }
        }

        return [
            'level'          => $level,
            'next'           => $next,
            'multiplication' => $m,
            'tenure_days'    => $tenure,
            'requirements'   => $reqs,
            'eligible'       => $next !== null && $reqs !== [] && !array_filter($reqs, fn($r) => !$r['met']),
        ];
    }

    /** Is the member eligible to advance now? Evidence-based, for every level. */
    public static function eligibleForNext(int $userId): bool
    {
        return (bool) self::criteria($userId)['eligible'];
    }

    /**
     * A recommendation for leadership — never an action.
     *
     * Returns the verdict, the evidence behind it and what is still missing, so
     * a human can disagree with it. Even when levels.auto_promote is on, this
     * method does not write; applying a recommendation stays an explicit call
     * to set().
     */
    public static function recommendation(int $userId): array
    {
        $c = self::criteria($userId);
        $m = $c['multiplication'];

        $evidence = [
            $m['pairings'] . ' active pairing' . ($m['pairings'] === 1 ? '' : 's') . ' on record.',
            $m['active'] . ' mentee' . ($m['active'] === 1 ? '' : 's') . ' meeting the activity bar (' . $m['min_sessions'] . '+ sessions in ' . $m['window_days'] . ' days).',
            $m['multiplying'] . ' of those mentee' . ($m['active'] === 1 ? '' : 's') . ' are themselves mentoring.',
        ];
        if ($c['tenure_days'] > 0) $evidence[] = 'Held level ' . $c['level'] . ' for ' . $c['tenure_days'] . ' days.';

        $gaps = [];
        foreach ($c['requirements'] as $r) {
            if (!$r['met']) $gaps[] = $r['label'] . ' — currently ' . $r['have'] . ' of ' . $r['need'] . '.';
        }

        $verdict = $c['next'] === null ? 'top_of_ladder' : ($c['eligible'] ? 'advance' : 'hold');
        if ($verdict === 'hold' && $m['pairings'] === 0 && $m['active'] === 0) $verdict = 'insufficient_data';

        return [
            'user_id'  => $userId,
            'level'    => $c['level'],
            'next'     => $c['next'],
            'verdict'  => $verdict,
            'evidence' => $evidence,
            'gaps'     => $gaps,
            // Deliberately no score. Character is not a number (concept report §3A).
            'note'     => $verdict === 'advance'
                ? 'Meets every criterion for ' . $c['next'] . '. Leadership decides whether to advance them.'
                : ($verdict === 'insufficient_data'
                    ? 'Too little activity on record to assess. This is not evidence against the member.'
                    : 'Not yet — see the gaps above.'),
        ];
    }

    public static function inviteUrl(int $userId): string
    {
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        return $base . '/login?next=' . rawurlencode('/portal/') . '&ref=' . $userId;
    }

    /** A render-ready progression summary for the portal. */
    public static function progress(int $userId): array
    {
        $c     = self::criteria($userId);
        $level = $c['level'];
        $next  = $c['next'];
        $lad   = self::ladder();

        return [
            'level'            => $level,
            'label'            => $lad[$level]['label'] ?? 'Member',
            'blurb'            => $lad[$level]['blurb'] ?? '',
            'next'             => $next,
            'next_label'       => $next ? (string) ($lad[$next]['label'] ?? '') : null,
            'referrals'        => self::referralCount($userId),
            'referrals_needed' => class_exists('AvRules') ? AvRules::int('levels.mentees_for_a', 2) : self::REFERRALS_FOR_A,
            'active_mentees'   => (int) $c['multiplication']['active'],
            'multiplying'      => (int) $c['multiplication']['multiplying'],
            'requirements'     => $c['requirements'],
            'eligible_next'    => (bool) $c['eligible'],
            'invite_url'       => self::inviteUrl($userId),
            'order'            => self::order(),
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
