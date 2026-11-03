<?php
/**
 * portal/prefs.php — JSON API for per-user preferences (currently timezone).
 *
 *   GET  ?action=get              → { ok, tz }
 *   POST ?action=set_tz {tz}      → { ok, tz }
 *   POST ?action=set_shortcuts {ids[]} → { ok, ids }   the phone More sheet's tiles
 *
 * Any signed-in user; writes are same-origin + CSRF + rate-limited.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
$uid = (int) $u['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'get');
$body = [];
if ($method === 'POST') { $body = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST; }

try {
    switch ($action) {
        case 'get':
            json_out(['ok' => true, 'tz' => av_user_tz($uid)]);

        case 'set_tz':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'prefs', 30);
            if (!Prefs::setTimezone($uid, (string) ($body['tz'] ?? ''))) json_out(['ok' => false, 'error' => 'Unknown timezone.'], 422);
            json_out(['ok' => true, 'tz' => av_user_tz($uid)]);

        case 'set_shortcuts':
            /* The More sheet's tiles. Only real section ids are kept, at most
               eight; whether the member may OPEN one is decided when the sheet
               is drawn (PortalNav::shortcuts), so a stored id never grants a section. */
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'prefs', 30);
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($body['ids'] ?? [])),
                static fn(string $id): bool => isset(PortalNav::SECTIONS[$id]) && $id !== 'overview')));
            $ids = array_slice($ids, 0, PortalNav::SHORTCUT_MAX);
            Prefs::set($uid, 'nav_shortcuts', implode(',', $ids));
            json_out(['ok' => true, 'ids' => $ids]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[prefs] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
