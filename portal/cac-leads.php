<?php
/**
 * portal/cac-leads.php — the follow-ups a member owes, and recording one.
 *
 *   GET                       → { ok, leads:[…] }
 *   POST ?action=log {id, summary, next_action, next_action_at}
 *                             → { ok }
 *
 * ── THE WRITE IS THE POINT ──────────────────────────────────────────────────
 * CACENTRE's leads screen exists because the centre's workbook has a NOTES
 * column filled in on none of ninety rows. A follow-up list here that only
 * READ would tell a member they owe a call and then send them to another site
 * to record it — rebuilding, in a new place, exactly the gap that emptied the
 * column. So the log is written from here, through the same call the console's
 * own button makes.
 *
 * Nothing is stored on this side. The lead lives in CACENTRE, which checks the
 * lead's owner against its own row before writing: a remote write is not a
 * licence to name any id.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) {
    json_out(['ok' => false, 'error' => 'Follow-ups are for Afrovanguard members.'], 403);
}
if (!class_exists('CacLeads')) {
    json_out(['ok' => false, 'error' => 'The console is not linked from here.'], 503);
}

$uid    = (int) $u['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');

/* The far side's word, turned into this site's sentence. A member reading
   "unreachable" learns nothing; one reading that the console did not answer
   knows to try again in a minute. */
$sentence = static function (string $why): string {
    return match ($why) {
        'not-configured' => 'The link to the console is not set up here yet.',
        'unreachable'    => 'The console did not answer. Nothing was changed — try again in a moment.',
        'not-yours'      => 'That follow-up is not yours.',
        'no-summary'     => 'Say what happened, even briefly. A tick that records nothing is how the centre ended up with ninety leads and no notes.',
        default          => str_starts_with($why, 'refused: ')
                            ? substr($why, 9)
                            : 'The console refused that.',
    };
};

try {
    if ($action === 'list') {
        if (!av_rate_ok('cac_leads', 60, 60)) {
            json_out(['ok' => false, 'error' => 'Too many requests — give it a moment.'], 429);
        }
        $r = CacLeads::forMember($uid);
        if (!$r['ok']) json_out(['ok' => false, 'error' => $sentence($r['error']), 'url' => $r['url']], 502);
        json_out(['ok' => true, 'leads' => $r['leads'], 'url' => $r['url']]);
    }

    if ($action === 'log') {
        if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
        require_same_origin();
        av_csrf_require();
        av_require_write($uid, 'cac_leads', 30);

        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $r = CacLeads::log($uid, (int) ($body['id'] ?? 0), [
            'summary'        => (string) ($body['summary'] ?? ''),
            'direction'      => (string) ($body['direction'] ?? 'outbound'),
            'next_action'    => (string) ($body['next_action'] ?? ''),
            'next_action_at' => (string) ($body['next_action_at'] ?? ''),
        ]);
        if (!$r['ok']) json_out(['ok' => false, 'error' => $sentence($r['error'])], 502);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('[portal cac-leads] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
