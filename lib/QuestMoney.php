<?php
/**
 * lib/QuestMoney.php — the Pyramid Manual's Section 3: points against fines,
 * month by month, by income bracket.
 *
 *   1. All fines are monetary and vary according to each member's monthly
 *      income category.
 *   2. All awards are expressed in points.
 *   3. At the end of every month, the points each member earned are
 *      monetised by their income bracket,
 *   4. and offset against the fines of the same period.
 *   5. A balance left over is paid within two weeks.
 *   6. Otherwise: a one-week suspension and ₦2,000 more.
 *   8. A percentage of collected fines goes to the Reward Pool.
 *
 * WHAT IS NOT SUPPLIED, AND SO NOT INVENTED. The manual names income brackets
 * and gives none; it monetises a point and does not say for how much; it
 * reserves "a percentage" for the pool. So brackets are DATA, entered on the
 * Quest desk — a label, the naira value of one point, and how the bracket
 * scales the manual's base fines — and until at least one exists and
 * quest.money_enabled is switched on, nothing here moves money.
 *
 * HOW MONEY MOVES, AND HOW IT DOES NOT.
 *   · Fines stay on the NGV ledger, where every fine already lives.
 *   · The offset is a WAIVER on the member's fines line, with the month, the
 *     points and the rate in its reason — the ledger's own word for "owed and
 *     not being asked for", clamped by the ledger to what is outstanding. No
 *     new credit kind, so no report that sums credits reads it differently.
 *   · Rule 6 is not imposed. The overdue sweep raises a suspension and the
 *     ₦2,000 fine as SUGGESTIONS (QuestLadder) for the committee to accept.
 *   · A member under an accepted suspension that month loses that month's
 *     incentive: their points are not monetised (the manual's suspension
 *     policy).
 *
 * Earnings by level (the manual's share of CACENTRE service charges) are in
 * the registry (Quest::rank()['earning']) and shown; computing them waits on
 * the owner's next document, and nothing here pretends otherwise.
 */
declare(strict_types=1);

