<?php
/**
 * lib/ProgrammeSessions.php — the year's programme sessions (TONI, MEDI,
 * SUMMER, DOMI), read from CACENTRE.
 *
 * CACENTRE keeps the one calendar for every site (its
 * includes/programme/ProgrammeSessions.php, edited in its console under
 * Programme sessions). This site keeps none of its own: it reads
 * {CAC_SITE_URL}/api/programme-sessions.php and shows what it is sent — the
 * portal's "live now", the homepage countdown, the Studio's list.
 *
 *   cached     what CACENTRE last sent is kept (meta programme_sessions_cache)
 *              and read again for TTL seconds. When CACENTRE does not answer,
 *              the last good copy is used and the Studio says how old it is;
 *              a page never waits on CACENTRE for more than TIMEOUT seconds.
 *   decided    here, from the dates: what is live, which week, what is next.
 *              So a copy an hour old still turns "next" into "live" on the
 *              morning a session starts. "Today" is Lagos time.
 *   report     what happened on THIS site within a session's dates: gate
 *              visits, volunteer hours (and whose), tasks finished.
 */
declare(strict_types=1);

final class ProgrammeSessions
{
    public const PATH    = '/api/programme-sessions.php';
    public const TZ      = 'Africa/Lagos';
    public const TTL     = 600;
    private const TIMEOUT = 3;
    private const META   = 'programme_sessions_cache';

    /** @var null|Closure(?int $year): ?array  stands in for CACENTRE in tests */
    private static ?Closure $source = null;
    private static ?array $memo = null;
    private static array $years = [];

    public static function useSource(?Closure $fn): void { self::$source = $fn; self::$memo = null; self::$years = []; }

