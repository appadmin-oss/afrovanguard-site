<?php
/**
 * events/ics.php — /events/calendar.ics: Afrovanguard's public events as a
 * calendar to subscribe to (Google Calendar → Other calendars → From URL).
 * Our own public events only; Africa GATES publishes its own at
 * afg.afrovanguard.org.ng/events.ics. lib/SiteEvents.php::ics().
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="afrovanguard-events.ics"');
header('Cache-Control: public, max-age=900');
header('X-Content-Type-Options: nosniff');
try {
    echo SiteEvents::ics();
} catch (Throwable $e) {
    error_log('[events.ics] ' . $e->getMessage());
    http_response_code(503);
    echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Afrovanguard//Events//EN\r\nEND:VCALENDAR\r\n";
}
