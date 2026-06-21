<?php
/**
 * diary/api.php — JSON API for the Diary.
 *
 *   GET  ?action=list                       → all entries (cards)
 *   GET  ?action=article&slug=<slug>        → one entry (+ sections, claps)
 *   GET  ?action=reactions&slug=<slug>      → live clap total
 *   POST ?action=react      {slug,count}    → increment claps, returns total
 *   POST ?action=subscribe  {email}         → store newsletter subscriber
 *
 * Reactions + subscribers persist in SQLite, so engagement is real and
 * shared across every visitor — not just per-browser.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Vary: Origin');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && preg_match('~^https?://([a-z0-9.-]*\.)?afrovanguard\.org\.ng$~i', $origin)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

try {
    $repo   = new DiaryRepository();
    $action = (string) ($_GET['action'] ?? 'list');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Accept JSON or form-encoded bodies for POST actions.
    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: $_POST;
    }
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['slug'] ?? $body['slug'] ?? '')));

    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'count' => count($a = $repo->all()), 'articles' => $a]);

        case 'article':
            $art = $slug ? $repo->bySlug($slug) : null;
            if (!$art) json_out(['ok' => false, 'error' => 'Article not found.'], 404);
            unset($art['id']);
            json_out(['ok' => true, 'article' => $art]);

        case 'reactions':
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            json_out(['ok' => true, 'slug' => $slug, 'claps' => $repo->claps($slug)]);

        case 'react':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            if ($slug === '') json_out(['ok' => false, 'error' => 'slug required'], 400);
            $count = (int) ($body['count'] ?? 1);
            json_out(['ok' => true, 'slug' => $slug, 'claps' => $repo->addClaps($slug, $count)]);

        case 'subscribe':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
            require_same_origin();
            $email = (string) ($body['email'] ?? '');
            if (!$repo->subscribe($email)) json_out(['ok' => false, 'error' => 'Enter a valid email address.'], 422);
            json_out(['ok' => true, 'message' => 'Subscribed. Watch for the next dispatch.']);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $ex) {
    error_log('[diary api] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
