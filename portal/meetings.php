<?php
/**
 * portal/meetings.php — JSON API for the standardized meeting system.
 *
 *   GET  ?action=list                                  → { ok, meetings, freq }
 *   GET  ?action=get&id=N                              → { ok, meeting }
 *   POST ?action=schedule {title,when,duration,frequency,agenda,attendees,context,context_id}
 *   POST ?action=cancel {id}
 *   POST ?action=transcript {id, text, source}         → structure Otter-style
 *   POST ?action=pull_transcript {id}                  → best-effort Google Meet pull
 *
 * Any signed-in user; writes are same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'meetings', 60); };

try {
    switch ($action) {
        case 'list':
            $freq = [];
            foreach (Meetings::FREQ as $k => $v) $freq[$k] = $v[0];
            json_out(['ok' => true, 'meetings' => Meetings::listFor($uid), 'freq' => $freq, 'me' => $uid]);

        case 'get':
            $m = Meetings::get($uid, (int) ($_GET['id'] ?? 0));
            if (!$m) json_out(['ok' => false, 'error' => 'Meeting not found.'], 404);
            json_out(['ok' => true, 'meeting' => $m]);

        case 'schedule':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::schedule($uid, is_array($body) ? $body : []));

        case 'cancel':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::cancel($uid, (int) ($body['id'] ?? 0)));

        case 'transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::saveTranscript($uid, (int) ($body['id'] ?? 0), (string) ($body['text'] ?? ''), (string) ($body['source'] ?? 'paste')));

        case 'pull_transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::pullGoogleTranscript($uid, (int) ($body['id'] ?? 0)));

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[meetings] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
