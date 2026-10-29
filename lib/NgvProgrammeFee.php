<?php
/**
 * lib/NgvProgrammeFee.php — the NGV programme fee, as the MoU states it.
 *
 * Modelled on NGG's training fee (nextgengen api/_lib/domain/fees.php) and
 * deliberately NOT a copy of it, because an audit of NGG's on 29 Oct 2026 found
 * it wrong in ways that would carry straight across:
 *
 *   · it had no fixed total — ₦75,000 per session came to ₦450,000 for most
 *     join dates and ₦375,000 for others, and a basis switch kept the rate,
 *     so ₦75,000 a session silently became ₦75,000 a day (₦5.25m);
 *   · it re-read the phase lengths from today's settings, so editing them
 *     rewrote everybody's worked-off figure overnight;
 *   · somebody who left kept working it off by the calendar, and the balance a
 *     leaver "owes" could never be asked for, because it never became payable;
 *   · the notional fee counted against the balance ceiling, so real dues
 *     stopped being charged once unpaid ones reached ₦50,000.
 *
 * What it is. The programme carries a cost; the participant is given the place
 * and works it off through the INTERNSHIP half of the year. Nobody is asked for
 * it while they are on the programme, and it is written off when they finish.
 * Somebody who withdraws owes what they had not yet worked off — and only then
 * does any of it become money anybody is asked for.
 *
 * Four bases, settable (NgvLedger settings `programmeBasis` / `programmeRate`):
 *
 *   total    one charge for the year, on the day they register — the default,
 *            ₦240,000 read off the public page's plan unless pinned
 *   session  per term their CACENTRE runs (Centres calendar), for terms that
 *            start inside the training half
 *   day      per day their centre meets in the training half (the same
 *            calendar the door marks absences on), while they are active
 *   week     per Monday-to-Sunday week holding at least one such day
 *
 * The basis, the rate, the start day and both halves' lengths are FROZEN on the
 * participant at registration (`freeze()`), from the settings of that day and
 * the MoU version they signed. Changing the settings changes what the next
 * person signs and nothing else.
 *
 * Worked off by ACTIVE DAYS inside the internship window, read from
 * ngv_status_log — so a pause or a withdrawal stops the clock and the time away
 * is never credited back, whatever happens later.
 */
declare(strict_types=1);

final class NgvProgrammeFee
{
    public const BASES = ['total', 'session', 'day', 'week'];
    public const BASIS_LABEL = ['total' => 'for the year', 'session' => 'per term', 'day' => 'per meeting day', 'week' => 'per week'];
    public const DEFAULT_TOTAL = 240000;
    public const TRAINING_MONTHS = 6;
    public const INTERNSHIP_MONTHS = 6;
    public const MONTHS_MAX = 24;
    /** Period prefix, so a unit can never collide with a legacy instalment ('YYYY-MM'). */
    private const P = 'P:';
    private const UNITS_MAX = 800;

    /* ══ Settings (stored in NgvLedger's settings blob) ═════════════════════ */

    /**
     * The terms the NEXT person would sign. `rate` is null when it cannot be
     * stated: a per-unit basis with no rate pinned. That is refused at save
     * time too, but a settings blob outlives the code that wrote it.
     */
    public static function terms(): array
    {
        $cfg = NgvLedger::settings();
        $basis = in_array((string) ($cfg['programmeBasis'] ?? 'total'), self::BASES, true) ? (string) $cfg['programmeBasis'] : 'total';
        $pinned = $cfg['programmeRate'] ?? null;
        $rate = null; $source = 'pinned';
        if ($pinned !== null && $pinned !== '' && (int) $pinned > 0) $rate = (int) $pinned;
        elseif ($basis === 'total') {
            /* The page's own price, so the website and the account cannot
               quote different money — the ledger's rule for every other fee. */
            $rate = self::DEFAULT_TOTAL; $source = 'fallback';
            foreach (NgvLedger::planCatalogue() as $pl) {
                if (!empty($pl['enabled']) && (int) $pl['fee'] > 0) { $rate = (int) $pl['fee']; $source = 'page'; break; }
            }
        }
        return [
            'basis' => $basis, 'rate' => $rate, 'source' => $rate === null ? 'unset' : $source,
            'trainingMonths' => max(1, min(self::MONTHS_MAX, (int) ($cfg['trainingMonths'] ?? self::TRAINING_MONTHS))),
            'internshipMonths' => max(1, min(self::MONTHS_MAX, (int) ($cfg['internshipMonths'] ?? self::INTERNSHIP_MONTHS))),
        ];
    }

