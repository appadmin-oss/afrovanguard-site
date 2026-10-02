<?php
/**
 * lib/Membership.php — DUES: the paid standing that opens paid courses.
 *
 * Not the same thing as being an Afrovanguard member (MemberRoster::MEMBER_SQL).
 * Anybody with an account can pay dues, a learner included, and paying them
 * changes nobody's role. The table is called `memberships` for history; every
 * screen calls it dues.
 *
 * The `memberships` table has held this since the Academy launched, written by
 * one path only — a Paystack payment (LmsRepository::finalizePayment). Nobody
 * at the office could see it, record dues paid in cash, give a lifetime
 * membership, or end one: the only way a membership changed was money arriving
 * through a gateway. And three readers disagreed about what "a member" was:
 *
 *   · isMember()        active AND unexpired
 *   · the Studio count  status = 'active' — so every lapsed member stayed
 *                       counted for ever
 *   · duesStatus()      the latest row of ANY status, so a cancelled lifetime
 *                       membership read as "active, lifetime"
 *
 * This file is the one definition, and everything else reads it:
 *
 *   STATE  lifetime | current | due_soon | lapsed | cancelled | never
 *     lifetime   an active row with no expiry
 *     current    an active row expiring more than DUE_SOON_DAYS from now
 *     due_soon   an active row expiring within DUE_SOON_DAYS
 *     lapsed     no active unexpired row, but there was one once
 *     cancelled  the most recent row was ended by the office
 *     never      no row at all
 *
 * Every change is a row, never an edit of history: a grant adds one, a
 * cancellation marks the live ones cancelled with who and why. All times are
 * UTC 'Y-m-d H:i:s', the format SQLite's datetime('now') writes and isMember()
 * compares against. (grantMembership wrote LOCAL time until this, so on a
 * server set to Lagos a membership ended an hour after it said it did.)
 */
declare(strict_types=1);

final class Membership
{
    public const DUE_SOON_DAYS = 30;
    public const MAX_MONTHS = 120;
    public const STATES = ['lifetime', 'current', 'due_soon', 'lapsed', 'cancelled', 'never'];
    public const METHODS = ['cash', 'transfer', 'pos', 'cheque', 'waiver', 'other'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return; self::$ready = true;
        $pdo = Database::pdo();
        /* Provenance on the row itself, added where missing so a settled
           deployment heals rather than 500s on the first grant. */
        foreach (['source' => "VARCHAR(16) NOT NULL DEFAULT ''", 'note' => "VARCHAR(500) NOT NULL DEFAULT ''",
                  'granted_by' => "VARCHAR(191) NOT NULL DEFAULT ''", 'ended_at' => "VARCHAR(32) NOT NULL DEFAULT ''",
                  'ended_by' => "VARCHAR(191) NOT NULL DEFAULT ''"] as $col => $type) {
            try { if (!Database::columnExists('memberships', $col)) $pdo->exec('ALTER TABLE memberships ADD COLUMN ' . Database::quoteIdent($col) . ' ' . $type); }
            catch (Throwable $e) { error_log('[membership] ensure ' . $col . ': ' . $e->getMessage()); }
        }
        foreach (['note' => "VARCHAR(500) NOT NULL DEFAULT ''", 'recorded_by' => "VARCHAR(191) NOT NULL DEFAULT ''", 'months' => 'INTEGER NOT NULL DEFAULT 12'] as $col => $type) {
            try { if (!Database::columnExists('payments', $col)) $pdo->exec('ALTER TABLE payments ADD COLUMN ' . Database::quoteIdent($col) . ' ' . $type); }
            catch (Throwable $e) { error_log('[membership] ensure payments.' . $col . ': ' . $e->getMessage()); }
        }
    }

    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function lms(): LmsRepository { return new LmsRepository(); }

    private static function email(int $userId): string
    {
        $st = Database::pdo()->prepare('SELECT email FROM lms_users WHERE id = ?'); $st->execute([$userId]);
        return strtolower((string) $st->fetchColumn());
    }

    /* ══ Reading ════════════════════════════════════════════════════════════ */

