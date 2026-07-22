<?php
/**
 * IQ/api.php — JSON API for Incorruptible Quiz (public + admin authoring).
 *
 *   GET  ?action=list[&category=]          → { ok, quizzes, categories }
 *   GET  ?action=quiz&slug=                → { ok, quiz }   (no correct answers)
 *   GET  ?action=leaderboard[&slug=]       → { ok, board }  (global if no slug)
 *   POST ?action=submit {slug,answers,name,duration} → graded result
 *   -- admin (coordinator/admin only) --
 *   GET  ?action=admin_list                → { ok, quizzes }
 *   GET  ?action=admin_get&id=             → { ok, quiz }
 *   POST ?action=admin_save {quiz…}        → { ok, id, slug }
 *   POST ?action=admin_delete {id}         → { ok }
 *
 * Public reads are open; submit + admin writes are same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u   = LmsAuth::user();
$uid = $u ? (int) $u['id'] : 0;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$isAdmin = $uid > 0 && class_exists('Community') && Community::isAdmin($uid);
$requireAdmin = function () use ($isAdmin) { if (!$isAdmin) json_out(['ok' => false, 'error' => 'Admins only.'], 403); };

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'quizzes' => IQ::listQuizzes($uid, (string) ($_GET['category'] ?? '')), 'categories' => IQ::categories()]);

        case 'quiz':
            $q = IQ::getForPlay((string) ($_GET['slug'] ?? ''));
            if (!$q) json_out(['ok' => false, 'error' => 'Quiz not found.'], 404);
            json_out(['ok' => true, 'quiz' => $q]);

        case 'leaderboard':
            $slug = (string) ($_GET['slug'] ?? '');
            json_out(['ok' => true, 'board' => $slug !== '' ? IQ::quizLeaderboard($slug) : IQ::globalLeaderboard()]);

        case 'submit':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            require_same_origin();
            if (!av_rate_ok('iq_submit_' . ($uid ?: av_client_ip()), 40, 600)) json_out(['ok' => false, 'error' => 'Slow down a moment and try again.'], 429);
            $answers = is_array($body['answers'] ?? null) ? $body['answers'] : [];
            json_out(IQ::grade((string) ($body['slug'] ?? ''), $answers, $uid, (string) ($body['name'] ?? ''), (int) ($body['duration'] ?? 0)));

        case 'badges':
            json_out(['ok' => true, 'badges' => IQ::badges($uid)]);

        case 'admin_list':
            $requireAdmin();
            json_out(['ok' => true, 'quizzes' => IQ::adminList()]);

        case 'admin_get':
            $requireAdmin();
            $q = IQ::getForEdit((int) ($_GET['id'] ?? 0));
            if (!$q) json_out(['ok' => false, 'error' => 'Not found.'], 404);
            json_out(['ok' => true, 'quiz' => $q]);

        case 'admin_save':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $requireAdmin();
            av_require_write($uid, 'iq_admin', 60);
            json_out(IQ::saveQuiz($uid, is_array($body) ? $body : []));

        case 'admin_delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $requireAdmin();
            av_require_write($uid, 'iq_admin', 60);
            json_out(['ok' => IQ::deleteQuiz((int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[IQ] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
