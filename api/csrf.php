<?php
/**
 * CSRF token mint for the STS static pages.
 *
 * Returns a stateless HMAC token (see av_csrf_token) and also drops it as a
 * readable `sts_csrf` cookie so the sponsor wizard can echo it back in its
 * `csrf_token` field. Both the sponsor and newsletter forms call this.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
if (function_exists('send_security_headers')) send_security_headers('public');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

$token  = av_csrf_token();
$secure = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
// Not httpOnly on purpose — the sponsor form reads it from document.cookie.
setcookie('sts_csrf', $token, [
    'expires'  => time() + 7200,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
]);
json_out(['ok' => true, 'token' => $token]);