    /** The live row: active and unexpired, lifetime first, then the latest expiry. */
    public static function live(int $userId): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare("SELECT * FROM memberships WHERE user_id = ? AND status = 'active'
                AND (expires_at IS NULL OR expires_at > ?) ORDER BY (expires_at IS NULL) DESC, expires_at DESC, id DESC LIMIT 1");
        $st->execute([$userId, self::now()]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** One member's membership, as the drawer shows it. */
    public static function summary(int $userId): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $live = self::live($userId);
        $st = $pdo->prepare('SELECT * FROM memberships WHERE user_id = ? ORDER BY id DESC LIMIT 1'); $st->execute([$userId]);
        $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = $pdo->prepare("SELECT MAX(expires_at) FROM memberships WHERE user_id = ? AND status = 'active'"); $st->execute([$userId]);
        $lastExpiry = (string) ($st->fetchColumn() ?: '');

        $daysLeft = null;
        if ($live && $live['expires_at'] !== null) $daysLeft = (int) floor((strtotime($live['expires_at'] . ' UTC') - time()) / 86400);
        if ($live)                                           $state = $live['expires_at'] === null ? 'lifetime' : ($daysLeft <= self::DUE_SOON_DAYS ? 'due_soon' : 'current');
        elseif (!$last)                                      $state = 'never';
        elseif ((string) $last['status'] === 'cancelled')    $state = 'cancelled';
        else                                                 $state = 'lapsed';

        $st = $pdo->prepare("SELECT COALESCE(SUM(amount_kobo), 0) k, COUNT(*) n, MAX(paid_at) last FROM payments WHERE user_id = ? AND kind = 'membership' AND status = 'paid'");
        $st->execute([$userId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC) ?: ['k' => 0, 'n' => 0, 'last' => null];
        return [
            'state'         => $state,
            'member'        => in_array($state, ['lifetime', 'current', 'due_soon'], true),
            'paid_through'  => $live['expires_at'] ?? ($state === 'lapsed' ? ($lastExpiry ?: null) : null),
            'days_left'     => $daysLeft,
            'since'         => self::firstStart($userId),
            'ended'         => $state === 'cancelled' ? ['at' => (string) $last['ended_at'], 'by' => (string) $last['ended_by'], 'note' => (string) $last['note']] : null,
            'total_paid_ngn' => (int) round(((int) $pay['k']) / 100),
            'payments'      => (int) $pay['n'],
            'last_paid_at'  => $pay['last'] ?: null,
        ];
    }

    private static function firstStart(int $userId): ?string
    {
        $st = Database::pdo()->prepare('SELECT MIN(started_at) FROM memberships WHERE user_id = ?'); $st->execute([$userId]);
        return ($v = $st->fetchColumn()) ? (string) $v : null;
    }

    /** Every membership row and every dues payment, newest first: what happened, by whom. */
    public static function history(int $userId): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, tier, status, started_at, expires_at, source, note, granted_by, ended_at, ended_by FROM memberships WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT reference, provider, amount_kobo, status, created_at, paid_at, months, note, recorded_by FROM payments WHERE user_id = ? AND kind = 'membership' ORDER BY id DESC LIMIT 100");
        $st->execute([$userId]);
        $pays = array_map(static fn($p) => $p + ['amount_ngn' => (int) round(((int) $p['amount_kobo']) / 100)], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['memberships' => $rows, 'payments' => $pays];
    }

    /**
     * SQL over `lms_users u` selecting members in one state. The roster's
     * filter and the dashboard's count both come from here, so the number on
     * the dashboard is the length of the list it opens.
     *
     * @return array{0:string,1:array}|null
     */
    public static function stateSql(string $state): ?array
    {
        self::ensure();
        $now = self::now();
        $soon = gmdate('Y-m-d H:i:s', time() + self::DUE_SOON_DAYS * 86400);
        $liveAny = "EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.status = 'active' AND (m.expires_at IS NULL OR m.expires_at > ?))";
        $lifetime = "EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.status = 'active' AND m.expires_at IS NULL)";
        $beyond = "EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.status = 'active' AND m.expires_at > ?)";
        $any = 'EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id)';
        $lastCancelled = "(SELECT m.status FROM memberships m WHERE m.user_id = u.id ORDER BY m.id DESC LIMIT 1) = 'cancelled'";
        switch ($state) {
            case 'member':    return [$liveAny, [$now]];
            case 'lifetime':  return [$lifetime, []];
            case 'current':   return ["NOT $lifetime AND $beyond", [$soon]];
            case 'due_soon':  return ["NOT $lifetime AND $liveAny AND NOT $beyond", [$now, $soon]];
            case 'lapsed':    return ["$any AND NOT $liveAny AND NOT $lastCancelled", [$now]];
            case 'cancelled': return ["NOT $liveAny AND $lastCancelled", [$now]];
            case 'never':     return ["NOT $any", []];
        }
        return null;
    }

    /** How many ACCOUNTS are in each dues state, plus "member" (dues live now).
     *  Over every account — learners pay dues too — so it is not a count of
     *  Afrovanguard members, and the screens that show it say "dues". */
    public static function counts(): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $out = [];
        foreach (array_merge(['member'], self::STATES) as $s) {
            [$sql, $a] = self::stateSql($s);
            $st = $pdo->prepare("SELECT COUNT(*) FROM lms_users u WHERE $sql"); $st->execute($a);
            $out[$s] = (int) $st->fetchColumn();
        }
        return $out;
    }

    /* ══ Changing ═══════════════════════════════════════════════════════════ */

    /**
     * Give a member N months, from the later of now and their current
     * paid-through date — paying early never forfeits time already paid for.
     *
     * With a payment (amount_ngn > 0, method), the money is recorded as a paid
     * `payments` row first, so "total dues paid" and the receipt trail include
     * money the office took in person. A waiver records no money and says so.
     *
     * opts: months, amount_ngn, method, reference, note, actor
     */
    public static function grant(int $userId, array $opts): array
    {
        self::ensure();
        if (self::email($userId) === '') return ['ok' => false, 'error' => 'No such member.'];
        $months = (int) ($opts['months'] ?? 12);
        if ($months < 1 || $months > self::MAX_MONTHS) return ['ok' => false, 'error' => 'Give between 1 and ' . self::MAX_MONTHS . ' months.'];
        $live = self::live($userId);
        if ($live && $live['expires_at'] === null) return ['ok' => false, 'error' => 'They already have a lifetime membership.', 'code' => 'lifetime'];
        $amount = (int) ($opts['amount_ngn'] ?? 0);
        $method = strtolower(trim((string) ($opts['method'] ?? '')));
        $note = mb_substr(trim((string) ($opts['note'] ?? '')), 0, 500);
        $actor = mb_substr((string) ($opts['actor'] ?? 'studio'), 0, 191);
        if ($amount < 0) return ['ok' => false, 'error' => 'An amount cannot be negative.'];
        if ($amount > 0 && !in_array($method, self::METHODS, true)) return ['ok' => false, 'error' => 'Say how it was paid: ' . implode(', ', self::METHODS) . '.'];
        if ($amount === 0 && $method !== 'waiver' && $note === '') return ['ok' => false, 'error' => 'Months with no payment need a reason — mark it a waiver, or say why.', 'code' => 'reason_required'];

        $pdo = Database::pdo();
        $ref = '';
        if ($amount > 0) {
            $ref = trim((string) ($opts['reference'] ?? ''));
            $ref = $ref !== '' ? 'OFF-' . preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr($ref, 0, 60)) : 'OFF-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4));
            $st = $pdo->prepare('SELECT 1 FROM payments WHERE reference = ?'); $st->execute([$ref]);
            if ($st->fetchColumn()) return ['ok' => false, 'error' => 'A payment with reference ' . $ref . ' is already recorded.', 'code' => 'duplicate_reference'];
        }

        /* Extend from the later of now and what they already have. */
        $base = time();
        if ($live && $live['expires_at'] !== null) $base = max($base, (int) strtotime($live['expires_at'] . ' UTC'));
        $expires = gmdate('Y-m-d H:i:s', (int) strtotime("+$months months", $base));
        $source = $amount > 0 ? 'offline' : ($method === 'waiver' ? 'waiver' : 'admin');

        $pdo->beginTransaction();
        try {
            if ($amount > 0) {
                $pdo->prepare("INSERT INTO payments (reference, user_id, provider, kind, amount_kobo, status, paid_at, months, note, recorded_by) VALUES (?, ?, ?, 'membership', ?, 'paid', ?, ?, ?, ?)")
                    ->execute([$ref, $userId, 'offline:' . $method, $amount * 100, self::now(), $months, $note, $actor]);
            }
            $pdo->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at, source, note, granted_by) VALUES (?, 'member', 'active', ?, ?, ?, ?, ?)")
                ->execute([$userId, self::now(), $expires, $source, $note, $actor]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'The membership was not saved: ' . $e->getMessage()];
        }
        self::lms()->audit('membership.grant', self::email($userId),
            $months . ' month' . ($months === 1 ? '' : 's') . ' → ' . substr($expires, 0, 10)
            . ($amount > 0 ? ' · ₦' . number_format($amount) . ' by ' . $method . ' (' . $ref . ')' : ' · ' . ($source === 'waiver' ? 'waived' : 'no payment'))
            . ($note !== '' ? ' · ' . $note : ''), $actor);
        return ['ok' => true, 'expires_at' => $expires, 'reference' => $ref ?: null, 'membership' => self::summary($userId)];
    }

    /** A membership that does not expire. A reason is required: it is a gift of every future year's dues. */
    public static function lifetime(int $userId, string $note, string $actor): array
    {
        self::ensure();
        if (self::email($userId) === '') return ['ok' => false, 'error' => 'No such member.'];
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') return ['ok' => false, 'error' => 'Say why this membership never expires.', 'code' => 'reason_required'];
        $live = self::live($userId);
        if ($live && $live['expires_at'] === null) return ['ok' => true, 'unchanged' => true, 'membership' => self::summary($userId)];
        Database::pdo()->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at, source, note, granted_by) VALUES (?, 'member', 'active', ?, NULL, 'lifetime', ?, ?)")
            ->execute([$userId, self::now(), $note, mb_substr($actor, 0, 191)]);
        self::lms()->audit('membership.lifetime', self::email($userId), $note, $actor);
        return ['ok' => true, 'membership' => self::summary($userId)];
    }

    /**
     * End a membership now. Every live row is marked cancelled — with who and
     * why — rather than deleted, so the history still says they were a member.
     * Money already paid is not touched: a refund is a separate decision.
     */
    public static function cancel(int $userId, string $note, string $actor): array
    {
        self::ensure();
        if (self::email($userId) === '') return ['ok' => false, 'error' => 'No such member.'];
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') return ['ok' => false, 'error' => 'Say why the membership is ending.', 'code' => 'reason_required'];
        if (!self::live($userId)) return ['ok' => true, 'unchanged' => true, 'membership' => self::summary($userId)];
        $st = Database::pdo()->prepare("UPDATE memberships SET status = 'cancelled', ended_at = ?, ended_by = ?, note = ? WHERE user_id = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > ?)");
        $st->execute([self::now(), mb_substr($actor, 0, 191), $note, $userId, self::now()]);
        self::lms()->audit('membership.cancel', self::email($userId), $note, $actor);
        return ['ok' => true, 'ended' => $st->rowCount(), 'membership' => self::summary($userId)];
    }

    /**
     * Undo a cancellation: the rows the most recent cancellation ended, and
     * that would still be running, are live again. Nothing past its expiry is
     * revived — that membership ran out on its own and needs dues.
     */
    public static function reinstate(int $userId, string $actor): array
    {
        self::ensure();
        if (self::email($userId) === '') return ['ok' => false, 'error' => 'No such member.'];
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT MAX(ended_at) FROM memberships WHERE user_id = ? AND status = 'cancelled'"); $st->execute([$userId]);
        $at = (string) ($st->fetchColumn() ?: '');
        if ($at === '') return ['ok' => false, 'error' => 'There is no cancelled membership to reinstate.', 'code' => 'nothing_cancelled'];
        $st = $pdo->prepare("UPDATE memberships SET status = 'active', ended_at = '', ended_by = '' WHERE user_id = ? AND status = 'cancelled' AND ended_at = ? AND (expires_at IS NULL OR expires_at > ?)");
        $st->execute([$userId, $at, self::now()]);
        if ($st->rowCount() === 0) return ['ok' => false, 'error' => 'That membership has run out since it was cancelled. Record their dues instead.', 'code' => 'expired_since'];
        self::lms()->audit('membership.reinstate', self::email($userId), 'cancellation of ' . substr($at, 0, 10) . ' undone', $actor);
        return ['ok' => true, 'membership' => self::summary($userId)];
    }
}
