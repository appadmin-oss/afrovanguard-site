<?php
/**
 * tests/eventspage.test.php — the rebuilt Events page (/events/, row 13).
 *
 * Pins what the page owns: it reads the events feed (AvEvents via
 * events-feed.php), features the summit only while it is ahead of us, keeps
 * the Host-with-us links, and draws the design's cover the same way on the
 * server and in the browser (the hash that places each motif must agree).
 */
declare(strict_types=1);

require_once AV_ROOT . '/partials/avev-cover.php';

// In a closure, so the page's variables stay out of the runner's scope.
$ev = (static function (): string {
    ob_start();
    try { include AV_ROOT . '/events/index.php'; } finally { $html = (string) ob_get_clean(); }
    return $html;
})();

ck('events page: inside the Home chrome', str_contains($ev, 'class="avh-nav"') && str_contains($ev, 'class="avh-foot"'));
ck('events page: hero heading', str_contains($ev, '<h1>Where the movement meets</h1>'));
ck('events page: list reads the events feed', str_contains($ev, 'data-feed="/events-feed.php"'));
ck('events page: list starts as a skeleton, not blank', substr_count($ev, 'class="avev-sk"') === 3);
ck('events page: six things we host', substr_count($ev, '<li><div class="avcv ') === 6);
ck('events page: volunteer link kept', str_contains($ev, 'href="' . e(AV_VOLUNTEER_URL) . '"'));
ck('events page: get in touch → contact', str_contains($ev, 'href="/contact.html">Get in touch'));
ck('events page: summit band only while it is ahead', str_contains($ev, 'avev-feat"') === !Summit::isPast());
ck('events page: canonical', str_contains($ev, 'rel="canonical" href="' . rtrim(SITE_URL, '/') . '/events/"'));

/* cover */
ck('cover: hash matches the browser (FNV-1a, UTF-16)', avev_cover_hash('Town halls') === 707498939
    && avev_cover_hash('D’Vanguard National Summit 2026') === 1356020805);
$c = avev_cover(['title' => 'Independence Day Youth Parade', 'category' => 'meetup', 'date' => '2026-10-01', 'time' => '8:00am', 'location' => 'Alimosho', 'today' => '2026-10-07']);
ck('cover: past event is marked and says so', str_contains($c, 'is-past') && str_contains($c, 'Took place'));
ck('cover: holiday stripe on a holiday', str_contains($c, 'avcv-hol-independence') && str_contains($c, 'Afrovanguard · Events · Independence Day'));
$c = avev_cover(['title' => 'Town hall', 'category' => 'townhall', 'date' => '2026-10-07', 'today' => '2026-10-07']);
ck('cover: today is live', str_contains($c, 'Live now'));
$c = avev_cover(['title' => '<b>x</b>', 'category' => 'nope']);
ck('cover: unknown category falls back, title escaped', str_contains($c, 'avcv--general') && !str_contains($c, '<b>'));
