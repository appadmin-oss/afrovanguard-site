<?php
/**
 * academy/dns/summit.ics.php — the summit as a calendar invitation.
 *
 *   /academy/dns/summit.ics   (see academy/.htaccess)
 *
 * Built from Summit::facts(), so it can never disagree with the page. The
 * flyer publishes a 9:00 AM start but no closing time, so this is written as a
 * multi-day ALL-DAY event with the start time in the description — a calendar
 * entry should not invent an end time the organisers never announced.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

$f = Summit::facts();
$v = $f['venue'];

/** Escape per RFC 5545 §3.3.11. */
$esc = static fn(string $s): string => str_replace(
    ["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $s);

/**
 * Fold long content lines to 75 octets, per RFC 5545 §3.1.
 *
 * The limit is in OCTETS but the content is UTF-8, so the break has to land on
 * a character boundary — splitting every 75 bytes would cut a multi-byte
 * character (every em dash in the description) in half.
 */
$fold = static function (string $line): string {
    if (strlen($line) <= 75) return $line;
    $out = ''; $cur = ''; $limit = 75;
    foreach (mb_str_split($line, 1, 'UTF-8') as $ch) {
        if (strlen($cur) + strlen($ch) > $limit) {
            $out .= ($out === '' ? '' : "\r\n ") . $cur;
            $cur = ''; $limit = 74;          // continuation lines carry a leading space
        }
        $cur .= $ch;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $cur;
};

$start = strtotime($f['starts']);
$endEx = strtotime($f['ends'] . ' +1 day');          // DTEND is exclusive for DATE values
if ($start === false || $endEx === false) { http_response_code(500); exit('Bad summit dates'); }

$location = $v['name'] . ', ' . $v['area'] . ', ' . $v['city'] . ', ' . $v['region'];
$desc = $f['lede'] . "\n\n"
      . 'Starts ' . $f['time_label'] . ' each day. '
      . 'Pass: ' . $f['pass']['label'] . ' (' . $f['pass']['note'] . ").\n"
      . 'Details and registration: ' . Summit::url() . "\n"
      . 'WhatsApp: ' . $f['whatsapp']['display'];

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Afrovanguard//' . $f['edition'] . '//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . Summit::EDITION . '@afrovanguard.org.ng',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;VALUE=DATE:' . date('Ymd', $start),
    'DTEND;VALUE=DATE:' . date('Ymd', $endEx),
    'SUMMARY:' . $esc($f['name'] . ' (' . $f['edition'] . ')'),
    'DESCRIPTION:' . $esc($desc),
    'LOCATION:' . $esc($location),
    'URL:' . Summit::url(),
    'ORGANIZER;CN=Afrovanguard:MAILTO:' . (defined('FROM_EMAIL') && FROM_EMAIL ? FROM_EMAIL : 'hello@afrovanguard.org.ng'),
    'STATUS:CONFIRMED',
    'TRANSP:TRANSPARENT',
    'BEGIN:VALARM',
    'TRIGGER:-P1D',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . $esc($f['name'] . ' starts tomorrow'),
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . Summit::EDITION . '.ics"');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo implode("\r\n", array_map($fold, $lines)) . "\r\n";
