<?php
/**
 * lib/QuestPoints.php — the Vanguard Quest's points, kept apart from the gate's.
 *
 * THE RULE (owner, 21 October 2026)
 *   "Points are awarded only for real evidence of change — not for attendance,
 *    knowledge, or talking."
 *
 * Until now there was one points column and two currencies in it. gate_points
 * grants for arriving on time, three- and five-day runs, a perfect week and a
 * birthday; Conduct's approved awards landed in the same table, under the
 * period `award:<case>`. GateAttendance::points() summed the lot, so the
 * number a rank would sit on was mostly punctuality, and nothing could tell a
 * point earned for evidence from a point earned for turning up except by
 * parsing a string — a convention, not a constraint.
 *
 * So this is a second ledger, not a filter over the first. Attendance keeps
 * its points, its streaks and its gate pass, untouched. What lands HERE is
 * only what somebody saw and a second person approved.
 *
 * ONCE, HOWEVER OFTEN IT IS ASKED
 *   Every grant names the decision that caused it in `period`, and the table
 *   is unique on (member_id, period). Approving the same case twice, replaying
 *   a log or recomputing a rank grants nothing the second time. The period
 *   vocabulary, from docs/vanguard-quest.md:
 *       award:<case>                      a compliance-approved award
 *       challenge:<member>:<challenge>    one of the seventy, signed off
 *       mentee-rank:<mentee>:<rank>       a mentee reaching a rank credits their mentor
 *
 * NOT A RANK ENGINE. This class stores and totals. What a total means is the
 * ladder's business, and the ladder is not built yet.
 */
declare(strict_types=1);

