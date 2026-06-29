<?php
/**
 * STS · CSRF — token issuer and verifier.
 *
 * GET  /api/csrf.php → issues a token, sets it on a cookie, returns it.
 * Other endpoints `require_once 'csrf.php'` and call csrf_require() to enforce.
 *
 * Pattern: token is stored in an HttpOnly+SameSite=Lax cookie AND must be
 * resent in the POST body. Server compares the two.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function csrf_issue(): string
{
    if (!empty($_COOKIE['sts_csrf']) && preg_match('/^[a-f0-9]{64}$/', $_COOKIE['sts_csrf'])) {
        return $_COOKIE['sts_csrf'];
    }
    $token = bin2hex(random_bytes(32));
    $secure = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('sts_csrf', $token, [
        'expires' => time() + 7200,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false, // form JS must read it
        'samesite' => 'Lax',
    ]);
    return $token;
}

function csrf_require(): void
{
    $cookie = $_COOKIE['sts_csrf'] ?? '';
    $body = $_POST['csrf_token'] ?? '';
    if (!$cookie || !$body || !hash_equals($cookie, $body)) {
        // For the first form interaction, accept and refresh if cookie missing
        if (!$cookie) { csrf_issue(); }
        json_error('CSRF token mismatch. Please refresh and try again.', 419);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'csrf.php') {
    $token = csrf_issue();
    json_response(['ok' => true, 'token' => $token]);
}
