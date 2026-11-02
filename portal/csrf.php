<?php
/**
 * portal/csrf.php — a fresh anti-forgery token for the signed-in member's own
 * page (assets/site/csrf-renew.js), so a page left open does not stop saving
 * when the one it loaded with expires.
 *
 *   GET → { ok, token }
 *
 * Reading it needs the member's own session, and another site cannot read the
 * answer (no CORS), so it hands nothing to anybody who could not already load
 * the page that carries one.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!LmsAuth::user() && !av_admin_cookie_valid()) json_out(['ok' => false, 'error' => 'Please sign in.'], 401);
json_out(['ok' => true, 'token' => av_csrf_token()]);
