<?php
/**
 * lib/Levels.php — the membership progression engine ("How Afrovanguard Works").
 *
 * The ladder and the criteria for climbing it are Afrovanguard's constitution,
 * so they are read from AvRules at runtime, not frozen into constants here:
 *
 *   levels.ladder                  the progression, e.g. O,A,B,C,D,E,F,G
 *   levels.active_mentees_for_a     how many ACTIVE mentees Level A needs
 *   levels.require_multiplication   whether levels above A need second-generation mentoring
 *   levels.min_days_at_level        tenure floor
 *   levels.min_attendance_pct       meeting consistency floor
 *   levels.min_commitment_pct       commitment completion floor
 *   levels.auto_promote             OFF by default — recommend, never decide
 *
 * The important change from the original engine is WHAT earns advancement.
 * Referral count is recruitment, not leadership: it rewards the headcount the
 * concept report explicitly says must not be rewarded (§17). Advancement now
 * tests *active, verified* mentorship — pairings that actually meet — via
 * Mentorship::multiplicationSummary(). Referrals are still recorded, because
 * introducing people matters, but they no longer buy a level on their own.
 *
 * recommend() produces evidence for leadership to weigh. It does not promote
 * anyone unless levels.auto_promote is deliberately switched on, per §20/§23.
 *
 * Self-contained: it provisions its own storage (portably, across
 * SQLite/MySQL/Postgres) and never throws into the request.
 */
declare(strict_types=1);

final class Levels
{
    /**
     * Settled commitments needed before completion counts toward a recommendation.
     *
     * Below this, a percentage is noise: one kept commitment reads as 100% and one
     * missed reads as 0%, and neither says anything about a member.
     */
    private const COMMITMENT_MIN_SAMPLE = 5;

