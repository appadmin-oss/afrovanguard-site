<?php
/**
 * tests/cacwifi.test.php — a member's Wi-Fi, which CACENTRE runs.
 *
 * Holds three promises: the Wi-Fi rides the connection this site already has
 * with CACENTRE (AV_SSO_SECRET, the same v1 token, a door named in `aud`), the
 * PIN is shown on the member's own portal dashboard, and only the agreed
 * fields travel.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CacSso.php';
require_once dirname(__DIR__) . '/lib/CacTasks.php';
require_once dirname(__DIR__) . '/lib/CacWifi.php';

$src    = (string) file_get_contents(dirname(__DIR__) . '/lib/CacWifi.php');
$api    = (string) file_get_contents(dirname(__DIR__) . '/portal/cac-wifi.php');
$portal = (string) file_get_contents(dirname(__DIR__) . '/portal/index.php');
$js     = (string) file_get_contents(dirname(__DIR__) . '/portal/wifi.js');
$cron   = (string) file_get_contents(dirname(__DIR__) . '/tasks/cron.php');

/* ── One connection, not a second ────────────────────────────────────────── */
ck('the Wi-Fi uses the existing CACENTRE key and origin, no key of its own', str_contains($src, 'CacSso::sign(') && str_contains($src, 'CacTasks::site()')
   && !preg_match('/getenv\(|WIFI_SECRET/', $src));
putenv('AV_SSO_SECRET=' . str_repeat('k', 40));
$tok = CacSso::sign(['aud' => 'wifi', 'uid' => 42]);
[$v, $body, $mac] = explode('.', $tok);
$unb = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
$c = json_decode($unb($body), true);
ck('a Wi-Fi token is the same v1 envelope, signed with AV_SSO_SECRET', $v === 'v1' && hash_equals(hash_hmac('sha256', $body, str_repeat('k', 40), true), $unb($mac)));
ck('…naming its door, so it can never pass as a sign-on link or another door’s', ($c['aud'] ?? '') === 'wifi' && ($c['iss'] ?? '') === 'av' && ($c['uid'] ?? 0) === 42
   && ($c['exp'] - $c['iat']) === CacSso::TTL);
putenv('AV_SSO_SECRET');

/* ── It fails soft ───────────────────────────────────────────────────────── */
ck('no member id makes no request at all', CacWifi::card(0)['error'] === 'no-member');
ck('no shared secret has its own answer, and sends nothing', CacWifi::card(7)['error'] === 'not-configured' && CacWifi::push()['error'] === 'not-configured');
ck('nothing is stored here: CACENTRE holds the PINs', !preg_match('/INSERT|UPDATE |DELETE|CREATE TABLE/', $src));

/* ── Only the agreed fields travel ───────────────────────────────────────── */
preg_match("/\\\$out\\[\\] = \\[(.*?)\\];/s", $src, $m);
preg_match_all("/'(\\w+)'\\s*=>/", $m[1] ?? '', $keys);
ck('the roster carries id, kind, name, email, the verdict and its reason — nothing else', $keys[1] === ['id', 'kind', 'name', 'email', 'printedId', 'homeSite', 'verdict', 'why']);
ck('an account that is not active goes as off, with the reason', str_contains($src, "'verdict'   => \$active ? 'on' : 'off'"));
ck('a list over CACENTRE’s cap is not cut and called full', str_contains($src, "count(\$people) > self::ROSTER_MAX"));

/* ── On the member's own dashboard ───────────────────────────────────────── */
$nav  = (string) file_get_contents(dirname(__DIR__) . '/lib/PortalNav.php');
$view = (string) file_get_contents(dirname(__DIR__) . '/portal/views/wifi.php');
ck('the portal has a Wi-Fi pane for members when the CACENTRE link is set up, in the one nav registry', str_contains($nav, "'wifi'        => ['group' => 'Work', 'label' => 'Wi-Fi', 'for' => ['inventory'], 'view' => 'wifi']")
   && str_contains($view, 'id="view-wifi"') && str_contains($portal, "/portal/wifi.js"));
ck('their own card only: the member comes from the session', str_contains($api, '$uid = (int) $u[\'id\'];') && !preg_match('/\$_GET\[.(id|uid|member)/', $api));
ck('a new PIN is a checked write (same origin, CSRF, rate)', str_contains($api, 'require_same_origin();') && str_contains($api, 'av_csrf_require();') && str_contains($api, "av_require_write(\$uid, 'cac_wifi'"));
ck('the PIN is hidden until asked for, with a pressed state', str_contains($js, "'••••••'") && str_contains($js, "setAttribute('aria-pressed'"));
ck('loading, an empty card and an honest error with a retry are all said', str_contains($js, 'Loading your Wi-Fi details') && str_contains($js, 'on their way from CACENTRE') && str_contains($js, 'retry.hidden = false'));
ck('the roster goes from the cron, under the heartbeat', str_contains($cron, "AiOps::run('wifi'") && str_contains($cron, 'CacWifi::push()'));
