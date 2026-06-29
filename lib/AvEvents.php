<?php
/**
 * lib/AvEvents.php — pull the latest / ongoing events from the AFG sub-site
 * (afg.afrovanguard.org.ng) for display on the main site's home page.
 *
 * Best-effort and FAIL-SAFE: it caches to disk, tries a couple of structured
 * sources (The Events Calendar REST API, then an RSS feed), and on any failure
 * returns the last good cache (or an empty list). It must NEVER throw or block
 * a page — the home banner simply hides itself when there are no events.
 */
declare(strict_types=1);

final class AvEvents
{
    private const SOURCE   = 'https://afg.afrovanguard.org.ng';
    private const TTL      = 1800;          // 30 min fresh window
    private const STALE_OK = 86400;         // serve stale up to a day on fetch failure

    private static function cacheFile(): string
    {
        $dir = (defined('AV_ROOT') ? AV_ROOT : dirname(__DIR__)) . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/afg-events.json';
    }

    /** Up to $limit upcoming/ongoing events. Always returns an array. */
    public static function latest(int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $file  = self::cacheFile();
        $now   = time();

        // Fresh cache → use it.
        if (is_file($file)) {
            $age = $now - (int) @filemtime($file);
            $cached = json_decode((string) @file_get_contents($file), true);
            if (is_array($cached) && isset($cached['events']) && $age < self::TTL) {
                return array_slice($cached['events'], 0, $limit);
            }
        }

        // Refresh from the source (each strategy is wrapped; none may throw out).
        $events = [];
        try { $events = self::fromTribeApi($limit); } catch (\Throwable $e) { error_log('[events] tribe: ' . $e->getMessage()); }
        if (!$events) { try { $events = self::fromRss($limit); } catch (\Throwable $e) { error_log('[events] rss: ' . $e->getMessage()); } }

        if ($events) {
            @file_put_contents($file, json_encode(['fetched' => $now, 'events' => $events]));
            return array_slice($events, 0, $limit);
        }

        // Fetch failed → serve stale cache if it isn't ancient.
        if (is_file($file)) {
            $age = $now - (int) @filemtime($file);
            $cached = json_decode((string) @file_get_contents($file), true);
            if (is_array($cached) && isset($cached['events']) && $age < self::STALE_OK) {
                return array_slice($cached['events'], 0, $limit);
            }
        }
        return [];
    }

    /** The Events Calendar (Tribe) public REST API — the common WP events plugin. */
    private static function fromTribeApi(int $limit): array
    {
        $today = date('Y-m-d');
        $url = self::SOURCE . '/wp-json/tribe/events/v1/events?per_page=' . $limit . '&start_date=' . $today . '&status=publish';
        $body = self::get($url);
        if (!$body) return [];
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['events']) || !is_array($d['events'])) return [];
        $out = [];
        foreach ($d['events'] as $e) {
            $start = (string) ($e['start_date'] ?? $e['utc_start_date'] ?? '');
            $out[] = self::norm([
                'title'    => (string) ($e['title'] ?? ''),
                'url'      => (string) ($e['url'] ?? ($e['website'] ?? self::SOURCE . '/events')),
                'start'    => $start,
                'end'      => (string) ($e['end_date'] ?? ''),
                'all_day'  => !empty($e['all_day']),
                'location' => (string) ($e['venue']['venue'] ?? ($e['venue']['city'] ?? '')),
                'excerpt'  => strip_tags((string) ($e['excerpt'] ?? $e['description'] ?? '')),
                'image'    => (string) (is_array($e['image'] ?? null) ? ($e['image']['url'] ?? '') : ($e['image'] ?? '')),
            ]);
        }
        return array_values(array_filter($out, fn($x) => $x['title'] !== ''));
    }

    /** RSS fallback: /events/feed/ (works for most WP events archives). */
    private static function fromRss(int $limit): array
    {
        foreach (['/events/feed/', '/feed/?post_type=tribe_events', '/feed/'] as $path) {
            $body = self::get(self::SOURCE . $path);
            if (!$body || stripos($body, '<item') === false) continue;
            $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$xml || !isset($xml->channel->item)) continue;
            $out = [];
            foreach ($xml->channel->item as $item) {
                $start = (string) $item->pubDate;
                $out[] = self::norm([
                    'title'    => trim((string) $item->title),
                    'url'      => trim((string) $item->link),
                    'start'    => $start ? date('c', strtotime($start)) : '',
                    'end'      => '',
                    'all_day'  => false,
                    'location' => '',
                    'excerpt'  => trim(strip_tags((string) $item->description)),
                    'image'    => '',
                ]);
                if (count($out) >= $limit) break;
            }
            if ($out) return $out;
        }
        return [];
    }

    /** Normalise one event into the shape the home banner consumes. */
    private static function norm(array $e): array
    {
        $ts = $e['start'] ? strtotime($e['start']) : 0;
        $endTs = !empty($e['end']) ? strtotime($e['end']) : 0;
        $ongoing = $ts && $endTs && $ts <= time() && $endTs >= time();
        $excerpt = trim(preg_replace('/\s+/', ' ', (string) $e['excerpt']));
        if (mb_strlen($excerpt) > 160) $excerpt = mb_substr($excerpt, 0, 157) . '…';
        return [
            'title'    => trim((string) $e['title']),
            'url'      => (string) $e['url'],
            'when'     => $ts ? date($e['all_day'] ? 'D, j M' : 'D, j M · g:ia', $ts) : '',
            'day'      => $ts ? date('j', $ts) : '',
            'month'    => $ts ? strtoupper(date('M', $ts)) : '',
            'iso'      => $ts ? date('c', $ts) : '',
            'ongoing'  => $ongoing,
            'location' => trim((string) $e['location']),
            'excerpt'  => $excerpt,
            'image'    => (string) $e['image'],
        ];
    }

    /** Outbound GET — short timeouts, no exceptions, returns body or null. */
    private static function get(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => 'AfrovanguardSite/1.0 (+https://afrovanguard.org.ng)',
                CURLOPT_HTTPHEADER     => ['accept: application/json, application/rss+xml, */*'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return (is_string($body) && $body !== '' && $code < 400) ? $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false && $body !== '') ? $body : null;
    }
}
