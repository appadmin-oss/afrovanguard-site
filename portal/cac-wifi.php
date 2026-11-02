<?php
/**
 * portal/cac-wifi.php — the member's own Wi-Fi, which CACENTRE runs.
 *
 *   GET                    → { ok, card:{username, hasPassword, pinSetAt, access, why, speed, usage, where, resetsLeft, hasRouter} | null }
 *   POST ?action=password  {password} → { ok, card }   the member's own choice; never echoed back
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
        'too_many'       => 'Your Wi-Fi password can be changed three times a day. Try again tomorrow.',
        'roster-refused' => 'CACENTRE, which runs the Wi-Fi, did not accept the list from this site. The team needs to check the link between the two sites (AV_SSO_SECRET).',
        'not-accepted'   => 'CACENTRE did not put you on the Wi-Fi list. Ask the team to look you up in CACENTRE → Wi-Fi.',
        'wifi-off'       => 'You don’t have Wi-Fi access right now, so there is no password to set.',
        default          => 'CACENTRE refused that.',
    };
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ((string) ($_GET['action'] ?? '') !== 'password') json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
        require_same_origin();
        av_csrf_require();
        av_require_write($uid, 'cac_wifi', 10);
        $in = json_decode((string) file_get_contents('php://input', false, null, 0, 4096), true);
        $pw = is_array($in) ? (string) ($in['password'] ?? '') : '';
        $r = CacWifi::setPassword($uid, $pw);
        /* CACENTRE's own sentence for a weak password says exactly what to change. */
        if (!$r['ok']) json_out(['ok' => false, 'error' => $r['message'] !== '' ? $r['message'] : $sentence($r['error']), 'detail' => (string) ($r['detail'] ?? '')], $r['error'] === 'too_many' ? 429 : ($r['error'] === 'weak' ? 422 : 200));
        json_out(['ok' => true, 'card' => $r['card']]);
    }
    if (!av_rate_ok('cac_wifi', 60, 60)) json_out(['ok' => false, 'error' => 'Too many requests — give it a moment.'], 429);
    $r = CacWifi::card($uid);
    /* 200, not 502: Cloudflare replaces a 502 from this server with its own
       page, and the reason below never reaches the member. `ok` says it failed. */
    if (!$r['ok']) json_out(['ok' => false, 'error' => ($r['message'] ?? '') !== '' ? (string) $r['message'] : $sentence($r['error']), 'detail' => (string) ($r['detail'] ?? '')], 200);
    json_out(['ok' => true, 'card' => $r['card']]);
} catch (Throwable $e) {
    error_log('[portal cac-wifi] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Server error.'], 500);
}