    /**
     * Validate the fee part of a settings save. Returns the clean fields, or an
     * error. A basis change has to come WITH its rate: keeping ₦240,000 when
     * the basis becomes "per day" is the ₦5.25m fault in NGG, and the only
     * safe answer is to refuse the save and say why.
     *
     * @return array{ok:bool, error?:string, fields?:array}
     */
    public static function cleanSettings(array $in, array $cur): array
    {
        $basis = array_key_exists('programmeBasis', $in) ? (string) $in['programmeBasis'] : (string) ($cur['programmeBasis'] ?? 'total');
        if (!in_array($basis, self::BASES, true)) return ['ok' => false, 'error' => 'Choose how the programme fee is charged.'];
        $was = (string) ($cur['programmeBasis'] ?? 'total');
        $rateIn = array_key_exists('programmeRate', $in) ? $in['programmeRate'] : ($cur['programmeRate'] ?? null);
        $rate = ($rateIn === null || $rateIn === '' || $rateIn === 'auto') ? null : NgvLedger::money($rateIn);
        if ($rate === 0) $rate = null;
        if ($basis !== 'total' && $rate === null) {
            return ['ok' => false, 'error' => 'A programme fee charged ' . self::BASIS_LABEL[$basis] . ' needs its rate: enter the amount ' . self::BASIS_LABEL[$basis] . '.'];
        }
        $wasRate = ($cur['programmeRate'] ?? null) === null ? null : (int) $cur['programmeRate'];
        if ($basis !== $was && (!array_key_exists('programmeRate', $in) || ($basis !== 'total' && $rate === $wasRate))) {
            return ['ok' => false, 'error' => 'You changed how the programme fee is charged. Enter the amount ' . self::BASIS_LABEL[$basis]
                . ' as well, so the old figure is not carried over to the new basis.'];
        }
        $months = static fn($v, int $fb) => max(1, min(self::MONTHS_MAX, (int) ($v ?? $fb) ?: $fb));
        return ['ok' => true, 'fields' => [
            'programmeBasis' => $basis,
            'programmeRate' => $rate,
            'trainingMonths' => $months($in['trainingMonths'] ?? null, (int) ($cur['trainingMonths'] ?? self::TRAINING_MONTHS)),
            'internshipMonths' => $months($in['internshipMonths'] ?? null, (int) ($cur['internshipMonths'] ?? self::INTERNSHIP_MONTHS)),
        ]];
    }

    /* ══ One participant ════════════════════════════════════════════════════ */

