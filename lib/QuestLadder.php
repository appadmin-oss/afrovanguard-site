<?php
/**
 * lib/QuestLadder.php — what the Pyramid Manual says should happen to a
 * member's level or standing, put to a person to decide.
 *
 * The owner, 22 October 2026: "only suggest demotions." The manual's "loss of
 * current level" (inactivity for 30 days), the one-week suspension for an
 * unsettled balance and its ₦2,000 fine (Section 3.6) all arrive here as
 * SUGGESTIONS. The machine observes and recommends; the committee decides.
 *
 *   suggest()   raise one — once per member, kind and source, however often
 *               the sweep runs
 *   accept()    a committee member or superadmin acts on it: a demotion moves
 *               the member down one level through Levels::set; a fine posts
 *               through NgvFines; a suspension is recorded for its month,
 *               which is what the money reconciliation reads
 *   dismiss()   with a reason; the suggestion stays on the record
 *   sweep()     once a day: members above the base level with no activity for
 *               quest.inactive_days
 *
 * And one thing that is not a suggestion, because a person already decided:
 * when a promotion is recorded (member.level.changed, upward), the mentee's
 * Vanguard Quest mentor receives the catalogue's "mentee rises" award, once
 * per mentee per level.
 *
 * Promotions themselves are Promotion's queue (lib/Promotion.php), on
 * Levels::recommend — which reads the manual's floors when
 * levels.quest_pyramid is on. Not duplicated here.
 */
declare(strict_types=1);

final class QuestLadder
{
    public const KINDS = [
        'demotion'   => 'Move down one level',
        'suspension' => 'Suspend for a week',
        'fine'       => 'Fine',
    ];

