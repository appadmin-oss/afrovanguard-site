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
 * WHO OWES DUES, AND HOW MUCH. Paying dues used to be the only thing that
 * happened here: nothing said an Afrovanguard member OWED them, so a member
 * who never paid read "never paid" for ever and owed nothing, and one who
 * lapsed in March and renewed in October was given a year from October —
 * the seven months between were simply forgiven. Dues are an obligation now:
 *
 *   OBLIGED   an Afrovanguard member (MemberRoster::MEMBER_SQL), active, at a
 *             level that owes (settings: from_level up), who is not a NextGen
 *             Vanguard (their membership is on the NGV account — charging it
 *             here too would bill them twice), and whose dues the office has
 *             not ended (cancelled) or made lifetime.
 *   OWES      from the later of the day dues began (settings: start), the day
 *             they joined and the day their paid dues ran out, one month's
 *             dues falls due on that day and on the same day every month
 *             after. Arrears = the months fallen due × the price.
 *   PAYING    covers the OLDEST month owed first: a grant extends from that
 *             day, not from today. Paying a year on 23 October for somebody
 *             paid through 10 March pays them through 10 March next year.
 *
 * Computed when read, never posted by a timer: nothing has to run for the
 * figure to be right, and there is no charge table to drift from the
 * membership rows. Prices and the rules are the office's (Studio → Members →
 * Dues), not environment constants a deployment has to be edited for.
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
    public const SETTINGS_KEY = 'av_dues_settings';
    /** Arrears are counted to here and no further: a bad start date must not produce a four-figure month count. */
    public const ARREARS_MAX_MONTHS = 240;
    public const PRICE_MAX_NGN = 10000000;

    private static bool $ready = false;
    private static ?array $settings = null;

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

    /* ══ The rules ══════════════════════════════════════════════════════════ */

    /**
     * The office's dues rules. Prices start from the deployment's
     * AV_DUES_MONTHLY_NGN / AV_DUES_ANNUAL_NGN, and once saved in the Studio
     * the saved figures are the price everywhere: the portal's Pay buttons,
     * the offline receipt check, the arrears, the answer the assistant gives.
     *
     * `start` is the day dues began to be owed. Nobody owes for a month before
     * it. It is fixed the first time the rules are read (the first of that
     * month) and saved, so it does not slide forward with the calendar — a
     * start that moved every month would never let anybody owe more than one.
     */
    public static function settings(): array
    {
        if (self::$settings !== null) return self::$settings;
        $d = [
            'enabled'     => true,
            'monthly_ngn' => defined('AV_DUES_MONTHLY_NGN') ? (int) AV_DUES_MONTHLY_NGN : 1000,
            'annual_ngn'  => defined('AV_DUES_ANNUAL_NGN') ? (int) AV_DUES_ANNUAL_NGN : 12000,
            'start'       => '',
            'from_level'  => class_exists('Levels') ? Levels::base() : 'O',
            'updated_at'  => '', 'updated_by' => '',
        ];
        $raw = null;
        try { $raw = Database::metaGet(self::SETTINGS_KEY); } catch (Throwable $e) {}
        $saved = is_string($raw) ? json_decode($raw, true) : null;
        $s = array_merge($d, is_array($saved) ? array_intersect_key($saved, $d) : []);
        $s['enabled'] = (bool) $s['enabled'];
        $s['monthly_ngn'] = max(0, (int) $s['monthly_ngn']);
        $s['annual_ngn'] = max(0, (int) $s['annual_ngn']);
        if (!self::isDate((string) $s['start'])) {
            $s['start'] = gmdate('Y-m-01');
            try { Database::metaSet(self::SETTINGS_KEY, (string) json_encode($s)); } catch (Throwable $e) {}
        }
        if (!in_array((string) $s['from_level'], self::ladder(), true)) $s['from_level'] = $d['from_level'];
        return self::$settings = $s;
    }

    /** Change the rules. Validated whole; nothing is saved if anything is wrong. */
    public static function saveSettings(array $in, string $by): array
    {
        $cur = self::settings();
        $e = [];
        $s = $cur;
        if (array_key_exists('enabled', $in)) $s['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        foreach (['monthly_ngn' => 'A month', 'annual_ngn' => 'A year'] as $k => $label) {
            if (!array_key_exists($k, $in)) continue;
            $v = trim((string) $in[$k]);
            if (!preg_match('/^\d+$/', $v) || (int) $v < 1 || (int) $v > self::PRICE_MAX_NGN) { $e[$k] = $label . ' is a whole number of naira, at least ₦1.'; continue; }
            $s[$k] = (int) $v;
        }
        if (!$e && $s['annual_ngn'] > 12 * $s['monthly_ngn']) $e['annual_ngn'] = 'A year cannot cost more than twelve months paid one at a time (₦' . number_format(12 * $s['monthly_ngn']) . ').';
        if (array_key_exists('start', $in)) {
            $v = trim((string) $in['start']);
            if (!self::isDate($v)) $e['start'] = 'Dues began on a date, like 2026-01-01.';
            elseif ($v > gmdate('Y-m-d', time() + 366 * 86400)) $e['start'] = 'The start is at most a year ahead.';
            else $s['start'] = $v;
        }
        if (array_key_exists('from_level', $in)) {
            $v = strtoupper(trim((string) $in['from_level']));
            if (!in_array($v, self::ladder(), true)) $e['from_level'] = 'The level is one of ' . implode(', ', self::ladder()) . '.';
            else $s['from_level'] = $v;
        }
        if ($e) return ['ok' => false, 'error' => implode(' ', $e), 'fields' => $e];
        $changed = [];
        foreach (['enabled', 'monthly_ngn', 'annual_ngn', 'start', 'from_level'] as $k) if ($s[$k] !== $cur[$k]) $changed[] = $k . ': ' . var_export($cur[$k], true) . ' → ' . var_export($s[$k], true);
        if (!$changed) return ['ok' => true, 'unchanged' => true, 'settings' => $cur];
        $s['updated_at'] = self::now(); $s['updated_by'] = mb_substr($by, 0, 191);
        Database::metaSet(self::SETTINGS_KEY, (string) json_encode($s));
        self::$settings = null;
        self::lms()->audit('membership.settings', 'dues', implode('; ', $changed), $by);
        return ['ok' => true, 'settings' => self::settings()];
    }

    public static function forgetSettings(): void { self::$settings = null; }

    private static function isDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    private static function ladder(): array
    {
        return class_exists('Levels') ? Levels::order() : ['O', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];
    }

    /** The levels that owe dues: from_level and every level above it. */
    public static function owingLevels(): array
    {
        $l = self::ladder();
        $i = array_search(self::settings()['from_level'], $l, true);
        return array_values(array_slice($l, $i === false ? 0 : (int) $i));
    }

    public static function monthly(): int { return (int) self::settings()['monthly_ngn']; }
    public static function annual(): int { return (int) self::settings()['annual_ngn']; }

    /** What N months of dues cost: whole years at the yearly price, the rest by the month. */
    public static function price(int $months): int
    {
        $months = max(0, $months);
        return intdiv($months, 12) * self::annual() + ($months % 12) * self::monthly();
    }

    /* ══ Who owes ═══════════════════════════════════════════════════════════ */

    /**
     * SQL over `lms_users u` for the members who owe dues at all (whether or
     * not they are behind). The roster's "owes dues" filter, the dashboard and
     * arrearsFor() all start from this, so they agree on who.
     *
     * @return array{0:string,1:array}
     */
    public static function obligedSql(): array
    {
        $s = self::settings();
        if (!$s['enabled'] || $s['start'] > gmdate('Y-m-d')) return ['1 = 0', []];
        $levels = self::owingLevels();
        $base = class_exists('Levels') ? Levels::base() : 'O';
        $ph = implode(',', array_fill(0, count($levels), '?'));
        $w = ["u.role <> 'learner'", "u.status = 'active'", "COALESCE(NULLIF(u.level, ''), ?) IN ($ph)",
              "NOT EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.status = 'active' AND m.expires_at IS NULL)",
              "COALESCE((SELECT m.status FROM memberships m WHERE m.user_id = u.id ORDER BY m.id DESC LIMIT 1), '') <> 'cancelled'"];
        /* The community bot is an account, not a person. */
        $w[] = 'LOWER(u.email) <> ' . Database::pdo()->quote(class_exists('Community') ? strtolower(Community::BOT_EMAIL) : 'community-bot@afrovanguard.org.ng');
        $ngv = class_exists('MemberRoster') ? array_keys(MemberRoster::vanguardIds()) : [];
        if ($ngv) $w[] = 'u.id NOT IN (' . implode(',', array_map('intval', $ngv)) . ')';
        return ['(' . implode(' AND ', $w) . ')', array_merge([$base], $levels)];
    }

    /** Members who are behind: obliged, with no dues running today. */
    public static function owingSql(): array
    {
        [$sql, $a] = self::obligedSql();
        return ["($sql AND NOT EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.status = 'active' AND (m.expires_at IS NULL OR m.expires_at > ?)))", array_merge($a, [self::now()])];
    }

    /**
     * Why a member does or does not owe dues, in words the office can repeat
     * to them. `owes` false always carries the reason.
     */
    public static function obligation(int $userId): array
    {
        $s = self::settings();
        $st = Database::pdo()->prepare('SELECT id, email, role, status, level FROM lms_users WHERE id = ?'); $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $no = static fn(string $code, string $why) => ['owes' => false, 'code' => $code, 'why' => $why];
        if (!$u) return $no('no_account', 'No such account.');
        if (!$s['enabled']) return $no('off', 'Dues are switched off.');
        if (class_exists('Community') && strtolower((string) $u['email']) === strtolower(Community::BOT_EMAIL)) return $no('system', 'A system account, not a person.');
        if ((string) $u['role'] === 'learner') return $no('learner', 'An Academy learner, not a member: dues are optional.');
        if (class_exists('MemberRoster') && isset(MemberRoster::vanguardIds()[$userId])) return $no('ngv', 'A NextGen Vanguard: membership is charged on their NGV account.');
        if ((string) $u['status'] !== 'active') return $no('suspended', 'Suspended: dues stop while the account is.');
        $lvl = (string) ($u['level'] ?? '') !== '' ? (string) $u['level'] : (class_exists('Levels') ? Levels::base() : 'O');
        if (!in_array($lvl, self::owingLevels(), true)) return $no('level', 'Level ' . $lvl . ': dues are owed from Level ' . $s['from_level'] . '.');
        $live = self::live($userId);
        if ($live && $live['expires_at'] === null) return $no('lifetime', 'Lifetime membership.');
        $st = Database::pdo()->prepare('SELECT status FROM memberships WHERE user_id = ? ORDER BY id DESC LIMIT 1'); $st->execute([$userId]);
        if ((string) $st->fetchColumn() === 'cancelled') return $no('cancelled', 'Dues ended by the office.');
        if ($s['start'] > gmdate('Y-m-d')) return $no('not_yet', 'Dues begin on ' . $s['start'] . '.');
        return ['owes' => true, 'code' => 'member', 'why' => 'Level ' . $lvl . ' member: dues are owed every month.'];
    }

    /**
     * What each member is behind by, for many members in two queries — the
     * roster draws a page of fifty, and one query each is the N+1 this
     * codebase removes wherever it finds one.
     *
     * @param int[] $ids
     * @return array<int, array{months:int, amount_ngn:int, since:string, next_due:string, paid_through:?string, from:string}>
     *         keyed by member id, only for members who are obliged
     */
    public static function arrearsFor(array $ids, ?int $now = null): array
    {
        self::ensure();
        if (class_exists('MemberRoster')) MemberRoster::ensure();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) return [];
        $now = $now ?? time();
        [$sql, $a] = self::obligedSql();
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = Database::pdo()->prepare('SELECT u.id, u.created_at, p.joined_on,
                    (SELECT MAX(m.expires_at) FROM memberships m WHERE m.user_id = u.id AND m.status = \'active\' AND m.expires_at IS NOT NULL) AS paid_through
                FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id
                WHERE u.id IN (' . implode(',', $chunk) . ') AND ' . $sql);
            $st->execute($a);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['id']] = self::owed($r, $now);
        }
        return $out;
    }

    /** One member's arrears, or nothing owed with the reason. */
    public static function arrears(int $userId, ?int $now = null): array
    {
        $ob = self::obligation($userId);
        $a = self::arrearsFor([$userId], $now)[$userId] ?? null;
        $zero = ['months' => 0, 'amount_ngn' => 0, 'since' => '', 'next_due' => '', 'paid_through' => null, 'from' => ''];
        return ($a ?? $zero) + ['owes' => $ob['owes'], 'code' => $ob['code'], 'why' => $ob['why']];
    }

    /**
     * The arithmetic, from one row (created_at, joined_on, paid_through).
     * The anchor is the first day not paid for: the later of the start, the
     * day they joined, and the day paid dues ran out. A month falls due on
     * the anchor and on the same day each month after it, up to now.
     */
    private static function owed(array $r, int $now): array
    {
        $anchor = self::anchorOf($r);
        $months = 0;
        while ($months < self::ARREARS_MAX_MONTHS) {
            $t = (int) strtotime('+' . $months . ' months', $anchor);
            if ($t > $now) break;
            $months++;
        }
        $next = (int) strtotime('+' . $months . ' months', $anchor);
        $pt = (string) ($r['paid_through'] ?? '');
        return ['months' => $months, 'amount_ngn' => self::price($months),
                'since' => $months > 0 ? gmdate('Y-m-d', $anchor) : '', 'next_due' => gmdate('Y-m-d', $next),
                'paid_through' => $pt !== '' ? $pt : null, 'from' => gmdate('Y-m-d H:i:s', $anchor)];
    }

    private static function anchorOf(array $r): int
    {
        $start = (int) strtotime(self::settings()['start'] . ' 00:00:00 UTC');
        $joined = (string) ($r['joined_on'] ?? '');
        /* The day they joined, or — with none recorded — the moment the
           account was made, so somebody paying on their first day pays from
           that moment, not from the midnight before it. */
        $made = substr((string) ($r['created_at'] ?? ''), 0, 19);
        $joinTs = self::isDate($joined) && $joined !== substr($made, 0, 10)
            ? (int) strtotime($joined . ' 00:00:00 UTC')
            : (int) strtotime($made . ' UTC');
        $pt = (string) ($r['paid_through'] ?? '');
        $ptTs = $pt !== '' ? (int) strtotime($pt . ' UTC') : 0;
        return max($start, $joinTs ?: 0, $ptTs);
    }

    /**
     * Where the next months of dues begin, for a grant. With dues running, at
     * their end. Behind, at the first month owed — paying clears arrears
     * oldest first rather than forgiving them. Otherwise, now. The Paystack
     * path (LmsRepository::grantMembership) and the office's grant both read
     * this, so a payment buys the same months whichever way it arrived.
     */
    public static function nextBase(int $userId, ?int $now = null): int
    {
        $now = $now ?? time();
        $live = self::live($userId);
        if ($live && $live['expires_at'] !== null) return max($now, (int) strtotime($live['expires_at'] . ' UTC'));
        $a = self::arrearsFor([$userId], $now)[$userId] ?? null;
        if ($a && $a['months'] > 0) return (int) strtotime($a['from'] . ' UTC');
        return $now;
    }

    /** How many accounts a [sql, args] pair over `lms_users u` selects. */
    public static function countWhere(array $q): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM lms_users u WHERE ' . $q[0]); $st->execute($q[1]);
        return (int) $st->fetchColumn();
    }

    /**
     * Who owes, how much in all, and the largest debts — the dashboard's
     * figure and the list it opens are the same members.
     */
    public static function owingSummary(int $top = 0): array
    {
        [$sql, $a] = self::owingSql();
        $st = Database::pdo()->prepare("SELECT u.id FROM lms_users u WHERE $sql"); $st->execute($a);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $all = self::arrearsFor($ids);
        $owing = array_filter($all, static fn($x) => $x['months'] > 0);
        $total = array_sum(array_column($owing, 'amount_ngn'));
        $out = ['members' => count($owing), 'amount_ngn' => $total, 'months' => array_sum(array_column($owing, 'months'))];
        if ($top > 0 && $owing) {
            uasort($owing, static fn($x, $y) => $y['amount_ngn'] <=> $x['amount_ngn']);
            $out['top'] = array_slice($owing, 0, $top, true);
        }
        return $out;
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
            'arrears'       => self::arrears($userId),
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
            case 'owing':     return self::owingSql();
            case 'obliged':   return self::obligedSql();
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
        /* Money is credited only from a verified offline payment (lib/OfflinePayments.php):
           a figure typed into a form is not evidence that it was paid. */
        if ($amount > 0 && empty($opts['verified_offline'])) return ['ok' => false, 'code' => 'evidence_required', 'error' => 'Dues paid offline are recorded with the receipt, and credited once it is checked. Use “Record an offline payment”.'];
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

        /* Extend from where their dues next begin: the end of what is
           running, or the oldest month they are behind on (nextBase). */
        $base = self::nextBase($userId);
        $expires = gmdate('Y-m-d H:i:s', (int) strtotime("+$months months", $base));
        $source = $amount > 0 ? 'offline' : ($method === 'waiver' ? 'waiver' : 'admin');

        $from = gmdate('Y-m-d H:i:s', min($base, time()));
        $pdo->beginTransaction();
        try {
            if ($amount > 0) {
                $pdo->prepare("INSERT INTO payments (reference, user_id, provider, kind, amount_kobo, status, paid_at, months, note, recorded_by) VALUES (?, ?, ?, 'membership', ?, 'paid', ?, ?, ?, ?)")
                    ->execute([$ref, $userId, 'offline:' . $method, $amount * 100, self::now(), $months, $note, $actor]);
            }
            /* started_at is where these months begin — in the past when
               they pay off arrears — so the history reads as the months it
               covers, not the day the office typed it. */
            $pdo->prepare("INSERT INTO memberships (user_id, tier, status, started_at, expires_at, source, note, granted_by) VALUES (?, 'member', 'active', ?, ?, ?, ?, ?)")
                ->execute([$userId, $from, $expires, $source, $note, $actor]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'The membership was not saved: ' . $e->getMessage()];
        }
        self::lms()->audit('membership.grant', self::email($userId),
            $months . ' month' . ($months === 1 ? '' : 's') . ' → ' . substr($expires, 0, 10)
            . ($amount > 0 ? ' · ₦' . number_format($amount) . ' by ' . $method . ' (' . $ref . ')' : ' · ' . ($source === 'waiver' ? 'waived' : 'no payment'))
            . ($note !== '' ? ' · ' . $note : ''), $actor);
        /* modules/EMAILS.md: dues_receipt — only where money actually
           changed hands. A waiver and a courtesy month are not receipts, and
           sending one would put a figure in somebody's inbox that nobody
           paid. */
        if ($amount > 0) self::duesReceipt($userId, $amount, $method, $ref, $months, $expires);
        return ['ok' => true, 'expires_at' => $expires, 'reference' => $ref ?: null, 'membership' => self::summary($userId)];
    }

    /**
     * The receipt for dues taken in person or by transfer.
     *
     * items[] is one line here — membership for N months. The template takes
     * a list because a payment can cover dues AND a training fee in one go;
     * when that path exists it adds a second line rather than a second email.
     * Best-effort: a receipt that fails to send never un-does the membership
     * that was just granted and recorded.
     */
    private static function duesReceipt(int $userId, int $amount, string $method, string $ref, int $months, string $expires): void
    {
        if (!class_exists('Mailer') || !method_exists('Mailer', 'sendTemplate')) return;
        try {
            $email = self::email($userId);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            $st = Database::pdo()->prepare('SELECT name FROM lms_users WHERE id = ?');
            $st->execute([$userId]);
            $name = (string) ($st->fetchColumn() ?: '');
            $money = '₦' . number_format($amount);
            $label = 'Membership dues · ' . $months . ' month' . ($months === 1 ? '' : 's');
            $site  = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
            Mailer::sendTemplate($email, 'dues_receipt', [
                'first_name'  => explode(' ', trim($name))[0] ?: 'there',
                'items'       => [['label' => $label, 'amount' => $money]],
                'total'       => $money,
                'receipt_no'  => $ref !== '' ? $ref : 'AVD-' . date('y-m-') . str_pad((string) $userId, 4, '0', STR_PAD_LEFT),
                'paid_on'     => date('j M Y'),
                'method'      => ucfirst($method),
                'period'      => 'Paid through ' . date('j M Y', strtotime($expires) ?: time()),
                'receipt_url' => $site . '/portal/#membership',
            ]);
        } catch (Throwable $e) { error_log('[membership] dues receipt: ' . $e->getMessage()); }
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
