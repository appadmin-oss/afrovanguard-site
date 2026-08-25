<?php
/**
 * tests/summit.test.php — D'Vanguard National Summit seat registrations.
 *
 * The summit page (academy/dns/index.php) is a public, unauthenticated intake,
 * so the things worth pinning are the ones that only bite in production: that a
 * seat is actually stored, that the same person cannot silently take two, that
 * a hostile or sloppy payload is capped rather than trusted, and that the seat
 * counter the page shows is a sum of seats rather than a row count.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$db = Database::pdo();
Summit::ensure();
$db->exec('DELETE FROM summit_registrations');

/* ══ The table is created on demand, on whatever driver is connected ═════ */

ck('summit: ensure() creates the registrations table', Database::tableExists('summit_registrations'));
ck('summit: ensure() is idempotent', (static function (): bool {
    Summit::ensure(); Summit::ensure();
    return Database::tableExists('summit_registrations');
})());

/* ══ A seat is stored, with the edition it belongs to ═══════════════════ */

$id = Summit::register([
    'name' => 'Ada Obi', 'email' => 'Ada@Example.COM', 'phone' => '+2348012345678',
    'location' => 'Ikeja', 'organisation' => 'Obi Studios', 'pillar' => 'Own',
    'seats' => 3, 'heard' => 'Instagram', 'message' => 'Bringing my team.',
]);
ck('summit: a valid registration is stored', $id > 0);

$row = $db->query('SELECT * FROM summit_registrations ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
ck('summit: the row carries the edition', ($row['edition'] ?? '') === Summit::EDITION);
ck('summit: email is normalised to lower case', ($row['email'] ?? '') === 'ada@example.com');
ck('summit: seats are kept', (int) ($row['seats'] ?? 0) === 3);
ck('summit: a known pillar is kept', ($row['pillar'] ?? '') === 'Own');
ck('summit: new registrations start as new', ($row['status'] ?? '') === 'new');

/* ══ Nothing unusable gets in ═══════════════════════════════════════════ */

ck('summit: a missing name is refused', Summit::register(['name' => '', 'email' => 'x@y.co']) === 0);
ck('summit: an invalid email is refused', Summit::register(['name' => 'X', 'email' => 'not-an-email']) === 0);
ck('summit: an empty payload is refused', Summit::register([]) === 0);

/* ══ Untrusted input is capped, not trusted ═════════════════════════════ */

Summit::register(['name' => 'Group Lead', 'email' => 'group@example.com', 'seats' => 999,
                  'pillar' => '<script>alert(1)</script>']);
$g = $db->query("SELECT * FROM summit_registrations WHERE email = 'group@example.com'")->fetch(PDO::FETCH_ASSOC);
ck('summit: an absurd seat count is clamped to the max', (int) ($g['seats'] ?? 0) === 20);
ck('summit: an unknown pillar is dropped rather than stored', ($g['pillar'] ?? 'x') === '');

Summit::register(['name' => 'Zero Seats', 'email' => 'zero@example.com', 'seats' => 0]);
$z = $db->query("SELECT seats FROM summit_registrations WHERE email = 'zero@example.com'")->fetchColumn();
ck('summit: a zero/negative seat count floors at one', (int) $z === 1);

$long = str_repeat('a', 400);
Summit::register(['name' => $long, 'email' => 'long@example.com']);
$l = $db->query("SELECT name FROM summit_registrations WHERE email = 'long@example.com'")->fetchColumn();
ck('summit: an over-long name is truncated, not rejected', mb_strlen((string) $l) === 120);

/* ══ One person, one seat claim ═════════════════════════════════════════ */

ck('summit: a registered email is recognised', Summit::alreadyRegistered('ada@example.com'));
ck('summit: the check ignores case', Summit::alreadyRegistered('ADA@EXAMPLE.COM'));
ck('summit: an unknown email is not registered', !Summit::alreadyRegistered('nobody@example.com'));
ck('summit: an empty email is not registered', !Summit::alreadyRegistered(''));

$before = (int) $db->query('SELECT COUNT(*) FROM summit_registrations')->fetchColumn();
Summit::register(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'seats' => 1]);
$after = (int) $db->query('SELECT COUNT(*) FROM summit_registrations')->fetchColumn();
ck('summit: the unique index refuses a second seat for the same email', $before === $after);

/* ══ The counter on the page counts seats, not rows ═════════════════════ */

// Ada 3 + group 20 + zero 1 + long 1 = 25 across 4 rows.
ck('summit: seatsClaimed() sums seats rather than counting rows', Summit::seatsClaimed() === 25);

$recent = Summit::recent(10);
ck('summit: recent() returns this edition, newest first', $recent !== [] && (int) $recent[0]['id'] > (int) $recent[count($recent) - 1]['id']);
ck('summit: recent() only returns this edition',
   count(array_filter($recent, static fn($r) => $r['edition'] !== Summit::EDITION)) === 0);

/* ══ The page itself is wired where the routing expects it ══════════════ */

ck('summit: the page lives where academy/.htaccess sends /academy/dns/',
   is_file(AV_ROOT . '/academy/dns/index.php'));