    private const META_SWEEP = 'quest_ladder_sweep_day';
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_suggestions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id   INTEGER NOT NULL,
                kind        VARCHAR(12) NOT NULL,
                source      VARCHAR(80) NOT NULL,
                reason      TEXT NOT NULL,
                amount      INTEGER NOT NULL DEFAULT 0,
                month       VARCHAR(7) NOT NULL DEFAULT '',
                status      VARCHAR(12) NOT NULL DEFAULT 'open',
                outcome     VARCHAR(80) NOT NULL DEFAULT '',
                decided_by  INTEGER NOT NULL DEFAULT 0,
                decided_at  VARCHAR(32) NOT NULL DEFAULT '',
                note        TEXT,
                created_at  VARCHAR(32) NOT NULL DEFAULT ''
            )");
            Database::ensureIndex($db, 'ux_quest_suggest_once', 'quest_suggestions', 'member_id, kind, source', true);
            Database::ensureIndex($db, 'idx_quest_suggest_status', 'quest_suggestions', 'status, created_at');
        } catch (Throwable $e) {
            error_log('[questladder] ensure: ' . $e->getMessage());
        }
    }

    /**
     * Raise a suggestion. Returns its id, or 0 when it was already raised for
     * this source (not an error) or the kind is switched off.
     */
    public static function suggest(int $memberId, string $kind, string $reason, string $source, int $amount = 0, string $month = ''): int
    {
        if ($memberId <= 0 || !isset(self::KINDS[$kind]) || trim($source) === '') return 0;
        if ($kind === 'demotion' && !AvRules::bool('quest.demotion_suggestions')) return 0;
        self::ensure();
        try {
            $db = Database::pdo();
            $st = $db->prepare(Database::insertIgnore('quest_suggestions',
                ['member_id', 'kind', 'source', 'reason', 'amount', 'month', 'status', 'created_at']));
            $st->execute([$memberId, $kind, mb_substr($source, 0, 80), mb_substr($reason, 0, 2000), max(0, $amount),
                          preg_match('/^\d{4}-\d{2}$/', $month) ? $month : '', 'open', gmdate('Y-m-d H:i:s')]);
            if ($st->rowCount() < 1) return 0;
            $id = (int) $db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[questladder] suggest: ' . $e->getMessage());
            return 0;
        }
        self::tellDeciders($id, $memberId, $kind, $reason);
        return $id;
    }

    public static function find(int $id): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM quest_suggestions WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    /** The queue, with names and current levels, oldest first. One query. */
    public static function queue(string $status = 'open', int $limit = 200): array
    {
        self::ensure();
        try {
            $sql = 'SELECT s.*, u.name AS member_name, u.email AS member_email, u.level AS member_level, d.name AS decided_by_name
                    FROM quest_suggestions s
                    LEFT JOIN lms_users u ON u.id = s.member_id
                    LEFT JOIN lms_users d ON d.id = s.decided_by'
                 . ($status === 'all' ? '' : ' WHERE s.status = ?')
                 . ' ORDER BY ' . ($status === 'open' ? 's.id ASC' : 's.id DESC') . ' LIMIT ' . max(1, min(1000, $limit));
            $st = Database::pdo()->prepare($sql);
            $st->execute($status === 'all' ? [] : [$status]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** @return array<int,array> one member's suggestions, newest first */
    public static function forMember(int $memberId): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM quest_suggestions WHERE member_id = ? ORDER BY id DESC');
            $st->execute([$memberId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** Is this member under an ACCEPTED suspension for that month? */
    public static function suspendedIn(int $memberId, string $month): bool
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare("SELECT 1 FROM quest_suggestions WHERE member_id = ? AND kind = 'suspension' AND status = 'accepted' AND month = ?");
            $st->execute([$memberId, $month]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * A person acts on a suggestion. Never the member it is about.
     */
    public static function accept(int $id, array $by, string $note = ''): array
    {
        $guard = self::guard($id, $by);
        if (isset($guard['error'])) return ['ok' => false, 'error' => $guard['error']];
        $s = $guard['row'];
        $m = (int) $s['member_id'];
        $outcome = '';

        if ($s['kind'] === 'demotion') {
            $cur = Levels::of($m);
            $order = Levels::order();
            $i = array_search($cur, $order, true);
            if ($i === false || $i === 0) {
                return ['ok' => false, 'error' => 'They are already at the first level. Dismiss it with that reason.'];
            }
            $to = (string) $order[$i - 1];
            if (!Levels::set($m, $to, (string) ($by['email'] ?? ('user#' . (int) $by['id'])))) {
                return ['ok' => false, 'error' => 'The level could not be changed.'];
            }
            $outcome = 'level:' . $cur . '>' . $to;
        } elseif ($s['kind'] === 'fine') {
            if (!class_exists('NgvFines') || !Conduct::fineable($m)) {
                return ['ok' => false, 'error' => 'They have no NGV account to owe a fine on. Dismiss it with that reason.'];
            }
            $r = NgvFines::issue($m, 'other', (int) $s['amount'], 'Quest: ' . mb_substr((string) $s['reason'], 0, 200), gmdate('Y-m-d'), true, (int) $by['id']);
            if (empty($r['ok'])) return ['ok' => false, 'error' => 'The fine could not be posted: ' . ($r['error'] ?? 'unknown')];
            $outcome = 'ngv_charge:' . (int) ($r['entryId'] ?? 0);
        } else {
            /* A suspension is the record itself: the reconciliation reads it
               for its month, and the member is told. */
            $outcome = 'suspended:' . ($s['month'] !== '' ? $s['month'] : gmdate('Y-m'));
        }

        if (!self::close($id, 'accepted', $by, $note, $outcome)) return ['ok' => false, 'error' => 'Somebody else decided this a moment ago.'];
        if (class_exists('Notifications')) {
            $title = ['demotion' => 'Your level has changed', 'suspension' => 'You have been suspended for a week', 'fine' => 'A fine has been posted'][$s['kind']];
            try { Notifications::push($m, 'award', $title, mb_substr((string) $s['reason'], 0, 200), '/portal/#quest', 'quest-suggest:' . $id); }
            catch (Throwable $e) { /* best-effort */ }
        }
        self::audit('accept', $id, $s['kind'] . ' → ' . $outcome, (int) $by['id']);
        return ['ok' => true, 'outcome' => $outcome];
    }

    public static function dismiss(int $id, array $by, string $note): array
    {
        $note = trim($note);
        if ($note === '') return ['ok' => false, 'error' => 'Say why — the suggestion stays on the record with your reason.'];
        $guard = self::guard($id, $by);
        if (isset($guard['error'])) return ['ok' => false, 'error' => $guard['error']];
        if (!self::close($id, 'dismissed', $by, $note, '')) return ['ok' => false, 'error' => 'Somebody else decided this a moment ago.'];
        self::audit('dismiss', $id, $note, (int) $by['id']);
        return ['ok' => true];
    }

    /** @return array{row?:array,error?:string} */
    private static function guard(int $id, array $by): array
    {
        if (!class_exists('Conduct') || !Conduct::canDecide($by)) return ['error' => 'Only the compliance committee or a superadmin decides these.'];
        $s = self::find($id);
        if (!$s) return ['error' => 'That suggestion does not exist.'];
        if ($s['status'] !== 'open') return ['error' => 'This was already ' . $s['status'] . '.'];
        if ((int) $by['id'] === (int) $s['member_id']) return ['error' => 'This is about you, so somebody else must decide it.'];
        return ['row' => $s];
    }

    private static function close(int $id, string $status, array $by, string $note, string $outcome): bool
    {
        try {
            $st = Database::pdo()->prepare("UPDATE quest_suggestions SET status = ?, decided_by = ?, decided_at = ?, note = ?, outcome = ?
                                            WHERE id = ? AND status = 'open'");
            $st->execute([$status, (int) $by['id'], gmdate('Y-m-d H:i:s'), mb_substr($note, 0, 2000), $outcome, $id]);
            return $st->rowCount() === 1;
        } catch (Throwable $e) { return false; }
    }

    /* ── Inactivity ────────────────────────────────────────────────────── */

    /**
     * Members above the base level whose last sign of activity — a Quest
     * grant, a gate sign-in, or a mentoring session as mentor or mentee — is
     * older than quest.inactive_days. Three grouped queries, never one per
     * member.
     *
     * @return array<int,string> member id => last active day ('' = never)
     */
    public static function inactive(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        Levels::ensure();
        $cutoff = gmdate('Y-m-d', strtotime($today . ' -' . max(1, AvRules::int('quest.inactive_days')) . ' days'));
        $base = Levels::base();
        $db = Database::pdo();
        try {
            $st = $db->prepare("SELECT id FROM lms_users WHERE level <> ? AND level <> '' AND COALESCE(status, 'active') = 'active'");
            $st->execute([$base]);
            $members = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) { return []; }
        if (!$members) return [];
        $last = array_fill_keys($members, '');
        $in = implode(',', $members);
        $take = static function (string $sql) use ($db, &$last): void {
            try {
                foreach ($db->query($sql) ?: [] as $r) {
                    $m = (int) $r['m']; $d = substr((string) $r['d'], 0, 10);
                    if (isset($last[$m]) && $d > $last[$m]) $last[$m] = $d;
                }
            } catch (Throwable $e) { /* a missing table is no activity */ }
        };
        $take("SELECT member_id AS m, MAX(day) AS d FROM quest_points WHERE points > 0 AND member_id IN ($in) GROUP BY member_id");
        $take("SELECT member_id AS m, MAX(day) AS d FROM gate_attendance WHERE member_id IN ($in) GROUP BY member_id");
        $take("SELECT p.mentor_id AS m, MAX(s.scheduled_at) AS d FROM mentor_sessions s JOIN mentorships p ON p.id = s.mentorship_id
               WHERE p.mentor_id IN ($in) GROUP BY p.mentor_id");
        $take("SELECT p.mentee_id AS m, MAX(s.scheduled_at) AS d FROM mentor_sessions s JOIN mentorships p ON p.id = s.mentorship_id
               WHERE p.mentee_id IN ($in) GROUP BY p.mentee_id");
        return array_filter($last, static fn($d) => $d < $cutoff);
    }

    /** Once a day: suggest a demotion for every inactive member. */
    public static function sweep(bool $force = false): array
    {
        $today = gmdate('Y-m-d');
        if (!$force && Database::metaGet(self::META_SWEEP) === $today) return ['skipped' => 'already ran today'];
        Database::metaSet(self::META_SWEEP, $today);
        if (!AvRules::bool('quest.demotion_suggestions')) return ['raised' => 0, 'off' => true];
        $days = AvRules::int('quest.inactive_days');
        $raised = 0;
        foreach (self::inactive($today) as $m => $lastDay) {
            $why = 'No activity for ' . $days . ' days or more (last: ' . ($lastDay !== '' ? $lastDay : 'none on record')
                 . '). The Pyramid Manual: inactivity for 30 days — loss of current level.';
            if (self::suggest((int) $m, 'demotion', $why, 'inactive:' . substr($today, 0, 7)) > 0) $raised++;
        }
        return ['raised' => $raised];
    }

    /* ── The mentor's reward for a mentee rising ──────────────────────── */

    public static function boot(): void
    {
        if (!class_exists('Events')) return;
        Events::on('member.level.changed', static function (array $p): void {
            try { self::onLevelChanged((int) ($p['user_id'] ?? 0), (string) ($p['from'] ?? ''), (string) ($p['level'] ?? '')); }
            catch (Throwable $e) { error_log('[questladder] level changed: ' . $e->getMessage()); }
        });
    }

    /**
     * A promotion was recorded. If it went UP, each of the member's active
     * Vanguard Quest mentors earns the catalogue's "mentee rises" award, held
     * to their own monthly ceiling, once per mentee per level.
     * @return int how many mentors were credited
     */
    public static function onLevelChanged(int $memberId, string $from, string $to): int
    {
        if ($memberId <= 0 || !AvRules::bool('quest.mentor_reward_on_rise')) return 0;
        $order = Levels::order();
        $a = array_search($from, $order, true); $b = array_search($to, $order, true);
        if ($a === false || $b === false || $b <= $a) return 0;
        $line = QuestRules::usable('award', 'mentee_rises');
        if (!$line) return 0;
        try {
            $st = Database::pdo()->prepare("SELECT DISTINCT mentor_id FROM mentorships WHERE mentee_id = ? AND status = 'active' AND kind = 'vquest'");
            $st->execute([$memberId]);
            $mentors = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) { return 0; }
        $n = 0;
        $today = gmdate('Y-m-d');
        foreach ($mentors as $mentor) {
            if ($mentor === $memberId) continue;
            $pts = Conduct::withinCeiling($mentor, (int) $line['value'], $today);
            if ($pts > 0 && QuestPoints::grant($mentor, $pts, 'mentee-rank:' . $memberId . ':' . $to, 'mentee_rises',
                    'A mentee rose to ' . Levels::labelOf($to), 0, $today)) {
                $n++;
                if (class_exists('Notifications')) {
                    try { Notifications::push($mentor, 'award', 'Your mentee rose a level', $pts . ' Quest points: a mentee you mentor was promoted to ' . Levels::labelOf($to) . '.', '/portal/#quest', 'mentee-rank:' . $memberId . ':' . $to); }
                    catch (Throwable $e) { /* best-effort */ }
                }
            }
        }
        return $n;
    }

    private static function tellDeciders(int $id, int $memberId, string $kind, string $reason): void
    {
        if (!class_exists('Notifications')) return;
        try {
            $ids = array_map('intval', Database::pdo()->query('SELECT user_id FROM compliance_members')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($ids as $uid) {
                if ($uid === $memberId) continue;
                Notifications::push($uid, 'conduct', 'Quest suggestion: ' . strtolower(self::KINDS[$kind]),
                    mb_substr($reason, 0, 200), '/mentorship/compliance/?tab=suggestions', 'quest-suggest:' . $id);
            }
        } catch (Throwable $e) { /* best-effort */ }
    }

    private static function audit(string $action, int $id, string $detail, int $byUid): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('quest', 'suggestion_' . $action, 'suggestion#' . $id, mb_substr($detail, 0, 300), null, 'user#' . $byUid); }
        catch (Throwable $e) { /* best-effort */ }
    }
}
