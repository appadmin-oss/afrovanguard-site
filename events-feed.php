<?php
/**
 * events-feed.php — same-origin JSON proxy of the latest AFG events.
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

try {
    $events = array_merge($events, AvEvents::latest(6));
} catch (\Throwable $e) { error_log('[events-feed] ' . $e->getMessage()); }

echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