final class QuestMoney
{
    private const META_SWEEP = 'quest_money_sweep_day';
    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            $db = Database::pdo();
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_income_brackets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                label       VARCHAR(80) NOT NULL,
                min_income  INTEGER NOT NULL DEFAULT 0,
                max_income  INTEGER,
                point_value INTEGER NOT NULL DEFAULT 0,
                fine_pct    INTEGER NOT NULL DEFAULT 100,
                created_at  VARCHAR(32) NOT NULL DEFAULT '',
                updated_at  VARCHAR(32) NOT NULL DEFAULT ''
            )");
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_member_brackets (
                member_id  INTEGER PRIMARY KEY,
                bracket_id INTEGER NOT NULL,
                set_by     INTEGER NOT NULL DEFAULT 0,
                set_at     VARCHAR(32) NOT NULL DEFAULT ''
            )");
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_reconciliations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                month       VARCHAR(7) NOT NULL,
                member_id   INTEGER NOT NULL,
                bracket_id  INTEGER NOT NULL DEFAULT 0,
                points      INTEGER NOT NULL DEFAULT 0,
                point_value INTEGER NOT NULL DEFAULT 0,
                credit      INTEGER NOT NULL DEFAULT 0,
                fines       INTEGER NOT NULL DEFAULT 0,
                offset_amt  INTEGER NOT NULL DEFAULT 0,
                balance     INTEGER NOT NULL DEFAULT 0,
                due_on      VARCHAR(10) NOT NULL DEFAULT '',
                status      VARCHAR(12) NOT NULL DEFAULT 'open',
                applied_by  INTEGER NOT NULL DEFAULT 0,
                created_at  VARCHAR(32) NOT NULL DEFAULT '',
                closed_at   VARCHAR(32) NOT NULL DEFAULT ''
            )");
            Database::ensureIndex($db, 'ux_quest_recon_once', 'quest_reconciliations', 'month, member_id', true);
            Database::execSchema($db, "CREATE TABLE IF NOT EXISTS quest_reward_pool (
                month       VARCHAR(7) PRIMARY KEY,
                collected   INTEGER NOT NULL DEFAULT 0,
                pct         INTEGER NOT NULL DEFAULT 0,
                amount      INTEGER NOT NULL DEFAULT 0,
                computed_at VARCHAR(32) NOT NULL DEFAULT ''
            )");
        } catch (Throwable $e) {
            error_log('[questmoney] ensure: ' . $e->getMessage());
        }
    }

    /** Who may set brackets and run the month: the people who decide fines. */
    public static function canRun(?array $user): bool
    {
        return class_exists('Conduct') && Conduct::canDecide($user);
    }

    /** Switched on, and something to monetise with. */
    public static function ready(): bool
    {
        if (!AvRules::bool('quest.money_enabled')) return false;
        foreach (self::brackets() as $b) if ((int) $b['point_value'] > 0) return true;
        return false;
    }

    /* ── Brackets ──────────────────────────────────────────────────────── */

    /** @return array<int,array> lowest income first */
    public static function brackets(): array
    {
        self::ensure();
        try {
            $rows = Database::pdo()->query('SELECT b.*, (SELECT COUNT(*) FROM quest_member_brackets m WHERE m.bracket_id = b.id) AS members
                                            FROM quest_income_brackets b ORDER BY b.min_income, b.id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** Add or edit a bracket. $in: id?, label, min_income, max_income?, point_value, fine_pct */
    public static function saveBracket(array $in, array $by): array
    {
        if (!self::canRun($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin sets income brackets.'];
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 80);
        if ($label === '') return ['ok' => false, 'error' => 'Name the bracket.'];
        $min = max(0, (int) preg_replace('/\D+/', '', (string) ($in['min_income'] ?? '0')));
        $maxRaw = trim((string) ($in['max_income'] ?? ''));
        $max = $maxRaw === '' ? null : (int) preg_replace('/\D+/', '', $maxRaw);
        if ($max !== null && $max < $min) return ['ok' => false, 'error' => 'The top of the bracket is below its bottom.'];
        $pv = (int) preg_replace('/\D+/', '', (string) ($in['point_value'] ?? '0'));
        if ($pv < 0 || $pv > 100000) return ['ok' => false, 'error' => 'A point is worth between ₦0 and ₦100,000.'];
        $fp = (int) ($in['fine_pct'] ?? 100);
        if ($fp < 0 || $fp > 1000) return ['ok' => false, 'error' => 'The fine scale is a percentage of the manual\'s base, 0 to 1000.'];
        self::ensure();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $db = Database::pdo();
            $id = (int) ($in['id'] ?? 0);
            if ($id > 0) {
                $st = $db->prepare('UPDATE quest_income_brackets SET label = ?, min_income = ?, max_income = ?, point_value = ?, fine_pct = ?, updated_at = ? WHERE id = ?');
                $st->execute([$label, $min, $max, $pv, $fp, $now, $id]);
                if ($st->rowCount() < 1) return ['ok' => false, 'error' => 'No such bracket.'];
            } else {
                $db->prepare('INSERT INTO quest_income_brackets (label, min_income, max_income, point_value, fine_pct, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$label, $min, $max, $pv, $fp, $now, $now]);
                $id = (int) $db->lastInsertId();
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'That could not be saved.'];
        }
        self::audit('bracket', 'bracket#' . $id, $label . ': 1 pt = ₦' . $pv . ', fines ' . $fp . '%', (int) $by['id']);
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteBracket(int $id, array $by): array
    {
        if (!self::canRun($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin sets income brackets.'];
        self::ensure();
        $b = self::brackets()[$id] ?? null;
        if (!$b) return ['ok' => false, 'error' => 'No such bracket.'];
        if ((int) $b['members'] > 0) return ['ok' => false, 'error' => 'Move its ' . (int) $b['members'] . ' member(s) to another bracket first.'];
        Database::pdo()->prepare('DELETE FROM quest_income_brackets WHERE id = ?')->execute([$id]);
        self::audit('bracket_delete', 'bracket#' . $id, (string) $b['label'], (int) $by['id']);
        return ['ok' => true];
    }

    /** Place a member in a bracket (0 removes them from every bracket). */
    public static function setMemberBracket(int $memberId, int $bracketId, array $by): array
    {
        if (!self::canRun($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin places members in brackets.'];
        if ($memberId <= 0) return ['ok' => false, 'error' => 'No such member.'];
        if ((int) $by['id'] === $memberId) return ['ok' => false, 'error' => 'Somebody else must set your own bracket.'];
        self::ensure();
        $db = Database::pdo();
        if ($bracketId === 0) {
            $db->prepare('DELETE FROM quest_member_brackets WHERE member_id = ?')->execute([$memberId]);
        } else {
            if (!isset(self::brackets()[$bracketId])) return ['ok' => false, 'error' => 'No such bracket.'];
            $now = gmdate('Y-m-d H:i:s');
            $db->prepare(Database::insertIgnore('quest_member_brackets', ['member_id', 'bracket_id', 'set_by', 'set_at']))
               ->execute([$memberId, $bracketId, (int) $by['id'], $now]);
            $db->prepare('UPDATE quest_member_brackets SET bracket_id = ?, set_by = ?, set_at = ? WHERE member_id = ?')
               ->execute([$bracketId, (int) $by['id'], $now, $memberId]);
        }
        self::audit('member_bracket', 'member#' . $memberId, 'bracket#' . $bracketId, (int) $by['id']);
        return ['ok' => true];
    }

    public static function bracketOf(int $memberId): ?array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT b.* FROM quest_member_brackets m JOIN quest_income_brackets b ON b.id = m.bracket_id WHERE m.member_id = ?');
            $st->execute([$memberId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * The manual's base fine, scaled to the member's bracket. The base when
     * they have none — which is everybody until brackets are set.
     */
    public static function scaleFine(int $memberId, int $base): int
    {
        if ($base <= 0) return $base;
        $b = self::bracketOf($memberId);
        if (!$b) return $base;
        return (int) round($base * max(0, (int) $b['fine_pct']) / 100);
    }

    /* ── The month ─────────────────────────────────────────────────────── */

    /**
     * What the month would do, without doing it. One row per member with a
     * bracket who earned points or was fined in the month; members with
     * activity but no bracket are listed separately, because skipping them
     * silently would read as "nothing owed".
     *
     * @return array{month:string, rows:array, unplaced:array, totals:array, applied:bool}
     */
    public static function preview(string $month): array
    {
        $out = ['month' => $month, 'rows' => [], 'unplaced' => [], 'totals' => ['credit' => 0, 'fines' => 0, 'offset' => 0, 'balance' => 0], 'applied' => false];
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) return $out;
        self::ensure();
        $db = Database::pdo();

        /* Net points per member that month — what was earned, less what was
           deducted. One grouped query. */
        $points = [];
        try {
            $st = $db->prepare('SELECT member_id, SUM(points) AS p FROM quest_points WHERE day LIKE ? GROUP BY member_id');
            $st->execute([$month . '-%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $points[(int) $r['member_id']] = max(0, (int) $r['p']);
        } catch (Throwable $e) { /* none */ }

        /* Fines that happened that month, and what of them is still owed. */
        $fines = []; $owing = [];
        if (class_exists('NgvFines')) {
            try {
                foreach (NgvFines::all(['from' => $month . '-01', 'to' => $month . '-31']) as $f) {
                    if ($f['status'] === 'voided') continue;
                    $m = (int) $f['member_id'];
                    $fines[$m] = ($fines[$m] ?? 0) + (int) $f['amount'];
                    $owing[$m] = ($owing[$m] ?? 0) + (int) $f['owing'];
                }
            } catch (Throwable $e) { /* none */ }
        }

        $done = [];
        try {
            $st = $db->prepare('SELECT member_id FROM quest_reconciliations WHERE month = ?');
            $st->execute([$month]);
            $done = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
        } catch (Throwable $e) { /* none */ }
        $out['applied'] = $done !== [];

        $members = array_unique(array_merge(array_keys($points), array_keys($fines)));
        $placed = [];
        if ($members) {
            try {
                $in = implode(',', array_map('intval', $members));
                foreach ($db->query("SELECT m.member_id, m.bracket_id, u.name FROM quest_member_brackets m LEFT JOIN lms_users u ON u.id = m.member_id WHERE m.member_id IN ($in)") ?: [] as $r) {
                    $placed[(int) $r['member_id']] = ['bracket' => (int) $r['bracket_id'], 'name' => (string) $r['name']];
                }
                $names = [];
                foreach ($db->query("SELECT id, name FROM lms_users WHERE id IN ($in)") ?: [] as $r) $names[(int) $r['id']] = (string) $r['name'];
            } catch (Throwable $e) { $names = []; }
        }
        $brackets = self::brackets();

        foreach ($members as $m) {
            $pts = $points[$m] ?? 0;
            $fn = $fines[$m] ?? 0;
            $ow = $owing[$m] ?? 0;
            if (!isset($placed[$m]) || !isset($brackets[$placed[$m]['bracket']])) {
                $out['unplaced'][] = ['member_id' => $m, 'name' => $names[$m] ?? ('#' . $m), 'points' => $pts, 'fines' => $fn];
                continue;
            }
            $b = $brackets[$placed[$m]['bracket']];
            $suspended = class_exists('QuestLadder') && QuestLadder::suspendedIn($m, $month);
            $pv = $suspended ? 0 : (int) $b['point_value'];
            $credit = $pts * $pv;
            $offset = min($credit, $ow);
            $row = [
                'member_id' => $m, 'name' => $placed[$m]['name'] !== '' ? $placed[$m]['name'] : ($names[$m] ?? ('#' . $m)),
                'bracket_id' => (int) $b['id'], 'bracket' => (string) $b['label'], 'points' => $pts, 'point_value' => $pv,
                'credit' => $credit, 'fines' => $fn, 'owing' => $ow, 'offset' => $offset, 'balance' => $ow - $offset,
                'suspended' => $suspended, 'applied' => isset($done[$m]),
            ];
            $out['rows'][] = $row;
            $out['totals']['credit'] += $credit; $out['totals']['fines'] += $fn;
            $out['totals']['offset'] += $offset; $out['totals']['balance'] += $row['balance'];
        }
        usort($out['rows'], static fn($a, $b) => $b['balance'] <=> $a['balance'] ?: strcmp($a['name'], $b['name']));
        $out['pool'] = self::poolFor($month);
        return $out;
    }

    /**
     * Run the month: offset each member's fines by their points' value, as a
     * waiver on the NGV fines line, and record a reconciliation row with the
     * balance and the day it is due. Once per member per month.
     */
    public static function apply(string $month, array $by): array
    {
        if (!self::canRun($by)) return ['ok' => false, 'error' => 'Only the compliance committee or a superadmin runs the month.'];
        if (!AvRules::bool('quest.money_enabled')) return ['ok' => false, 'error' => 'The reconciliation is switched off.'];
        if (!self::ready()) return ['ok' => false, 'error' => 'Set at least one income bracket with a value for a point first.'];
        if (!preg_match('/^\d{4}-\d{2}$/', $month) || $month >= gmdate('Y-m')) return ['ok' => false, 'error' => 'Only a month that has ended can be run.'];
        $p = self::preview($month);
        $due = gmdate('Y-m-d', strtotime('+' . max(1, AvRules::int('quest.money_settle_days')) . ' days'));
        $now = gmdate('Y-m-d H:i:s');
        $db = Database::pdo();
        $ran = 0; $offset = 0; $failed = [];
        foreach ($p['rows'] as $r) {
            if ($r['applied']) continue;
            $given = 0;
            if ($r['offset'] > 0 && class_exists('NgvLedger')) {
                $w = NgvLedger::waive((int) $r['member_id'], 'fine', $r['offset'],
                    'Quest points offset for ' . $month . ': ' . $r['points'] . ' pts × ₦' . number_format($r['point_value']) . ' (' . $r['bracket'] . ')', (int) $by['id']);
                if (empty($w['ok'])) { $failed[] = $r['name'] . ': ' . ($w['error'] ?? 'not recorded'); continue; }
                $given = (int) ($w['waived'] ?? $r['offset']);
            }
            $balance = max(0, $r['owing'] - $given);
            try {
                $db->prepare(Database::insertIgnore('quest_reconciliations',
                    ['month', 'member_id', 'bracket_id', 'points', 'point_value', 'credit', 'fines', 'offset_amt', 'balance', 'due_on', 'status', 'applied_by', 'created_at']))
                   ->execute([$month, $r['member_id'], $r['bracket_id'], $r['points'], $r['point_value'], $r['credit'], $r['fines'], $given, $balance,
                              $balance > 0 ? $due : '', $balance > 0 ? 'open' : 'settled', (int) $by['id'], $now]);
            } catch (Throwable $e) { $failed[] = $r['name'] . ': not recorded'; continue; }
            $ran++; $offset += $given;
            if (class_exists('Notifications')) {
                $msg = $r['points'] . ' points at ₦' . number_format($r['point_value']) . ' — ₦' . number_format($given) . ' taken off your fines.'
                     . ($balance > 0 ? ' ₦' . number_format($balance) . ' is left to pay by ' . $due . '.' : ' Nothing is left to pay.');
                try { Notifications::push((int) $r['member_id'], 'award', 'Your Quest month: ' . $month, $msg, '/portal/#quest', 'quest-month:' . $month); }
                catch (Throwable $e) { /* best-effort */ }
            }
        }
        self::computePool($month);
        self::audit('apply', 'month:' . $month, $ran . ' member(s), ₦' . number_format($offset) . ' offset' . ($failed ? ', ' . count($failed) . ' failed' : ''), (int) $by['id']);
        return ['ok' => true, 'ran' => $ran, 'offset' => $offset, 'failed' => $failed];
    }

    /** @return array<int,array> a member's months, newest first */
    public static function forMember(int $memberId): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM quest_reconciliations WHERE member_id = ? ORDER BY month DESC');
            $st->execute([$memberId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * Once a day: an open balance past its day is overdue. If the fines are
     * still owed, the manual's consequences are SUGGESTED to the committee —
     * a week's suspension, and the overdue fine — and the row is marked;
     * if they have been paid since, it is closed as settled.
     */
    public static function sweep(bool $force = false): array
    {
        $today = gmdate('Y-m-d');
        if (!$force && Database::metaGet(self::META_SWEEP) === $today) return ['skipped' => 'already ran today'];
        Database::metaSet(self::META_SWEEP, $today);
        if (!AvRules::bool('quest.money_enabled')) return ['off' => true];
        self::ensure();
        $db = Database::pdo();
        $overdue = 0; $settled = 0;
        try {
            $st = $db->prepare("SELECT * FROM quest_reconciliations WHERE status = 'open' AND due_on <> '' AND due_on < ?");
            $st->execute([$today]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return ['error' => 'could not read']; }
        $fine = AvRules::int('quest.money_overdue_fine');
        foreach ($rows as $r) {
            $m = (int) $r['member_id'];
            $still = class_exists('NgvFines') ? (int) NgvFines::forMember($m)['owing'] : 0;
            if ($still <= 0) {
                $db->prepare("UPDATE quest_reconciliations SET status = 'settled', closed_at = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s'), (int) $r['id']]);
                $settled++;
                continue;
            }
            $db->prepare("UPDATE quest_reconciliations SET status = 'overdue' WHERE id = ?")->execute([(int) $r['id']]);
            $why = 'The balance of ₦' . number_format((int) $r['balance']) . ' from ' . $r['month'] . ' was due on ' . $r['due_on']
                 . ' and ₦' . number_format($still) . ' in fines is still owed. The Pyramid Manual, Section 3.6.';
            if (class_exists('QuestLadder')) {
                QuestLadder::suggest($m, 'suspension', $why, 'overdue:' . $r['month'], 0, substr($today, 0, 7));
                if ($fine > 0) QuestLadder::suggest($m, 'fine', $why . ' Overdue fine: ₦' . number_format($fine) . '.', 'overdue-fine:' . $r['month'], $fine);
            }
            $overdue++;
        }
        return ['overdue' => $overdue, 'settled' => $settled];
    }

    /* ── The Reward Pool ───────────────────────────────────────────────── */

    /** Fines actually paid in a month, and the pool's share of them. */
    public static function computePool(string $month): array
    {
        $pct = AvRules::int('quest.reward_pool_pct');
        $collected = 0;
        if (class_exists('NgvDb')) {
            try {
                $st = NgvDb::pdo()->prepare("SELECT COALESCE(SUM(amount), 0) FROM ngv_payments WHERE kind = 'fine' AND credit_kind = 'payment' AND voided = 0 AND created_at LIKE ?");
                $st->execute([$month . '-%']);
                $collected = (int) $st->fetchColumn();
            } catch (Throwable $e) { /* none */ }
        }
        $amount = (int) floor($collected * $pct / 100);
        self::ensure();
        try {
            $db = Database::pdo();
            $db->prepare(Database::insertIgnore('quest_reward_pool', ['month', 'collected', 'pct', 'amount', 'computed_at']))
               ->execute([$month, $collected, $pct, $amount, gmdate('Y-m-d H:i:s')]);
            $db->prepare('UPDATE quest_reward_pool SET collected = ?, pct = ?, amount = ?, computed_at = ? WHERE month = ?')
               ->execute([$collected, $pct, $amount, gmdate('Y-m-d H:i:s'), $month]);
        } catch (Throwable $e) { /* best-effort */ }
        return ['month' => $month, 'collected' => $collected, 'pct' => $pct, 'amount' => $amount];
    }

    public static function poolFor(string $month): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM quest_reward_pool WHERE month = ?');
            $st->execute([$month]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['month' => $month, 'collected' => (int) $r['collected'], 'pct' => (int) $r['pct'], 'amount' => (int) $r['amount']];
        } catch (Throwable $e) { /* none */ }
        return ['month' => $month, 'collected' => 0, 'pct' => AvRules::int('quest.reward_pool_pct'), 'amount' => 0];
    }

    /** The pool across every month recorded. */
    public static function poolTotal(): int
    {
        self::ensure();
        try { return (int) Database::pdo()->query('SELECT COALESCE(SUM(amount), 0) FROM quest_reward_pool')->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }

    private static function audit(string $action, string $subject, string $detail, int $byUid): void
    {
        if (!class_exists('AdminAudit')) return;
        try { AdminAudit::log('quest', 'money_' . $action, $subject, mb_substr($detail, 0, 300), null, 'user#' . $byUid); }
        catch (Throwable $e) { /* best-effort */ }
    }
}
