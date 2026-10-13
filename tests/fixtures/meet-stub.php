<?php
/**
 * tests/fixtures/meet-stub.php — Google Calendar, Meet, Directory, Workspace
 * Events and Pub/Sub, enough of each for tests/meetauto.test.php, served with
 * `php -S … meet-stub.php`.
 *
 * State lives in the JSON file named by MEET_STUB_STATE (the test writes it):
 *   transcript  'none' | 'pending' | 'ready'
 *   live        true while the conference is still on
 *   norec       true: the edition refuses recording (PATCH with recording → 403)
 *   pull        the messages the next Pub/Sub pull returns (then emptied)
 *   sub_exists  true: creating the events subscription is refused ALREADY_EXISTS (SUB0 is listed)
 * Every request is appended to MEET_STUB_STATE.log as one JSON line:
 * {method, path, query, sub, body} — `sub` being who the token was minted for.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit("dev server only\n"); }

header('Content-Type: application/json');
$stateFile = (string) getenv('MEET_STUB_STATE');
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string) file_get_contents('php://input');
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
/* The access token carries who it was minted for: "tok:<sub>". */
$sub = str_starts_with($auth, 'Bearer tok:') ? substr($auth, 11) : '';
$out = static function (int $code, array $body): bool { http_response_code($code); echo json_encode($body); return true; };
file_put_contents($stateFile . '.log', json_encode(['method' => $method, 'path' => $path, 'query' => $query, 'sub' => $sub, 'body' => json_decode($raw, true)]) . "\n", FILE_APPEND);

