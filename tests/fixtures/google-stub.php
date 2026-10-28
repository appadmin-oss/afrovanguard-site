<?php
/**
 * tests/fixtures/google-stub.php — a stand-in for Google's token endpoint and
 * the few REST reads the site makes, for `php -S … google-stub.php`.
 *
 * There is no network to Google from the test host, so tests/google.test.php
 * points AV_WS_TOKEN_URL / AV_WS_BASE_URL / AV_REPORTS_BASE_URL here. It answers
 * the way Google answers, including the failures that matter:
 *
 *   authorization_code  code "av-setup-probe" → invalid_grant if the client
 *                       secret is "good-secret", else invalid_client.
 *                       Any other code is base64url(JSON claims): the id_token
 *                       is minted from exactly those claims.
 *   refresh_token       "dead" → invalid_grant; anything else → a new token.
 *   jwt-bearer          a `sub` starting "nodwd" → unauthorized_client (domain-
 *                       wide delegation not granted); otherwise a token.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit("dev server only\n"); }

header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$out  = static function (int $code, array $body): void { http_response_code($code); echo json_encode($body); };

if ($path === '/token') {
    $g = (string) ($_POST['grant_type'] ?? '');
    if ($g === 'authorization_code') {
        $code = (string) ($_POST['code'] ?? '');
        if ($code === 'av-setup-probe') {
            ($_POST['client_secret'] ?? '') === 'good-secret'
                ? $out(400, ['error' => 'invalid_grant', 'error_description' => 'Malformed auth code.'])
                : $out(401, ['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.']);
            return true;
        }
        $claims = json_decode((string) base64_decode(strtr($code, '-_', '+/')), true);
        if (!is_array($claims)) { $out(400, ['error' => 'invalid_grant']); return true; }
        $jwt = $b64u('{"alg":"RS256","typ":"JWT"}') . '.' . $b64u((string) json_encode($claims)) . '.sig';
        $out(200, ['access_token' => 'at-' . bin2hex(random_bytes(4)), 'expires_in' => 3599, 'id_token' => $jwt,
                   'scope' => (string) ($claims['__scope'] ?? 'openid email profile'), 'token_type' => 'Bearer']
                  + (isset($claims['__refresh']) ? ['refresh_token' => (string) $claims['__refresh']] : []));
        return true;
    }
    if ($g === 'refresh_token') {
        ($_POST['refresh_token'] ?? '') === 'dead'
            ? $out(400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'])
            : $out(200, ['access_token' => 'at-refreshed', 'expires_in' => 3599]);
        return true;
    }
    if ($g === 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
        $p = explode('.', (string) ($_POST['assertion'] ?? ''));
        $c = json_decode((string) base64_decode(strtr($p[1] ?? '', '-_', '+/')), true) ?: [];
        $sub = (string) ($c['sub'] ?? '');
        /* "nogmail…": delegation granted, but not the Gmail scope. */
        if (str_starts_with($sub, 'nogmail') && str_contains((string) ($c['scope'] ?? ''), 'gmail')) {
            $out(401, ['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.']);
            return true;
        }
        if (str_starts_with($sub, 'nodwd')) {
            $out(401, ['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.']);
        } else {
            /* The token says who it acts as, so a call can be checked for it (see $who). */
            $out(200, ['access_token' => 'sa.' . $b64u($sub) . '.' . bin2hex(random_bytes(4)), 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }
        return true;
    }
    $out(400, ['error' => 'unsupported_grant_type']); return true;
}
/* Who the call acts as ('' = the bare service account), and a log of writes the
   test reads back: GSTUB_LOG, one JSON line per call. */
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$who = preg_match('~^Bearer sa\.([A-Za-z0-9_-]*)\.~', $auth, $wm) ? (string) base64_decode(strtr($wm[1], '-_', '+/')) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string) file_get_contents('php://input');
$log = (string) getenv('GSTUB_LOG');
if ($log !== '' && $method !== 'GET') @file_put_contents($log, json_encode(['m' => $method, 'path' => $path, 'q' => (string) ($_SERVER['QUERY_STRING'] ?? ''), 'who' => $who]) . "\n", FILE_APPEND);
$link = 'https://meet.google.com/abc-defg-hij';

