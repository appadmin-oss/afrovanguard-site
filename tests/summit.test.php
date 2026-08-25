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

$db->exec('DELETE FROM summit_registrations');
