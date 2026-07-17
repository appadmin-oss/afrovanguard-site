<?php
/**
 * portal/reminders.php — JSON API for personal reminders (any signed-in user).
 *
 *   GET  ?action=list                → { ok, reminders:[…] }
 *   POST ?action=add {text, due}     → { ok, id }
 *   POST ?action=toggle {id}         → { ok }
 *   POST ?action=delete {id}         → { ok }
 *
 * Writes are same-origin + CSRF + rate-limited; each user sees only their own.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/Reminders.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

$writeGuard = function () use ($uid) { av_require_write($uid, 'reminders', 80); };

try {
    switch ($action) {
        case 'list':
            json_out(['ok' => true, 'reminders' => Reminders::listFor($uid, 100)]);

        case 'add':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            $id = Reminders::add($uid, (string) ($body['text'] ?? ''), (string) ($body['due'] ?? ''));
            if (!$id) json_out(['ok' => false, 'error' => 'Add a reminder.'], 422);
            json_out(['ok' => true, 'id' => $id]);

        case 'toggle':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Reminders::toggle($uid, (int) ($body['id'] ?? 0))]);

        case 'delete':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            $writeGuard();
            json_out(['ok' => Reminders::remove($uid, (int) ($body['id'] ?? 0))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[reminders] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
