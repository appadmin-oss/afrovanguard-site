<?php
/**
 * events-feed.php — the events the site shows: ours and Africa GATES', public only.
 *
 * The static home page can't fetch afg.afrovanguard.org.ng directly (CORS),
 * so it fetches this endpoint, which serves AvEvents' cached, fail-safe list.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
header('X-Content-Type-Options: nosniff');

$events = [];

// Our own summit leads the rail while it is still ahead of us. It is prepended
// rather than merged by date because it is the one event on this feed we host
// ourselves — and Summit::feedEntry() returns null once it is over, so a
// finished summit can never sit at the head of an "upcoming" list.
try {
    if (class_exists('Summit')) {
        $own = Summit::feedEntry();
        if ($own) $events[] = $own;
    }
} catch (\Throwable $e) { error_log('[events-feed] summit: ' . $e->getMessage()); }

/* Then everything else, ours and Africa GATES' (lib/SiteEvents.php): what is
   coming, and the recent past for the page's "Past" filter. Public events
   only — this feed is for visitors. */
$past = [];
try {
    $events = array_merge($events, SiteEvents::listFor(null, 'upcoming', 24));
    $past = SiteEvents::listFor(null, 'past', 12);
} catch (\Throwable $e) { error_log('[events-feed] ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'events' => $events, 'past' => $past], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
