<?php
/**
 * lib/Greeting.php — the line at the top of Today.
 *
 * From the Home v4 design ("Greeting — rules"): short, warm, a little
 * different each day, then out of the way. Two to four words and the first
 * name, no full stop, no numbers, no exclamation marks. Facts live under it,
 * never inside it.
 *
 * How one is picked:
 *   1. A moment wins the day — everyone's first (birthday, a session's first
 *      day or final week), then a volunteer's (on duty today).
 *   2. Otherwise one line from the member's pools — everyday, honours,
 *      fits-the-day, and service if they volunteer — chosen by
 *      crc32(member id + date). The same all day, different tomorrow, and no
 *      state stored. Honours come up about one day in four.
 *   3. A sad or formal day (a fine or an appeal waiting) gets a plain line:
 *      no title, no joke.
 *
 * Pure: everything it needs is passed in, so the test can walk every branch
 * without a database. The names that are not the member's ("Quiet hero,
 * welcome") stay rare by being one line in a larger pool.
 */
declare(strict_types=1);

final class Greeting
{
    public const EVERYDAY = ['Hello, %s', 'Back at it, %s', 'What’s next, %s?', 'Ready when you are, %s', 'Good to see you, %s'];

    /* Said with a smile, to every member alike — not an office anybody holds,
       so nothing is stored or chosen. "Ambassador" echoes the seal. */
    public const HONOURS = ['Good day, Your Excellency', 'Welcome, Your Excellency', 'Ambassador %s', 'Honourable %s',
                            'Distinguished %s', 'Chief %s, welcome', 'Odogwu, welcome back'];

    public const SERVICE = ['Thanks for serving, %s', 'Ready to serve, %s?', 'Back on duty, %s', 'Hands on deck, %s',
                            'Service with heart, %s', 'The centre thanks you, %s', 'Good to have you, Volunteer %s',
                            'Serving well, %s', 'Your service counts, %s', 'Quiet hero, welcome'];

    private const WEEKDAY = [1 => 'Happy Monday, %s', 5 => 'Friday at last, %s', 6 => 'Saturday at the centre, %s?'];

    /**
     * @param array{
     *   id:int, first:string, date:string, hour:int,
     *   volunteer?:bool, birthday?:bool, session_day_one?:bool, session_final_week?:bool,
     *   on_duty?:bool, reading?:bool, formal?:bool
     * } $c
     * @return array{text:string, kind:string}
     */
    public static function pick(array $c): array
    {
        $name = trim((string) ($c['first'] ?? '')) ?: 'friend';
        $date = (string) ($c['date'] ?? gmdate('Y-m-d'));
        $hour = (int) ($c['hour'] ?? 12);
        $line = static fn(string $t): string => sprintf($t, $name);

        /* A fine or an appeal is not a day for "Your Excellency". */
        if (!empty($c['formal'])) return ['text' => $line('Hello, %s'), 'kind' => 'plain'];

        /* Moments — always win, everyone's first, then service. */
        if (!empty($c['birthday']))           return ['text' => $line('Happy birthday, %s'), 'kind' => 'moment'];
        if (!empty($c['session_day_one']))    return ['text' => $line('Day one, %s'), 'kind' => 'moment'];
        if (!empty($c['session_final_week'])) return ['text' => $line('Final week, %s'), 'kind' => 'moment'];
        if (!empty($c['volunteer']) && !empty($c['on_duty'])) return ['text' => $line('On duty today, %s'), 'kind' => 'moment'];

        $seed = crc32((int) ($c['id'] ?? 0) . '|' . $date);

        /* Honours about one day in four. */
        if ($seed % 4 === 0) {
            return ['text' => $line(self::HONOURS[intdiv($seed, 4) % count(self::HONOURS)]), 'kind' => 'honours'];
        }

        $pool = self::EVERYDAY;
        $dow = (int) (DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable($date))->format('N');
        if (isset(self::WEEKDAY[$dow])) $pool[] = self::WEEKDAY[$dow];
        if ($hour >= 18) $pool[] = 'Evening, %s';
        if (!empty($c['reading'])) $pool[] = 'Chapter time, %s?';
        if (!empty($c['volunteer'])) $pool = array_merge($pool, self::SERVICE);

        $t = $pool[intdiv($seed, 4) % count($pool)];
        return ['text' => $line($t), 'kind' => in_array($t, self::SERVICE, true) ? 'service' : 'everyday'];
    }

    /**
     * The one quiet line under it, as the design writes it:
     *   in a session      "Week 45 · DOMI 2026, week 9 of 14"   + "41 days left"
     *   before the next   "Week 45 · DOMI 2026 starts in 12 days"
     *   with neither      "Week 45 · Tuesday 3 November"
     * The ISO week of the year first, always. The tail (days left) is a
     * separate part because the phone board leaves it off.
     * @return array{main:string, tail:string}
     */
    public static function sublineParts(string $date, ?array $session): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new DateTimeImmutable();
        $main = 'Week ' . (int) $d->format('W');
        $tail = '';
        if ($session && !empty($session['live'])) {
            $main .= ' · ' . (string) $session['label'] . ', week ' . (int) $session['week_of'] . ' of ' . (int) $session['total_weeks'];
            $left = (int) ($session['days_left'] ?? 0);
            $tail = $left > 0 ? $left . ' day' . ($left === 1 ? '' : 's') . ' left' : 'last day';
        } elseif ($session && ($session['state'] ?? '') === 'upcoming') {
            $n = (int) ($session['days_to_start'] ?? 0);
            $main .= ' · ' . (string) $session['label'] . ($n <= 1 ? ' starts tomorrow' : ' starts in ' . $n . ' days');
        } else {
            $main .= ' · ' . $d->format('l j F');
        }
        return ['main' => $main, 'tail' => $tail];
    }

    /** The whole line as one string (the parts joined), for anywhere but the hero. */
    public static function subline(string $date, ?array $session): string
    {
        $p = self::sublineParts($date, $session);
        return $p['main'] . ($p['tail'] !== '' ? ' · ' . $p['tail'] : '');
    }
}
