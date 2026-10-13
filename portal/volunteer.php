<?php
/**
 * portal/volunteer.php — the Volunteer hub's API (lib/Volunteers, MeetingMinutes, MeetingLive).
 *
 *   GET  ?action=hub                                   → everything the hub shows
 *   GET  ?action=people&q=                             → volunteers by name or email
 *   GET  ?action=team&id=                              → a team, its members and their hours
 *   POST ?action=team_create {name, purpose}
 *   POST ?action=team_update {id, name?, purpose?, archived?}
 *   POST ?action=team_member {team_id, who, role}      → add, or change a role
 *   POST ?action=team_remove {team_id, user_id}
 *   POST ?action=schedule {team_id, title, when, duration, frequency, agenda, extra}
 *   POST ?action=rsvp {meeting_id, occurrence, answer, note}
 *   GET  ?action=minutes&meeting_id=&occurrence=       → the minutes (made when the writer opens them)
 *   POST ?action=minutes_save {id, body, attendance, secretary_id}
 *   POST ?action=minutes_notes {id, notes}             → rough notes tidied into the sections
 *   POST ?action=minutes_submit {id} | minutes_return {id, note} | minutes_publish {id}
 *   GET  ?action=room&meeting_id=&occurrence=&since=   → the live room: who is here, what is said, the check-in QR
 *   POST ?action=capture {meeting_id, occurrence, type, text, owner_id, due} → into the draft, live
 *   POST ?action=here {meeting_id, occurrence}         → "I'm here"
 *   POST ?action=caption {meeting_id, occurrence, text, id, speaker}
 *   POST ?action=hours {day, hours, activity} | hours_remove {id}
 *   GET  ?action=ics&meeting_id=                       → the calendar file
 *   GET  ?action=print&id= | pdf&id=                   → the minutes, to print or as a PDF
 *   GET  ?action=checkin&m=&o=&c=                      → a phone that scanned the room's QR
 *
 * Signed in; the hub is for volunteers (Volunteers::isVolunteer). Writes are
 * same-origin + CSRF + rate-limited, like the rest of the portal's APIs.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'hub');

$u = LmsAuth::user();

/* Scanning the room's QR opens this in the phone's browser: a page, not JSON. */
if ($action === 'checkin') {
    $page = static function (string $title, string $line, bool $good) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . '</title>'
           . '<style>body{font-family:system-ui,sans-serif;background:#f5f6f8;color:#15120e;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}main{background:#fff;border:1px solid #e7e9ee;border-radius:18px;padding:32px 24px;max-width:420px;text-align:center}'
           . '.i{font-size:48px;line-height:1}.g{color:#137a3a}.b{color:#c0322b}a{color:#e4162b;font-weight:700}</style></head><body><main>'
           . '<div class="i ' . ($good ? 'g' : 'b') . '">' . ($good ? '✓' : '!') . '</div><h1 style="font-size:20px">' . e($title) . '</h1><p>' . e($line) . '</p><p><a href="/portal/#volunteer">Open your Volunteer hub</a></p></main></body></html>';
        exit;
    };
    if (!$u) { header('Location: ' . av_login_url((string) ($_SERVER['REQUEST_URI'] ?? '/portal/'))); exit; }
    $r = MeetingLive::checkin($u, (int) ($_GET['m'] ?? 0), (string) ($_GET['o'] ?? ''), (string) ($_GET['c'] ?? ''));
    if (!$r['ok']) $page('Not checked in', (string) $r['error'], false);
    $page($r['already'] ? 'Already checked in' : 'You are checked in', (string) $r['title'] . ' — welcome.', true);
}

if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!Volunteers::isVolunteer($u)) json_out(['ok' => false, 'error' => 'The Volunteer hub is for Afrovanguard volunteers.'], 403);
$uid = (int) $u['id'];

$body = [];
if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    /* Captions arrive a sentence at a time while a meeting is on: their own,
       roomier bucket, so a long meeting is never refused halfway through. */
    av_require_write($uid, $action === 'caption' ? 'vol_caption' : 'volunteer', $action === 'caption' ? 900 : 120);
}
$in = static fn(string $k, $d = '') => $body[$k] ?? $_GET[$k] ?? $d;

