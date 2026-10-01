<?php
/**
 * portal/prefs.php — JSON API for per-user preferences (currently timezone).
 *
 *   GET  ?action=get              → { ok, tz }
 *   POST ?action=set_tz {tz}      → { ok, tz }
 *   POST ?action=set_birthday {birthday: 'YYYY-MM-DD' | 'MM-DD' | '', keep_year} → { ok, label }
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

        /* The member's own birthday (lib/Birthdays). The year is optional, and
           keep_year=false stores the day alone for somebody who would rather
           not say how old they are. */
        case 'set_birthday':
            if ($method !== 'POST') json_out(['ok' => false, 'error' => 'POST required.'], 405);
            av_require_write($uid, 'prefs', 30);
            $r = Birthdays::set($uid, (string) ($body['birthday'] ?? ''), !array_key_exists('keep_year', $body) || !empty($body['keep_year']));
            if (!$r['ok']) json_out($r, 422);
            json_out(['ok' => true, 'label' => Birthdays::label(Birthdays::of($uid))]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('[prefs] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => av_is_prod() ? 'Server error.' : ('Server error: ' . $e->getMessage())], 500);
}
