<?php
/**
 * lib/Summit.php — seat registrations for the D'Vanguard National Summit (DNS).
 *
 * The intake store behind /academy/dns/. Deliberately small: the summit's
 * CONTENT lives in the page itself (one editable array at the top of
 * academy/dns/index.php) — only the things people submit need a database.
 *
 * Driver-aware DDL via Database::execSchema (SQLite / MySQL / Postgres), and
 * every read/write is fail-safe: a database hiccup must never take down a
 * public landing page, so callers get 0 / [] rather than an exception.
 */
declare(strict_types=1);

final class Summit
{
    /** The edition this store is scoped to — carried on every row so a later
     *  edition ('27, '28…) shares the table without colliding. */
    public const EDITION = 'dns-26';

    /** Interest tracks a registrant may pick. Mirrors the summit's three pillars. */
    public const PILLARS = ['Master', 'Tame', 'Own'];

    /**
     * The summit's facts — the SINGLE definition every surface reads: the page
     * itself, the home page's live events rail, the events page and the Academy
     * catalogue. Page-only content (the pillars, the session list, the speakers,
     * the FAQ) stays in academy/dns/index.php; only what other pages also need
     * lives here.
     *
     * Editing a date, the venue or the pass here updates every surface at once.
     */
    public static function facts(): array
    {
        return [
            'name'       => "D'Vanguard National Summit",
            'edition'    => "DNS '26",
            'presenter'  => 'Afrovanguard',
            'triad'      => 'Master | Tame | Own',
            'lede'       => "Four days in Lagos with the people building the next Nigeria — where you learn to master the community you lead, tame the corruption in your space, and own everything you build.",

            // ISO 8601, Africa/Lagos (+01:00). The flyer states a 9:00 AM start;
            // no closing time is published, so the end is a date, not a datetime.
            'starts'     => '2026-09-01T09:00:00+01:00',
            'ends'       => '2026-09-04',
            'date_label' => 'Tuesday 1 – Friday 4 September 2026',
            'date_short' => '1–4 Sept 2026',
            'days'       => '4 days',
            'time_label' => '9:00 AM',

            'venue' => [
                'name'    => 'Effortwill Schools',
                'area'    => 'Ejigbo',
                'city'    => 'Lagos',
                'region'  => 'Lagos State',
                'country' => 'NG',
                'map'     => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Effortwill Schools, Ejigbo, Lagos'),
            ],

            'pass' => [
                'label'    => '$30',
                'amount'   => 30,
                'currency' => 'USD',
                'note'     => 'One pass, all four days',
            ],

            'whatsapp' => ['display' => '+234 903 777 6318', 'e164' => '2349037776318'],
            'partner'  => 'Alimosho',
        ];
    }

    /** Absolute URL of the summit page. */
    public static function url(): string
    {
        return rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . '/academy/dns/';
    }

    /** Generated social card (see academy/dns/og.php). */
    public static function ogImage(): string
    {
        return rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . '/academy/dns/og.png';
    }

    /** Unix start; 0 if the date is unparseable. */
    public static function startsAt(): int
    {
        return strtotime((string) self::facts()['starts']) ?: 0;
    }

    /** Unix end — the close of the last day, Lagos time. */
    public static function endsAt(): int
    {
        return strtotime(self::facts()['ends'] . 'T23:59:59+01:00') ?: 0;
    }

    /** The summit has finished. */
    public static function isPast(): bool
    {
        $end = self::endsAt();
        return $end > 0 && time() > $end;
    }

    /** The summit is running right now. */
    public static function isLive(): bool
    {
        $start = self::startsAt();
        return !self::isPast() && $start > 0 && time() >= $start;
    }

    /** Seats can still be claimed. */
    public static function isOpen(): bool
    {
        return !self::isPast();
    }

    /**
     * One entry shaped exactly like the home page's live events rail expects
     * (day / month / when / location / title / url / ongoing), so the summit
     * appears there without the static home page being edited.
     *
     * Returns null once the summit is over — a finished event must not sit at
     * the head of an "upcoming" rail.
     */
    public static function feedEntry(): ?array
    {
        if (self::isPast()) return null;
        $f  = self::facts();
        $ts = self::startsAt();
        if ($ts <= 0) return null;
        $v = $f['venue'];
        return [
            'title'    => $f['name'] . ' (' . $f['edition'] . ')',
            'url'      => self::url(),
            'day'      => date('j', $ts),
            'month'    => strtoupper(date('M', $ts)),
            'when'     => $f['date_short'] . ' · ' . $f['time_label'],
            'location' => $v['area'] . ', ' . $v['city'],
            'ongoing'  => self::isLive(),
            'source'   => 'afrovanguard',   // distinguishes it from the AFG sub-site feed
        ];
    }

