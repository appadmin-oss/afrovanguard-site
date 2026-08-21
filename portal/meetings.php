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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');

// The recording bot posts transcripts back here — a SERVICE call authed by the
// per-meeting HMAC token, NOT a user session. Handle it before the auth wall.
if ($action === 'bot_ingest') {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    $in = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;
    json_out(Meetings::botIngest((int) ($in['meeting_id'] ?? 0), (string) ($in['token'] ?? ''), (string) ($in['transcript'] ?? '')));
}

// Recall.ai posts bot status / transcript events here — token-gated service call.
if ($action === 'recall_webhook') {
    if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
    $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    json_out(Meetings::recallWebhook((string) ($_GET['t'] ?? ''), is_array($in) ? $in : []));
}

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$body = [];
if ($method === 'POST' && $action !== 'transcribe') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'meetings', 60); };

try {
    switch ($action) {
        case 'list':
            $freq = [];
            foreach (Meetings::FREQ as $k => $v) $freq[$k] = $v[0];
            $mtgs = Meetings::listFor($uid);
            // The chair's pending drafts ride along — one query, rather than one
            // per card, which at a full calendar is the difference between a page
            // load and a page crawl.
            $drafts = Agenda::pendingForMany(array_column($mtgs, 'id'));
            foreach ($mtgs as $i => $mm) {
                $mtgs[$i]['agenda_draft'] = ($mm['creator_id'] ?? 0) === $uid ? ($drafts[$mm['id']] ?? null) : null;
            }
            json_out(['ok' => true, 'meetings' => $mtgs, 'freq' => $freq, 'me' => $uid, 'agenda_ai' => Agenda::enabled(),
                'gemini' => class_exists('Gemini') && Gemini::configured(),
                'bot' => Meetings::botConfigured(), 'bot_provider' => Meetings::botProvider(),
                'bot_allowed' => Meetings::botAllowed(), 'bot_on_demand' => Meetings::botOnDemandAllowed()]);

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

        /* ── Report §7: the agenda the AI proposed, and the chair's decision.
           Reading it is participant-gated; DECIDING is organiser-only, enforced
           inside Agenda — §7 is explicit that the chair approves or edits. ── */
        case 'agenda_draft':
            if (!Meetings::get($uid, (int) ($_GET['id'] ?? 0))) json_out(['ok' => false, 'error' => 'Meeting not found.'], 404);
            json_out(['ok' => true, 'draft' => Agenda::pendingFor((int) ($_GET['id'] ?? 0)),
                      'enabled' => Agenda::enabled(), 'is_chair' => Agenda::chairOf((int) ($_GET['id'] ?? 0)) === $uid]);

        case 'agenda_suggest': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = (int) ($body['id'] ?? 0);
            // Drafting costs a model call and reads every commitment in the
            // meeting's orbit, so only the chair may ask for one on demand.
            if (Agenda::chairOf($id) !== $uid) json_out(['ok' => false, 'error' => 'Only the organiser can request an agenda.'], 403);
            if (!av_rate_ok('agenda_draft_' . $uid, 10, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            $r = Agenda::draft($id, !empty($body['force']));
            json_out(['ok' => !empty($r['ok']), 'draft' => $r['draft'] ?? null, 'error' => $r['reason'] ?? null]);
        }

        case 'agenda_apply':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $items = null;
            if (isset($body['items']) && is_array($body['items'])) $items = $body['items'];
            elseif (isset($body['text']))                          $items = Agenda::itemsFromText((string) $body['text']);
            json_out(Agenda::apply($uid, (int) ($body['draft_id'] ?? 0), $items));

        case 'agenda_dismiss':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Agenda::dismiss($uid, (int) ($body['draft_id'] ?? 0)));

        // Send the AI notetaker into a meeting, or take it back out. Both are
        // participant-gated inside Meetings — anyone in the call can do either,
        // because someone who wants a conversation off the record should not
        // have to find the organiser first.
        case 'add_bot':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::inviteBot($uid, (int) ($body['id'] ?? 0)));

        case 'remove_bot':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::removeBot($uid, (int) ($body['id'] ?? 0)));

        case 'transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::saveTranscript($uid, (int) ($body['id'] ?? 0), (string) ($body['text'] ?? ''), (string) ($body['source'] ?? 'paste')));

        case 'pull_transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(Meetings::pullGoogleTranscript($uid, (int) ($body['id'] ?? 0)));

        case 'transcribe':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $mid = (int) ($_POST['id'] ?? 0);
            if (empty($_FILES['audio']) || ($_FILES['audio']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                json_out(['ok' => false, 'error' => 'Attach an audio recording.'], 422);
            }
            if ((int) ($_FILES['audio']['size'] ?? 0) > 19 * 1024 * 1024) {
                json_out(['ok' => false, 'error' => 'Recording too large (max ~19MB). Use the Google Meet transcript instead.'], 422);
            }
            $bytes = (string) file_get_contents($_FILES['audio']['tmp_name']);
            $mime  = (string) ($_FILES['audio']['type'] ?? 'audio/mpeg');
            json_out(Meetings::transcribeAudio($uid, $mid, $bytes, $mime));

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[meetings] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
