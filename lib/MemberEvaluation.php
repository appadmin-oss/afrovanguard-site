<?php
/**
 * lib/MemberEvaluation.php — how one member is doing, in one read.
 *
 * The member drawer showed who somebody is (name, centre, level, notes) and
 * nothing of how they are doing; that lived in five places: the gate's
 * attendance, commitments, the level ladder, fines and dues, and — for a
 * NextGen Vanguard — the NGV console. An admin deciding whether to call
 * somebody, promote them or put them on probation had to open all five.
 *
 * This puts them side by side, from the records only (no AI, no guessing):
 *
 *   attendance   the last 90 days at the gate: came, on time, late, absent, streak
 *   commitments  what they said they would do, and did
 *   growth       their level, the next one, and what stands between (Levels::recommend)
 *   money        fines owing and dues behind
 *   trend        the last 30 days against the 60 before: slipping, level, improving
 *   tenure       when they joined; the first year is when members drift away
 *   followups    who is following up, by when (lib/MemberFollowUps.php)
 *   vanguard     for an NGV: status, track, cohort, programme year, points,
 *                probation, programme account — a vanguard IS a member, so this
 *                is one block of the member's evaluation, not a separate desk
 *
 * and then one standing — doing well, steady, or needs attention — with the
 * reasons written out, because a label without its reasons is an accusation.
 *
 *   · A judgement needs evidence: attendance is judged on ≥ ATT_MIN_DAYS
 *     counted days (MemberRoster's rule, so the tile and the drawer agree),
 *     commitments on ≥ COMMIT_MIN settled. Too little data says so.
 *   · Each part is read on its own; one that fails is reported as unavailable,
 *     never as zero (a silent zero is the bug that hides an outage).
 */
declare(strict_types=1);

final class MemberEvaluation
{
    public const DAYS = 90;
    public const COMMIT_MIN = 3;

    /** Thresholds, written once: the roster's segments use the same numbers. */
    public const ATT_LOW = 70;        // under this attendance rate needs attention
    public const ATT_STRONG = 90;     // at or over this, and on time…
    public const PUNCT_STRONG = 85;   // …this often, is doing well
    public const LATE_MANY = 3;       // late this many times in 30 days needs attention
    public const COMMIT_LOW = 50;
    /* The trend: the last 30 days against the 60 before them, each on at least
       TREND_MIN counted days. Tardiness and a falling rate show before a low
       90-day average does — the early sign, while it can still be turned. */
    public const TREND_DAYS = 30;
    public const TREND_MIN = 3;
    public const TREND_SHIFT = 15;   // points of attendance rate
    /* A member's first year is when they most often drift away (4-H retention
       research: being a first-year member is the main predictor of leaving). */
    public const FIRST_YEAR_DAYS = 365;