    public static function today(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** Where the calendar is edited. */
    public static function consoleUrl(): string
    {
        $b = class_exists('CacSso') ? CacSso::base() : '';
        return $b !== '' ? $b . '/admin/programme-sessions.php' : '';
    }

    /* ── Reading CACENTRE ──────────────────────────────────────────────── */

    /**
     * The sessions CACENTRE sent (last year to next year), from the cache
     * while it is fresh. Returns ['sessions', 'fetched_at', 'stale', 'error'].
     */
    public static function calendar(bool $force = false): array
    {
        if (self::$memo !== null && !$force) return self::$memo;
        $cache = json_decode((string) (Database::metaGet(self::META) ?? ''), true);
        $cache = is_array($cache) ? $cache : null;
        $age = $cache ? time() - (int) ($cache['fetched_at'] ?? 0) : PHP_INT_MAX;
        if (!$force && $cache && $age < self::TTL) {
            return self::$memo = ['sessions' => (array) ($cache['sessions'] ?? []), 'fetched_at' => (int) $cache['fetched_at'], 'stale' => false, 'error' => ''];
        }
        $d = self::fetch(null);
        if (is_array($d) && !empty($d['ok']) && is_array($d['sessions'] ?? null)) {
            $rows = array_values(array_filter(array_map([self::class, 'clean'], $d['sessions'])));
            Database::metaSet(self::META, json_encode(['fetched_at' => time(), 'sessions' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::$memo = ['sessions' => $rows, 'fetched_at' => time(), 'stale' => false, 'error' => ''];
        }
        $why = is_array($d) ? (string) ($d['error'] ?? 'refused') : (self::consoleUrl() === '' ? 'not-configured' : 'unreachable');
        return self::$memo = ['sessions' => (array) ($cache['sessions'] ?? []), 'fetched_at' => (int) ($cache['fetched_at'] ?? 0), 'stale' => true, 'error' => $why];
    }

    /** A year's sessions, each with its state today. Outside the cached window, asked for. */
    public static function forYear(int $year, ?int $now = null): array
    {
        $rows = array_values(array_filter(self::calendar()['sessions'], static fn($r) => (int) $r['year'] === $year));
        $cy = (int) substr(self::today($now), 0, 4);
        if (!$rows && abs($year - $cy) > 1) {
            if (!array_key_exists($year, self::$years)) {
                $d = self::fetch($year);
                self::$years[$year] = is_array($d) && !empty($d['ok']) ? array_values(array_filter(array_map([self::class, 'clean'], (array) ($d['year_sessions'] ?? [])))) : [];
            }
            $rows = self::$years[$year];
        }
        $today = self::today($now);
        usort($rows, static fn($a, $b) => strcmp($a['start_date'], $b['start_date']));
        return array_map(static fn($r) => self::shape($r, $today), $rows);
    }

    public static function find(string $sessionId): ?array
    {
        foreach (self::calendar()['sessions'] as $r) if ($r['session_id'] === $sessionId) return self::shape($r, self::today());
        if (preg_match('/-(\d{4})$/', $sessionId, $m)) {
            foreach (self::forYear((int) $m[1]) as $s) if ($s['session_id'] === $sessionId) return $s;
        }
        return null;
    }

    /**
     * The session running today, the next to start, the featured one, and
     * which of them the homepage counts down to.
     */
    public static function now(?int $now = null): array
    {
        $today = self::today($now);
        $cur = null; $next = null; $feat = null;
        foreach (self::calendar()['sessions'] as $r) {
            if ($r['start_date'] <= $today && $r['end_date'] >= $today && (!$cur || $r['start_date'] >= $cur['start_date'])) $cur = $r;
            if ($r['start_date'] > $today && (!$next || $r['start_date'] < $next['start_date'])) $next = $r;
            if (!empty($r['featured']) && $r['end_date'] >= $today) $feat = $r;
        }
        $out = [
            'today'    => $today,
            'current'  => $cur ? self::shape($cur, $today) : null,
            'next'     => $next ? self::shape($next, $today) : null,
            'featured' => $feat ? self::shape($feat, $today) : null,
        ];
        $out['countdown'] = ($out['featured'] && $out['featured']['state'] === 'upcoming') ? $out['featured'] : $out['next'];
        return $out;
    }

    private static function fetch(?int $year): ?array
    {
        if (self::$source) return (self::$source)($year);
        $base = class_exists('CacSso') ? CacSso::base() : '';
        if ($base === '' || !function_exists('curl_init')) return null;
        $ch = curl_init($base . self::PATH . ($year !== null ? '?year=' . $year : ''));
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Afrovanguard/1.0 (+sessions)', CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if (str_starts_with($base, 'https://')) { $opts[CURLOPT_SSL_VERIFYPEER] = true; $opts[CURLOPT_SSL_VERIFYHOST] = 2; }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        if (class_exists('CacBridge')) CacBridge::see($ch, $out, 'sessions');
        curl_close($ch);
        $d = is_string($out) && $out !== '' ? json_decode($out, true) : null;
        return is_array($d) ? $d : null;
    }

    /** One session as CACENTRE sent it, checked, or null. */
    private static function clean($r): ?array
    {
        if (!is_array($r)) return null;
        $sid = (string) ($r['session_id'] ?? ''); $s = (string) ($r['start_date'] ?? ''); $e = (string) ($r['end_date'] ?? '');
        if (!preg_match('/^[A-Z][A-Z0-9]{1,15}-\d{4}$/', $sid) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) || $e < $s) return null;
        $cover = (string) ($r['cover_url'] ?? '');
        if ($cover !== '' && !preg_match('~^(https://[^\s"<>]+|/[^\s"<>]*)$~', $cover)) $cover = '';
        /* A cover CACENTRE gave as a path is on CACENTRE, not here. */
        if ($cover !== '' && $cover[0] === '/' && class_exists('CacSso') && CacSso::base() !== '') $cover = CacSso::base() . $cover;
        return [
            'session_id' => $sid, 'code' => (string) ($r['code'] ?? strtok($sid, '-')), 'year' => (int) ($r['year'] ?? substr($sid, -4)),
            'name' => mb_substr((string) ($r['name'] ?? ''), 0, 80), 'start_date' => $s, 'end_date' => $e,
            'theme' => mb_substr((string) ($r['theme'] ?? ''), 0, 160), 'cover_url' => $cover, 'featured' => !empty($r['featured']),
        ];
    }

    /* ── What happened here in a session ───────────────────────────────── */

    /** Gate visits, volunteer hours and tasks finished within its dates. Each part fails soft. */
    public static function report(string $sessionId): ?array
    {
        $s = self::find($sessionId);
        if (!$s) return null;
        $pdo = Database::pdo();
        $from = $s['start_date']; $to = min($s['end_date'], self::today());
        $out = ['session' => $s, 'visits' => 0, 'visitors' => 0, 'volunteer_hours' => 0.0, 'volunteers' => 0, 'top_volunteers' => [], 'tasks_done' => 0];
        if ($from > $to) return $out;   /* not started yet */
        try {
            if (class_exists('GateAttendance')) GateAttendance::ensure();
            $st = $pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT member_id) FROM gate_attendance WHERE day >= ? AND day <= ? AND status <> 'absent'");
            $st->execute([$from, $to]);
            [$out['visits'], $out['visitors']] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare('SELECT COALESCE(SUM(minutes), 0), COUNT(DISTINCT user_id) FROM vol_hours WHERE day >= ? AND day <= ?');
            $st->execute([$from, $to]);
            [$m, $n] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $out['volunteer_hours'] = round((int) $m / 60, 1); $out['volunteers'] = (int) $n;
            $st = $pdo->prepare('SELECT h.user_id, u.name, SUM(h.minutes) m FROM vol_hours h LEFT JOIN lms_users u ON u.id = h.user_id WHERE h.day >= ? AND h.day <= ? GROUP BY h.user_id, u.name ORDER BY m DESC LIMIT 5');
            $st->execute([$from, $to]);
            $out['top_volunteers'] = array_map(static fn($r) => ['id' => (int) $r['user_id'], 'name' => (string) ($r['name'] ?? '') ?: 'Member ' . (int) $r['user_id'], 'hours' => round((int) $r['m'] / 60, 1)],
                $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM collab_tasks WHERE done = 1 AND done_at >= ? AND done_at < ?');
            $st->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
            $out['tasks_done'] = (int) $st->fetchColumn();
        } catch (Throwable $e) {}
        return $out;
    }

    /* ── Shapes ────────────────────────────────────────────────────────── */

    public static function label(array $r): string
    {
        $name = trim((string) ($r['name'] ?? '')) ?: (string) ($r['code'] ?? '');
        $year = (string) ($r['year'] ?? '');
        return str_contains($name, $year) ? $name : trim($name . ' ' . $year);
    }

    public static function span(string $from, string $to): string
    {
        $a = strtotime($from); $b = strtotime($to);
        if (!$a || !$b) return $from . ' – ' . $to;
        return date('Y', $a) === date('Y', $b) ? date('j M', $a) . ' – ' . date('j M Y', $b) : date('j M Y', $a) . ' – ' . date('j M Y', $b);
    }

    public static function shape(array $r, string $today): array
    {
        $start = (string) $r['start_date']; $end = (string) $r['end_date'];
        $state = $today < $start ? 'upcoming' : ($today > $end ? 'past' : 'live');
        $days = static fn(string $a, string $b): int => (int) round((strtotime($b . ' 12:00 UTC') - strtotime($a . ' 12:00 UTC')) / 86400);
        $total = max(1, (int) ceil(($days($start, $end) + 1) / 7));
        return [
            'session_id' => (string) $r['session_id'], 'code' => (string) $r['code'], 'year' => (int) $r['year'],
            'name' => (string) $r['name'] ?: (string) $r['code'], 'label' => self::label($r),
            'start_date' => $start, 'end_date' => $end, 'dates' => self::span($start, $end),
            'theme' => (string) $r['theme'], 'cover_url' => (string) $r['cover_url'],
            'state' => $state, 'live' => $state === 'live', 'featured' => !empty($r['featured']),
            'total_weeks' => $total,
            'week_of' => $state === 'live' ? min($total, intdiv($days($start, $today), 7) + 1) : null,
            'days_to_start' => $state === 'upcoming' ? $days($today, $start) : null,
            'days_left' => $state === 'live' ? $days($today, $end) : null,
            'starts_at' => (new DateTimeImmutable($start . ' 00:00', new DateTimeZone(self::TZ)))->format(DATE_ATOM),
        ];
    }
}