    public static function ensure(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $db  = Database::pdo();
            $ddl = "CREATE TABLE IF NOT EXISTS summit_registrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                edition VARCHAR(24) NOT NULL DEFAULT '',
                name VARCHAR(120) NOT NULL DEFAULT '',
                email VARCHAR(160) NOT NULL DEFAULT '',
                phone VARCHAR(40) NOT NULL DEFAULT '',
                location VARCHAR(120) NOT NULL DEFAULT '',
                organisation VARCHAR(160) NOT NULL DEFAULT '',
                pillar VARCHAR(24) NOT NULL DEFAULT '',
                seats INTEGER NOT NULL DEFAULT 1,
                heard VARCHAR(60) NOT NULL DEFAULT '',
                message TEXT NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                source VARCHAR(24) NOT NULL DEFAULT 'web',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_summit_reg_edition ON summit_registrations(edition, created_at);
            CREATE UNIQUE INDEX IF NOT EXISTS idx_summit_reg_who ON summit_registrations(edition, email);";
            Database::execSchema($db, $ddl);
        } catch (Throwable $e) {
            error_log('[summit] ensure: ' . $e->getMessage());
        }
    }

    /** True when this email already holds a seat for this edition. */
    public static function alreadyRegistered(string $email): bool
    {
        $email = trim($email);
        if ($email === '') return false;
        self::ensure();
        try {
            $st = Database::pdo()->prepare(
                'SELECT 1 FROM summit_registrations WHERE edition = ? AND email = ? LIMIT 1'
            );
            $st->execute([self::EDITION, mb_strtolower($email)]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Record one seat claim. Returns the new row id, or 0 when the submission
     * is unusable (no name / invalid email) or the write failed.
     *
     * Everything is length-capped and the pillar is accepted only when it
     * matches a known value — the form is public and unauthenticated, so
     * nothing that arrives is trusted as-is.
     */
    public static function register(array $d): int
    {
        $name  = mb_substr(trim((string) ($d['name'] ?? '')), 0, 120);
        $email = mb_strtolower(mb_substr(trim((string) ($d['email'] ?? '')), 0, 160));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;

        $pillar = (string) ($d['pillar'] ?? '');
        if (!in_array($pillar, self::PILLARS, true)) $pillar = '';
        $seats = (int) ($d['seats'] ?? 1);
        $seats = max(1, min(20, $seats));

        self::ensure();
        try {
            $now = Database::nowExpr();
            $st  = Database::pdo()->prepare(
                "INSERT INTO summit_registrations
                   (edition,name,email,phone,location,organisation,pillar,seats,heard,message,status,source,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'new', ?, {$now})"
            );
            $st->execute([
                self::EDITION, $name, $email,
                mb_substr(trim((string) ($d['phone'] ?? '')), 0, 40),
                mb_substr(trim((string) ($d['location'] ?? '')), 0, 120),
                mb_substr(trim((string) ($d['organisation'] ?? '')), 0, 160),
                $pillar, $seats,
                mb_substr(trim((string) ($d['heard'] ?? '')), 0, 60),
                mb_substr(trim((string) ($d['message'] ?? '')), 0, 1500),
                mb_substr(trim((string) ($d['source'] ?? 'web')), 0, 24),
            ]);
            return (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            // The unique index is a backstop behind alreadyRegistered(): two
            // submissions racing for the same email land here. That is the index
            // doing its job, not a fault, so it does not belong in the error log.
            if (!preg_match('/\b(1062|23000|23505)\b|unique constraint|duplicate entry/i', $e->getMessage())) {
                error_log('[summit] register: ' . $e->getMessage());
            }
            return 0;
        }
    }

    /** Seats claimed so far for this edition (sum, not row count). 0 on any error. */
    public static function seatsClaimed(): int
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare(
                'SELECT COALESCE(SUM(seats), 0) FROM summit_registrations WHERE edition = ?'
            );
            $st->execute([self::EDITION]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Most recent registrations for this edition — for staff tooling. */
    public static function recent(int $limit = 200): array
    {
        self::ensure();
        $limit = max(1, min(1000, $limit));
        try {
            $st = Database::pdo()->prepare(
                "SELECT * FROM summit_registrations WHERE edition = ?
                  ORDER BY id DESC LIMIT {$limit}"
            );
            $st->execute([self::EDITION]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
