<?php
/**
 * portal/bookmarks.php — JSON API for the shared team link hub (org members only).
 *
 *   GET  ?action=list                    → { ok, links:[…] }
 *   POST ?action=add {title, url, note}  → { ok, id }
 *   POST ?action=delete {id}             → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; reads require an org member.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Bookmarks.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'Team links are for Afrovanguard members.'], 403);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'links', 60); };

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'links' => Bookmarks::listLinks($uid, 60)]);

        case 'add':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = Bookmarks::add($uid, (string) ($body['title'] ?? ''), (string) ($body['url'] ?? ''), (string) ($body['note'] ?? ''));
            if (!$id) json_out(['ok' => false, 'error' => 'Add a valid link (http/https).'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Bookmarks::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[bookmarks] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
