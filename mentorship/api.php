<?php
/**
 * mentorship/api.php — JSON API for the Afrovanguard mentor network.
 *
 *   GET  ?action=mentors                 → accepting mentors (signed in)
 *   GET  ?action=mine                    → my mentorships (as mentee + as mentor)
 *   POST ?action=become {headline,bio,focus,capacity,accepting}  (org members)
 *   POST ?action=request {mentor_id,message}                     (signed in)
 *   POST ?action=respond {id, accept}                            (the mentor)
 *   POST ?action=end     {id}                                    (either party)
 *   POST ?action=session {id, title, when, notes}                (the mentor)
 *
 * The mentor portal's own actions are grouped at the end of the switch; they
 * all answer with an HTTP status that matches, so the browser can tell a
 * refusal from a save without reading the body.
 *
 * Reads + writes require a signed-in account (LmsAuth). Becoming a mentor
 * requires Afrovanguard membership. Writes are same-origin + rate limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/**
 * The portal's JS sends the token as `X-CSRF`; the quiz posts it as a form
 * field because it must work with JavaScript off. Accept either, and say
 * plainly when there is no signing key rather than rejecting every save on an
 * installation with no APP_KEY and no way for the mentor to tell why.
 */
function mentor_csrf_require(): void
{
    if (av_secret() === '') {
        static $warned = false;
        if (!$warned) { $warned = true; error_log('[mentorship] APP_KEY is not set: portal writes fall back to same-origin only. Set APP_KEY to a long random string.'); }
        return;
    }
    $t = (string) ($_SERVER['HTTP_X_CSRF'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if (!av_csrf_valid($t)) json_out(['ok' => false, 'error' => 'This page has been open a while — reload and try again.'], 403);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'mentors');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

try {
    switch ($action) {
        case 'mentors':
            json_out(['ok' => true, 'mentors' => Mentorship::availableMentors($uid)]);

        case 'mine':
            json_out([
                'ok'        => true,
                'is_mentor' => Mentorship::isMentor($uid),
                'profile'   => Mentorship::profile($uid),
                'as_mentee' => Mentorship::myMentors($uid),
                'as_mentor' => Mentorship::isMentor($uid) ? Mentorship::myMentees($uid) : [],
            ]);

        case 'become':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Mentoring is for Afrovanguard members. Sign in with your @' . (defined('AV_ORG_DOMAIN') ? AV_ORG_DOMAIN : 'afrovanguard.org.ng') . ' account.'], 403);
            json_out(Mentorship::becomeMentor($uid, is_array($body) ? $body : []));

        case 'request':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('mentor_request', 12, 900)) json_out(['ok' => false, 'error' => 'Too many requests — give it a moment.'], 429);
            json_out(Mentorship::request($uid, (int) ($body['mentor_id'] ?? 0), (string) ($body['message'] ?? '')));

        case 'respond':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::respond($uid, (int) ($body['id'] ?? 0), !empty($body['accept'])));

        case 'end':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::end($uid, (int) ($body['id'] ?? 0)));

        case 'session':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::addSession($uid, (int) ($body['id'] ?? 0), (string) ($body['title'] ?? ''), (string) ($body['when'] ?? ''), (string) ($body['notes'] ?? ''), (string) ($body['meet_url'] ?? ''), (int) ($body['duration_min'] ?? 60), (string) ($body['type'] ?? 'checkin')));

        case 'outcome':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::recordOutcome($uid, (int) ($body['session_id'] ?? 0), (string) ($body['outcome'] ?? '')));

        case 'attend':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::markAttendance($uid, (int) ($body['session_id'] ?? 0), (string) ($body['status'] ?? ''), (int) ($body['duration_min'] ?? 0)));

        case 'meet':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::setMeetLink($uid, (int) ($body['session_id'] ?? 0), (string) ($body['meet_url'] ?? '')));

        /* ── Triggered from the portal: open the Meet link + auto-log start/end ── */
        case 'meet_start':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('meet_start_' . $uid, 30, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment.'], 429);
            json_out(Mentorship::startMeeting($uid, (int) ($body['session_id'] ?? 0)));

        case 'meet_ping':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::pingMeeting($uid, (int) ($body['session_id'] ?? 0)));

        case 'meet_end':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::endMeeting($uid, (int) ($body['session_id'] ?? 0)));

        case 'meet_state':
            json_out(Mentorship::meetingState($uid, (int) ($_GET['session_id'] ?? $body['session_id'] ?? 0)));

        case 'transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::attachTranscript($uid, (int) ($body['session_id'] ?? 0), (string) ($body['url'] ?? '')));

        /* ── Session minutes and the AI notetaker ──
           Either party may do any of these. A mentee is as entitled to a record
           of what was agreed — and as entitled to take the notetaker out of the
           room — as their mentor is. */
        case 'session_transcript':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::saveSessionTranscript(
                $uid, (int) ($body['session_id'] ?? 0), (string) ($body['text'] ?? ''), 'paste'
            ));

        case 'session_minutes': {
            $m = Mentorship::sessionMinutes($uid, (int) ($_GET['session_id'] ?? $body['session_id'] ?? 0));
            if (!$m) json_out(['ok' => false, 'error' => 'No minutes for that session.'], 404);
            json_out(['ok' => true, 'minutes' => $m]);
        }

        case 'session_add_bot':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::inviteSessionBot($uid, (int) ($body['session_id'] ?? 0)));

        case 'session_remove_bot':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            json_out(Mentorship::removeSessionBot($uid, (int) ($body['session_id'] ?? 0)));

        /* ══ THE MENTOR PORTAL ════════════════════════════════════════════
           Every one of these is a WRITE by a mentor against a pairing whose
           id arrived in the request body. MentorPortal re-checks ownership on
           each one — including each id inside a bulk action — so an edited id
           gets "not your mentee", not somebody else's. */
        case 'goals': case 'goal-add': case 'goal-edit': case 'goal-status': case 'goal-remove':
        case 'close-step': case 'close': case 'plan':
        case 'session-create': case 'session-log': case 'session-missed': case 'session-unlog':
        case 'message': case 'values': case 'checkin': case 'reflect-reply': case 'reflect-unreply':
        case 'request-accept': case 'request-decline': case 'request-undecline':
        case 'profile': case 'concern': case 'ack': case 'support':
        case 'bulk': case 'bulk-undo': case 'quiz': {
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            mentor_csrf_require();
            if (!Mentorship::isMentor($uid)) json_out(['ok' => false, 'error' => 'You do not have a mentor profile.'], 403);
            if (!av_rate_ok('mentor_write_' . $uid, 240, 600)) json_out(['ok' => false, 'error' => 'That is a lot of saving at once. Give it a moment.'], 429);

            /* The Values form posts seven `level[key]` and seven
               `evidence[key]` fields. The browser serialises a FormData to a
               FLAT object, so they arrive as literal keys like
               "level[diligence]" rather than nested arrays — which PHP would
               have nested for itself had the body been form-encoded. Expanded
               here, once, rather than teaching one view to read a shape no
               other view uses. */
            foreach ($body as $k => $val) {
                if (!is_string($k) || !preg_match('/^([a-z_]+)\[([a-z0-9_-]+)\]$/i', $k, $m)) continue;
                $body[$m[1]] = ($body[$m[1]] ?? []);
                if (is_array($body[$m[1]])) $body[$m[1]][$m[2]] = $val;
                unset($body[$k]);
            }

            $portal = new MentorPortal($uid);
            $pid = (int) ($body['pairing_id'] ?? 0);

            /** Answer with a status that matches the answer. */
            $out = static function (array $r, int $bad = 422): void {
                json_out($r, empty($r['ok']) ? ($r['error'] ?? '') === 'Not your mentee.' ? 403 : $bad : 200);
            };

            switch ($action) {
                case 'goals':
                    $out($portal->saveGoals($pid, (string) ($body['goals'] ?? '')));

                case 'goal-add':
                    $out($portal->addGoal($pid, (string) ($body['title'] ?? ''),
                                          (string) ($body['measure'] ?? ''), (string) ($body['due'] ?? '')));

                case 'goal-edit':
                    $out($portal->editGoal($pid, (int) ($body['goal_id'] ?? 0), (string) ($body['title'] ?? ''),
                                           (string) ($body['measure'] ?? ''), (string) ($body['due'] ?? '')));

                case 'goal-status':
                    $out($portal->setGoalStatus($pid, (int) ($body['goal_id'] ?? 0), (string) ($body['status'] ?? '')));

                case 'goal-remove':
                    $out($portal->removeGoal($pid, (int) ($body['goal_id'] ?? 0)));

                case 'close-step':
                    $out($portal->closeStep($pid, (string) ($body['step'] ?? ''), !empty($body['on']) && $body['on'] !== 'false'));

                case 'close':
                    $out($portal->closePairing($pid));

                case 'plan': {
                    $lines = $portal->planLines($pid);
                    if (!$lines) json_out(['ok' => false, 'error' => 'Not your mentee.'], 403);
                    json_out(['ok' => true, 'lines' => $lines]);
                }

                case 'session-create': {
                    // One dialog serves both the single and the group case; a
                    // group session is the same write, repeated and checked.
                    $ids = array_filter(array_map('intval', explode(',', (string) ($body['group_ids'] ?? ''))));
                    $targets = $ids ?: [$pid];
                    $made = 0; $failed = [];
                    foreach (array_slice($targets, 0, 100) as $t) {
                        $r = $portal->createSession($t, (string) ($body['type'] ?? 'checkin'), (string) ($body['when'] ?? ''),
                                                    (int) ($body['minutes'] ?? 60), (string) ($body['agenda'] ?? ''));
                        if (!empty($r['ok'])) $made++; else $failed[] = $t;
                    }
                    $out(['ok' => $made > 0, 'made' => $made, 'failed' => $failed, 'error' => $made ? '' : 'None of those could be scheduled.']);
                }

                case 'session-log':
                    $out($portal->logSession((int) ($body['session_id'] ?? 0), (int) ($body['minutes'] ?? 60),
                                                 (array) ($body['topics'] ?? []), (string) ($body['mood'] ?? ''),
                                                 (string) ($body['outcome'] ?? '')));

                case 'session-missed':
                    $out($portal->markMissed((int) ($body['session_id'] ?? 0)));

                case 'session-unlog':
                    $out($portal->unlogSession((int) ($body['session_id'] ?? 0)));

                case 'message':
                    $out($portal->sendMessage($pid, (string) ($body['body'] ?? '')));

                case 'values':
                    $out($portal->saveValues($pid, (array) ($body['level'] ?? []), (array) ($body['evidence'] ?? [])));

                case 'checkin':
                    $out($portal->saveCheckin((int) ($body['checkin_id'] ?? 0), $pid,
                                                  (int) ($body['q0'] ?? -1), (int) ($body['q1'] ?? -1), (int) ($body['q2'] ?? -1)));

                case 'reflect-reply':
                    $out($portal->replyToReflection((int) ($body['entry_id'] ?? 0), (string) ($body['reply'] ?? '')));

                case 'reflect-unreply':
                    $out($portal->unreplyToReflection((int) ($body['entry_id'] ?? 0)));

                case 'request-accept':
                    $out($portal->acceptRequest((int) ($body['id'] ?? 0)));

                case 'request-decline':
                    $out($portal->declineRequest((int) ($body['id'] ?? 0)));

                case 'request-undecline':
                    $out($portal->undeclineRequest((int) ($body['id'] ?? 0), (string) ($body['token'] ?? '')));

                case 'profile':
                    $out($portal->saveProfile($body));

                case 'concern':
                    $out($portal->reportConcern((string) ($body['category'] ?? ''), (string) ($body['facts'] ?? ''), $pid));

                case 'ack':
                    $out($portal->acknowledgeNote((int) ($body['id'] ?? 0)));

                case 'support': {
                    // Straight to the coordinator inbox, with the topic the
                    // mentor picked so it can be routed without being read first.
                    $topic = mb_substr(trim(strip_tags((string) ($body['topic'] ?? 'Support'))), 0, 160);
                    $text  = mb_substr(trim(strip_tags((string) ($body['body'] ?? ''))), 0, 4000);
                    if ($text === '') json_out(['ok' => false, 'error' => 'Write what you need.'], 422);
                    av_emit_event('mentor.support', ['mentor_id' => $uid, 'topic' => $topic, 'body' => $text]);
                    error_log('[mentor support] #' . $uid . ' · ' . $topic . ' · ' . $text);
                    json_out(['ok' => true]);
                }

                case 'bulk':
                    json_out($portal->bulk((string) ($body['action'] ?? ''), (array) ($body['ids'] ?? []), (string) ($body['body'] ?? '')));

                case 'bulk-undo':
                    $out($portal->bulkUndo((string) ($body['token'] ?? '')));

                case 'quiz': {
                    // The quiz posts as a form, so it answers with a redirect
                    // rather than JSON: it must work with JavaScript off.
                    $key = preg_replace('/[^a-z]/', '', (string) ($body['key'] ?? ''));
                    $r = $portal->recordAttempt($key, (array) ($body['a'] ?? []));
                    if (empty($r['ok'])) json_out($r, 422);
                    $to = '/mentorship/mentor/?v=module&key=' . rawurlencode($key) . '&score=' . (int) $r['score'] . ($r['passed'] ? '&passed=1' : '');
                    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) json_out($r);
                    header('Location: ' . $to, true, 303);
                    exit;
                }
            }
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[mentorship api] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
