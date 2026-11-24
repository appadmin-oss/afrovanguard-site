<?php
/**
 * portal/prefs.php — JSON API for per-user preferences (currently timezone).
 *
 *   GET  ?action=get              → { ok, tz }
 *   POST ?action=set_tz {tz}      → { ok, tz }
 *   POST ?action=set_shortcuts {ids[]} → { ok, ids }   the phone More sheet's tiles
 *   POST ?action=set_a11y {value, at} → { ok, a11y, a11y_at }   the Accessibility panel (lib/A11y.php):
 *        the member's display settings, so they follow them to another device
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
            $a11y = Prefs::get($uid, A11y::PREF, '');
            json_out(['ok' => true, 'tz' => av_user_tz($uid), 'a11y' => A11y::valueOf($a11y), 'a11y_at' => A11y::stampOf($a11y)]);

        case 'set_a11y':
            /* Only allow-listed words reach the store (A11y::parse); "when" is the
               device's clock, clamped to now, so newest-wins cannot be gamed into
               the future and a device with no record (0) never beats a saved one. */
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'prefs', 60);
            $val = A11y::serialize(A11y::parse((string) ($body['value'] ?? '')));
            $at  = max(1, min(time(), (int) ($body['at'] ?? 0) ?: time()));
            Prefs::set($uid, A11y::PREF, $val . '@' . $at);
            A11y::setCookie($val);
            json_out(['ok' => true, 'a11y' => $val === '0' ? '' : $val, 'a11y_at' => $at]);

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