    /** Fallback ladder when rules are unavailable. AvRules is the real source. */
    public const ORDER = ['O', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /**
     * Human labels for the known levels. A ladder longer than this still works —
     * labelOf() falls back to "Level X" — so leadership can extend the ladder
     * without a code change.
     */
    public const LADDER = [
        'O' => ['label' => 'Foundation Member',                 'blurb' => 'Learning the culture, serving weekly, guided by a mentor.'],
        'A' => ['label' => 'Level A — Growing Leader',            'blurb' => 'Actively mentoring others; earns an official Afrovanguard email and an accountability mentor.'],
        'B' => ['label' => 'Level B — Established Leader',        'blurb' => 'Mentees are themselves mentoring — leadership has begun to reproduce.'],
        'C' => ['label' => 'Level C — Organisational Leadership', 'blurb' => 'Carries organisational leadership; membership dues are mandatory.'],
        'D' => ['label' => 'Level D — Multiplying Leader',         'blurb' => 'A network several generations deep, still meeting consistently.'],
        'E' => ['label' => 'Level E — Senior Multiplier',          'blurb' => 'Sustained multiplication across many active relationships.'],
        'F' => ['label' => 'Level F — Movement Leader',            'blurb' => 'Leads leaders who lead leaders.'],
        'G' => ['label' => 'Level G — Grand',                      'blurb' => 'A mature multiplication network carrying the movement forward.'],
    ];

    private static bool $ensured = false;

    private static function db(): PDO { return Database::pdo(); }

    /** The live ladder, lowest level first. */
    public static function order(): array
    {
        if (class_exists('AvRules')) {
            $l = AvRules::list('levels.ladder');
            if (count($l) >= 2) return $l;
        }
        return self::ORDER;
    }

    public static function labelOf(string $code): string
    {
        return (string) (self::LADDER[$code]['label'] ?? ('Level ' . $code));
    }

    public static function blurbOf(string $code): string
    {
        return (string) (self::LADDER[$code]['blurb'] ?? '');
    }

    /** The lowest level — where everyone starts. */
    public static function base(): string
    {
        $o = self::order();
        return (string) ($o[0] ?? 'O');
    }

    /** Idempotently provision the level column + referrals table. */
    public static function ensure(): void
    {
        if (self::$ensured) return;
        self::$ensured = true;
        try {
            $pdo = self::db();
            // Portable DDL, like the rest of lib/ — the original hand-rolled
            // SQLite syntax (TEXT columns, datetime('now')) failed on the
            // MySQL/Postgres targets the app otherwise supports.
            if (!Database::columnExists('lms_users', 'level')) {
                // The DDL default is a fixed literal, never the configurable
                // ladder: base() derives from a rule, and interpolating a rule
                // value into schema text is an injection path (and would bake
                // today's ladder into the column forever). of() already treats an
                // unrecognised stored level as the base, so a plain 'O' default is
                // both safe and correct. VARCHAR(8) leaves room for the longer
                // codes the csv validator permits.
                try { $pdo->exec("ALTER TABLE lms_users ADD COLUMN level VARCHAR(8) NOT NULL DEFAULT 'O'"); }
                catch (\Throwable $e) { /* raced or already present */ }
            }
            foreach (['level_at' => "VARCHAR(32) NOT NULL DEFAULT ''", 'level_by' => "VARCHAR(191) NOT NULL DEFAULT ''"] as $col => $decl) {
                if (!Database::columnExists('lms_users', $col)) {
                    try { $pdo->exec('ALTER TABLE lms_users ADD COLUMN ' . $col . ' ' . $decl); }
                    catch (\Throwable $e) { /* raced or already present */ }
                }
            }
            Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS member_referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id INTEGER NOT NULL,
                referred_id INTEGER NOT NULL,
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE UNIQUE INDEX IF NOT EXISTS ux_ref_referred ON member_referrals(referred_id);");
        } catch (\Throwable $e) { error_log('[levels] ensure: ' . $e->getMessage()); }
    }

    public static function of(int $userId): string
    {
        self::ensure();
        $base = self::base();
        try {
            $s = self::db()->prepare('SELECT level FROM lms_users WHERE id = ?');
            $s->execute([$userId]);
            $v = (string) ($s->fetchColumn() ?: $base);
            // A level that has since been removed from the ladder still reads as
            // itself rather than silently demoting the member to base.
            return in_array($v, self::order(), true) ? $v : (isset(self::LADDER[$v]) ? $v : $base);
        } catch (\Throwable $e) { return $base; }
    }

    /** Set a member's level (leadership action). Returns true on change. */
    public static function set(int $userId, string $level, string $actor = ''): bool
    {
        if (!in_array($level, self::order(), true)) return false;
        self::ensure();
        try {
            self::db()->prepare('UPDATE lms_users SET level = ?, level_at = ?, level_by = ? WHERE id = ?')
                      ->execute([$level, gmdate('c'), $actor, $userId]);
            if (class_exists('Events')) Events::emit('member.level.changed', ['user_id' => $userId, 'level' => $level, 'by' => $actor]);
            return true;
        } catch (\Throwable $e) { error_log('[levels] set: ' . $e->getMessage()); return false; }
    }

    /** Record that $referrerId personally introduced $referredId. Idempotent. */
    public static function recordReferral(int $referrerId, int $referredId): bool
    {
        if ($referrerId <= 0 || $referredId <= 0 || $referrerId === $referredId) return false;
        self::ensure();
        try {
            $sql = Database::insertIgnore('member_referrals', ['referrer_id', 'referred_id', 'created_at']);
            self::db()->prepare($sql)->execute([$referrerId, $referredId, gmdate('c')]);
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
        return ($i !== false && $i + 1 < count($order)) ? $order[$i + 1] : null;
    }

    /** How many days the member has held their current level (null if unknown). */
    public static function daysAtLevel(int $userId): ?int
    {
        self::ensure();
        try {
            $s = self::db()->prepare('SELECT level_at FROM lms_users WHERE id = ?');
            $s->execute([$userId]);
            $at = trim((string) ($s->fetchColumn() ?: ''));
            if ($at === '') return null;                       // never stamped — pre-dates this column
            $ts = strtotime($at);
            if (!$ts) return null;
            return (int) floor((time() - $ts) / 86400);
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Whether the member currently satisfies the criteria for their next level.
     *
     * This is a *criteria check*, not a promotion: see recommend() for the
     * evidence-carrying version leadership actually reads.
     */
    public static function eligibleForNext(int $userId): bool
    {
        return (bool) (self::recommend($userId)['recommend'] ?? false);
    }

    /**
     * Assess readiness for the next level and return the evidence for it.
     *
     * Read-only: it promotes nobody and writes nothing (not even via the
     * mentorship reconcile path — see the memberConsistency call below).
     * promoteIfEligible() is the one that can act, and only when leadership calls
     * it or auto_promote is deliberately on.
     *
     * @return array{recommend:bool, level:string, next:?string, reasons:string[], gaps:string[], metrics:array}
     */
    public static function recommend(int $userId): array
    {
        $level = self::of($userId);
        $next  = self::nextOf($level);
        $out = ['recommend' => false, 'level' => $level, 'next' => $next, 'reasons' => [], 'gaps' => [], 'metrics' => []];
        if ($next === null || $userId <= 0) {
            if ($next === null) $out['gaps'][] = 'Already at the top of the ladder.';
            return $out;
        }

        $needA   = class_exists('AvRules') ? max(1, AvRules::int('levels.active_mentees_for_a')) : 2;
        $needMul = class_exists('AvRules') ? AvRules::bool('levels.require_multiplication') : true;
        $minDays = class_exists('AvRules') ? AvRules::int('levels.min_days_at_level') : 0;
        $minAtt  = class_exists('AvRules') ? AvRules::int('levels.min_attendance_pct') : 0;

        $mult = class_exists('Mentorship')
            ? Mentorship::multiplicationSummary($userId)
            : ['active' => 0, 'multiplying' => 0, 'descendants' => 0, 'depth' => 0];
        // Read-only: recommend() is called from page renders and from per-member
        // loops, so it must not reconcile (a write plus outbound Google calls).
        $cons = class_exists('Mentorship') ? Mentorship::memberConsistency($userId, false) : ['rate' => null, 'held' => 0];
        $days = self::daysAtLevel($userId);

        $out['metrics'] = [
            'active_mentees'      => $mult['active'],
            'multiplying_mentees' => $mult['multiplying'],
            'descendants'         => $mult['descendants'],
            'depth'               => $mult['depth'],
            'attendance_pct'      => $cons['rate'],
            'sessions_held'       => $cons['held'] ?? 0,
            'days_at_level'       => $days,
            'referrals'           => self::referralCount($userId),
        ];

        // ── Commitment completion (G-1) ──
        // levels.min_commitment_pct was stored from the start and enforced by
        // nothing, because there were no commitments to count. There are now, so it
        // becomes a real criterion — but only for a member who has settled enough of
        // them to mean anything. Judging somebody on one commitment would be worse
        // than not judging them at all, and a member with none yet must not be
        // blocked by a metric the system has not had a chance to observe.
        $minPct = 0;
        try { $minPct = class_exists('AvRules') ? (int) AvRules::get('levels.min_commitment_pct') : 0; }
        catch (Throwable $e) { $minPct = 0; }
        if (class_exists('Commitments')) {
            try {
                $com = Commitments::completion($userId);
                $out['metrics']['commitments_settled']  = (int) $com['done'] + (int) $com['missed'];
                $out['metrics']['commitments_kept']     = (int) $com['done'];
                $out['metrics']['commitment_pct']       = $com['rate'];
                // Recorded so a leader sees candour as the positive signal it is,
                // rather than a member who reports their own misses scoring worse.
                $out['metrics']['self_reported_misses'] = (int) $com['self_reported_misses'];

                $settled = (int) $com['done'] + (int) $com['missed'];
                if ($minPct > 0 && $settled >= self::COMMITMENT_MIN_SAMPLE) {
                    if ((int) $com['rate'] >= $minPct) {
                        $out['reasons'][] = 'Kept ' . (int) $com['rate'] . '% of ' . $settled . ' settled commitments (needs ' . $minPct . '%).';
                    } else {
                        $out['gaps'][] = 'Commitment completion is ' . (int) $com['rate'] . '% of ' . $settled
                            . ' settled commitments; ' . $minPct . '% is expected.';
                    }
                } elseif ($minPct > 0) {
                    // Deliberately NOT a gap. A gap blocks the recommendation
                    // (recommend = no gaps), so treating "we have not observed
                    // enough yet" as a failure would freeze every promotion in the
                    // movement until commitments accumulated — punishing members for
                    // a hole in the record rather than in their work. It is reported
                    // as a fact about the data so a UI can say so, and nothing else.
                    $out['metrics']['commitment_sample_short'] = true;
                    $out['metrics']['commitment_sample_needed'] = self::COMMITMENT_MIN_SAMPLE;
                }
            } catch (Throwable $e) { error_log('[levels] commitments: ' . $e->getMessage()); }
        }

        $order = self::order();
        $idx   = (int) array_search($level, $order, true);

        // ── Multiplication: the criterion that distinguishes this ladder ──
        if ($idx === 0) {
            // base → second level: active mentorship, verified by real meetings.
            if ($mult['active'] >= $needA) {
                $out['reasons'][] = 'Actively mentoring ' . $mult['active'] . ' member(s) — each met recently, not merely listed.';
            } else {
                $out['gaps'][] = 'Needs ' . $needA . ' active mentee(s); currently ' . $mult['active']
                    . '. An active mentee is one this member has actually met inside the review window.';
            }
        } elseif ($needMul) {
            // Above the second level, each rung wants another generation of depth.
            $wantDepth = min($idx + 1, 8);
            if ($mult['depth'] >= $wantDepth && $mult['multiplying'] >= 1) {
                $out['reasons'][] = 'Multiplication network runs ' . $mult['depth'] . ' generation(s) deep with '
                    . $mult['multiplying'] . ' mentee(s) now mentoring others, and ' . $mult['descendants'] . ' active people below them.';
            } else {
                $out['gaps'][] = 'Level ' . $next . ' expects ' . $wantDepth . ' generation(s) of active multiplication; currently '
                    . $mult['depth'] . ' with ' . $mult['multiplying'] . ' mentee(s) mentoring.';
            }
            if ($mult['active'] < $needA) {
                $out['gaps'][] = 'Direct active mentees have dropped to ' . $mult['active'] . ' (expected at least ' . $needA . ').';
            }
        } else {
            $out['gaps'][] = 'Levels above ' . ($order[1] ?? 'A') . ' are granted by leadership (multiplication requirement is switched off).';
            return $out;   // nothing to compute — leadership decides outright
        }

        // ── Consistency ──
        $rate = $cons['rate'];
        if ($rate === null) {
            $out['gaps'][] = 'No completed mentorship sessions on record yet, so meeting consistency cannot be verified.';
        } elseif ((int) $rate >= $minAtt) {
            $out['reasons'][] = 'Meeting consistency ' . (int) $rate . '% across ' . (int) ($cons['held'] ?? 0) . ' session(s).';
        } else {
            $out['gaps'][] = 'Meeting consistency ' . (int) $rate . '% is below the required ' . $minAtt . '%.';
        }

        // ── Tenure ──
        if ($minDays > 0) {
            if ($days === null) {
                $out['gaps'][] = 'Time at the current level is unknown (the level pre-dates tenure tracking) — confirm manually.';
            } elseif ($days >= $minDays) {
                $out['reasons'][] = 'Has held ' . $level . ' for ' . $days . ' days (minimum ' . $minDays . ').';
            } else {
                $out['gaps'][] = 'Only ' . $days . ' days at ' . $level . '; the minimum is ' . $minDays . '.';
            }
        }

        $out['recommend'] = empty($out['gaps']);
        return $out;
    }

    /**
     * Act on a recommendation. Returns what happened rather than a bare bool, so
     * a caller (or the cron) can report honestly.
     *
     * With levels.auto_promote off — the default, and what §20 asks for — this
     * records the recommendation and promotes nobody.
     *
     * @return array{promoted:bool, recommended:bool, level:string, next:?string, reasons:string[], gaps:string[]}
     */
    public static function promoteIfEligible(int $userId, string $actor = 'system'): array
    {
        $rec = self::recommend($userId);
        $res = ['promoted' => false, 'recommended' => (bool) $rec['recommend'], 'level' => $rec['level'],
                'next' => $rec['next'], 'reasons' => $rec['reasons'], 'gaps' => $rec['gaps']];
        if (!$rec['recommend'] || $rec['next'] === null) return $res;

        $auto = class_exists('AvRules') ? AvRules::bool('levels.auto_promote') : false;
        if (!$auto) {
            if (class_exists('Events')) {
                try { Events::emit('member.level.recommended', ['user_id' => $userId, 'from' => $rec['level'], 'to' => $rec['next'], 'reasons' => $rec['reasons']]); }
                catch (\Throwable $e) {}
            }
            return $res;
        }
        $res['promoted'] = self::set($userId, (string) $rec['next'], $actor);
        return $res;
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
        $rec   = self::recommend($userId);
        $needA = class_exists('AvRules') ? max(1, AvRules::int('levels.active_mentees_for_a')) : 2;

        return [
            'level'            => $level,
            'label'            => self::labelOf($level),
            'blurb'            => self::blurbOf($level),
            'next'             => $next,
            'next_label'       => $next ? self::labelOf($next) : null,
            'referrals'        => self::referralCount($userId),
            'referrals_needed' => $needA,   // kept for template compatibility
            'active_mentees'   => $rec['metrics']['active_mentees'] ?? 0,
            'mentees_needed'   => $needA,
            'eligible_next'    => (bool) $rec['recommend'],
            'reasons'          => $rec['reasons'],
            'gaps'             => $rec['gaps'],
            'metrics'          => $rec['metrics'],
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