try {
    switch ($action) {
        case 'hub':          json_out(Volunteers::hub($u));
        case 'people':       json_out(['ok' => true, 'people' => Volunteers::people((string) $in('q'))]);
        case 'team': {
            $id = (int) $in('id', 0);
            $t = Volunteers::team($id);
            if (!$t || (Volunteers::roleIn($id, $uid) === '' && !Volunteers::canRunTeams($u))) json_out(['ok' => false, 'error' => 'No such team.'], 404);
            json_out(['ok' => true, 'team' => $t + ['my_role' => Volunteers::roleIn($id, $uid), 'can_manage' => Volunteers::canManageTeam($u, $id)], 'hours' => Volunteers::teamHours($id)]);
        }
        case 'team_create':  json_out(Volunteers::createTeam($u, (string) $in('name'), (string) $in('purpose')));
        case 'team_update':  json_out(Volunteers::updateTeam($u, (int) $in('id', 0), $body));
        case 'team_member':  json_out(Volunteers::setMember($u, (int) $in('team_id', 0), (string) $in('who'), (string) $in('role', 'member')));
        case 'team_remove':  json_out(Volunteers::removeMember($u, (int) $in('team_id', 0), (int) $in('user_id', 0)));
        case 'schedule':     json_out(MeetingMinutes::scheduleForTeam($u, (int) $in('team_id', 0), $body));
        case 'rsvp':         json_out(MeetingMinutes::rsvp($u, (int) $in('meeting_id', 0), (string) $in('occurrence'), (string) $in('answer'), (string) $in('note')));
        case 'minutes':      json_out(MeetingMinutes::open($u, (int) $in('meeting_id', 0), (string) $in('occurrence')));
        case 'minutes_save': json_out(MeetingMinutes::save($u, (int) $in('id', 0), (array) $in('body', []), (array) $in('attendance', []), (int) $in('secretary_id', 0)));
        case 'minutes_notes':   json_out(MeetingMinutes::fromNotes($u, (int) $in('id', 0), (string) $in('notes')));
        case 'minutes_submit':  json_out(MeetingMinutes::submit($u, (int) $in('id', 0)));
        case 'minutes_return':  json_out(MeetingMinutes::returnToDraft($u, (int) $in('id', 0), (string) $in('note')));
        case 'minutes_publish': json_out(MeetingMinutes::publish($u, (int) $in('id', 0)));
        case 'room':         json_out(MeetingLive::room($u, (int) $in('meeting_id', 0), (string) $in('occurrence'), (int) $in('since', 0)));
        case 'capture':      json_out(MeetingMinutes::capture($u, (int) $in('meeting_id', 0), (string) $in('occurrence'), (string) $in('type'), (string) $in('text'), (int) $in('owner_id', 0), (string) $in('due')));
        case 'here':         json_out(MeetingLive::here($u, (int) $in('meeting_id', 0), (string) $in('occurrence')));
        case 'caption':      json_out(MeetingLive::caption($u, (int) $in('meeting_id', 0), (string) $in('occurrence'), (string) $in('text'), (string) $in('id'), (string) $in('speaker')));
        case 'hours':        json_out(Volunteers::logHours($uid, (string) $in('day'), $in('hours', 0), (string) $in('activity')));
        case 'hours_remove': json_out(Volunteers::removeHours($uid, (int) $in('id', 0)));
        case 'ics': {
            $ics = MeetingMinutes::ics($u, (int) $in('meeting_id', 0));
            if ($ics === null) json_out(['ok' => false, 'error' => 'No such meeting.'], 404);
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="meeting-' . (int) $in('meeting_id', 0) . '.ics"');
            echo $ics; exit;
        }
        case 'print': {
            $html = MeetingMinutes::printable($u, (int) $in('id', 0));
            if ($html === null) json_out(['ok' => false, 'error' => 'No such minutes.'], 404);
            header('Content-Type: text/html; charset=utf-8');
            echo $html; exit;
        }
        case 'pdf': {
            $pdf = MeetingMinutes::pdfFor($u, (int) $in('id', 0));
            if ($pdf === null) json_out(['ok' => false, 'error' => 'No such minutes, or PDFs are not available here.'], 404);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $pdf['name'] . '"');
            echo $pdf['bytes']; exit;
        }
    }
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('[volunteer] ' . $action . ': ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Something went wrong. Try again.'], 500);
}