if ($path === '/token') {
    $p = explode('.', (string) ($_POST['assertion'] ?? ''));
    $c = json_decode((string) base64_decode(strtr($p[1] ?? '', '-_', '+/')), true) ?: [];
    return $out(200, ['access_token' => 'tok:' . (string) ($c['sub'] ?? ''), 'expires_in' => 3599]);
}
/* Calendar: create an event with a Meet; read it back. */
if (preg_match('#^/calendar/v3/calendars/[^/]+/events$#', $path) && $method === 'POST') {
    return $out(200, ['id' => 'ev-1', 'htmlLink' => 'https://calendar.test/ev-1', 'hangoutLink' => empty($state['pending_link']) ? 'https://meet.google.com/abc-defg-hij' : null]);
}
if (preg_match('#^/calendar/v3/calendars/[^/]+/events/([\w-]+)$#', $path)) {
    return $out(200, ['id' => 'ev-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']);
}
/* Meet */
if ($path === '/v2/spaces/abc-defg-hij' && $method === 'GET') return $out(200, ['name' => 'spaces/S1', 'meetingCode' => 'abc-defg-hij']);
if ($path === '/v2/spaces/S1' && $method === 'PATCH') {
    if (!empty($state['norec']) && str_contains(urldecode($query), 'recordingConfig')) return $out(403, ['error' => ['code' => 403, 'message' => 'Recording is not available for this edition.', 'status' => 'PERMISSION_DENIED']]);
    return $out(200, ['name' => 'spaces/S1']);
}
if ($path === '/v2/conferenceRecords' && str_contains(urldecode($query), 'spaces/S1')) {
    return $out(200, ['conferenceRecords' => [['name' => 'conferenceRecords/C1', 'space' => 'spaces/S1', 'startTime' => (string) ($state['conf_start'] ?? gmdate('c')),
        ] + (empty($state['live']) ? ['endTime' => (string) ($state['conf_end'] ?? gmdate('c'))] : [])]]);
}
if ($path === '/v2/conferenceRecords/C1') {
    return $out(200, ['name' => 'conferenceRecords/C1', 'space' => 'spaces/S1', 'startTime' => (string) ($state['conf_start'] ?? gmdate('c'))] + (empty($state['live']) ? ['endTime' => (string) ($state['conf_end'] ?? gmdate('c'))] : []));
}
if ($path === '/v2/conferenceRecords/C1/participants') {
    return $out(200, ['participants' => [
        ['name' => 'conferenceRecords/C1/participants/P1', 'signedinUser' => ['user' => 'users/111', 'displayName' => 'Ada Obi'], 'earliestStartTime' => $state['conf_start'] ?? gmdate('c'), 'latestEndTime' => $state['conf_end'] ?? gmdate('c')],
        ['name' => 'conferenceRecords/C1/participants/P2', 'anonymousUser' => ['displayName' => 'Guest Bisi'], 'earliestStartTime' => $state['conf_start'] ?? gmdate('c')],
    ]]);
}
if ($path === '/v2/conferenceRecords/C1/participants/P1/participantSessions') {
    $s = strtotime((string) ($state['conf_start'] ?? 'now'));
    return $out(200, ['participantSessions' => [
        ['name' => 'conferenceRecords/C1/participants/P1/participantSessions/A', 'startTime' => gmdate('c', $s), 'endTime' => gmdate('c', $s + 600)],
        ['name' => 'conferenceRecords/C1/participants/P1/participantSessions/B', 'startTime' => gmdate('c', $s + 900), 'endTime' => gmdate('c', $s + 2400)],
    ]]);
}
if ($path === '/v2/conferenceRecords/C1/participants/P2/participantSessions') return $out(200, ['participantSessions' => []]);
if ($path === '/v2/conferenceRecords/C1/transcripts') {
    $t = (string) ($state['transcript'] ?? 'none');
    if ($t === 'none') return $out(200, []);
    return $out(200, ['transcripts' => [['name' => 'conferenceRecords/C1/transcripts/T1', 'state' => $t === 'ready' ? 'FILE_GENERATED' : 'STARTED', 'docsDestination' => ['document' => 'doc-T1']]]]);
}
if ($path === '/v2/conferenceRecords/C1/transcripts/T1/entries') {
    return $out(200, ['transcriptEntries' => [
        ['participant' => 'conferenceRecords/C1/participants/P1', 'text' => 'Welcome everyone, let us start with the outreach plan.'],
        ['participant' => 'conferenceRecords/C1/participants/P2', 'text' => 'I can lead the school visits.'],
    ]]);
}
if ($path === '/v2/conferenceRecords/C1/smartNotes') return $out(200, ['smartNotes' => [['name' => 'conferenceRecords/C1/smartNotes/N1', 'state' => 'FILE_GENERATED', 'docsDestination' => ['document' => 'doc-N1']]]]);
if ($path === '/drive/v3/files/doc-N1/export') { header('Content-Type: text/plain'); echo "Summary: Bisi leads the school visits.\nNext steps: Ada books the hall."; return true; }
/* Directory */
if (preg_match('#^/admin/directory/v1/users/([^/]+)$#', $path, $m)) return $out(200, ['id' => '4242', 'primaryEmail' => urldecode($m[1])]);
/* Workspace Events */
if ($path === '/v1/subscriptions' && $method === 'POST') {
    if (!empty($state['sub_exists'])) return $out(409, ['error' => ['code' => 409, 'status' => 'ALREADY_EXISTS', 'message' => 'subscription already exists']]);
    return $out(200, ['name' => 'operations/op1', 'done' => true, 'response' => ['name' => 'subscriptions/SUB1']]);
}
if ($path === '/v1/subscriptions' && $method === 'GET') return $out(200, ['subscriptions' => !empty($state['sub_exists'])
    ? [['name' => 'subscriptions/SUB0', 'targetResource' => '//cloudidentity.googleapis.com/users/4242']] : []]);
if ($path === '/v1/subscriptions/SUB0' && $method === 'PATCH') return $out(404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND']]);
if ($path === '/v1/subscriptions/SUB1' && $method === 'PATCH') return $out(200, ['name' => 'operations/op2', 'done' => true]);
/* Pub/Sub */
if (preg_match('#^/v1/projects/p/subscriptions/meet-pull:pull$#', $path)) {
    $msgs = (array) ($state['pull'] ?? []);
    $state['pull'] = [];
    file_put_contents($stateFile, json_encode($state));
    return $out(200, $msgs ? ['receivedMessages' => $msgs] : []);
}
if (preg_match('#^/v1/projects/p/subscriptions/meet-pull:acknowledge$#', $path)) return $out(200, []);

return $out(404, ['error' => ['code' => 404, 'message' => 'stub: no route ' . $method . ' ' . $path, 'status' => 'NOT_FOUND']]);