/* Calendar writes. A title with "pending" gets a conference Google has not
   finished making: no link on create, none on read back (id "evp-…"). */
if (preg_match('~^/calendar/v3/calendars/([^/]+)/events$~', $path, $cm) && $method === 'POST') {
    $ev = json_decode($raw, true) ?: [];
    $pending = str_contains(strtolower((string) ($ev['summary'] ?? '')), 'pending');
    $id = ($pending ? 'evp-' : 'ev-') . bin2hex(random_bytes(4));
    $body = ['id' => $id, 'htmlLink' => 'https://calendar.google.com/event?eid=' . $id, 'organizer' => ['email' => $who ?: urldecode($cm[1])]];
    if (isset($ev['conferenceData'])) {
        $body['conferenceData'] = ['createRequest' => ['status' => ['statusCode' => $pending ? 'pending' : 'success']]];
        if (!$pending) $body['hangoutLink'] = $link;
    }
    $out(200, $body); return true;
}
if (preg_match('~^/calendar/v3/calendars/[^/]+/events/([^/]+)$~', $path, $cm)) {
    if ($method === 'DELETE') { http_response_code(204); return true; }
    $id = urldecode($cm[1]);
    $out(200, ['id' => $id] + (str_starts_with($id, 'evp-') ? [] : ['hangoutLink' => $link])); return true;
}
/* Each member's own Google, read as them. */
if ($path === '/gmail/v1/users/me/labels/UNREAD') { $out(200, ['messagesTotal' => 3]); return true; }
if ($path === '/gmail/v1/users/me/messages') { $out(200, ['messages' => [['id' => 'm1']]]); return true; }
if (str_starts_with($path, '/gmail/v1/users/me/messages/')) {
    $out(200, ['id' => 'm1', 'snippet' => 'Hello', 'labelIds' => ['INBOX', 'UNREAD'], 'payload' => ['headers' => [
        ['name' => 'From', 'value' => 'Ada Obi <ada@afrovanguard.org.ng>'], ['name' => 'Subject', 'value' => 'Agenda for ' . $who]]]]);
    return true;
}
if ($path === '/upload/drive/v3/files' && $method === 'POST') {
    $out(200, ['id' => 'f1', 'webViewLink' => 'https://drive.google.com/file/d/f1/view']); return true;
}
if ($path === '/v2/conferenceRecords') { $out(200, ['conferenceRecords' => []]); return true; }
if ($path === '/v1/spaces') { $out(200, ['spaces' => [['name' => 'spaces/AAA1', 'displayName' => 'Volunteers', 'spaceType' => 'SPACE']]]); return true; }
if (str_starts_with($path, '/v2/spaces') && $method === 'POST') {
    $out(200, ['name' => 'spaces/S1', 'meetingUri' => 'https://meet.google.com/qqq-rrrr-sss', 'meetingCode' => 'qqq-rrrr-sss']); return true;
}
if (str_starts_with($path, '/v2/spaces/') && $method === 'PATCH') { $out(200, ['name' => 'spaces/S1']); return true; }
if (str_starts_with($path, '/calendar/v3/calendars/')) {
    $out(200, ['items' => [['id' => 'ev1', 'summary' => 'Stub event', 'start' => ['dateTime' => '2030-01-01T09:00:00Z'], 'end' => ['dateTime' => '2030-01-01T10:00:00Z']]]]);
    return true;
}
if ($path === '/drive/v3/files') { $out(200, ['files' => []]); return true; }
if ($path === '/admin/directory/v1/users') {
    $out(200, ['users' => [
        ['primaryEmail' => 'dir.one@afrovanguard.org.ng', 'name' => ['fullName' => 'Dir One'], 'suspended' => false],
        ['primaryEmail' => 'dir.gone@afrovanguard.org.ng', 'name' => ['fullName' => 'Dir Gone'], 'suspended' => true],
    ]]);
    return true;
}
if ($path === '/admin/directory/v1/groups') { $out(200, ['groups' => []]); return true; }
$out(404, ['error' => ['code' => 404, 'message' => 'stub: no route ' . $path, 'status' => 'NOT_FOUND']]);
return true;
