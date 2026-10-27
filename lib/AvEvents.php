<?php
/**
 * lib/AvEvents.php — Africa GATES events, read from Africa GATES.
 *
 * Africa GATES (afg.afrovanguard.org.ng, the africa-gates repository) keeps
 * its own events calendar. It publishes it as JSON at /api/events
 * (src/Services/EventsFeed.php there): published events only, by the same
 * rules as its /events page, each with its start and end in UTC and the zone
 * it happens in, a cover, an RSVP link and a link to the event there.
 *
 * This used to ask the host for a WordPress events API and RSS feeds. Africa
 * GATES is not WordPress, so every request was a 404 and no Africa GATES event
 * ever reached the home page, /events/ or the portal calendar.
 *
 *   cached     both lists (upcoming, and the recent past) are kept in
 *              data/afg-events.json and read again for TTL seconds; when
 *              Africa GATES does not answer, the last copy is used, however
 *              old, and the Studio says so. After a failed ask it is not asked
 *              again for RETRY seconds. A page never waits more than TIMEOUT.
 *   shaped     each event comes out in the shape the site already used
 *              (title, url, when, day, month, iso, ongoing, location, excerpt,
 *              image) plus source 'gates', end, date/time and the RSVP link.
 *              Times are shown in the event's own zone, not UTC.
 *
 * Source: AV_GATES_URL (Studio or env), default https://afg.afrovanguard.org.ng.
 * It must NEVER throw or block a page.
 */
declare(strict_types=1);

final class AvEvents
{
    public const DEFAULT_SOURCE = 'https://afg.afrovanguard.org.ng';
    private const TTL     = 1800;
    private const RETRY   = 300;
    private const TIMEOUT = 5;

    /** @var null|Closure(string $url): ?string  stands in for Africa GATES in tests */
    private static ?Closure $fetcher = null;
    private static ?array $memo = null;
    private static string $lastError = '';

    public static function useFetcher(?Closure $f): void { self::$fetcher = $f; self::$memo = null; }

    public static function source(): string
    {
        $u = '';
        try { $u = class_exists('Config') ? (string) Config::get('AV_GATES_URL', '') : ''; } catch (Throwable $e) {}
        if ($u === '') $u = (string) getenv('AV_GATES_URL');
        $u = rtrim(trim($u), '/');
        return preg_match('~^https?://[^\s/]+~i', $u) ? $u : self::DEFAULT_SOURCE;
    }

    /** Where Africa GATES events are edited. */
    public static function adminUrl(): string { return self::source() . '/admin/events'; }