    /** The signed terms on a participant row, or null for a participant from before them. */
    public static function snapshot(array $p): ?array
    {
        $basis = (string) ($p['prog_basis'] ?? '');
        if (!in_array($basis, self::BASES, true)) return null;
        $start = (string) ($p['prog_start'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return null;
        $tm = max(1, (int) ($p['prog_training_months'] ?? 0)); $im = max(1, (int) ($p['prog_internship_months'] ?? 0));
        $trainingEnds = self::addMonths($start, $tm);
        return ['basis' => $basis, 'rate' => max(0, (int) ($p['prog_rate'] ?? 0)), 'start' => $start,
                'trainingMonths' => $tm, 'internshipMonths' => $im,
                'trainingEnds' => $trainingEnds, 'internshipEnds' => self::addMonths($trainingEnds, $im),
                'mouVersion' => (string) ($p['prog_mou_version'] ?? '')];
    }

    /**
     * Fix the terms on a participant at registration. Refuses to overwrite a
     * snapshot that exists: changing what somebody signed is not a re-run.
     *
     * @return array{ok:bool, error?:string, snapshot?:array}
     */
    public static function freeze(int $memberId, string $start, string $mouVersion = ''): array
    {
        $p = NgvMember::participant($memberId);
        if (!$p) return ['ok' => false, 'error' => 'No such participant.'];
        if (self::snapshot($p) !== null) return ['ok' => false, 'error' => 'Their programme fee is already set.'];
        $t = self::terms();
        if ($t['rate'] === null || $t['rate'] <= 0) return ['ok' => false, 'error' => 'The programme fee has no amount set. Set it in the fee settings first.'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return ['ok' => false, 'error' => 'A start date is needed.'];
        NgvDb::pdo()->prepare('UPDATE ngv_participants SET prog_basis = ?, prog_rate = ?, prog_start = ?, prog_training_months = ?,
                               prog_internship_months = ?, prog_mou_version = ?, updated_at = ' . NgvDb::nowExpr() . ' WHERE member_id = ?')
            ->execute([$t['basis'], $t['rate'], $start, $t['trainingMonths'], $t['internshipMonths'], mb_substr($mouVersion, 0, 40), $memberId]);
        return ['ok' => true, 'snapshot' => self::snapshot(NgvMember::participant($memberId) ?? [])];
    }

    /**
     * Post every unit of the fee that has happened. Idempotent (the ledger's
     * unique index). Units on a per-day or per-week basis are only for days
     * the participant was ACTIVE; a term on the per-session basis is charged
     * if they were active on its first day.
     */
    public static function accrue(array $p, ?string $asOf = null): int
    {
        $snap = self::snapshot($p);
        if ($snap === null || $snap['rate'] <= 0) return 0;
        $mid = (int) $p['member_id'];
        $n = 0;
        foreach (self::units($mid, $snap, $asOf ?: self::today()) as $key => $u) {
            if (NgvLedger::postCharge($mid, 'programme', $snap['rate'], self::P . $key,
                    ['note' => 'Programme fee — ' . $u['label'], 'source' => 'accrual'])) $n++;
        }
        return $n;
    }

    /**
     * The units the fee is charged for, up to $upto.
     *
     * @return array<string, array{date:string, label:string}>
     */
    public static function units(int $memberId, array $snap, string $upto): array
    {
        $out = [];
        if ($snap['basis'] === 'total') {
            if ($snap['start'] <= $upto) $out['total'] = ['date' => $snap['start'], 'label' => 'the programme year from ' . $snap['start']];
            return $out;
        }
        $last = self::addDays($snap['trainingEnds'], -1);
        if ($last > $upto) $last = $upto;
        if ($snap['start'] > $last) return $out;
        $active = self::activeSegments($memberId, $last);
        if ($snap['basis'] === 'session') {
            foreach (self::terms_for($memberId) as $tm) {
                $s = (string) $tm['start'];
                if ($s < $snap['start'] || $s > $last || !self::activeOn($active, $s)) continue;
                $out['T' . $s] = ['date' => $s, 'label' => 'term from ' . $s];
            }
            return $out;
        }
        for ($d = $snap['start'], $i = 0; $d <= $last && $i < 400; $d = self::addDays($d, 1), $i++) {
            if (!self::activeOn($active, $d) || !self::meets($memberId, $d)) continue;
            if ($snap['basis'] === 'day') $out['D' . $d] = ['date' => $d, 'label' => $d];
            else {
                $mon = self::monday($d);
                $out['W' . $mon] ??= ['date' => $mon, 'label' => 'week of ' . $mon];
            }
            if (count($out) >= self::UNITS_MAX) break;
        }
        return $out;
    }

    /**
     * Where the fee stands. `charged` and `credited` are the programme line's
     * own figures from the ledger; the rest is derived.
     *
     *   earned     worked off so far: charged × active internship days ÷ window days
     *   covered    paid + written off/waived + earned, never above charged
     *   remaining  charged − covered
     *   owed       remaining, once they have WITHDRAWN — the only time any of it is asked for
     *   notional   true while it is not money anybody is asked for
     */
    public static function position(array $p, int $charged, int $credited, ?string $asOf = null): ?array
    {
        $snap = self::snapshot($p);
        if ($snap === null) return null;
        $today = $asOf ?: self::today();
        $status = (string) ($p['status'] ?? 'active');
        $windowDays = max(1, self::daysBetween($snap['trainingEnds'], $snap['internshipEnds']));
        $to = min($today, self::addDays($snap['internshipEnds'], -1));
        $activeDays = $to >= $snap['trainingEnds']
            ? self::activeDaysBetween((int) $p['member_id'], $snap['trainingEnds'], $to, $p) : 0;
        $pct = $status === 'completed' ? 1.0 : min(1.0, $activeDays / $windowDays);
        $earned = (int) floor($charged * $pct);
        $covered = min($charged, $credited + $earned);
        $remaining = max(0, $charged - $covered);
        $phase = $today < $snap['trainingEnds'] ? 'training' : ($today < $snap['internshipEnds'] ? 'internship' : 'complete');
        return [
            'basis' => $snap['basis'], 'rate' => $snap['rate'], 'basisLabel' => self::BASIS_LABEL[$snap['basis']],
            'start' => $snap['start'], 'trainingEnds' => $snap['trainingEnds'], 'internshipEnds' => $snap['internshipEnds'],
            'phase' => $phase, 'mouVersion' => $snap['mouVersion'],
            'charged' => $charged, 'credited' => $credited, 'earned' => $earned,
            'activeDays' => $activeDays, 'windowDays' => $windowDays,
            'covered' => $covered, 'remaining' => $remaining,
            'coveredPct' => $charged > 0 ? (int) floor(100 * $covered / $charged) : 0,
            'notional' => $status !== 'withdrawn',
            'owed' => $status === 'withdrawn' ? $remaining : 0,
        ];
    }

    /**
     * The year is done: what is left of the fee is written off, as a ledger
     * entry, so the account says the obligation was discharged rather than
     * leaving a derived figure to say it. Called by the completion sweep and
     * by staff marking the MoU accomplished early.
     */
    public static function discharge(int $memberId, int $byUid = 0): int
    {
        $p = NgvMember::participant($memberId);
        if (!$p || self::snapshot($p) === null) return 0;
        $b = NgvLedger::balance($memberId);
        $charged = (int) ($b['chargedBy']['programme'] ?? 0);
        $left = max(0, $charged - (int) ($b['paidBy']['programme'] ?? 0));
        if ($left <= 0) return 0;
        $r = NgvLedger::writeOff($memberId, 'programme', $left, 'Programme completed — the MoU is accomplished', $byUid);
        return !empty($r['ok']) ? $left : 0;
    }

    /* ══ Status history ═════════════════════════════════════════════════════ */

    /** Record a status taking effect on a day. Called wherever status changes. */
    public static function logStatus(int $memberId, string $status, ?string $since = null): void
    {
        self::$logs = null;
        try {
            /* The first change ever logged for somebody who was already on the
               programme: their active stretch BEFORE it is recorded too, or it
               would vanish from the history the moment the log began. */
            $st = NgvDb::pdo()->prepare('SELECT COUNT(*) FROM ngv_status_log WHERE member_id = ?');
            $st->execute([$memberId]);
            if ((int) $st->fetchColumn() === 0 && $status !== 'active') {
                $p = NgvMember::participant($memberId);
                $from = $p ? (string) ($p['prog_start'] ?: $p['start_date'] ?: substr((string) $p['created_at'], 0, 10)) : '';
                if ($p && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && in_array((string) $p['status'], ['active', 'completed'], true)) {
                    NgvDb::pdo()->prepare('INSERT INTO ngv_status_log (member_id, status, since, created_at) VALUES (?, ?, ?, ' . NgvDb::nowExpr() . ')')
                        ->execute([$memberId, 'active', $from]);
                }
            }
            NgvDb::pdo()->prepare('INSERT INTO ngv_status_log (member_id, status, since, created_at) VALUES (?, ?, ?, ' . NgvDb::nowExpr() . ')')
                ->execute([$memberId, mb_substr($status, 0, 20), $since ?: self::today()]);
        } catch (Throwable $e) { error_log('[ngv] status log: ' . $e->getMessage()); }
    }

    /**
     * Active stretches as [from, to] pairs, `to` inclusive, up to $upto.
     *
     * A participant with no log at all (from before it existed) is read as
     * active since their start — the only reading available — and from the
     * first logged change on, the log decides.
     */
    public static function activeSegments(int $memberId, string $upto, ?array $p = null): array
    {
        if (self::$logs !== null && array_key_exists($memberId, self::$logs)) $log = self::$logs[$memberId];
        else {
            $st = NgvDb::pdo()->prepare('SELECT status, since FROM ngv_status_log WHERE member_id = ? ORDER BY since, id');
            $st->execute([$memberId]);
            $log = $st->fetchAll() ?: [];
        }
        $p = $p ?? NgvMember::participant($memberId);
        if (!$log) {
            if (!$p || (string) $p['status'] !== 'active') return [];
            return [['0000-00-00', $upto]];
        }
        $segs = []; $open = null;
        foreach ($log as $r) {
            $since = (string) $r['since'];
            if ($since > $upto) break;
            if ((string) $r['status'] === 'active') { if ($open === null) $open = $since; }
            elseif ($open !== null) { if ($since > $open) $segs[] = [$open, self::addDays($since, -1)]; $open = null; }
        }
        if ($open !== null) $segs[] = [$open, $upto];
        return $segs;
    }

    /** @var array<int, array>|null status history held for a roster-wide pass */
    private static ?array $logs = null;

    /**
     * Read the whole status history in one query, for a pass over every
     * account (NgvLedger::sweep). Without it, the arrears list and the reminder
     * scan would ask once per participant. `release()` when done.
     */
    public static function preload(): void
    {
        self::$logs = [];
        try {
            foreach (NgvDb::pdo()->query('SELECT member_id, status, since FROM ngv_status_log ORDER BY member_id, since, id')->fetchAll() ?: [] as $r) {
                self::$logs[(int) $r['member_id']][] = $r;
            }
        } catch (Throwable $e) { self::$logs = null; }
    }
    public static function release(): void { self::$logs = null; }

    private static function activeOn(array $segs, string $d): bool
    {
        foreach ($segs as [$a, $b]) if ($d >= $a && $d <= $b) return true;
        return false;
    }

    private static function activeDaysBetween(int $memberId, string $from, string $to, ?array $p = null): int
    {
        $n = 0;
        foreach (self::activeSegments($memberId, $to, $p) as [$a, $b]) {
            $a = max($a, $from); $b = min($b, $to);
            if ($a <= $b) $n += self::daysBetween($a, $b) + 1;
        }
        return $n;
    }

    /* ══ Calendar ═══════════════════════════════════════════════════════════ */

    /** The participant's centre's terms: [{start, end}], sorted. */
    private static function terms_for(int $memberId): array
    {
        $centre = '';
        try {
            $st = Database::pdo()->prepare('SELECT centre FROM member_profiles WHERE user_id = ?');
            $st->execute([$memberId]);
            $centre = (string) ($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        $cal = ($centre !== '' && class_exists('Centres')) ? Centres::calendarFor($centre) : null;
        $terms = [];
        foreach ((array) ($cal['terms'] ?? []) as $t) {
            $s = (string) ($t['start'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $terms[] = ['start' => $s, 'end' => (string) ($t['end'] ?? '')];
        }
        if (!$terms && (string) ($cal['term_start'] ?? '') !== '') $terms[] = ['start' => (string) $cal['term_start'], 'end' => (string) ($cal['term_end'] ?? '')];
        usort($terms, static fn($a, $b) => strcmp($a['start'], $b['start']));
        return $terms;
    }

    private static function meets(int $memberId, string $d): bool
    {
        return class_exists('GateAttendance') ? GateAttendance::isProgrammeDayFor($memberId, $d) : false;
    }

    /* ══ Dates ══════════════════════════════════════════════════════════════ */

    private static function today(): string
    {
        return function_exists('av_today_tz') ? av_today_tz() : gmdate('Y-m-d');
    }
    public static function addMonths(string $d, int $m): string { return gmdate('Y-m-d', (int) strtotime($d . ' 00:00:00 UTC +' . $m . ' months')); }
    private static function addDays(string $d, int $n): string { return gmdate('Y-m-d', (int) strtotime($d . ' 00:00:00 UTC ' . ($n >= 0 ? '+' : '') . $n . ' days')); }
    private static function daysBetween(string $a, string $b): int { return (int) floor((strtotime($b . ' 00:00:00 UTC') - strtotime($a . ' 00:00:00 UTC')) / 86400); }
    private static function monday(string $d): string { $t = strtotime($d . ' 00:00:00 UTC'); return gmdate('Y-m-d', $t - ((int) gmdate('N', $t) - 1) * 86400); }
}
