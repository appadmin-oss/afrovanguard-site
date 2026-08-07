<?php
/**
 * portal/notifications.php — JSON API for the per-user notifications inbox.
 *
 *   GET  ?action=list            → { ok, items:[…], unread }
 *   GET  ?action=count           → { ok, unread }
 *   POST ?action=read {ids:[…]}  → { ok }
 *   POST ?action=read_all        → { ok }
 *
 * Any signed-in user; writes are same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Notifications.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'items' => Notifications::listFor($uid, 30), 'unread' => Notifications::unreadCount($uid)]);

        case 'count':
            json_out(['ok' => true, 'unread' => Notifications::unreadCount($uid)]);

        case 'read':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'notif', 120);
            Notifications::markRead($uid, is_array($body['ids'] ?? null) ? $body['ids'] : []);
            json_out(['ok' => true, 'unread' => Notifications::unreadCount($uid)]);

        case 'read_all':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'notif', 120);
            Notifications::markAllRead($uid);
            json_out(['ok' => true, 'unread' => 0]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[notifications] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
