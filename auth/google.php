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
    $hint      = (string) ($_GET['hint'] ?? '');
    $canonHost = (string) parse_url(SITE_URL, PHP_URL_HOST);
    $curHost   = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($canonHost !== '' && $curHost !== '' && strcasecmp($curHost, $canonHost) !== 0) {
        $q = ['next' => (string) ($_GET['next'] ?? '/academy/')];
        if ($hint !== '') $q['hint'] = $hint;
        header('Location: ' . rtrim(SITE_URL, '/') . '/auth/google/start?' . http_build_query($q));
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
    // Connect-on-sign-in: also request offline Workspace scopes so a single
    // Google sign-in both authenticates AND connects their Workspace.
    $extra = GoogleWorkspaceUser::connectOnSignin() ? GoogleWorkspaceUser::scopes() : '';
    header('Location: ' . GoogleAuth::authUrl($state, $hint, $extra));
    exit;
}

/* ── Incremental authorization: connect the member's OWN Google Workspace ──
   (offline access → refresh token → the site acts AS them). Separate from
   sign-in; requires an existing session. Returns to the shared callback. */
if ($action === 'connect') {
    $u = LmsAuth::user();
    if (!$u) { header('Location: ' . av_login_url('/workspace')); exit; }
    if (!av_rate_ok('gws_connect', 20, 600)) av_oauth_bounce('/workspace?e=rate');
    $state = GoogleWorkspaceUser::makeState((int) $u['id'], (string) ($_GET['next'] ?? '/workspace'));
    setcookie(GoogleWorkspaceUser::STATE_COOKIE, $state, [
        'expires' => time() + GoogleWorkspaceUser::STATE_TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    header('Location: ' . GoogleWorkspaceUser::connectUrl($state, (string) ($u['email'] ?? '')));
    exit;
}

if ($action === 'disconnect') {
    $u = LmsAuth::user();
    // State-changing → require POST + a valid CSRF token.
    if ($u && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && av_csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        GoogleWorkspaceUser::disconnect((int) $u['id']);
    }
    header('Location: /workspace');
    exit;
}

if ($action === 'callback') {
    if (isset($_GET['error'])) {
        // A cancelled connect returns to the hub; a cancelled sign-in to /login.
        $isConnect = ($_COOKIE[GoogleWorkspaceUser::STATE_COOKIE] ?? '') !== '';
        av_oauth_bounce($isConnect ? '/workspace?e=connect_cancelled' : '/login?e=google_cancelled');
    }
    $state = (string) ($_GET['state'] ?? '');

    // Is this the CONNECT flow? (its own signed state + cookie)
    $connState = GoogleWorkspaceUser::readState($state);
    $connCookie = (string) ($_COOKIE[GoogleWorkspaceUser::STATE_COOKIE] ?? '');
    if ($connState !== null && $connCookie !== '' && hash_equals($connCookie, $state)) {
        setcookie(GoogleWorkspaceUser::STATE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $u = LmsAuth::user();
        if (!$u || (int) $u['id'] !== $connState['uid']) av_oauth_bounce('/workspace?e=connect_session');
        $ok = GoogleWorkspaceUser::exchangeAndStore((string) ($_GET['code'] ?? ''), (int) $u['id']);
        av_oauth_bounce($connState['next'] . (str_contains($connState['next'], '?') ? '&' : '?') . ($ok ? 'connected=1' : 'e=connect_failed'));
    }

    // …otherwise the normal sign-in flow.
    $cookie = (string) ($_COOKIE[GoogleAuth::STATE_COOKIE] ?? '');
    if ($state === '' || !hash_equals($cookie, $state)) av_oauth_bounce('/login?e=google_state');
    $next = GoogleAuth::readState($state);
    if ($next === null) av_oauth_bounce('/login?e=google_state');

    $tokens = GoogleAuth::exchangeTokens((string) ($_GET['code'] ?? ''));
    $profile = $tokens ? GoogleAuth::profileFromTokens($tokens) : null;
    if (!$profile) av_oauth_bounce('/login?e=google_failed');

    $res = LmsAuth::oauthSignIn($profile['email'], $profile['name'], (bool) $profile['verified'], 'google');
    if (empty($res['ok'])) av_oauth_bounce('/login?e=google_failed');

    // Connect-on-sign-in: if the grant carried offline Workspace scopes, capture
    // the connection for the just-signed-in member (best-effort — never blocks login).
    $uid = (int) ($res['user']['id'] ?? 0);
    if ($uid > 0 && GoogleWorkspaceUser::connectOnSignin()) {
        try { GoogleWorkspaceUser::captureFromSignin($uid, $tokens); }
        catch (Throwable $e) { error_log('[google] connect-on-signin: ' . $e->getMessage()); }
    }
    av_oauth_bounce($next); // success → back to where they started
}

// Unknown action
header('Location: /login');
exit;
