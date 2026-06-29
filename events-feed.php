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

try {
    $events = AvEvents::latest(6);
    echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[events-feed] ' . $e->getMessage());
    echo json_encode(['ok' => true, 'events' => []]);
}