ck('summit: .htaccess routes dns/ before the course catch-all', (static function (): bool {
    $h = (string) @file_get_contents(AV_ROOT . '/academy/.htaccess');
    $dns = strpos($h, 'RewriteRule ^dns/?$');
    $any = strpos($h, 'RewriteRule ^([a-z0-9-]+)/?$');
    return $dns !== false && $any !== false && $dns < $any;
})());
ck('summit: the stylesheet the page asks for exists', is_file(AV_ROOT . '/academy/dns.css'));
ck('summit: the summit is in the sitemap', (static function (): bool {
    $r = new ReflectionClass('Sitemap');
    $pages = $r->getConstant('STATIC_PAGES') ?: [];
    foreach ($pages as $p) { if (($p[0] ?? '') === 'academy/dns/') return true; }
    return false;
})());

/* ══ The facts are one definition, shared by every surface ══════════════ */

$f = Summit::facts();
ck('summit: facts carry a name and an edition', ($f['name'] ?? '') !== '' && ($f['edition'] ?? '') !== '');
ck('summit: the start date parses', Summit::startsAt() > 0);
ck('summit: the end is after the start', Summit::endsAt() > Summit::startsAt());
ck('summit: a summit cannot be both live and finished', !(Summit::isLive() && Summit::isPast()));
ck('summit: seats are open exactly while it has not finished', Summit::isOpen() === !Summit::isPast());
ck('summit: the page URL is absolute and canonical',
   str_starts_with(Summit::url(), 'http') && str_ends_with(Summit::url(), '/academy/dns/'));
ck('summit: the social card is the generated one', str_ends_with(Summit::ogImage(), '/academy/dns/og.png'));

/* ══ The home page rail entry ═══════════════════════════════════════════ */

$entry = Summit::feedEntry();
if (Summit::isPast()) {
    ck('summit: a finished summit is withheld from the upcoming rail', $entry === null);
} else {
    ck('summit: the rail entry exists while the summit is ahead', is_array($entry));
    // The home page's rail reads exactly these keys — a rename here renders blank.
    foreach (['title', 'url', 'day', 'month', 'when', 'location', 'ongoing'] as $k) {
        ck("summit: the rail entry carries {$k}", array_key_exists($k, (array) $entry));
    }
    ck('summit: the rail entry points at the summit page',
       ($entry['url'] ?? '') === Summit::url());
    ck('summit: the rail day and month agree with the start date',
       (int) ($entry['day'] ?? 0) === (int) date('j', Summit::startsAt())
       && ($entry['month'] ?? '') === strtoupper(date('M', Summit::startsAt())));
    ck('summit: "happening now" tracks the live window',
       ($entry['ongoing'] ?? null) === Summit::isLive());
}

/* ══ The endpoints the page points at actually exist ════════════════════ */

ck('summit: the social card generator exists', is_file(AV_ROOT . '/academy/dns/og.php'));
ck('summit: the calendar invitation exists', is_file(AV_ROOT . '/academy/dns/summit.ics.php'));
ck('summit: .htaccess routes the social card', (static function (): bool {
    $h = (string) @file_get_contents(AV_ROOT . '/academy/.htaccess');
    return str_contains($h, 'dns/og.php') && str_contains($h, 'dns/summit.ics.php');
})());
ck('summit: the dev router serves both too', (static function (): bool {
    $r = (string) @file_get_contents(AV_ROOT . '/router.php');
    return str_contains($r, '/academy/dns/og.png') && str_contains($r, '/academy/dns/summit.ics');
})());
ck('summit: the sitemap points at the generated card', (static function (): bool {
    $x = (string) @file_get_contents(AV_ROOT . '/sitemap.xml');
    return str_contains($x, '/academy/dns/og.png');
})());

/* ══ The surfaces that publicise it ═════════════════════════════════════ */

foreach ([
    'the home page feed'      => '/events-feed.php',
    'the events page'         => '/events/index.php',
    'the Academy catalogue'   => '/academy/index.php',
] as $what => $file) {
    $src = (string) @file_get_contents(AV_ROOT . $file);
    ck("summit: {$what} reads the shared facts",
       str_contains($src, 'Summit::feedEntry') || str_contains($src, 'Summit::facts'));
}
ck('summit: the Academy nav links to it', str_contains(
    (string) @file_get_contents(AV_ROOT . '/lib/partials.php'), "/academy/dns/"));

/* ══ The static-chrome build is idempotent ══════════════════════════════
   It was not: nav_parts() splits render_nav() at </header>, so the drawer half
   carries the search dialog, which the drawer replacement never matched — every
   run left the old one and appended another. The committed pages had seven. */

foreach (['index.html', 'about.html', 'contact.html', 'donate.html', 'projects/index.html'] as $page) {
    $html = (string) @file_get_contents(AV_ROOT . '/' . $page);
    if ($html === '') continue;
    ck("chrome: {$page} has exactly one search dialog", substr_count($html, 'id="avSearch"') === 1);
    ck("chrome: {$page} has exactly one drawer scrim", substr_count($html, 'class="scrim"') === 1);
}
ck('chrome: the builder clears loose chrome before injecting', str_contains(
    (string) @file_get_contents(AV_ROOT . '/tools/build-chrome.php'), 'strip_loose_chrome'));

$db->exec('DELETE FROM summit_registrations');
