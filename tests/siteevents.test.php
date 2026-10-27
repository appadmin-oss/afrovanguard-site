<?php
/**
 * tests/siteevents.test.php — events: ours (lib/SiteEvents.php) and Africa
 * GATES', read from its /api/events feed (lib/AvEvents.php).
 *
 * Africa GATES used to be asked for a WordPress events API it never had, so
 * no event of theirs ever reached this site. These pin the new feed's shape,
 * its time zones, what happens when it is down, who sees which of ours, and
 * that the Studio, the homepage, /events/ and the portal all read one list.
 */
declare(strict_types=1);

$cache = AV_ROOT . '/data/afg-events.json';
$keep = is_file($cache) ? (string) file_get_contents($cache) : null;
@unlink($cache);
SiteEvents::ensure();
Database::pdo()->exec('DELETE FROM av_site_events');

$future = static fn(int $days, string $t = '18:00'): string => (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->modify("+$days days")->format('Y-m-d') . ' ' . $t;
$utc = static fn(int $days, string $t): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+$days days")->format('Y-m-d') . 'T' . $t . ':00Z';

/* ── Africa GATES: the /api/events feed ───────────────────────────────── */
$asked = [];
$feed = ['success' => true, 'events' => [[
    'id' => 7, 'slug' => 'gala', 'title' => 'Africa GATES Gala', 'tagline' => 'The night of the awards',
    'description' => 'Long text', 'url' => 'https://afg.afrovanguard.org.ng/events/gala',
    'calendar_url' => 'https://afg.afrovanguard.org.ng/events/gala/calendar.ics', 'rsvp_url' => 'https://afg.afrovanguard.org.ng/events/gala',
    'starts_at' => $utc(12, '17:00'), 'ends_at' => $utc(12, '21:00'), 'timezone' => 'Africa/Lagos',
    'venue' => 'Eko Hotel', 'location' => 'Lagos', 'host' => '', 'state' => 'open', 'price' => '₦15,000',
    'cover_image' => 'https://afg.afrovanguard.org.ng/uploads/g.jpg', 'livestream' => '', 'recording' => '',
], ['id' => 8, 'title' => 'No start', 'starts_at' => ''], ['id' => 9, 'title' => 'Bad link', 'starts_at' => $utc(13, '09:00'), 'url' => 'javascript:alert(1)']]];
AvEvents::useFetcher(function (string $url) use (&$asked, &$feed) {
    $asked[] = $url;
    return str_contains($url, 'scope=past') ? json_encode(['success' => true, 'events' => []]) : json_encode($feed);
});
$up = AvEvents::latest(10);
ck('it asks Africa GATES for /api/events, upcoming and past', count($asked) === 2
   && str_starts_with($asked[0], AvEvents::source() . '/api/events?scope=upcoming') && str_contains($asked[1], 'scope=past'));
ck('an event with no start is dropped, the rest kept', count($up) === 2 && $up[0]['title'] === 'Africa GATES Gala');
ck('the UTC start is shown in the event\'s own zone (17:00Z is 6pm in Lagos)', $up[0]['time'] === '18:00' && $up[0]['end_time'] === '22:00'
   && str_ends_with($up[0]['iso'], '+01:00'));
ck('the page, cover, calendar file and price cross over', $up[0]['url'] === 'https://afg.afrovanguard.org.ng/events/gala'
   && $up[0]['image'] === 'https://afg.afrovanguard.org.ng/uploads/g.jpg' && $up[0]['ics'] !== '' && $up[0]['price'] === '₦15,000');
ck('where it is reads venue, then city', $up[0]['location'] === 'Eko Hotel, Lagos');
ck('an empty host is Africa GATES', $up[0]['host'] === 'Africa GATES' && $up[0]['source'] === 'gates');
ck('a link that is not http(s) never reaches a page', !str_contains($up[1]['url'], 'javascript'));
ck('the excerpt is the tagline', $up[0]['excerpt'] === 'The night of the awards');

$asked = [];
AvEvents::useFetcher(function (string $url) use (&$asked) { $asked[] = $url; return null; });
ck('a fresh copy is read from the cache, not asked again', count(AvEvents::latest(5)) === 2 && $asked === []);
$st = AvEvents::status(true);
ck('when Africa GATES is down the last copy is kept and the Studio is told why',
   $st['stale'] === true && $st['upcoming'] === 2 && str_contains($st['error'], 'did not answer'));
AvEvents::useFetcher(fn() => '<html>Not found</html>');
$st = AvEvents::status(true);
ck('an answer that is not the feed says the update may not be deployed there', str_contains($st['error'], '/api/events'));

/* ── Ours ─────────────────────────────────────────────────────────────── */
$bad = SiteEvents::save(['title' => '', 'starts_at' => $future(3)], 'test');
ck('a title is required', !$bad['ok'] && str_contains($bad['error'], 'title'));
$bad = SiteEvents::save(['title' => 'X', 'starts_at' => 'next tuesday-ish'], 'test');
ck('a start that is not a date is refused in words', !$bad['ok'] && str_contains($bad['error'], 'start'));
$bad = SiteEvents::save(['title' => 'X', 'starts_at' => $future(3), 'ends_at' => $future(2)], 'test');
ck('an end before the start is refused', !$bad['ok'] && str_contains($bad['error'], 'ends before'));
$bad = SiteEvents::save(['title' => 'X', 'starts_at' => $future(3), 'rsvp_url' => 'http://insecure.example'], 'test');
ck('links must be https', !$bad['ok'] && str_contains($bad['error'], 'https://'));

$pub = SiteEvents::save(['title' => 'Town Hall: Lagos', 'summary' => 'Open floor', 'starts_at' => $future(5, '16:00'), 'ends_at' => $future(5, '18:00'),
    'venue' => 'CACENTRE Hall', 'location' => 'Ikeja', 'audience' => 'public', 'status' => 'published', 'category' => 'Community'], 'test');
$mem = SiteEvents::save(['title' => 'Members Mixer', 'starts_at' => $future(6), 'audience' => 'members', 'status' => 'published'], 'test');
$org = SiteEvents::save(['title' => 'Team Retreat', 'starts_at' => $future(7), 'all_day' => 1, 'audience' => 'org', 'status' => 'published'], 'test');
$dft = SiteEvents::save(['title' => 'Draft Idea', 'starts_at' => $future(4), 'status' => 'draft'], 'test');
$old = SiteEvents::save(['title' => 'Last Month Expo', 'starts_at' => $future(-30), 'status' => 'published'], 'test');
ck('valid events save', $pub['ok'] && $mem['ok'] && $org['ok'] && $dft['ok'] && $old['ok']);
ck('a slug is made from the title', str_starts_with($pub['event']['slug'], 'town-hall-lagos'));
ck('an all-day event keeps the day alone', $org['event']['starts_at'] === substr($future(7), 0, 10));

$titles = static fn(array $l): array => array_column($l, 'title');
$anon = $titles(SiteEvents::listFor(null, 'upcoming', 20));
ck('a visitor sees our public events and Africa GATES\', soonest first, never a draft or a members\' event',
   $anon === ['Town Hall: Lagos', 'Africa GATES Gala', 'Bad link']);
$member = $titles(SiteEvents::listFor(['id' => 1, 'email' => 'someone@gmail.com', 'role' => 'student'], 'upcoming', 20));
ck('a signed-in member also sees members\' events, not the team\'s', in_array('Members Mixer', $member, true) && !in_array('Team Retreat', $member, true));
$team = $titles(SiteEvents::listFor(['id' => 2, 'email' => 'x@afrovanguard.org.ng', 'role' => 'member'], 'upcoming', 20));
ck('the team sees team events too', in_array('Team Retreat', $team, true));
ck('the past is its own list', $titles(SiteEvents::listFor(null, 'past', 5)) === ['Last Month Expo']);
$sh = SiteEvents::listFor(null, 'upcoming', 1)[0];
ck('ours link to their own page here', $sh['url'] === '/events/' . $pub['event']['slug'] . '/' && $sh['source'] === 'av'
   && $sh['time'] === '16:00' && $sh['end_time'] === '18:00');

$cal = SiteEvents::calendarItems(['id' => 2, 'email' => 'x@afrovanguard.org.ng', 'role' => 'member'], date('Y-m-d'), date('Y-m-d', strtotime('+20 days')));
$kinds = array_count_values(array_column($cal, 'kind'));
ck('the portal calendar has ours as "site" and Africa GATES\' as "afg"', ($kinds['site'] ?? 0) === 3 && ($kinds['afg'] ?? 0) === 2);

$ics = SiteEvents::ics();
ck('the .ics holds our public events only', str_contains($ics, 'SUMMARY:Town Hall: Lagos') && !str_contains($ics, 'Members Mixer') && !str_contains($ics, 'Draft Idea'));
ck('with absolute links', str_contains($ics, 'URL:' . rtrim(SITE_URL, '/') . '/events/'));
ck('in Lagos time', str_contains($ics, 'DTSTART;TZID=Africa/Lagos:') || str_contains($ics, 'DTSTART:'));

$cx = SiteEvents::save(['id' => $pub['event']['id'], 'status' => 'cancelled'], 'test');
ck('a cancelled event stays listed, marked', $cx['ok'] && SiteEvents::listFor(null, 'upcoming', 1)[0]['cancelled'] === true && str_contains(SiteEvents::ics(), 'STATUS:CANCELLED'));
ck('deleting removes it', SiteEvents::delete((int) $dft['event']['id'], 'test')['ok'] && SiteEvents::find((int) $dft['event']['id']) === null);

/* ── The pages read the one list ──────────────────────────────────────── */
$api = (string) file_get_contents(AV_ROOT . '/admin/api.php');
ck('the Studio\'s event actions are management-only', preg_match("~managementOnly = \\[.*'sev_list', 'sev_refresh', 'sev_save', 'sev_delete'~s", $api) === 1);
$idx = (string) file_get_contents(AV_ROOT . '/admin/index.php');
ck('the Studio has an Events tab and editor', str_contains($idx, 'data-tab="events"') && str_contains($idx, 'id="evEditView"') && str_contains($idx, 'id="evGatesList"'));
ck('the homepage rail is filled from the feed', str_contains((string) file_get_contents(AV_ROOT . '/index.html'), 'data-avh-events')
   && str_contains((string) file_get_contents(AV_ROOT . '/assets/site/avh.js'), "fetch('/events-feed.php'"));
ck('the feed serves ours and Africa GATES\'', str_contains((string) file_get_contents(AV_ROOT . '/events-feed.php'), 'SiteEvents::listFor(null'));
ck('Today shows the events this member may see', str_contains((string) file_get_contents(AV_ROOT . '/portal/views/today.php'), "SiteEvents::listFor(\$u, 'upcoming', 4)"));
foreach (['events/calendar.ics' => 'events/ics.php', 'events/([a-z0-9-]+)' => 'events/view.php'] as $from => $to) {
    ck(".htaccess and the dev router route /$from", str_contains((string) file_get_contents(AV_ROOT . '/.htaccess'), $to)
       && str_contains((string) file_get_contents(AV_ROOT . '/router.php'), $to));
}

AvEvents::useFetcher(null);
Database::pdo()->exec('DELETE FROM av_site_events');
@unlink($cache);
if ($keep !== null) file_put_contents($cache, $keep);
