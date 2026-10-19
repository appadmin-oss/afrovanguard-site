<?php
/**
 * lib/Recurrence.php — how a meeting repeats, said once and read everywhere.
 *
 * A meeting used to repeat by one of six words (daily, weekdays, weekly,
 * fortnightly, monthly). People meet on Mondays and Thursdays, every third
 * week, on the last Friday of the month, ten times and then stop, and not on
 * the public holiday in the middle. This is the rule that says all of that:
 *
 *   freq      daily | weekly | monthly | yearly
 *   interval  every 1–99 of those
 *   days      weekly: which weekdays (MO…SU); none means the start's own day
 *   monthly   date (the 15th) | nth (the second Tuesday) | last (the last Friday),
 *             counted from the start
 *   end       never | until (a date, inclusive) | count (this many times)
 *   skip      dates it does not happen (at most 100)
 *
 * Stored as JSON with the meeting (meetings.recur), sent to Google Calendar
 * as RFC 5545 (RRULE + EXDATE: Google expands it, invites and reminds), and
 * expanded here — in the meeting's timezone, so 10:00 stays 10:00 — to say
 * when it next happens and to show the next dates before anybody saves.
 *
 * Pure: no database, no clock unless one is passed in.
 */
declare(strict_types=1);

final class Recurrence
{
    public const FREQS = ['daily', 'weekly', 'monthly', 'yearly'];
    public const DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    private const DAY_NAMES = ['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'];
    private const DAY_LONG = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];
    private const ORD = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];
    /** Candidates looked at before giving up: a rule can never spin a page. */
    private const WALK = 5000;

    /** The meeting timezone: the one Google expands the series in. */
    public static function tz(): string
    {
        $tz = class_exists('Config') ? (string) Config::get('AV_WS_TZ', 'Africa/Lagos') : 'Africa/Lagos';
        try { new DateTimeZone($tz); return $tz; } catch (Throwable $e) { return 'Africa/Lagos'; }
    }

    /** A UTC 'Y-m-d H:i:s' as a wall-clock time in the meeting timezone. */
    public static function local(string $utc, ?string $tz = null): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz ?? self::tz()));
    }

    /** What the old one-word cadences mean, as a rule. null is a one-off. */
    public static function fromPreset(string $freq, DateTimeImmutable $start): ?array
    {
        $day = self::dayOf($start);
        return match ($freq) {
            'daily'    => self::normalise(['freq' => 'daily'], $start),
            'weekdays' => self::normalise(['freq' => 'weekly', 'days' => ['MO', 'TU', 'WE', 'TH', 'FR']], $start),
            'weekly'   => self::normalise(['freq' => 'weekly', 'days' => [$day]], $start),
            'biweekly' => self::normalise(['freq' => 'weekly', 'interval' => 2, 'days' => [$day]], $start),
            'monthly'  => self::normalise(['freq' => 'monthly'], $start),
            default    => null,
        };
    }

    /** The old word for a rule, where there is one ('custom' otherwise, 'once' for none). */
    public static function presetOf(?array $s, DateTimeImmutable $start): string
    {
        if ($s === null) return 'once';
        foreach (['daily', 'weekdays', 'weekly', 'biweekly', 'monthly'] as $p) {
            if (self::fromPreset($p, $start) == $s) return $p;
        }
        return 'custom';
    }

    /**
     * A rule from a form (or stored JSON), checked and put in one shape; null
     * when it does not repeat. Anything it cannot use falls back to a safe value
     * rather than failing: a bad interval is 1, an unknown day is dropped.
     */
    public static function normalise($in, DateTimeImmutable $start): ?array
    {
        if (is_string($in)) $in = json_decode($in, true);
        if (!is_array($in)) return null;
        $freq = (string) ($in['freq'] ?? '');
        if (!in_array($freq, self::FREQS, true)) return null;
        $s = ['freq' => $freq, 'interval' => max(1, min(99, (int) ($in['interval'] ?? 1)))];

        if ($freq === 'weekly') {
            $days = $in['days'] ?? [];
            if (is_string($days)) $days = preg_split('/[\s,]+/', $days) ?: [];
            $days = array_values(array_intersect(self::DAYS, array_map(static fn($d) => strtoupper(substr(trim((string) $d), 0, 2)), (array) $days)));
            $s['days'] = $days ?: [self::dayOf($start)];
        }
        if ($freq === 'monthly') {
            $m = (string) ($in['monthly'] ?? 'date');
            $s['monthly'] = in_array($m, ['date', 'nth', 'last'], true) ? $m : 'date';
            /* The fifth Tuesday is not in every month: it is the last one. */
            if ($s['monthly'] === 'nth' && self::nthOf($start) > 4) $s['monthly'] = 'last';
        }

        $end = (string) ($in['end'] ?? 'never');
        $s['end'] = 'never';
        if ($end === 'until' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['until'] ?? '')) && (string) $in['until'] >= $start->format('Y-m-d')) {
            $s['end'] = 'until'; $s['until'] = (string) $in['until'];
        } elseif ($end === 'count' && (int) ($in['count'] ?? 0) > 0) {
            $s['end'] = 'count'; $s['count'] = min(500, (int) $in['count']);
        }

        $skip = $in['skip'] ?? [];
        if (is_string($skip)) $skip = preg_split('/[\s,;]+/', $skip) ?: [];
        $skip = array_values(array_unique(array_filter(array_map(static fn($d) => trim((string) $d), (array) $skip),
            static fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)))));
        sort($skip);
        if ($skip) $s['skip'] = array_slice($skip, 0, 100);
        return $s;
    }

    /**
     * Every occurrence, in order, from the start: wall-clock times in the
     * start's timezone. COUNT counts skipped dates too (RFC 5545, as Google
     * does): "ten times, not on the 12th" is nine meetings.
     *
     * @return Generator<DateTimeImmutable>
     */
    public static function each(array $s, DateTimeImmutable $start): Generator
    {
        $skip = array_flip($s['skip'] ?? []);
        $n = 0; $looked = 0;
        foreach (self::candidates($s, $start) as $c) {
            if (++$looked > self::WALK) return;
            if ($c < $start) continue;
            if (($s['end'] ?? '') === 'until' && $c->format('Y-m-d') > $s['until']) return;
            if (($s['end'] ?? '') === 'count' && $n >= (int) $s['count']) return;
            $n++;
            if (!isset($skip[$c->format('Y-m-d')])) yield $c;
        }
    }

    /** The rule's dates, before the end and the skips are applied. */
    private static function candidates(array $s, DateTimeImmutable $start): Generator
    {
        $i = (int) $s['interval'];
        [$h, $m, $sec] = [(int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s')];
        switch ($s['freq']) {
            case 'daily':
                for ($k = 0; ; $k++) yield $start->modify('+' . ($k * $i) . ' day');
            case 'weekly':
                $monday = $start->modify('-' . ((int) $start->format('N') - 1) . ' day');
                $offs = array_map(static fn($d) => array_search($d, self::DAYS, true), $s['days']);
                sort($offs);
                for ($w = 0; ; $w++) {
                    $base = $monday->modify('+' . ($w * $i) . ' week');
                    foreach ($offs as $o) yield $base->modify('+' . $o . ' day')->setTime($h, $m, $sec);
                }
            case 'monthly':
                $first = $start->modify('first day of this month')->setTime($h, $m, $sec);
                $day = (int) $start->format('j');
                $wd = $start->format('l');
                $nth = self::nthOf($start);
                for ($k = 0; ; $k++) {
                    $mo = $first->modify('+' . ($k * $i) . ' month');
                    if ($s['monthly'] === 'date') {
                        if ($day <= (int) $mo->format('t')) yield $mo->setDate((int) $mo->format('Y'), (int) $mo->format('n'), $day);
                    } elseif ($s['monthly'] === 'last') {
                        yield $mo->modify('last ' . $wd . ' of this month')->setTime($h, $m, $sec);
                    } else {
                        $c = $mo->modify(self::ORD[$nth] . ' ' . $wd . ' of this month')->setTime($h, $m, $sec);
                        if ((int) $c->format('n') === (int) $mo->format('n')) yield $c;
                    }
                }
            case 'yearly':
                [$mon, $day] = [(int) $start->format('n'), (int) $start->format('j')];
                for ($k = 0; ; $k++) {
                    $y = (int) $start->format('Y') + $k * $i;
                    if (checkdate($mon, $day, $y)) yield $start->setDate($y, $mon, $day);
                }
        }
    }

    /** The first time it really happens: the start itself, or the first day the rule allows after it. */
    public static function first(?array $s, DateTimeImmutable $start): ?DateTimeImmutable
    {
        if ($s === null) return $start;
        foreach (self::each($s, $start) as $c) return $c;
        return null;
    }

    /** The next $limit occurrences that have not finished by $from. */
    public static function upcoming(?array $s, DateTimeImmutable $start, int $from, int $limit = 5, int $durationMin = 0): array
    {
        if ($s === null) return $start->getTimestamp() + $durationMin * 60 >= $from ? [$start] : [];
        $out = [];
        foreach (self::each($s, $start) as $c) {
            if ($c->getTimestamp() + $durationMin * 60 < $from) continue;
            $out[] = $c;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** RFC 5545 lines for Google's `recurrence` (empty for a one-off). */
    public static function lines(?array $s, DateTimeImmutable $start): array
    {
        if ($s === null) return [];
        $r = 'FREQ=' . strtoupper($s['freq']);
        if ($s['interval'] > 1) $r .= ';INTERVAL=' . $s['interval'];
        if ($s['freq'] === 'weekly') $r .= ';BYDAY=' . implode(',', $s['days']);
        if ($s['freq'] === 'monthly') {
            $d = self::dayOf($start);
            $r .= match ($s['monthly']) { 'nth' => ';BYDAY=' . self::nthOf($start) . $d, 'last' => ';BYDAY=-1' . $d, default => ';BYMONTHDAY=' . $start->format('j') };
        }
        if ($s['end'] === 'until') {
            $u = (new DateTimeImmutable($s['until'] . ' 23:59:59', $start->getTimezone()))->setTimezone(new DateTimeZone('UTC'));
            $r .= ';UNTIL=' . $u->format('Ymd\THis\Z');
        } elseif ($s['end'] === 'count') {
            $r .= ';COUNT=' . $s['count'];
        }
        $out = ['RRULE:' . $r];
        if (!empty($s['skip'])) {
            $t = $start->format('\THis');
            $out[] = 'EXDATE;TZID=' . $start->getTimezone()->getName() . ':' . implode(',', array_map(static fn($d) => str_replace('-', '', $d) . $t, $s['skip']));
        }
        return $out;
    }

    /** "Every 2 weeks on Mon and Thu, 10 times, except 1 date" — or "One-off". */
    public static function label(?array $s, DateTimeImmutable $start): string
    {
        if ($s === null) return 'One-off';
        $i = (int) $s['interval'];
        $every = static fn(string $one, string $many) => $i === 1 ? 'Every ' . $one : 'Every ' . $i . ' ' . $many;
        switch ($s['freq']) {
            case 'daily':  $t = $every('day', 'days'); break;
            case 'weekly':
                if ($i === 1 && $s['days'] === ['MO', 'TU', 'WE', 'TH', 'FR']) { $t = 'Every weekday'; break; }
                $names = array_map(static fn($d) => self::DAY_NAMES[$d], $s['days']);
                $t = $every('week', 'weeks') . ' on ' . (count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : $names[0]);
                break;
            case 'monthly':
                $wd = self::DAY_LONG[self::dayOf($start)];
                $t = $every('month', 'months') . ' on the ' . match ($s['monthly']) {
                    'nth' => self::ORD[self::nthOf($start)] . ' ' . $wd, 'last' => 'last ' . $wd, default => self::ordinal((int) $start->format('j')) };
                break;
            default: $t = $every('year', 'years') . ' on ' . $start->format('j F');
        }
        if ($s['end'] === 'until') $t .= ', until ' . (new DateTimeImmutable($s['until']))->format('j M Y');
        if ($s['end'] === 'count') $t .= ', ' . $s['count'] . ' time' . ($s['count'] === 1 ? '' : 's');
        $k = count($s['skip'] ?? []);
        if ($k) $t .= ', except ' . $k . ' date' . ($k === 1 ? '' : 's');
        return $t;
    }

    private static function dayOf(DateTimeImmutable $d): string { return self::DAYS[(int) $d->format('N') - 1]; }
    private static function nthOf(DateTimeImmutable $d): int { return intdiv((int) $d->format('j') - 1, 7) + 1; }
    private static function ordinal(int $n): string
    {
        $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        return $n . $s;
    }
}
