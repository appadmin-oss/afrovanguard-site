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
                notified_at VARCHAR(32) NOT NULL DEFAULT '',
                notify_error VARCHAR(400) NOT NULL DEFAULT '',
                created_at VARCHAR(32) NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS idx_summit_reg_edition ON summit_registrations(edition, created_at);
            CREATE UNIQUE INDEX IF NOT EXISTS idx_summit_reg_who ON summit_registrations(edition, email);";
            Database::execSchema($db, $ddl);
            // Installs created before delivery was tracked still have the old
            // shape; add the two columns rather than asking for a migration.
            foreach (['notified_at' => "VARCHAR(32) NOT NULL DEFAULT ''",
                      'notify_error' => "VARCHAR(400) NOT NULL DEFAULT ''"] as $col => $type) {
                if (!Database::columnExists('summit_registrations', $col)) {
                    $db->exec("ALTER TABLE summit_registrations ADD COLUMN {$col} {$type}");
                }
            }
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

    /**
     * Registrations for the staff list, newest first. `$q` matches name, email,
     * phone or organisation; `$mail` filters on delivery ('sent' | 'failed').
     */
    public static function search(string $q = '', string $mail = '', int $limit = 500): array
    {
        self::ensure();
        $limit = max(1, min(2000, $limit));
        $sql   = 'SELECT * FROM summit_registrations WHERE edition = ?';
        $args  = [self::EDITION];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR organisation LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if ($mail === 'sent')   $sql .= " AND notified_at <> ''";
        if ($mail === 'failed') $sql .= " AND notified_at = ''";
        $sql .= " ORDER BY id DESC LIMIT {$limit}";
        try {
            $st = Database::pdo()->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[summit] search: ' . $e->getMessage());
            return [];
        }
    }

    /** One registration by id, or [] — the row a resend is rebuilt from. */
    public static function find(int $id): array
    {
        self::ensure();
        try {
            $st = Database::pdo()->prepare('SELECT * FROM summit_registrations WHERE id = ? AND edition = ?');
            $st->execute([$id, self::EDITION]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Headline numbers for the staff list: how many people, how many seats, and
     * — the one that matters when mail is misconfigured — how many of them never
     * got their confirmation.
     */
    public static function stats(): array
    {
        self::ensure();
        $out = ['registrations' => 0, 'seats' => 0, 'emailed' => 0, 'unemailed' => 0];
        try {
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(seats),0) AS s,
                        COALESCE(SUM(CASE WHEN notified_at <> '' THEN 1 ELSE 0 END),0) AS e
                   FROM summit_registrations WHERE edition = ?"
            );
            $st->execute([self::EDITION]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['registrations'] = (int) ($r['n'] ?? 0);
            $out['seats']         = (int) ($r['s'] ?? 0);
            $out['emailed']       = (int) ($r['e'] ?? 0);
            $out['unemailed']     = max(0, $out['registrations'] - $out['emailed']);
        } catch (Throwable $e) {
            error_log('[summit] stats: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Send (or re-send) one registration's confirmation, and alert staff.
     *
     * Lives here rather than on the page so the Studio's "resend" runs the exact
     * same mail a fresh claim does. The seat is already saved by the time this
     * runs, so a mail failure is recorded and reported, never thrown: the
     * registrant keeps their seat either way.
     *
     * Returns ['ok' => bool, 'error' => string, 'admin' => bool].
     */
    public static function notify(int $id, array $d): array
    {
        if (!class_exists('Mailer')) {
            self::markNotified($id, false, 'Mailer unavailable');
            return ['ok' => false, 'error' => 'Mailer unavailable', 'admin' => false];
        }
        $f     = self::facts();
        $esc   = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $name  = trim((string) ($d['name'] ?? '')) ?: 'there';
        $first = $esc(explode(' ', $name)[0]);
        $email = trim((string) ($d['email'] ?? ''));
        $v     = $f['venue'];
        $where = $esc($v['name'] . ', ' . $v['area'] . ', ' . $v['city']);
        $seats = max(1, (int) ($d['seats'] ?? 1));
        $wa    = $esc($f['whatsapp']['display']);

        $ok = false; $error = '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $rows = [
                "Hi {$first},",
                'Your seat at the <b>' . $esc($f['name']) . ' (' . $esc($f['edition']) . ')</b> is reserved.',
                '<b>When:</b> ' . $esc($f['date_label']) . ', from ' . $esc($f['time_label']) . '<br>'
                    . "<b>Where:</b> {$where}<br>"
                    . '<b>Seats held:</b> ' . $seats . '<br>'
                    . '<b>Pass:</b> ' . $esc($f['pass']['label']) . ' — ' . $esc($f['pass']['note']),
                '<b>Next step:</b> our team will confirm your place and share payment details. '
                    . "If you would rather sort it out now, message us on WhatsApp at {$wa}.",
                'Master. Tame. Own.<br>— Afrovanguard',
            ];
            $html = Mailer::shell(
                'Your seat is reserved',
                $rows,
                ['url' => self::url(), 'text' => 'See the summit page'],
                $f['edition'] . ' — ' . $f['date_short'] . ', ' . $v['area'] . ', ' . $v['city']
            );
            try {
                $ok = Mailer::send($email, 'Your seat at ' . $f['edition'] . ' is reserved', $html);
                if (!$ok) $error = Mailer::lastError() ?: 'Send failed';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'No valid email address on the registration';
        }
        self::markNotified($id, $ok, $error);

        // Staff alert. A missing admin address used to end this function in
        // silence — the reason staff can watch seats fill and never hear about
        // one — so an unset mailbox is logged like any other delivery failure.
        $adminOk = false;
        $admin = defined('ADMIN_EMAIL') && ADMIN_EMAIL ? (string) ADMIN_EMAIL
               : (defined('FROM_EMAIL') && FROM_EMAIL ? (string) FROM_EMAIL : '');
        if ($admin === '') {
            error_log('[summit] seat claim #' . $id . ' saved but no staff alert sent: '
                . 'neither ADMIN_EMAIL nor FROM_EMAIL is configured.');
        } else {
            $lines = [];
            foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'location' => 'Location',
                      'organisation' => 'Organisation', 'pillar' => 'Pillar', 'heard' => 'Heard via',
                      'message' => 'Message'] as $k => $label) {
                $val = trim((string) ($d[$k] ?? ''));
                if ($val !== '') $lines[] = '<b>' . $label . ':</b> ' . $esc($val);
            }
            $html = Mailer::shell(
                'New ' . $esc($f['edition']) . ' seat claim',
                ['Claim #' . $id . ' — ' . $seats . ' seat(s).', implode('<br>', $lines)],
                ['url' => rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . '/admin/', 'text' => 'Open the Studio'],
                $name . ' — ' . $seats . ' seat(s)'
            );
            try {
                $adminOk = Mailer::send($admin, 'DNS ' . $f['edition'] . " seat claim #{$id}", $html);
                if (!$adminOk) error_log('[summit] staff alert for #' . $id . ' failed: ' . (Mailer::lastError() ?: 'unknown'));
            } catch (Throwable $e) {
                error_log('[summit] staff alert for #' . $id . ': ' . $e->getMessage());
            }
        }
        return ['ok' => $ok, 'error' => $error, 'admin' => $adminOk];
    }

    /**
     * Record what the mailer actually did with a registration's confirmation.
     * A failed send leaves notified_at empty on purpose: that is what the staff
     * list filters on and what a resend picks up, so a bad SMTP week is a
     * recoverable queue rather than a silent hole.
     */
    public static function markNotified(int $id, bool $ok, string $error = ''): void
    {
        if ($id <= 0) return;
        self::ensure();
        try {
            $now = $ok ? Database::nowExpr() : "''";
            $st  = Database::pdo()->prepare(
                "UPDATE summit_registrations SET notified_at = {$now}, notify_error = ? WHERE id = ?"
            );
            $st->execute([$ok ? '' : mb_substr($error, 0, 400), $id]);
        } catch (Throwable $e) {
            error_log('[summit] markNotified: ' . $e->getMessage());
        }
    }
}