    public static function evaluate(int $id): array
    {
        $out = ['ok' => true, 'id' => $id, 'days' => self::DAYS, 'unavailable' => []];
        $part = static function (string $key, callable $f) use (&$out) {
            try { $out[$key] = $f(); }
            catch (Throwable $e) { error_log('[member-eval] ' . $key . ': ' . $e->getMessage()); $out[$key] = null; $out['unavailable'][] = $key; }
        };

        $part('attendance', static function () use ($id): array {
            $s = GateAttendance::summary($id, self::DAYS);
            $m = GateAttendance::summary($id, 30);
            return ['rate' => $s['rate'], 'punctuality' => $s['punctuality'], 'grade' => $s['grade'], 'counted' => (int) $s['counted'],
                    'present' => (int) $s['present'], 'late' => (int) $s['late'], 'absent' => (int) $s['absent'], 'excused' => (int) $s['excused'],
                    'late_minutes' => (int) $s['late_minutes'], 'streak' => (int) $s['streak'], 'late_30' => (int) $m['late'],
                    'enough' => (int) $s['counted'] >= MemberRoster::ATT_MIN_DAYS] + ['trend' => self::trend(GateAttendance::history($id, self::DAYS))];
        });
        $part('tenure', static function () use ($id): array {
            MemberRoster::ensure();
            $st = Database::pdo()->prepare('SELECT u.created_at, p.joined_on FROM lms_users u LEFT JOIN member_profiles p ON p.user_id = u.id WHERE u.id = ?');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $since = trim((string) ($r['joined_on'] ?? '')) !== '' ? (string) $r['joined_on'] : substr((string) ($r['created_at'] ?? ''), 0, 10);
            $days = $since !== '' && ($t = strtotime($since . ' UTC')) ? (int) floor((time() - $t) / 86400) : null;
            return ['since' => $since, 'days' => $days, 'first_year' => $days !== null && $days < self::FIRST_YEAR_DAYS];
        });
        $part('followups', static fn(): array => MemberFollowUps::forMember($id));
        $part('commitments', static function () use ($id): array {
            $c = Commitments::completion($id);
            $settled = (int) $c['done'] + (int) $c['missed'];
            return ['done' => (int) $c['done'], 'missed' => (int) $c['missed'], 'open' => (int) $c['open'], 'overdue' => (int) $c['overdue'],
                    'rate' => $c['rate'], 'settled' => $settled, 'enough' => $settled >= self::COMMIT_MIN];
        });
        $part('growth', static function () use ($id): array {
            $r = Levels::recommend($id);
            $p = class_exists('Promotion') ? Promotion::latestFor($id) : null;
            return ['level' => (string) $r['level'], 'level_label' => Levels::labelOf((string) $r['level']),
                    'next' => $r['next'], 'next_label' => $r['next'] !== null ? Levels::labelOf((string) $r['next']) : '',
                    'ready' => (bool) $r['recommend'], 'reasons' => array_values($r['reasons']), 'gaps' => array_values($r['gaps']),
                    'mentees' => (int) ($r['metrics']['active_mentees'] ?? 0), 'days_at_level' => $r['metrics']['days_at_level'] ?? null,
                    'review' => $p ? ['status' => (string) ($p['status'] ?? ''), 'to' => (string) ($p['to_level'] ?? $p['proposed'] ?? ''), 'at' => (string) ($p['created_at'] ?? '')] : null];
        });
        $part('money', static function () use ($id): array {
            $f = NgvFines::forMember($id);
            $a = Membership::arrears($id);
            return ['fines_owing_ngn' => (int) $f['owing'], 'fines_open' => (int) $f['open'],
                    'dues_owes' => (bool) $a['owes'], 'dues_months' => (int) $a['months'], 'dues_ngn' => (int) $a['amount_ngn']];
        });
        $out['vanguard'] = null;
        if (NgvMember::isVanguard($id)) {
            $part('vanguard', static function () use ($id): array {
                $p = NgvMember::participant($id);
                $w = $p ? NgvMember::programmeWindow($p) : null;
                $pts = GateAttendance::points($id);
                $prob = GateAttendance::probationFor($id);
                $acct = NgvMember::feeStatus($id);
                return ['recorded' => (bool) $p, 'status' => (string) ($p['status'] ?? 'card only'), 'track' => (string) ($p['track'] ?? ''),
                        'cohort' => (string) ($p['cohort'] ?? ''), 'phase' => (string) ($p['phase'] ?? ''),
                        'start' => $w['start'] ?? '', 'end' => $w['end'] ?? '', 'months_in' => $w['monthsIn'] ?? 0, 'days_left' => $w['daysLeft'] ?? 0,
                        'points' => (int) $pts['total'], 'points_month' => (int) $pts['month'],
                        'probation' => $prob ? ['since' => (string) $prob['since'], 'until' => (string) $prob['until'], 'reason' => (string) $prob['reason']] : null,
                        'owing_ngn' => (int) $acct['total'], 'card' => GateAttendance::cardFor($id)];
            });
        }
        return $out + self::standing($out);
    }

    /**
     * The last TREND_DAYS against the days before them, from the gate's rows.
     * null when either side has too few counted days to say anything.
     */
    public static function trend(array $rows): ?array
    {
        $cut = gmdate('Y-m-d', strtotime('-' . self::TREND_DAYS . ' days'));
        $n = ['recent' => [0, 0], 'before' => [0, 0]];   // [came, counted]
        foreach ($rows as $r) {
            $st = (string) $r['status'];
            if (!in_array($st, ['present', 'late', 'absent'], true)) continue;
            $k = (string) $r['day'] >= $cut ? 'recent' : 'before';
            $n[$k][1]++; if ($st !== 'absent') $n[$k][0]++;
        }
        if ($n['recent'][1] < self::TREND_MIN || $n['before'][1] < self::TREND_MIN) return null;
        $a = (int) round(100 * $n['recent'][0] / $n['recent'][1]);
        $b = (int) round(100 * $n['before'][0] / $n['before'][1]);
        $dir = $a <= $b - self::TREND_SHIFT ? 'slipping' : ($a >= $b + self::TREND_SHIFT ? 'improving' : 'level');
        return ['recent' => $a, 'before' => $b, 'direction' => $dir];
    }

