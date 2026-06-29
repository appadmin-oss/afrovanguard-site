<?php
/**
 * auth/google.php — Google sign-in endpoints.
 *   /auth/google/start    (?action=start)    → bounce to Google
 *   /auth/google/callback (?action=callback) → verify + sign in + redirect
 *
 * Gated by GoogleAuth::configured(); with no credentials it bounces back to
 * /login with a friendly notice (the button is disabled there anyway).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$action = (string) ($_GET['action'] ?? 'start');

/** Redirect helper that also clears the one-shot state cookie. */
function av_oauth_bounce(string $to): void
{
    setcookie(GoogleAuth::STATE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $to);
    exit;
}

if (!GoogleAuth::configured()) {
    av_oauth_bounce('/login?e=google_off');
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if ($action === 'start') {
    // Pin the WHOLE flow to the canonical host. Google returns to the registered
    // redirect_uri (built from SITE_URL); if the visitor started on a different
    // host (e.g. www vs non-www), the state cookie set here wouldn't be sent to
    // that callback host → "link expired". Bounce to the canonical host first.
    $canonHost = (string) parse_url(SITE_URL, PHP_URL_HOST);
    $curHost   = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($canonHost !== '' && $curHost !== '' && strcasecmp($curHost, $canonHost) !== 0) {
        header('Location: ' . rtrim(SITE_URL, '/') . '/auth/google/start?next=' . rawurlencode((string) ($_GET['next'] ?? '/academy/')));
        exit;
    }
    if (LmsAuth::user()) { header('Location: ' . GoogleAuth::safeNext((string) ($_GET['next'] ?? '/academy/'))); exit; }
    if (!av_rate_ok('oauth_start', 20, 600)) av_oauth_bounce('/login?e=rate');
    $state = GoogleAuth::makeState((string) ($_GET['next'] ?? '/academy/'));
    // Pin the state to a one-shot, SameSite=Lax cookie so it survives the Google
    // round-trip but can't be replayed cross-site (login-CSRF protection).
    setcookie(GoogleAuth::STATE_COOKIE, $state, [
        'expires' => time() + GoogleAuth::STATE_TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    header('Location: ' . GoogleAuth::authUrl($state));
    exit;
}

if ($action === 'callback') {
    if (isset($_GET['error'])) av_oauth_bounce('/login?e=google_cancelled');
    $state  = (string) ($_GET['state'] ?? '');
    $cookie = (string) ($_COOKIE[GoogleAuth::STATE_COOKIE] ?? '');
    if ($state === '' || !hash_equals($cookie, $state)) av_oauth_bounce('/login?e=google_state');
    $next = GoogleAuth::readState($state);
    if ($next === null) av_oauth_bounce('/login?e=google_state');

    $profile = GoogleAuth::exchange((string) ($_GET['code'] ?? ''));
    if (!$profile) av_oauth_bounce('/login?e=google_failed');

    $res = LmsAuth::oauthSignIn($profile['email'], $profile['name'], (bool) $profile['verified'], 'google');
    if (empty($res['ok'])) av_oauth_bounce('/login?e=google_failed');
    av_oauth_bounce($next); // success → back to where they started
}

// Unknown action
header('Location: /login');
exit;
