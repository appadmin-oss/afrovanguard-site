<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 *  AFROVANGUARD — Admin Authentication Middleware
 *  File: admin-auth.php
 *
 *  Include in any admin-only endpoint AFTER security.php.
 *  Call av_require_admin() to gate access.
 *
 *  Token accepted via:
 *    Authorization: Bearer <ADMIN_TOKEN>   (recommended)
 *    X-Admin-Token: <ADMIN_TOKEN>          (alternative header)
 *
 *  The token value lives in config.php → define('ADMIN_TOKEN', '...')
 *  Generate a new one: php -r "echo bin2hex(random_bytes(32));"
 * ═══════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// Ensure security + config are loaded
if (!function_exists('av_json_fail')) {
    require_once __DIR__ . '/security.php';
}
if (!defined('ADMIN_TOKEN')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Enforce admin authentication.
 * Reads token from Authorization header or X-Admin-Token header.
 * Terminates with HTTP 401 if the token is absent or incorrect.
 */
function av_require_admin(): void
{
    if (!defined('ADMIN_TOKEN') || strlen((string) ADMIN_TOKEN) < 32) {
        av_log('admin-auth', 'ADMIN_TOKEN is missing or too short — check config.php');
        av_json_fail('Server configuration error.', 500);
    }

    $provided = '';

    // 1. Standard Authorization: Bearer <token>
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (str_starts_with($auth, 'Bearer ')) {
        $provided = trim(substr($auth, 7));
    }

    // 2. Fallback: X-Admin-Token header (for environments that strip Authorization)
    if ($provided === '') {
        $provided = trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
    }

    // Constant-time comparison prevents timing attacks
    if ($provided === '' || !hash_equals((string) ADMIN_TOKEN, $provided)) {
        av_log('admin-auth', 'Unauthorised admin request');
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="Afrovanguard Admin", charset="UTF-8"');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
        exit;
    }
}
