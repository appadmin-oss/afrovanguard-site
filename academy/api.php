<?php
/**
 * academy/api.php — public Academy + LMS API.
 *
 *  Catalogue:  GET list | GET course&slug | POST enroll {slug,name,email}
 *  Accounts:   GET me | POST register | POST login | POST logout
 *  Learning:   POST join {slug} | POST lesson_complete {course,lesson}
 *              POST lesson_uncomplete | GET progress&course=slug
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? $_GET['course'] ?? $body['slug'] ?? $body['course'] ?? '')));

try {
    $ac = new AcademyRepository();
    $lms = new LmsRepository();

    switch ($action) {
        case 'list':    json_out(['ok' => true, 'courses' => $ac->all()]);
        case 'course':
            $c = $slug ? $ac->bySlug($slug) : null;
            if (!$c) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'course' => $c]);

        case 'enroll': // lead-capture form (non-account)
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('enroll', 8, 600)) json_out(['ok' => false, 'error' => 'Too many submissions — please try later.'], 429);
            if (!$ac->enroll($slug, $body)) json_out(['ok' => false, 'error' => 'Please provide a valid name and email.'], 422);
            json_out(['ok' => true, 'message' => 'Application received — we will be in touch shortly.']);

        /* ── Accounts ── */
        case 'me':
            $u = LmsAuth::user();
            json_out(['ok' => true, 'user' => $u ? LmsAuth::publicUser($u) : null, 'member' => $u ? $lms->isMember((int) $u['id']) : false]);
        case 'register':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('lms_register', 6, 900)) json_out(['ok' => false, 'error' => 'Too many attempts — try again later.'], 429);
            json_out(LmsAuth::register((string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['password'] ?? '')));
        case 'login':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('lms_login', 10, 900)) json_out(['ok' => false, 'error' => 'Too many attempts — try again later.'], 429);
            json_out(LmsAuth::login((string) ($body['email'] ?? ''), (string) ($body['password'] ?? '')));
        case 'logout':
            LmsAuth::logout(); json_out(['ok' => true]);

        /* ── Learning ── */
        case 'join':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $c = $ac->bySlug($slug, true);
            if (!$c) json_out(['ok' => false, 'error' => 'Course not found.'], 404);
            if (($c['access_type'] ?? 'open') === 'paid' && !$lms->isMember((int) $u['id']))
                json_out(['ok' => false, 'error' => 'This course requires payment or membership.'], 402);
            $lms->enrol((int) $u['id'], (int) $c['id']);
            json_out(['ok' => true]);
        case 'lesson_complete':
        case 'lesson_uncomplete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $c = $ac->bySlug($slug, true);
            $lesson = $c ? $lms->lesson((int) $c['id'], preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['lesson'] ?? '')))) : null;
            if (!$lesson) json_out(['ok' => false, 'error' => 'Lesson not found.'], 404);
            if (!$lms->canAccess($u, $c, $lesson)) json_out(['ok' => false, 'error' => 'No access to this lesson.'], 403);
            if ($action === 'lesson_complete') $lms->markComplete((int) $u['id'], $lesson); else $lms->unmark((int) $u['id'], (int) $lesson['id']);
            json_out(['ok' => true, 'progress' => $lms->progress((int) $u['id'], (int) $c['id'])]);
        case 'quiz_submit':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            $u = LmsAuth::require();
            $c = $ac->bySlug($slug, true);
            $lesson = $c ? $lms->lesson((int) $c['id'], preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($body['lesson'] ?? '')))) : null;
            if (!$lesson) json_out(['ok' => false, 'error' => 'Lesson not found.'], 404);
            if (!$lms->canAccess($u, $c, $lesson)) json_out(['ok' => false, 'error' => 'No access.'], 403);
            $res = $lms->gradeQuiz((int) $u['id'], $lesson, array_map('intval', (array) ($body['answers'] ?? [])));
            if (empty($res['ok'])) json_out(['ok' => false, 'error' => 'This lesson has no quiz.'], 400);
            $res['progress'] = $lms->progress((int) $u['id'], (int) $c['id']);
            json_out($res);

        case 'progress':
            $u = LmsAuth::user();
            $c = $slug ? $ac->bySlug($slug, true) : null;
            if (!$u || !$c) json_out(['ok' => true, 'progress' => null]);
            json_out(['ok' => true, 'progress' => $lms->progress((int) $u['id'], (int) $c['id'])]);

        default: json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[academy api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $ex->getMessage())], 500);
}