    /** One word and its reasons. Only evidence counts; too little of it is said. */
    public static function standing(array $e): array
    {
        $concerns = []; $strengths = []; $notes = [];
        $a = $e['attendance'] ?? null;
        if ($a) {
            if (!$a['enough']) $notes[] = 'Too few days at the gate in ' . self::DAYS . ' days to judge attendance (' . $a['counted'] . ' counted).';
            elseif ($a['rate'] < self::ATT_LOW) $concerns[] = 'Came on ' . $a['rate'] . '% of ' . $a['counted'] . ' days (under ' . self::ATT_LOW . '%).';
            elseif ($a['rate'] >= self::ATT_STRONG && $a['punctuality'] >= self::PUNCT_STRONG) $strengths[] = 'Came on ' . $a['rate'] . '% of days, on time ' . $a['punctuality'] . '%.';
            if ($a['late_30'] >= self::LATE_MANY) $concerns[] = 'Late ' . $a['late_30'] . ' times in the last 30 days.';
            $t = $a['trend'] ?? null;
            if ($t && $t['direction'] === 'slipping') $concerns[] = 'Attendance slipping: ' . $t['before'] . '% before, ' . $t['recent'] . '% in the last ' . self::TREND_DAYS . ' days.';
            elseif ($t && $t['direction'] === 'improving') $strengths[] = 'Attendance improving: ' . $t['before'] . '% before, ' . $t['recent'] . '% in the last ' . self::TREND_DAYS . ' days.';
        }
        $c = $e['commitments'] ?? null;
        if ($c) {
            if ($c['enough'] && $c['rate'] !== null && $c['rate'] < self::COMMIT_LOW) $concerns[] = 'Kept ' . $c['rate'] . '% of ' . $c['settled'] . ' commitments.';
            elseif ($c['enough'] && $c['rate'] !== null && $c['rate'] >= 80) $strengths[] = 'Kept ' . $c['rate'] . '% of ' . $c['settled'] . ' commitments.';
            if ($c['overdue'] > 0) $concerns[] = $c['overdue'] . ' commitment' . ($c['overdue'] === 1 ? '' : 's') . ' overdue.';
        }
        $m = $e['money'] ?? null;
        if ($m) {
            if ($m['fines_owing_ngn'] > 0) $concerns[] = '₦' . number_format($m['fines_owing_ngn']) . ' in fines owing.';
            if ($m['dues_owes'] && $m['dues_months'] >= 2) $concerns[] = $m['dues_months'] . ' months behind on dues.';
        }
        $v = $e['vanguard'] ?? null;
        if ($v && $v['probation']) $concerns[] = 'On probation' . ($v['probation']['until'] !== '' ? ' until ' . $v['probation']['until'] : '') . '.';
        $g = $e['growth'] ?? null;
        if ($g && $g['ready']) $strengths[] = 'Meets the bar for ' . $g['next_label'] . '.';

        $ten = $e['tenure'] ?? null;
        if ($ten && $ten['first_year'] && $concerns) $notes[] = 'In their first year, when members most often drift away: follow up early.';
        $open = array_values(array_filter((array) ($e['followups'] ?? []), static fn($f) => $f['status'] === 'open'));
        if ($open && $open[0]['overdue']) $concerns[] = 'Follow-up by ' . $open[0]['owner'] . ' was due ' . $open[0]['due_on'] . '.';
        $standing = $concerns ? 'attention' : ($strengths ? 'well' : 'steady');
        return ['standing' => $standing,
                'standing_label' => ['attention' => 'Needs attention', 'well' => 'Doing well', 'steady' => 'Steady'][$standing],
                'concerns' => $concerns, 'strengths' => $strengths, 'notes' => $notes,
                'followup_open' => $open[0] ?? null];
    }
}