final class QuestPoints
{
    /** A single grant may not exceed this. Conduct::POINTS_MAX is the award cap. */
    public const MAX_PER_GRANT = 1000;

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $pdo = Database::pdo();
            Database::execSchema($pdo, "CREATE TABLE IF NOT EXISTS quest_points (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id  INTEGER NOT NULL,
                day        VARCHAR(10) NOT NULL DEFAULT '',
                period     VARCHAR(80) NOT NULL,
                points     INTEGER NOT NULL DEFAULT 0,
                reason     VARCHAR(40) NOT NULL DEFAULT '',
                value_key  VARCHAR(40) NOT NULL DEFAULT '',
                note       VARCHAR(160) NOT NULL DEFAULT '',
                granted_by INTEGER NOT NULL DEFAULT 0,
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
            /* The once-only promise is the database's, not the caller's. */
            Database::ensureIndex($pdo, 'idx_quest_points_once', 'quest_points', 'member_id, period', true);
            Database::ensureIndex($pdo, 'idx_quest_points_member', 'quest_points', 'member_id');
        } catch (Throwable $e) {
            error_log('[questpoints] ensure: ' . $e->getMessage());
        }
    }

    /**
     * Record points for a member. Returns false when nothing was written —
     * because the arguments were refused, or because this period already
     * granted, which is not an error.
     *
     * @param string $period The decision that caused it. Never a bare label.
     * @param string $valueKey One of MentorPortal::VALUES, when the grant is
     *                         attributable to a value. '' when it is not.
     */
    public static function grant(int $memberId, int $points, string $period, string $reason = '',
                                 string $note = '', int $byUid = 0, string $day = '', string $valueKey = ''): bool
    {
        if ($memberId <= 0 || $points <= 0 || $points > self::MAX_PER_GRANT) return false;
        $period = trim($period);
        if ($period === '' || !str_contains($period, ':')) return false;
        self::ensure();
        if ($day === '') $day = gmdate('Y-m-d');
        try {
            $st = Database::pdo()->prepare(Database::insertIgnore('quest_points',
                ['member_id', 'day', 'period', 'points', 'reason', 'value_key', 'note', 'granted_by', 'created_at']));
            $st->execute([$memberId, $day, mb_substr($period, 0, 80), $points, mb_substr($reason, 0, 40),
                          mb_substr($valueKey, 0, 40), mb_substr($note, 0, 160), $byUid, gmdate('Y-m-d H:i:s')]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[questpoints] grant: ' . $e->getMessage());
            return false;
        }
    }

    /** What this member has earned by evidence. Never includes attendance. */
    public static function total(int $memberId): int
    {
        if ($memberId <= 0) return 0;
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT COALESCE(SUM(points), 0) FROM quest_points WHERE member_id = ?');
            $st->execute([$memberId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /** Has this decision already granted? */
    public static function granted(int $memberId, string $period): bool
    {
        if ($memberId <= 0 || $period === '') return false;
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT 1 FROM quest_points WHERE member_id = ? AND period = ?');
            $st->execute([$memberId, $period]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * The challenges this member has signed off, in one query. A sign-off is
     * a grant under `challenge:<member>:<challenge>`, so the ledger already
     * knows; asking it seventy times, once per slot, was the old way.
     * @return string[] challenge ids, e.g. 'diligence.4'
     */
    public static function challengesDone(int $memberId): array
    {
        if ($memberId <= 0) return [];
        self::ensure();
        $prefix = 'challenge:' . $memberId . ':';
        try {
            $st = Database::pdo()->prepare('SELECT period FROM quest_points WHERE member_id = ? AND period LIKE ?');
            $st->execute([$memberId, $prefix . '%']);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $p) {
                $p = (string) $p;
                if (str_starts_with($p, $prefix)) $out[] = substr($p, strlen($prefix));
            }
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** @return array<int,array{day:string,points:int,reason:string,note:string}> newest first */
    public static function recent(int $memberId, int $limit = 10): array
    {
        if ($memberId <= 0) return [];
        self::ensure();
        try {
            $limit = max(1, min(100, $limit));
            $st = Database::pdo()->prepare('SELECT day, points, reason, note FROM quest_points
                                            WHERE member_id = ? ORDER BY id DESC LIMIT ' . $limit);
            $st->execute([$memberId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * Points per value, for the day a member's record is shown by value rather
     * than as one number. Values with nothing earned are present and zero, so
     * the caller never has to know the seven keys.
     * @return array<string,int>
     */
    public static function byValue(int $memberId): array
    {
        $out = [];
        if (class_exists('MentorPortal')) foreach (array_keys(MentorPortal::VALUES) as $k) $out[$k] = 0;
        if ($memberId <= 0) return $out;
        self::ensure();
        try {
            $st = Database::pdo()->prepare("SELECT value_key, SUM(points) AS p FROM quest_points
                                            WHERE member_id = ? AND value_key <> '' GROUP BY value_key");
            $st->execute([$memberId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(string) $r['value_key']] = (int) $r['p'];
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Awards granted before the two ledgers were separated are still sitting in
     * gate_points under `award:<case>`, counting toward a member's attendance
     * total. This moves them where they belong.
     *
     * A DRY RUN BY DEFAULT. Nothing is written unless $apply is true, because
     * this takes points off the number shown on members' gate passes — which is
     * the correct outcome and is still a change somebody should decide to make
     * rather than have happen on deploy.
     *
     * Idempotent: a row already adopted is skipped, so running it twice moves
     * nothing the second time.
     *
     * @return array{rows:int,points:int,members:int,applied:bool}
     */
    public static function adoptLegacyAwards(bool $apply = false): array
    {
        self::ensure();
        $out = ['rows' => 0, 'points' => 0, 'members' => 0, 'applied' => false];
        try {
            $pdo = Database::pdo();
            $st = $pdo->query("SELECT member_id, day, period, points, note FROM gate_points WHERE period LIKE 'award:%'");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $members = [];
            foreach ($rows as $r) {
                $mid = (int) $r['member_id'];
                if (self::granted($mid, (string) $r['period'])) continue;
                $out['rows']++;
                $out['points'] += (int) $r['points'];
                $members[$mid] = true;
                if (!$apply) continue;
                if (self::grant($mid, (int) $r['points'], (string) $r['period'], 'award',
                                (string) $r['note'], 0, (string) $r['day'])) {
                    $pdo->prepare('DELETE FROM gate_points WHERE member_id = ? AND period = ?')
                        ->execute([$mid, (string) $r['period']]);
                }
            }
            $out['members'] = count($members);
            $out['applied'] = $apply;
        } catch (Throwable $e) {
            error_log('[questpoints] adopt: ' . $e->getMessage());
        }
        return $out;
    }

    /** The leaders by evidence. Attendance has its own board and this is not it. */
    public static function top(int $limit = 10): array
    {
        self::ensure();
        try {
            $limit = max(1, min(100, $limit));
            $st = Database::pdo()->query('SELECT q.member_id, u.name, SUM(q.points) AS points
                                          FROM quest_points q LEFT JOIN lms_users u ON u.id = q.member_id
                                          GROUP BY q.member_id, u.name ORDER BY points DESC, q.member_id ASC LIMIT ' . $limit);
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) { return []; }
    }
}
