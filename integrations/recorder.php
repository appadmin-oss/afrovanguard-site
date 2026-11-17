<?php
/**
 * integrations/recorder.php — the Meeting Recorder extension's door (lib/Recorder.php).
 *
 *   POST ?action=pair      {code, device}                      → { ok, token, user }   (no token needed)
 *   GET  ?action=limits                                        → { ok, chunk_bytes, … }
 *   GET  ?action=whoami                                        → { ok, user, device }
 *   POST ?action=start     {meet_code, title, url, meeting_id?, consent_shown}
 *   POST ?action=chunk     multipart: rec, seg, idx, sha256, data(file)
 *   POST ?action=segment   {rec, seg, chunks, offset, mime, talk?: [[start, end, "me"|"them"]…]}     → assembles, or lists missing chunks
 *   POST ?action=finish    {rec, segments, marks?: [seconds…], people?: most people Meet showed,
 *                                 consent?: none|reminded|confirmed, consent_at?}
 *   GET  ?action=status&rec=N
 *   POST ?action=cancel    {rec}
 *
 * Every call but pair carries the device token, as "X-AV-Recorder-Token: av_rec_…"
 * (always reaches PHP, even on a CGI shared host that drops Authorization) or
 * "Authorization: Bearer av_rec_…". The extension calls from its own pages with
 * host permission for this site, so no CORS headers are sent: a web page
 * elsewhere cannot read these answers.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = static function (array $b, int $code = 200): void {
    unset($b['status']);
    http_response_code($code);
    echo json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$reply = static function (array $r) use ($out): void {
    $out($r, (int) ($r['status'] ?? (!empty($r['ok']) ? 200 : 422)));
};

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? '');
$posts = ['pair', 'start', 'chunk', 'segment', 'finish', 'cancel'];
$gets = ['limits', 'whoami', 'status'];
if (!in_array($action, array_merge($posts, $gets), true)) $out(['ok' => false, 'error' => 'Unknown action.'], 400);
if (in_array($action, $posts, true) && $method !== 'POST') $out(['ok' => false, 'error' => 'POST required.'], 405);
if (in_array($action, $gets, true) && $method !== 'GET') $out(['ok' => false, 'error' => 'GET required.'], 405);

$body = [];
if ($method === 'POST' && $action !== 'chunk') {
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > 262144) $out(['ok' => false, 'error' => 'Too large.'], 413);
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
}

try {
    if ($action === 'pair') {
        // Codes are 128-bit and single-use; the limit is against noise, not guessing.
        if (!av_rate_ok('recpair', 20, 600)) $out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
        $reply(Recorder::pair((string) ($body['code'] ?? ''), (string) ($body['device'] ?? '')));
    }

    $tok = trim((string) ($_SERVER['HTTP_X_AV_RECORDER_TOKEN'] ?? ''));
    $auth = $tok !== '' ? 'Bearer ' . $tok : (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $device = Recorder::device($auth);
    if (!$device) {
        av_rate_ok('recbad', 30, 600) || $out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
        $out(['ok' => false, 'error' => 'This recorder is not connected. Connect it again from the portal.', 'reconnect' => true], 401);
    }
    // Generous: an hour of audio is a few dozen requests; this stops a runaway loop.
    if (!av_rate_ok('rec_' . (int) $device['id'], 900, 600)) $out(['ok' => false, 'error' => 'Slow down a moment.', 'retry' => true], 429);

    $rec = (int) ($body['rec'] ?? ($_POST['rec'] ?? ($_GET['rec'] ?? 0)));
    switch ($action) {
        case 'limits':
            $reply(Recorder::limits());
        case 'whoami':
            $st = Database::pdo()->prepare('SELECT name, email FROM lms_users WHERE id = ?');
            $st->execute([(int) $device['user_id']]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $reply(['ok' => true, 'user' => ['name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? '')], 'device' => (string) $device['name']] + Recorder::limits());
        case 'start':
            $reply(Recorder::start($device, $body));
        case 'chunk':
            $f = $_FILES['data'] ?? null;
            if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
                $big = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);
                $out(['ok' => false, 'error' => $big ? 'Chunk too large for this host.' : 'No chunk arrived.', 'chunk_bytes' => Recorder::chunkBytes(), 'retry' => !$big], $big ? 413 : 422);
            }
            $reply(Recorder::chunk($device, $rec, (int) ($_POST['seg'] ?? -1), (int) ($_POST['idx'] ?? -1),
                strtolower((string) ($_POST['sha256'] ?? '')), (string) $f['tmp_name'], (int) $f['size']));
        case 'segment':
            $reply(Recorder::segmentDone($device, $rec, (int) ($body['seg'] ?? -1), (int) ($body['chunks'] ?? 0),
                (int) ($body['offset'] ?? 0), (string) ($body['mime'] ?? 'audio/webm'), is_array($body['talk'] ?? null) ? $body['talk'] : []));
        case 'finish':
            $reply(Recorder::finish($device, $rec, (int) ($body['segments'] ?? 0), is_array($body['marks'] ?? null) ? $body['marks'] : [], (int) ($body['people'] ?? 0),
                (string) ($body['consent'] ?? 'none'), (string) ($body['consent_at'] ?? '')));
        case 'status':
            $reply(Recorder::status($device, $rec));
        case 'cancel':
            $reply(Recorder::cancel($device, $rec));
    }
} catch (Throwable $e) {
    error_log('[recorder] ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'The server had a problem. The recorder will try again.', 'retry' => true], 500);
}
