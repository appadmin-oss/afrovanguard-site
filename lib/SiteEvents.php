<?php
/**
 * lib/SiteEvents.php — Afrovanguard's events: its own, and Africa GATES'.
 *
 * Afrovanguard's own events (town halls, the gala, workshops, meetups) are
 * kept here and edited in the Studio (Events). Africa GATES keeps its own
 * calendar and is read from it (lib/AvEvents.php). Everywhere events are shown
 * — /events/, the home page, the portal's Today and calendar — gets one list,
 * soonest first, each event saying whose it is.
 *
 *   audience   who sees an event of ours: public (the site, everyone),
 *              members (signed in to the portal), org (Afrovanguard's own
 *              team). Africa GATES events are public.
 *   status     draft (only the Studio sees it), published, cancelled (still
 *              shown, marked, so nobody turns up to something that is off).
 *   times      Lagos wall-clock times, as people type them; "all day" has no
 *              time. Validated: real dates, the end after the start.
 *   pages      a published public event has its own page, /events/{slug}/,
 *              with "add to calendar"; /events/calendar.ics is the public
 *              calendar to subscribe to.
 */
declare(strict_types=1);

final class SiteEvents
{
    public const TZ = 'Africa/Lagos';
    public const AUDIENCES = ['public' => 'Everyone', 'members' => 'Members (signed in)', 'org' => 'The Afrovanguard team'];
    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'cancelled' => 'Cancelled'];
    public const CATEGORIES = ['Leadership', 'Learning', 'Arts', 'Community'];

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        Database::execSchema(Database::pdo(), "CREATE TABLE IF NOT EXISTS av_site_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug VARCHAR(120) NOT NULL DEFAULT '',
            title VARCHAR(200) NOT NULL DEFAULT '',
            summary VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT,
            category VARCHAR(20) NOT NULL DEFAULT '',
            starts_at VARCHAR(16) NOT NULL DEFAULT '',
            ends_at VARCHAR(16) NOT NULL DEFAULT '',
            all_day INTEGER NOT NULL DEFAULT 0,
            venue VARCHAR(200) NOT NULL DEFAULT '',
            location VARCHAR(160) NOT NULL DEFAULT '',
            online_url VARCHAR(500) NOT NULL DEFAULT '',
            cover_url VARCHAR(500) NOT NULL DEFAULT '',
            rsvp_url VARCHAR(500) NOT NULL DEFAULT '',
            audience VARCHAR(10) NOT NULL DEFAULT 'public',
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            google_event_id VARCHAR(128) NOT NULL DEFAULT '',
            created_by VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL DEFAULT '',
            updated_at VARCHAR(32) NOT NULL DEFAULT ''
        );
        CREATE UNIQUE INDEX IF NOT EXISTS ux_av_site_events_slug ON av_site_events(slug);
        CREATE INDEX IF NOT EXISTS ix_av_site_events_start ON av_site_events(status, starts_at)");
    }

    private static function tz(): DateTimeZone { return new DateTimeZone(self::TZ); }
    private static function nowLocal(?int $now = null): string
    {
        return (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(self::tz())->format('Y-m-d H:i');
    }

    /* ── Our own: the Studio ───────────────────────────────────────────── */

    public static function find(int $id): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM av_site_events WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::row($r) : null;
    }

    public static function bySlug(string $slug): ?array
    {
        self::ensure();
        $st = Database::pdo()->prepare('SELECT * FROM av_site_events WHERE slug = ?');
        $st->execute([$slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::row($r) : null;
    }

    /** Every event of ours for the Studio: upcoming soonest first, then the past, newest first. */
    public static function all(?int $now = null): array
    {
        self::ensure();
        $rows = array_map([self::class, 'row'], Database::pdo()->query('SELECT * FROM av_site_events ORDER BY starts_at')->fetchAll(PDO::FETCH_ASSOC));
        $n = self::nowLocal($now);
        $up = array_values(array_filter($rows, static fn($r) => self::endOf($r) >= $n));
        $past = array_reverse(array_values(array_filter($rows, static fn($r) => self::endOf($r) < $n)));
        return ['upcoming' => $up, 'past' => $past];
    }

    private const FIELDS = ['title', 'summary', 'body', 'category', 'starts_at', 'ends_at', 'all_day', 'venue', 'location', 'online_url', 'cover_url', 'rsvp_url', 'audience', 'status'];

    /** Add (id 0) or change one. ['ok', 'event'] or ['ok' => false, 'error'] in words. */
    public static function save(array $in, string $actor): array
    {
        self::ensure();
        $pdo = Database::pdo();
        $id = (int) ($in['id'] ?? 0);
        $was = $id > 0 ? self::find($id) : null;
        if ($id > 0 && !$was) return ['ok' => false, 'error' => 'That event is no longer there. Reload the list.'];
        $v = [];
        foreach (self::FIELDS as $f) $v[$f] = array_key_exists($f, $in) ? $in[$f] : ($was[$f] ?? '');
        foreach ($v as $k => $x) if (!is_bool($x)) $v[$k] = trim((string) $x);
        $v['all_day'] = !empty($v['all_day']) && $v['all_day'] !== '0' ? 1 : 0;
        $v['body'] = str_replace("\r", '', (string) $v['body']);

        if ($v['title'] === '') return ['ok' => false, 'error' => 'Give the event a title.'];
        if (mb_strlen($v['title']) > 200) return ['ok' => false, 'error' => 'Keep the title to 200 characters.'];
        if (mb_strlen($v['summary']) > 255) return ['ok' => false, 'error' => 'Keep the one-line summary to 255 characters.'];
        if (mb_strlen($v['body']) > 8000) return ['ok' => false, 'error' => 'Keep the description to 8,000 characters.'];
        if ($v['category'] !== '' && !in_array($v['category'], self::CATEGORIES, true)) return ['ok' => false, 'error' => 'Choose a category from the list.'];
        if (!isset(self::AUDIENCES[$v['audience']])) $v['audience'] = 'public';
        if (!isset(self::STATUSES[$v['status']])) $v['status'] = 'draft';
        $start = self::parse($v['starts_at'], (bool) $v['all_day']);
        if ($start === null) return ['ok' => false, 'error' => 'Give the start as a real date' . ($v['all_day'] ? '' : ' and time') . '.'];
        $v['starts_at'] = $start;
        if ($v['ends_at'] !== '') {
            $end = self::parse($v['ends_at'], (bool) $v['all_day']);
            if ($end === null) return ['ok' => false, 'error' => 'Give the end as a real date' . ($v['all_day'] ? '' : ' and time') . ', or leave it empty.'];
            if ($end < $start) return ['ok' => false, 'error' => 'The event ends before it starts.'];
            $v['ends_at'] = $end;
        }
        foreach (['online_url' => 'online link', 'rsvp_url' => 'RSVP link'] as $f => $word) {
            if ($v[$f] !== '' && !preg_match('~^https://[^\s"<>]{3,490}$~i', $v[$f])) return ['ok' => false, 'error' => 'The ' . $word . ' must start with https://.'];
        }
        if ($v['cover_url'] !== '' && !preg_match('~^(https://[^\s"<>]{3,490}|/[^\s"<>]{1,490})$~i', $v['cover_url'])) {
            return ['ok' => false, 'error' => 'The cover is a link starting https:// or a path on this site starting /.'];
        }
        foreach (['venue' => 200, 'location' => 160] as $f => $max) $v[$f] = mb_substr($v[$f], 0, $max);

        $now = gmdate('Y-m-d H:i:s');
        if ($was) {
            $pdo->prepare('UPDATE av_site_events SET title=?, summary=?, body=?, category=?, starts_at=?, ends_at=?, all_day=?, venue=?, location=?, online_url=?, cover_url=?, rsvp_url=?, audience=?, status=?, updated_at=? WHERE id=?')
                ->execute([$v['title'], $v['summary'], $v['body'], $v['category'], $v['starts_at'], $v['ends_at'], $v['all_day'], $v['venue'], $v['location'], $v['online_url'], $v['cover_url'], $v['rsvp_url'], $v['audience'], $v['status'], $now, $id]);
            $changed = array_values(array_filter(self::FIELDS, static fn($f) => (string) $v[$f] !== (string) ($was[$f] ?? '')));
            self::audit('event.update', $was['slug'], $changed ? 'changed ' . implode(', ', $changed) : 'no change', $actor);
        } else {
            $slug = self::slugFor($v['title'], $start);
            $pdo->prepare('INSERT INTO av_site_events (slug, title, summary, body, category, starts_at, ends_at, all_day, venue, location, online_url, cover_url, rsvp_url, audience, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$slug, $v['title'], $v['summary'], $v['body'], $v['category'], $v['starts_at'], $v['ends_at'], $v['all_day'], $v['venue'], $v['location'], $v['online_url'], $v['cover_url'], $v['rsvp_url'], $v['audience'], $v['status'], mb_substr($actor, 0, 160), $now, $now]);
            $id = (int) $pdo->lastInsertId();
            self::audit('event.create', $slug, $v['starts_at'] . ' · ' . $v['audience'] . ' · ' . $v['status'], $actor);
        }
        $ev = self::find($id);
        try { if ($ev && class_exists('GoogleHub')) GoogleHub::mirrorEvent($ev); } catch (Throwable $e) { error_log('[events] calendar: ' . $e->getMessage()); }
        return ['ok' => true, 'event' => self::find($id)];
    }

    public static function delete(int $id, string $actor): array
    {
        $ev = self::find($id);
        if (!$ev) return ['ok' => false, 'error' => 'That event is no longer there.'];
        try { if (class_exists('GoogleHub')) GoogleHub::unmirrorEvent($ev); } catch (Throwable $e) { error_log('[events] calendar: ' . $e->getMessage()); }
        Database::pdo()->prepare('DELETE FROM av_site_events WHERE id = ?')->execute([$id]);
        self::audit('event.delete', $ev['slug'], $ev['starts_at'], $actor);
        return ['ok' => true];
    }

    public static function setGoogleId(int $id, string $gid): void
    {
        self::ensure();
        Database::pdo()->prepare('UPDATE av_site_events SET google_event_id = ? WHERE id = ?')->execute([mb_substr($gid, 0, 128), $id]);
    }

    /* ── Everyone's: the site and the portal ───────────────────────────── */

    /** The audiences a viewer may see: null = a visitor. */
    public static function audiencesFor(?array $u): array
    {
        if (!$u) return ['public'];
        $org = class_exists('LmsAuth') && LmsAuth::isOrgMember($u);
        return $org ? ['public', 'members', 'org'] : ['public', 'members'];
    }

    /**
     * One list: our published (and cancelled) events this viewer may see, and
     * Africa GATES', soonest first ($scope 'upcoming') or newest first ('past').
     */
    public static function listFor(?array $u, string $scope = 'upcoming', int $limit = 12, ?int $now = null): array
    {
        self::ensure();
        $aud = self::audiencesFor($u);
        $n = self::nowLocal($now);
        $in = implode(',', array_fill(0, count($aud), '?'));
        $st = Database::pdo()->prepare("SELECT * FROM av_site_events WHERE status IN ('published','cancelled') AND audience IN ($in) ORDER BY starts_at");
        $st->execute($aud);
        $ours = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r = self::row($r);
            $over = self::endOf($r) < $n;
            if (($scope === 'past') === $over) $ours[] = self::shape($r, $now);
        }
        $gates = [];
        try { $gates = $scope === 'past' ? AvEvents::past(24) : AvEvents::latest(24); } catch (Throwable $e) {}
        $all = array_merge($ours, $gates);
        usort($all, static fn($a, $b) => $scope === 'past' ? strcmp($b['iso'], $a['iso']) : strcmp($a['iso'], $b['iso']));
        return array_slice($all, 0, max(1, min(50, $limit)));
    }

    /** One of ours, as the site shows it. */
    public static function shape(array $r, ?int $now = null): array
    {
        $tz = self::tz();
        $start = new DateTimeImmutable($r['starts_at'], $tz);
        $end = $r['ends_at'] !== '' ? new DateTimeImmutable($r['ends_at'], $tz) : null;
        $allDay = (bool) $r['all_day'];
        $n = self::nowLocal($now);
        /* Relative, so it works on whichever host serves it; ics() and the Google link make it absolute. */
        $page = '/events/' . $r['slug'] . '/';
        $where = self::where($r);
        $excerpt = $r['summary'] !== '' ? $r['summary'] : mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $r['body'])), 0, 157);
        return [
            'source'    => 'av',
            'id'        => 'av-' . (int) $r['id'],
            'slug'      => $r['slug'],
            'title'     => $r['title'],
            /* Every event has its page. A members' or team event's page asks for the sign-in and
               the right audience (events/view.php); it used to point at /portal/#overview — the
               portal's own Today, where the card was — so pressing it did nothing at all. */
            'url'       => $page,
            'rsvp'      => $r['rsvp_url'],
            'online'    => $r['online_url'],
            /* The join link is a button from half an hour before until it ends: earlier than that
               a "Join" that opens an empty call is the wrong answer to "what do I do now". */
            'join_open' => $r['online_url'] !== '' && $r['status'] !== 'cancelled'
                           && $start->modify('-30 minutes')->format('Y-m-d H:i:s') <= $n && self::endOf($r) >= $n,
            'calendar'  => self::googleAddUrl($r),
            'when'      => $start->format($allDay ? 'D, j M' : 'D, j M · g:ia'),
            'day'       => $start->format('j'),
            'month'     => strtoupper($start->format('M')),
            'iso'       => $start->format(DATE_ATOM),
            'ends_iso'  => $end ? $end->format(DATE_ATOM) : '',
            'date'      => $start->format('Y-m-d'),
            'time'      => $allDay ? '' : $start->format('H:i'),
            'end_time'  => $end && $end->format('Y-m-d') === $start->format('Y-m-d') && !$allDay ? $end->format('H:i') : '',
            'all_day'   => $allDay,
            'ongoing'   => $r['starts_at'] <= $n && self::endOf($r) >= $n,
            'location'  => $where,
            'excerpt'   => $excerpt,
            'image'     => $r['cover_url'],
            'category'  => $r['category'],
            'audience'  => $r['audience'],
            'cancelled' => $r['status'] === 'cancelled',
            'host'      => 'Afrovanguard',
        ];
    }

    /** Portal calendar items between two dates (Y-m-d), for TeamCalendar::feed(). */
    public static function calendarItems(?array $u, string $from, string $to): array
    {
        $out = [];
        foreach (array_merge(self::listFor($u, 'upcoming', 50), self::listFor($u, 'past', 20)) as $e) {
            if ($e['date'] < $from || $e['date'] > $to) continue;
            $out[] = [
                'kind' => $e['source'] === 'gates' ? 'afg' : 'site', 'id' => 0,
                'title' => ($e['cancelled'] ?? false ? 'Cancelled: ' : '') . $e['title'],
                'date' => $e['date'], 'time' => $e['time'], 'end' => $e['end_time'], 'all_day' => $e['all_day'],
                'location' => $e['location'], 'note' => $e['excerpt'], 'url' => $e['rsvp'] ?: $e['url'],
                'who' => $e['host'], 'mine' => false, 'can_delete' => false,
            ];
        }
        return $out;
    }

    /** The public calendar (ours, published, public) as iCalendar. */
    public static function ics(?int $now = null): string
    {
        self::ensure();
        $rows = Database::pdo()->query("SELECT * FROM av_site_events WHERE status IN ('published','cancelled') AND audience = 'public' ORDER BY starts_at")->fetchAll(PDO::FETCH_ASSOC);
        $host = parse_url(defined('SITE_URL') ? (string) SITE_URL : 'https://afrovanguard.org.ng', PHP_URL_HOST) ?: 'afrovanguard.org.ng';
        $esc = static fn(string $s): string => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Afrovanguard//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Afrovanguard events', 'X-WR-TIMEZONE:' . self::TZ];
        foreach ($rows as $r) {
            $r = self::row($r);
            $e = self::shape($r, $now);
            [$ds, $de] = self::icsTimes($r);
            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:event-' . $r['id'] . '@' . $host;
            $out[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $out[] = $ds;
            $out[] = $de;
            $out[] = 'SUMMARY:' . $esc(($e['cancelled'] ? 'Cancelled: ' : '') . $r['title']);
            if ($r['body'] !== '' || $r['summary'] !== '') $out[] = 'DESCRIPTION:' . $esc(trim($r['summary'] . "\n\n" . $r['body']));
            if ($e['location'] !== '') $out[] = 'LOCATION:' . $esc($e['location']);
            if ($e['cancelled']) $out[] = 'STATUS:CANCELLED';
            $out[] = 'URL:' . self::abs($e['url']);
            $out[] = 'END:VEVENT';
        }
        $out[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(static fn($l) => rtrim(chunk_split($l, 74, "\r\n "), "\r\n "), $out)) . "\r\n";
    }

    /** "Add to Google Calendar" for one of ours. */
    public static function googleAddUrl(array $r): string
    {
        [$ds, $de] = self::icsTimes($r);
        $a = substr($ds, strpos($ds, ':') + 1); $b = substr($de, strpos($de, ':') + 1);
        // Not through shape(): shape() carries this link, so asking it here would never return.
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE', 'text' => $r['title'], 'dates' => $a . '/' . $b,
            'details' => trim($r['summary'] . "\n\n" . self::abs('/events/' . $r['slug'] . '/')), 'location' => self::where($r), 'ctz' => self::TZ,
        ]);
    }

    /** Where it is, in one line: the venue, and the place unless the venue already says it. */
    private static function where(array $r): string
    {
        return $r['venue'] === '' ? $r['location'] : ($r['location'] === '' || stripos($r['venue'], $r['location']) !== false ? $r['venue'] : $r['venue'] . ', ' . $r['location']);
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    /** [DTSTART line, DTEND line] — a date for all-day events, UTC times otherwise. */
    private static function icsTimes(array $r): array
    {
        $tz = self::tz();
        $s = new DateTimeImmutable($r['starts_at'], $tz);
        if ($r['all_day']) {
            $f = ($r['ends_at'] !== '' ? new DateTimeImmutable($r['ends_at'], $tz) : $s)->modify('+1 day');
            return ['DTSTART;VALUE=DATE:' . $s->format('Ymd'), 'DTEND;VALUE=DATE:' . $f->format('Ymd')];
        }
        $f = $r['ends_at'] !== '' ? new DateTimeImmutable($r['ends_at'], $tz) : $s->modify('+2 hours');
        $u = new DateTimeZone('UTC');
        return ['DTSTART:' . $s->setTimezone($u)->format('Ymd\THis\Z'), 'DTEND:' . $f->setTimezone($u)->format('Ymd\THis\Z')];
    }

    /** When it is over: its end, or the end of its day when it has none. */
    private static function endOf(array $r): string
    {
        if ($r['ends_at'] !== '') return strlen($r['ends_at']) === 10 ? $r['ends_at'] . ' 23:59' : $r['ends_at'];
        return substr($r['starts_at'], 0, 10) . ' 23:59';
    }

    /** 'Y-m-d H:i' (or 'Y-m-d' all day) from a form's date / datetime-local value, or null. */
    private static function parse(string $s, bool $allDay): ?string
    {
        $s = str_replace('T', ' ', trim($s));
        if ($allDay) $s = substr($s, 0, 10);
        $fmt = $allDay ? '!Y-m-d' : '!Y-m-d H:i';
        $d = DateTimeImmutable::createFromFormat($fmt, substr($s, 0, $allDay ? 10 : 16), self::tz());
        if (!$d || $d->format($allDay ? 'Y-m-d' : 'Y-m-d H:i') !== substr($s, 0, $allDay ? 10 : 16)) return null;
        $y = (int) $d->format('Y');
        if ($y < 2000 || $y > 2100) return null;
        return $d->format($allDay ? 'Y-m-d' : 'Y-m-d H:i');
    }

    private static function slugFor(string $title, string $start): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)), '-');
        $base = substr($base !== '' ? $base : 'event', 0, 80) . '-' . substr(str_replace('-', '', $start), 0, 8);
        $slug = $base; $i = 2;
        $st = Database::pdo()->prepare('SELECT 1 FROM av_site_events WHERE slug = ?');
        while (true) { $st->execute([$slug]); if (!$st->fetchColumn()) return $slug; $slug = $base . '-' . $i++; }
    }

    private static function abs(string $u): string
    {
        return $u !== '' && $u[0] === '/' ? rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . $u : $u;
    }

    private static function row(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['all_day'] = (int) $r['all_day'];
        foreach (['slug', 'title', 'summary', 'body', 'category', 'starts_at', 'ends_at', 'venue', 'location', 'online_url', 'cover_url', 'rsvp_url', 'audience', 'status', 'google_event_id'] as $k) $r[$k] = (string) ($r[$k] ?? '');
        return $r;
    }

    private static function audit(string $action, string $target, string $detail, string $actor): void
    {
        try { (new LmsRepository())->audit($action, $target, $detail, $actor !== '' ? $actor : 'admin'); } catch (Throwable $e) {}
    }
}
