<?php
/**
 * academy/api.php — public Academy API.
 *   GET  ?action=list                    → published courses
 *   GET  ?action=course&slug=…           → one course
 *   POST ?action=enroll {slug,name,email,phone?,note?} → store an application
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

try {
    $repo = new AcademyRepository();
    $action = (string) ($_GET['action'] ?? 'list');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $body = [];
    if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? $body['slug'] ?? '')));

    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'courses' => $repo->all()]);
        case 'course':
            $c = $slug ? $repo->bySlug($slug) : null;
            if (!$c) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'course' => $c]);
        case 'enroll':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('enroll', 8, 600)) json_out(['ok' => false, 'error' => 'Too many submissions — please try later.'], 429);
            if (!$repo->enroll($slug, $body)) json_out(['ok' => false, 'error' => 'Please provide a valid name and email.'], 422);
            json_out(['ok' => true, 'message' => 'Application received — we will be in touch shortly.']);
        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[academy api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
