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
 * Reads + writes require a signed-in account (LmsAuth). Becoming a mentor
 * requires Afrovanguard membership. Writes are same-origin + rate limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
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
            json_out(Mentorship::addSession($uid, (int) ($body['id'] ?? 0), (string) ($body['title'] ?? ''), (string) ($body['when'] ?? ''), (string) ($body['notes'] ?? '')));

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[mentorship api] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