    private static function cacheFile(): string
    {
        $dir = (defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__)) . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/afg-events.json';
    }

    /** Up to $limit upcoming or running events, soonest first. Always an array. */
    public static function latest(int $limit = 6): array
    {
        return array_slice(self::load(false)['upcoming'], 0, max(1, min(50, $limit)));
    }

    /** Up to $limit past events, newest first. */
    public static function past(int $limit = 12): array
    {
        return array_slice(self::load(false)['past'], 0, max(1, min(50, $limit)));
    }

    /** For the Studio: when it was last fetched, whether that copy is old, and why. */
    public static function status(bool $refresh = false): array
    {
        $c = self::load($refresh);
        return ['source' => self::source(), 'admin_url' => self::adminUrl(), 'fetched_at' => $c['fetched'] ? gmdate('c', $c['fetched']) : '',
                'stale' => $c['stale'], 'error' => $c['error'], 'upcoming' => count($c['upcoming']), 'past' => count($c['past'])];
    }

    /** ['upcoming','past','fetched','stale','error'] — from the cache while fresh. */
    private static function load(bool $force): array
    {
        if (self::$memo !== null && !$force) return self::$memo;
        $file = self::cacheFile();
        $c = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $c = is_array($c) && isset($c['upcoming']) ? $c : ['fetched' => 0, 'tried' => 0, 'error' => '', 'upcoming' => [], 'past' => []];
        $now = time();
        $fresh = $now - (int) $c['fetched'] < self::TTL;
        $backoff = $now - (int) ($c['tried'] ?? 0) < self::RETRY;
        if (!$force && ($fresh || $backoff)) {
            return self::$memo = self::live($c, !$fresh);
        }
        $up = self::fetch('upcoming', 50);
        $past = is_array($up) ? self::fetch('past', 12) : null;
        if (is_array($up)) {
            $c = ['fetched' => $now, 'tried' => $now, 'error' => '', 'upcoming' => $up, 'past' => is_array($past) ? $past : (array) $c['past']];
        } else {
            $c['tried'] = $now;
            $c['error'] = self::$lastError ?: 'Africa GATES did not answer';
        }
        @file_put_contents($file, json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return self::$memo = self::live($c, !is_array($up));
    }

    /** The cached lists, with "ongoing" worked out now rather than when fetched. */
    private static function live(array $c, bool $stale): array
    {
        $now = time();
        $up = [];
        foreach ((array) $c['upcoming'] as $e) {
            $s = strtotime((string) ($e['iso'] ?? '')) ?: 0;
            $f = strtotime((string) ($e['ends_iso'] ?? '')) ?: 0;
            $over = $f ? $f < $now : ($s && $s < $now - 6 * 3600);
            if ($over) continue;   /* it has ended since it was fetched */
            $e['ongoing'] = $s && $s <= $now;
            $up[] = $e;
        }
        return ['upcoming' => $up, 'past' => (array) $c['past'], 'fetched' => (int) $c['fetched'], 'stale' => $stale, 'error' => (string) ($c['error'] ?? '')];
    }

    private static function fetch(string $scope, int $limit): ?array
    {
        self::$lastError = '';
        $url = self::source() . '/api/events?scope=' . $scope . '&limit=' . $limit;
        $body = self::$fetcher ? (self::$fetcher)($url) : self::get($url);
        if ($body === null) { self::$lastError = self::$lastError ?: 'Africa GATES did not answer at ' . self::source(); return null; }
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['success']) || !is_array($d['events'] ?? null)) {
            self::$lastError = 'Africa GATES answered, but not with its events feed (/api/events). Is the update deployed there?';
            return null;
        }
        $out = [];
        foreach ($d['events'] as $e) {
            if (is_array($e) && ($x = self::norm($e)) !== null) $out[] = $x;
        }
        return $out;
    }

    /** One event from /api/events, in the site's shape. */
    public static function norm(array $e): ?array
    {
        $title = trim((string) ($e['title'] ?? ''));
        $zone = (string) ($e['timezone'] ?? '');
        $start = self::at((string) ($e['starts_at'] ?? ''), $zone);
        if ($title === '' || !$start) return null;
        $end = self::at((string) ($e['ends_at'] ?? ''), $zone);
        $allDay = !empty($e['all_day']);
        $safe = static fn(string $u): string => preg_match('~^https?://[^\s"<>]+$~i', $u) ? $u : '';
        $page = $safe((string) ($e['url'] ?? ''));
        $rsvp = $safe((string) ($e['rsvp_url'] ?? ''));
        $excerpt = trim((string) ($e['tagline'] ?? '')) ?: trim((string) preg_replace('/\s+/', ' ', (string) ($e['description'] ?? '')));
        if (mb_strlen($excerpt) > 160) $excerpt = mb_substr($excerpt, 0, 157) . '…';
        $venue = trim((string) ($e['venue'] ?? '')); $loc = trim((string) ($e['location'] ?? ''));
        $where = $venue === '' ? $loc : ($loc === '' || stripos($venue, $loc) !== false ? $venue : $venue . ', ' . $loc);
        return [
            'source'   => 'gates',
            'id'       => 'gates-' . (int) ($e['id'] ?? 0),
            'title'    => mb_substr($title, 0, 200),
            'url'      => $page ?: ($rsvp ?: self::source() . '/events'),
            'rsvp'     => $rsvp,
            'when'     => $start->format($allDay ? 'D, j M' : 'D, j M · g:ia'),
            'day'      => $start->format('j'),
            'month'    => strtoupper($start->format('M')),
            'iso'      => $start->format(DATE_ATOM),
            'ends_iso' => $end ? $end->format(DATE_ATOM) : '',
            'date'     => $start->format('Y-m-d'),
            'time'     => $allDay ? '' : $start->format('H:i'),
            'end_time' => $end && $end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('H:i') : '',
            'all_day'  => $allDay,
            'ongoing'  => false,
            'location' => $where,
            'excerpt'  => $excerpt,
            'image'    => $safe((string) ($e['cover_image'] ?? '')),
            'category' => '',
            'host'     => trim((string) ($e['host'] ?? '')) ?: 'Africa GATES',
            'price'    => mb_substr(trim((string) ($e['price'] ?? '')), 0, 40),
            'state'    => preg_replace('/[^a-z]/', '', strtolower((string) ($e['state'] ?? ''))),
            'ics'      => $safe((string) ($e['calendar_url'] ?? '')),
            'live'     => $safe((string) ($e['livestream'] ?? '')),
        ];
    }

    /** An ISO time (UTC from Africa GATES) moved into the event's zone; Lagos when it names none. */
    private static function at(string $iso, string $zone = ''): ?DateTimeImmutable
    {
        if ($iso === '') return null;
        try {
            $tz = new DateTimeZone(in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'Africa/Lagos');
            return (new DateTimeImmutable($iso))->setTimezone($tz);
        } catch (Throwable $e) { return null; }
    }

    /** Outbound GET — short timeouts, no exceptions, returns body or null. */
    private static function get(string $url): ?string
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                 CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 3,
                 CURLOPT_USERAGENT => 'AfrovanguardSite/1.0 (+https://afrovanguard.org.ng)', CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if (str_starts_with($url, 'https://')) { $opts[CURLOPT_SSL_VERIFYPEER] = true; $opts[CURLOPT_SSL_VERIFYHOST] = 2; }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);
        if ($err !== '') self::$lastError = 'Could not reach Africa GATES (' . $err . ')';
        elseif ($code >= 400) self::$lastError = 'Africa GATES answered ' . $code . ' for /api/events. Is the update deployed there?';
        return (is_string($body) && $body !== '' && $code < 400) ? $body : null;
    }
}
