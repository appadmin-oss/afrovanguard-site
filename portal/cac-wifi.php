<?php
/**
 * portal/cac-wifi.php — the member's own Wi-Fi, which CACENTRE runs.
 *
 *   GET                    → { ok, card:{username, pin, access, why, speed, usage, where, resetsLeft, hasRouter} | null }
 *   POST ?action=newpin    → { ok, card }
 *
 * Their own card only: the member comes from the session and travels to
 * CACENTRE inside the signature (lib/CacWifi.php). Nothing is stored here.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$u = LmsAuth::user();
if (!$u) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
if (!LmsAuth::isOrgMember($u)) json_out(['ok' => false, 'error' => 'The centre’s Wi-Fi is for Afrovanguard members.'], 403);

$uid = (int) $u['id'];
/* The far side's word, turned into this site's sentence. */
$sentence = static function (string $why): string {
    return match ($why) {
        'not-configured' => 'The link to CACENTRE is not set up here yet.',
        'unreachable'    => 'CACENTRE, which runs the Wi-Fi, did not answer. Try again in a moment.',
        'too-many'       => 'A Wi-Fi PIN can be changed three times a day. Try again tomorrow.',
        'wifi-off'       => 'You don’t have Wi-Fi access right now, so there is no PIN to change.',
        default          => 'CACENTRE refused that.',
    };
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ((string) ($_GET['action'] ?? '') !== 'newpin') json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
        require_same_origin();
        av_csrf_require();
        av_require_write($uid, 'cac_wifi', 10);
        $r = CacWifi::newPin($uid);
        if (!$r['ok']) json_out(['ok' => false, 'error' => $sentence($r['error'])], $r['error'] === 'too-many' ? 429 : 502);
        json_out(['ok' => true, 'card' => $r['card']]);
    }
    if (!av_rate_ok('cac_wifi', 60, 60)) json_out(['ok' => false, 'error' => 'Too many requests — give it a moment.'], 429);
    $r = CacWifi::card($uid);
    if (!$r['ok']) json_out(['ok' => false, 'error' => $sentence($r['error'])], 502);
    json_out(['ok' => true, 'card' => $r['card']]);
} catch (Throwable $e) {
    error_log('[portal cac-wifi] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
