<?php
/**
 * academy/class.php — a live class as a calendar entry.
 *
 *   /academy/class.php?id=12&f=ics
 *
 * The reminder email (academy_live_class) offers "add to calendar", and a
 * link in an email that 404s is worse than no link. Only the class's own
 * facts are published here — title, time, host and the join link — to the
 * learner the reminder was signed for (or a signed-in learner on the course);
 * the booking list is never exposed.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

LmsDrip::ensure();
$id = (int) ($_GET['id'] ?? 0);
$st = Database::pdo()->prepare('SELECT * FROM lms_live_classes WHERE id = ?');
$st->execute([$id]);
$c = $st->fetch(PDO::FETCH_ASSOC);

/* The id is sequential and the entry carries the join link, so it is served
   only to the person a reminder was signed for, or to a signed-in learner who
   may take the course. Anybody else gets the same answer as no such class. */
$uid = (int) ($_GET['u'] ?? 0);
$ok = $c && $uid > 0 && hash_equals(LmsDrip::classSig($id, $uid), (string) ($_GET['t'] ?? ''));
if ($c && !$ok && ($me = LmsAuth::user())) {
    $course = Database::pdo()->prepare('SELECT * FROM courses WHERE id = ?');
    $course->execute([(int) $c['course_id']]);
    $crow = $course->fetch(PDO::FETCH_ASSOC);
    $lms = new LmsRepository();
    $ok = $crow && ($lms->isEnrolled((int) $me['id'], (int) $crow['id']) || $lms->teaches($me, $crow));
}
if (!$c || !$ok) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No such class.\n";
    exit;
}

$ts  = strtotime(((string) $c['starts_at']) . ' UTC') ?: time();
$esc = static fn(string $s): string => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $s);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="afrovanguard-class-' . $id . '.ics"');
echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Afrovanguard//Academy//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
echo "BEGIN:VEVENT\r\nUID:class-{$id}@afrovanguard.org.ng\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
echo 'DTSTART:' . gmdate('Ymd\THis\Z', $ts) . "\r\n";
echo 'DTEND:' . gmdate('Ymd\THis\Z', $ts + 3600) . "\r\n";
echo 'SUMMARY:' . $esc((string) $c['title']) . "\r\n";
echo 'DESCRIPTION:' . $esc('Hosted by ' . (string) $c['host_name'] . '. Join: ' . (string) $c['join_url']) . "\r\n";
if (trim((string) $c['join_url']) !== '') echo 'URL:' . $esc((string) $c['join_url']) . "\r\n";
echo "END:VEVENT\r\nEND:VCALENDAR\r\n";
